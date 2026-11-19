#!/usr/bin/env python3
"""
Generate alt text for Media Library images that have none.

WHY THIS EXISTS
---------------
alt-find.php writes step-2-alt-find/reports/missing-alt.json (and .csv): every image with an
empty `_wp_attachment_image_alt`, the path to a mid-size rendition, and what the
page it lives on is about. This script turns that into step-3-alt-generate/reports/alt-draft.csv.
A person reviews it, saves it as step-3-alt-generate/reports/alt-reviewed.csv, and alt-import.php
writes it into the Media Library.

BEFORE-STEP REVIEW: if step-2-alt-find/reports/missing-alt.csv exists, it is the send list.
Delete a row to leave that image out. Change a row's `route` (for example photo
to infographic) to change how Claude is asked. Edit nothing and everything goes.

DESIGN NOTES
------------
- Message Batches API: half price, and a large image backlog is exactly the
  non-latency-sensitive workload it exists for. Results come back in ANY
  ORDER -- everything is keyed by custom_id, never by position.
- The prompt varies by the `route` the audit assigned. A headshot wants the
  person's name; a logo wants "<Org> logo"; an infographic cannot be conveyed
  in 150 characters at all, so it gets a summary AND needs_human=1.
- Resumable: IDs already in the output CSV are skipped, so the 50-image review
  gate and the remaining ~950 are the same command run twice.

USAGE (run from the WordPress project root, on the host, not in the container)
  one time: python3 -m venv ~/.venvs/alt-text && ~/.venvs/alt-text/bin/pip install anthropic
  export ANTHROPIC_API_KEY=...                       # never commit this
  ~/.venvs/alt-text/bin/python bin/wp-alt-text-workflow/step-3-alt-generate/alt-generate.py --dry-run                 # build prompts, send nothing
  ~/.venvs/alt-text/bin/python bin/wp-alt-text-workflow/step-3-alt-generate/alt-generate.py --site "a research institute" --limit 10
  ~/.venvs/alt-text/bin/python bin/wp-alt-text-workflow/step-3-alt-generate/alt-generate.py --site "..."              # everything still missing
"""

import argparse
import base64
import csv
import json
import os
import pathlib
import re
import sys
import time

MODEL = "claude-sonnet-5-5"  # tested; use --model claude-opus-5-5 to compare
MAX_LEN = 150
CHUNK = 250  # requests per batch submission, keeps payload well under 256MB

HERE = os.path.dirname(os.path.abspath(__file__))
FIND = os.path.normpath(os.path.join(HERE, "..", "step-2-alt-find", "reports"))  # written by step 2
OUT = os.path.join(HERE, "reports")  # this step's own reports folder
MANIFEST = f"{FIND}/missing-alt.json"
SEND_LIST = f"{FIND}/missing-alt.csv"  # optional: a person's edits to the list
# The manifest is written inside the DDEV container; image paths in it are
# relative to the uploads basedir so they resolve on the host too.
UPLOADS_ROOT = "wp-content/uploads"
RESULTS = f"{OUT}/alt-draft.csv"
RAW = f"{OUT}/raw.jsonl"

SEND_ROUTES = {"photo", "person", "logo", "infographic"}
ROUTES = SEND_ROUTES | {"skip_svg", "no_file", "no_context"}

DEFAULT_SITE = "a website"

# {site} is filled in from --site (or the ALT_SITE environment variable) so the
# model knows what kind of organization it is writing for.
SYSTEM_TEMPLATE = (
    "You write alt text for {site}. "
    "Alt text must be under 150 characters, use American spelling, and never "
    "begin with 'image of', 'photo of', 'picture of', or 'graphic of'. "
    "Write what a sighted reader would take from the image, not a catalog of "
    "everything visible. Ignore photographer credits, copyright lines, and "
    "filenames -- a credit like 'Jane Doe / Example University' is not a description and must "
    "never appear in the alt text.\n\n"
    "PEOPLE. Never describe a person's physical appearance: no hair, facial "
    "features, skin, makeup, body, age, clothing, or expression. Words like "
    "'smiling', 'bearded', 'young', 'wearing a blue blazer', 'with long dark "
    "hair' must not appear. Never infer or state a person's gender, race, "
    "ethnicity, disability, religion, or nationality from how they look, and "
    "never identify anyone from their face: a name comes only from the "
    "context. Instead:\n"
    "  - If the context tells you who the person is, use their name.\n"
    "  - Otherwise identify them by role or activity and describe the setting "
    "and what is happening -- 'A researcher examines a soil sample in a dry "
    "streambed.' Use neutral words ('a person', 'people', 'they') unless the "
    "context says otherwise.\n"
    "  - If the image is a bare portrait against a plain background, with no "
    "setting or activity to describe and no name available, set needs_human to "
    "true and say so in the note."
)

