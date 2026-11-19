# Step 3: Generate alt text with AI

Sends each image from `alt-find` to the Claude API and writes a draft CSV of alt
text. Does not touch WordPress. Needs Python 3.9+, the `anthropic` package (in the shared
environment below) and an API key. **Images are sent to Anthropic.** Check your data-use rules and
consent first.

## Use it

1. **One time on your computer (not per site):** make one shared Python environment.

   ```bash
   python3 -m venv ~/.venvs/alt-text
   ~/.venvs/alt-text/bin/pip install anthropic
   ```

   A "pip version" warning at the end is harmless. To silence it, run
   `~/.venvs/alt-text/bin/python -m pip install --upgrade pip`.

   **Each terminal window, before the commands below:** set your key. Either type
   it, or load it from a private file kept outside any repo.

   ```bash
   export ANTHROPIC_API_KEY=...          # this terminal only; never save it in a repo
   set -a; source ~/.anthropic-env; set +a   # or load it from a private file
   ```

2. **Before: review the send list.** You did this in Step 2. Whatever is in
   `step-2-alt-find/reports/missing-alt.csv` now is what gets sent. No CSV? Everything in the JSON is sent.

3. **Preview.** Builds the prompts, sends nothing, costs nothing:

   ```bash
   ~/.venvs/alt-text/bin/python bin/wp-alt-text-workflow/step-3-alt-generate/alt-generate.py --dry-run
   ```

4. **Try a few.** `--site` tells Claude who the site is for:

   ```bash
   ~/.venvs/alt-text/bin/python bin/wp-alt-text-workflow/step-3-alt-generate/alt-generate.py --site "a university research institute" --limit 10
   ```

   It uses the Message Batches API (half price), waits, and prints tokens and cost.
   Rerun without `--limit` for the rest; images already in the draft are skipped.

5. **After: review every row.** Open `step-3-alt-generate/reports/alt-draft.csv` in Modern CSV and
   read each alt text. Edit freely. Then **save a copy as `step-3-alt-generate/reports/alt-reviewed.csv`**.
   Keep the draft: it shows what Claude wrote and what you changed.

## Review checklist

- Names are right and nobody's appearance is described.
- Does it tell me something the page does not? No "image of".
- Under 150 characters (import rejects longer).
- A chart or map that was routed as a photo: set `needs_human=1`.
- Rows with `needs_human=1` have a note saying why. Write those by hand or clear the flag after you do.

Columns: `id, alt, route, needs_human, note, context, filename, edit_url, file_url, model`. `filename` and `edit_url` (opens the image in the Media Library) help you check a row against the image. See
`../examples/rehearsal-run/` for a draft and its reviewed copy.

## Files

| File | Contents |
|---|---|
| `step-3-alt-generate/reports/alt-draft.csv` | Claude's draft. Grows as batches finish |
| `step-3-alt-generate/reports/alt-reviewed.csv` | Your reviewed copy. The next step reads this |
| `step-3-alt-generate/reports/raw.jsonl` | Raw API responses, for debugging |

## People and bias

> AI can guess at who people are and what they look like, and its guesses reflect stereotypes, so alt text should name a person only when the page does, describe only what is relevant, and always get a human read.

The rule Claude is given (from `alt-generate.py`, word for word):

```text
PEOPLE. Never describe a person's physical appearance: no hair, facial
features, skin, makeup, body, age, clothing, or expression. Words like
'smiling', 'bearded', 'young', 'wearing a blue blazer', 'with long dark
hair' must not appear. Never infer or state a person's gender, race,
ethnicity, disability, religion, or nationality from how they look, and
never identify anyone from their face: a name comes only from the
context. Instead:
  - If the context tells you who the person is, use their name.
  - Otherwise identify them by role or activity and describe the setting
    and what is happening -- 'A researcher examines a soil sample in a dry
    streambed.' Use neutral words ('a person', 'people', 'they') unless the
    context says otherwise.
  - If the image is a bare portrait against a plain background, with no
    setting or activity to describe and no name available, set needs_human to
    true and say so in the note.
```

How to avoid bias: identify people from the page, never the face; describe what
is relevant, not what is visible; do not guess gender, race, age or disability;
describe the activity for groups; have someone who knows the people read those
rows first; look for patterns across rows.

**A prompt is an instruction, not a filter.** On a rerun of 26 images no person
got a gender, race, age, disability or appearance word. That is a small test on
easy cases, not proof for an unnamed portrait or a crowd. The human review is
the safeguard.

## Notes

- Default model is `claude-sonnet-5-5` (tested, cheaper). Compare with `--model claude-opus-5-5`.
- Change the style by editing the prompt in `alt-generate.py`, deleting `alt-draft.csv`, and rerunning.
- Next: [`alt-import`](../step-4-alt-import/).