SCHEMA = {
    "type": "json_schema",
    "schema": {
        "type": "object",
        "properties": {
            "alt": {"type": "string", "description": "The alt text, under 150 characters."},
            "needs_human": {"type": "boolean", "description": "True if this image cannot be responsibly described in under 150 characters, or you cannot tell what it shows."},
            "note": {"type": "string", "description": "Short reason, only if needs_human is true. Otherwise empty."},
        },
        "required": ["alt", "needs_human", "note"],
        "additionalProperties": False,
    },
}

MEDIA_TYPES = {
    "image/jpeg": "image/jpeg", "image/jpg": "image/jpeg", "image/png": "image/png",
    "image/gif": "image/gif", "image/webp": "image/webp",
}


def build_prompt(item):
    ctx = item.get("context") or {}
    topic = (ctx.get("title") or "").strip()
    route = item["route"]

    hints = []
    for field in ("caption", "description"):
        v = (item.get(field) or "").strip()
        if v:
            hints.append(f"{field.capitalize()} on file: {v[:300]}")

    if route == "person":
        p = (
            f"This is the profile image for {topic}, on their staff/faculty page. "
            "If it is a portrait or headshot, the alt text should be that person's name "
            "-- optionally with the setting if the setting is meaningful (a lab, a field "
            "site, a lectern). Do not describe their appearance, clothing, or expression. "
            "If the image is clearly NOT a portrait of them, describe it normally."
        )
    elif route == "logo":
        p = (
            "This is a logo or emblem. Return the organization or program name followed "
            "by the word 'logo' -- for example 'Acme Foundation logo'. Nothing else. If you "
            "cannot read the name, set needs_human to true."
        )
    elif route == "infographic":
        p = (
            f"This is an infographic or chart. It appears on a page about {topic}. "
            "Write a one-line summary of what it presents (its subject and takeaway), "
            "under 150 characters. Then set needs_human to true: an infographic needs a "
            "long description that a short alt attribute cannot carry, and a human has to "
            "write that."
        )
    else:
        p = (
            f"Write alt text for this image, under 150 characters. It appears on a page "
            f"about {topic}. Describe what a sighted reader would take from it. "
            "Don't start with 'image of' or 'photo of.'"
        )

    if hints:
        p += "\n\n" + "\n".join(hints) + "\n(Use these only if they help; they are often filenames or boilerplate.)"
    return p


LEAD = re.compile(r"^\s*(an?\s+)?(image|photo|photograph|picture|graphic|screenshot)\s+(of|showing|depicting)\s+", re.I)


# A literal "\\uXXXX" (double-escaped by the model), or a control character
# where the escape prefix should have been -- the model sometimes emits
# "\\ru00e9" instead of "\\u00e9", and json.loads honours the \\r.
UNI = re.compile(r"(?:\\|[\r\n\t])u([0-9a-fA-F]{4})")


def clean(text):
    """Returns (alt, problem_or_None)."""
    t = (text or "").strip()
    # Opus-family models sometimes DOUBLE-escape non-ASCII inside JSON string
    # values, so json.loads yields a literal "\u00f1" rather than "ñ". Decode
    # whatever survived, or accented names ship mangled.
    t = UNI.sub(lambda m: chr(int(m.group(1), 16)), t)
    # Only unwrap when the WHOLE string is quoted. A bare .strip('"') mangles
    # alt text that legitimately opens with a quoted title -- it removes the
    # leading quote and orphans its partner mid-string.
    while len(t) >= 2 and t[0] == t[-1] and t[0] in ('"', "'"):
        t = t[1:-1].strip()
    t = LEAD.sub("", t).strip()
    if t:
        t = t[0].upper() + t[1:]
    if not t:
        return "", "empty"
    # A control character surviving to here means the model corrupted an escape
    # sequence mid-string (seen as "2007\\ncent2020" for "2007-2020", and
    # "animals \\ed by" for "animals led by"). The intent is not reliably
    # recoverable, so flag rather than guess.
    if any(ord(ch) < 32 for ch in t):
        return t, "corrupted escape sequence in model output"
    if len(t) > MAX_LEN:
        return t, f"too long ({len(t)})"
    return t, None


def load_done():
    done = set()
    if os.path.exists(RESULTS):
        with open(RESULTS, newline="", encoding="utf-8") as f:
            for row in csv.DictReader(f):
                done.add(int(row["id"]))
    return done


def sniff(data):
    """Media type from magic bytes.

    post_mime_type cannot be trusted: the webp-uploads plugin generates WebP
    renditions while the attachment still reports image/jpeg, and the API
    rejects a declared type that disagrees with the bytes.
    """
    if data[:3] == b"\xff\xd8\xff":
        return "image/jpeg"
    if data[:8] == b"\x89PNG\r\n\x1a\n":
        return "image/png"
    if data[:6] in (b"GIF87a", b"GIF89a"):
        return "image/gif"
    if data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        return "image/webp"
    return None


def encode(item, uploads_root):
    path = pathlib.Path(uploads_root) / item["image_rel"]
    if not path.exists():
        return None, None
    data = path.read_bytes()
    mt = sniff(data)
    if not mt:
        return None, None
    return mt, base64.standard_b64encode(data).decode("ascii")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--limit", type=int, help="only process the first N pending images")
    ap.add_argument("--ids", help="comma-separated attachment IDs to process")
    ap.add_argument("--dry-run", action="store_true", help="build prompts, send nothing")
    ap.add_argument("--model", default=MODEL)
    ap.add_argument("--site", default=os.environ.get("ALT_SITE", DEFAULT_SITE),
                    help="what the site is, for the prompt, e.g. "
                         "'a university research institute' (env: ALT_SITE)")
    ap.add_argument("--uploads-root", default=UPLOADS_ROOT,
                    help="host path to wp-content/uploads")
    args = ap.parse_args()

    system_prompt = SYSTEM_TEMPLATE.format(site=args.site)
    if not os.path.exists(MANIFEST):
        sys.exit(f"{os.path.relpath(MANIFEST)} not found. Run this from the site folder, after alt-find.")
    manifest = json.load(open(MANIFEST, encoding="utf-8"))

    # Apply the person's edits to the send list, if there is one.
    if os.path.exists(SEND_LIST):
        with open(SEND_LIST, newline="", encoding="utf-8") as f:
            listed = {int(r["id"]): (r.get("route") or "").strip() for r in csv.DictReader(f)}
        bad = {v for v in listed.values() if v and v not in ROUTES}
        if bad:
            sys.exit(f"{os.path.relpath(SEND_LIST)}: unknown route {sorted(bad)}. Use one of {sorted(ROUTES)}")
        kept = []
        for i in manifest:
            if i["id"] in listed:
                if listed[i["id"]]:
                    i["route"] = listed[i["id"]]
                kept.append(i)
        print(f"{os.path.relpath(SEND_LIST)}: {len(kept)} of {len(manifest)} images kept")
        manifest = kept
    else:
        print(f"No {os.path.relpath(SEND_LIST)}; using every image in {os.path.relpath(MANIFEST)}")

    # Some routes never go to the API: SVG, missing file, no page uses the image.
    held = [i for i in manifest if i["route"] not in SEND_ROUTES]
    print(f"{len(held)} images never sent ({', '.join(sorted({i['route'] for i in held})) or 'none'}): write those by hand")

    done = load_done()
    pending = [i for i in manifest if i["route"] in SEND_ROUTES and i["id"] not in done]
    if args.ids:
        want = {int(x) for x in args.ids.split(",")}
        pending = [i for i in pending if i["id"] in want]
    if args.limit:
        pending = pending[: args.limit]

    print(f"{len(done)} already done, {len(pending)} to process this run")
    if not pending:
        return

    if args.dry_run:
        for i in pending[:3]:
            print(f"\n--- {i['id']} [{i['route']}] {i['title']}\n{build_prompt(i)}")
        print(f"\n(dry run: {len(pending)} requests would be sent to {args.model})")
        return

    import anthropic
    from anthropic import Anthropic

    client = Anthropic()

    os.makedirs(OUT, exist_ok=True)
    columns = ["id", "alt", "route", "needs_human", "note", "context",
               "filename", "edit_url", "file_url", "model"]
    new_csv = not os.path.exists(RESULTS)
    if not new_csv:
        # Keep an older draft's columns, so new rows line up with its header.
        with open(RESULTS, newline="", encoding="utf-8") as f:
            columns = next(csv.reader(f), columns) or columns
    out = open(RESULTS, "a", newline="", encoding="utf-8")
    writer = csv.DictWriter(out, fieldnames=columns, extrasaction="ignore")
    if new_csv:
        writer.writeheader()
    raw = open(RAW, "a", encoding="utf-8")

    by_id = {i["id"]: i for i in pending}
    missing = []
    totals = {"in": 0, "out": 0, "ok": 0, "flagged": 0, "failed": 0}

    for start in range(0, len(pending), CHUNK):
        chunk = pending[start : start + CHUNK]
        requests = []
        for item in chunk:
            mt, b64 = encode(item, args.uploads_root)
            if not b64:
                missing.append(item["id"])
                continue
            requests.append({
                "custom_id": f"att-{item['id']}",
                "params": {
                    "model": args.model,
                    "max_tokens": 1000,
                    "system": system_prompt,
                    "output_config": {"effort": "low", "format": SCHEMA},
                    "messages": [{"role": "user", "content": [
                        {"type": "image", "source": {"type": "base64", "media_type": mt, "data": b64}},
                        {"type": "text", "text": build_prompt(item)},
                    ]}],
                },
            })

        try:
            batch = client.messages.batches.create(requests=requests)
        except anthropic.AuthenticationError:
            sys.exit("Anthropic rejected the API key (401). Check ANTHROPIC_API_KEY: "
                     "create a key in the Console, then `export ANTHROPIC_API_KEY=...` in this shell.")
        except anthropic.NotFoundError:
            sys.exit(f"Model '{args.model}' was not found. Pass --model with a current model ID.")
        print(f"batch {batch.id}: {len(requests)} requests submitted", flush=True)

        while True:
            b = client.messages.batches.retrieve(batch.id)
            if b.processing_status == "ended":
                break
            print(f"  {b.processing_status} "
                  f"(succeeded={b.request_counts.succeeded} errored={b.request_counts.errored} "
                  f"processing={b.request_counts.processing})", flush=True)
            time.sleep(20)

        for entry in client.messages.batches.results(batch.id):
            att_id = int(entry.custom_id.removeprefix("att-"))
            item = by_id[att_id]
            if entry.result.type != "succeeded":
                totals["failed"] += 1
                detail = ""
                err = getattr(entry.result, "error", None)
                if err is not None:
                    inner = getattr(err, "error", err)
                    detail = getattr(inner, "message", "") or str(inner)[:160]
                print(f"  !! {att_id}: {entry.result.type} {detail}")
                continue

            msg = entry.result.message
            totals["in"] += msg.usage.input_tokens
            totals["out"] += msg.usage.output_tokens
            raw.write(json.dumps({"id": att_id, "content": [b.model_dump() for b in msg.content]}) + "\n")

            text = "".join(b.text for b in msg.content if b.type == "text")
            try:
                parsed = json.loads(text)
            except json.JSONDecodeError:
                parsed = {"alt": text, "needs_human": True, "note": "response was not valid JSON"}

            alt, problem = clean(parsed.get("alt", ""))
            needs_human = bool(parsed.get("needs_human")) or problem is not None
            note = parsed.get("note", "") or (problem or "")
            if needs_human:
                totals["flagged"] += 1
            else:
                totals["ok"] += 1

            ctx = item.get("context") or {}
            writer.writerow({
                "id": att_id, "alt": alt, "route": item["route"],
                "needs_human": 1 if needs_human else 0, "note": note,
                "context": f"{ctx.get('type','')}: {ctx.get('title','')}",
                "filename": item.get("filename", ""),
                "edit_url": item.get("edit_url", ""),   # open the image in the Media Library
                "file_url": item.get("file_url", ""),   # the image itself
                "model": args.model,
            })
        out.flush()
        raw.flush()

    out.close()
    raw.close()

    if missing:
        print(f"\n{len(missing)} images skipped -- missing file, or a format the "
              f"vision API does not accept (AVIF, SVG): {missing[:10]}")

    cost = totals["in"] / 1e6 * 5 * 0.5 + totals["out"] / 1e6 * 25 * 0.5
    print(f"\nclean: {totals['ok']}   flagged for review: {totals['flagged']}   failed: {totals['failed']}")
    print(f"tokens: {totals['in']:,} in / {totals['out']:,} out")
    print(f"cost this run (batch pricing): ${cost:.2f}")
    print(f"wrote {os.path.relpath(RESULTS)}")


if __name__ == "__main__":
    main()
