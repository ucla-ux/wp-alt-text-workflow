# Fix missing alt text in WordPress with WP-CLI, a CSV and Claude

A workflow to find every Media Library image with no alt text, have Claude describe each one, review the results in a CSV, and write them back with WP-CLI.

**You do all of this on a test copy of the site first. Never on the live site.**

## Pick your path

| You have | Do this |
|---|---|
| Just a browser and a spreadsheet | Look at a real run in `examples/rehearsal-run/`: Claude's draft CSV, then the CSV after a person reviewed it. Nothing to install |
| DDEV, Python and an API key | Follow this README on the rehearsal site (about 20 minutes) |
| A different setup (Local, wp-env, a staging server) | The scripts are plain WP-CLI and Python, so they adapt. See [other setups](#other-setups-and-getting-a-test-copy-of-a-real-site) |

## Assumptions

This README describes **one way** to do this. It assumes:

- **A Mac or Linux laptop.** The commands are shell commands. Windows through WSL should work but was not tested.
- **DDEV** with Docker or OrbStack. DDEV provides WordPress, PHP and WP-CLI (`ddev wp`).
- **A disposable test site**, not a real one. The demo uses the *rehearsal site*: a throwaway WordPress with fake and sample images (see `rehearsal/README.md`).
- **Python 3.9+** on your laptop, not inside the container, with the `anthropic` package.
- **An Anthropic API key** exported in your terminal. Images are sent to the API.
- **The scripts live in the site's project folder** under `bin/alt-text/`, and all output goes to `tmp/` there.
- **Table prefix `wp_`.** The scripts use WordPress's own table names, so other prefixes should work, but only `wp_` was tested.

If your setup differs, the commands change, not the workflow.

## How the demo is set up

The demo runs on a local DDEV project, `~/Sites/alt-rehearsal` (URL `https://alt-rehearsal.ddev.site`):

```text
~/Sites/alt-rehearsal/          the DDEV WordPress project
  bin/alt-text/                 these scripts, copied in
  tmp/                          every CSV and report (ignored by git)
  .venv/                        Python with the anthropic package
```

For the demo we use this setup because everyone sees the same results and nothing real is at risk. Other setups work just as well.

## What you will do

```text
1. Find images with no alt text            alt-audit.php      -> tmp/alt-manifest.json
2. Preview what Claude will be asked       alt-generate.py --dry-run
3. Have Claude describe a small batch      alt-generate.py    -> tmp/alt-results.csv
4. Review the CSV                          a person reads every row
5. Generate the rest
6. Write the alt text into the Media Library   alt-apply.php
7. Fix pages that were already published       alt-to-blocks.php
8. Check the result
9. Undo if needed
10. Repeat on staging, then live
```

| Script | What it does | Writes to the database? |
|---|---|---|
| `scripts/alt-audit.php` | Lists images with empty alt, plus what page each appears on. Sorts each as `photo`, `person`, `logo`, `infographic`, or "never sent" (SVG, file missing, no page uses it) | No |
| `scripts/alt-generate.py` | Sends each image (the medium-size version) to Claude and writes a CSV. Resumable. Marks images it can't describe responsibly with `needs_human=1` | No |
| `scripts/alt-apply.php` | Writes the CSV into the Media Library. Never overwrites existing alt, skips `needs_human` rows, rejects text over 150 characters. Saves a rollback list | Yes |
| `scripts/alt-to-blocks.php` | Copies library alt into **linked** images in published pages that were saved with `alt=""`. Leaves standalone `alt=""` alone (that means "decorative") | Yes, `post_content` |

**Why step 7 exists.** WordPress copies `alt` into the page content when an editor saves. Filling the Media Library later does not change pages that were already saved, so a linked image can still have no accessible name. That is the Siteimprove issue "Link missing a text alternative".

## Safety rules

- Test copy first. Do not point these scripts at the live site until you have reviewed the CSV.
- Anything that writes is a **dry run** until you add the word `execute`.
- Alt text an editor wrote is never overwritten.
- **People: AI can guess at who people are and what they look like, and its guesses reflect stereotypes, so alt text should name a person only when the page does, describe only what is relevant, and always get a human read.** See [People and bias](#people-and-bias).
- Take a database snapshot before step 7 `execute`.
- Keep `ANTHROPIC_API_KEY` in your shell environment. Never save it in a file in a repo.
- **Images are sent to the Anthropic API.** Confirm your campus or department data-use rules allow it, and never send images of people who have not consented. Batch pricing is half the normal rate; a few cents covers a 50-image review batch (check current pricing).

---

## People and bias

> AI can guess at who people are and what they look like, and its guesses reflect stereotypes, so alt text should name a person only when the page does, describe only what is relevant, and always get a human read.

**The rule Claude is given.** This is the people paragraph in the system prompt in `scripts/alt-generate.py`, word for word:

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

Headshots also get their own prompt: the alt text is the person's name, optionally with a meaningful setting.

**How to avoid bias in alt text**

1. **Identify people from the page, never from the face.** A name comes from the post title, caption or staff page.
2. **Describe what is relevant, not what is visible.** "A researcher holds a soil core" is relevant. Hair, skin, build and age usually are not.
3. **Do not guess identity traits.** Gender, race, age and disability are the common failures. Use neutral words ("a person," "they," "people") unless the page says otherwise.
4. **Include a trait only when the page makes it relevant,** such as a caption that states it. Not because the model inferred it from a photo.
5. **For groups, describe the activity,** not each person: "Students work at a lab bench."
6. **Have someone who knows the people read those rows first** in the review step. They will catch a wrong name or an assumption.
7. **Look for patterns across rows,** not just single rows. If one group's images get longer or more personal descriptions than another's, that is a bias signal.

**What the rule does not guarantee.** A prompt is an instruction, not a filter: the model can still slip, and nothing checks its output for bias automatically. The identity-trait and face-identification lines were added after the first rehearsal run. On a rerun of the same 26 images, no alt text used a gender, race, age, disability or appearance word for any person, and the two named people and a group photo of nine cyclists were still described by name, activity and setting. That is a small test with images that were already handled well, so it shows no regression, not that the new lines work on harder cases such as an unnamed portrait or a conference crowd. The human review is the safeguard, not the prompt. If your site has many photos of people, read every one of those rows.

---

## Setup (once)

1. **Make the test site.** Follow `rehearsal/README.md`. It builds the disposable WordPress site and seeds it with images. Or use your own test copy ([other setups](#other-setups-and-getting-a-test-copy-of-a-real-site)).
2. **Add the scripts and the Python package.** From the WordPress project root:

```bash
mkdir -p tmp bin
cp -R /path/to/wp-alt-text-workflow/scripts bin/alt-text
python3 -m venv .venv && source .venv/bin/activate
pip install anthropic
export ANTHROPIC_API_KEY=...        # this terminal only; never save it in a repo
```

All output goes to `tmp/`. Add `tmp/` and `.venv/` to the site's `.gitignore`, because the CSVs name your pages and images.

3. **Check you are on the test site.** It must print the `.ddev.site` address:

```bash
ddev wp option get siteurl
```

## Step 1. Find images with no alt text

Read-only. It does not touch the database.

```bash
ddev wp eval-file bin/alt-text/alt-audit.php
```

You should see a count of images with no alt, split by route (`photo`, `person`, `logo`, `infographic`, and the "never sent" routes). It writes `tmp/alt-manifest.json`.

The audit only sends images whose files are on disk. Images with no file are skipped and listed as `no_file`.

## Step 2. Preview what Claude will be asked

Builds the prompts and sends nothing, so it costs nothing:

```bash
python3 bin/alt-text/alt-generate.py --dry-run --site "a university research institute"
```

Replace the quoted text with a short description of your site. Claude uses it to match the tone and to know who the audience is. The first three prompts are printed. It also writes `tmp/alt-needs-human.csv`, the images that were never sent.

## Step 3. Generate a small review batch

Start with 50 images (or fewer on the rehearsal site):

```bash
python3 bin/alt-text/alt-generate.py --site "a university research institute" --limit 50
```

The script uses the Message Batches API, which costs half the normal price. Results usually arrive in under an hour and can take up to 24 hours. The script waits and prints progress. At the end it prints token counts and the cost of this run, so this small batch tells you what the full run will cost.

It writes `tmp/alt-results.csv`.

## Step 4. Review the CSV

Open `tmp/alt-results.csv` in a spreadsheet and read every row. See `examples/alt-results-sample.csv` for the format.

Columns: `id, alt, route, needs_human, note, context, model`

- `id` is the attachment ID.
- `alt` is the text Claude wrote. Edit it here if needed.
- `needs_human = 1` means Claude could not responsibly describe it (an infographic, a bare portrait with no name, an unreadable logo). `alt-apply.php` skips these. A person writes those.
- `context` is the page the image appears on.

Check for:
- Names of people are right, and no one's appearance is described.
- No "image of" or "photo of" at the start.
- Nothing over 150 characters. `alt-apply.php` rejects those.
- Anything that is really a chart or map but was routed as a photo. Set `needs_human=1`.

If the style is wrong, change `--site` or edit the prompt in `alt-generate.py`, delete `tmp/alt-results.csv`, and run step 3 again.

## Step 5. Generate the rest

When the batch looks right, run the same command without `--limit`. Images already in the CSV are skipped:

```bash
python3 bin/alt-text/alt-generate.py --site "a university research institute"
```

Review the new rows the same way as step 4.

## Step 6. Write the alt text into the Media Library

First a dry run. Read the counts it prints:

```bash
ddev wp eval-file bin/alt-text/alt-apply.php tmp/alt-results.csv
```

Then apply it:

```bash
ddev wp eval-file bin/alt-text/alt-apply.php tmp/alt-results.csv execute
ddev wp cache flush
```

It only fills empty alt. It records every attachment it changed in `tmp/alt-rollback-<timestamp>.json`.

## Step 7. Fix pages that were already published

This edits `post_content`. Take a snapshot first:

```bash
ddev export-db --file=tmp/before-alt-to-blocks.sql.gz
```

Dry run, then apply:

```bash
ddev wp eval-file bin/alt-text/alt-to-blocks.php
ddev wp eval-file bin/alt-text/alt-to-blocks.php execute
ddev wp cache flush
```

It only changes images wrapped in a link, only when the alt in the page is empty, and only using alt that already exists in the Media Library. Standalone images with `alt=""` are left alone on purpose.

## Step 8. Check the result

- Open a few pages on the test site, view the source, and look for the `alt` attribute on images and linked images.
- On the rehearsal site, `rehearsal/alt-column.php` adds an **Alt text** column to Media → Library (list view), with **missing** in red. It makes the before and after easy to see.
- Run Lighthouse on a page that was affected.
- Re-test in Siteimprove. Its findings reflect its last crawl, not the site as it is now.

## Step 9. Undo (if needed)

Undo step 6 straight after a run, before editors change anything:

```bash
jq -r '.attachments[]' tmp/alt-rollback-YYYYMMDD-HHMMSS.json |
  xargs -I{} ddev wp post meta delete {} _wp_attachment_image_alt
```

Undo step 7 by restoring the snapshot:

```bash
ddev import-db --file=tmp/before-alt-to-blocks.sql.gz
```

## Step 10. Repeat on staging, then the live site

Only after the CSV is reviewed and the test copy looks right. On each server, in this order:

1. Take a database backup.
2. Copy over the reviewed `tmp/alt-results.csv` and `bin/alt-text/*.php`.
3. Run `alt-apply.php` as a dry run, then with `execute`. It only fills empty alt, so work editors have done since your copy was made is safe.
4. Run `alt-to-blocks.php` as a dry run, then with `execute`.
5. Flush caches.

Run WP-CLI from the home folder with `--path=<webroot>` if you do not want `tmp/` inside the webroot.

---

## Other setups, and getting a test copy of a real site

The demo uses a disposable rehearsal site. These are other ways to get a place to practice. Pick whichever fits; the steps above are the same once you have a site and `wp` working.

- **DDEV with a copy of a real site** (below).
- **Another local tool** such as Local or `wp-env`: replace `ddev wp` with that tool's WP-CLI command.
- **A staging site** you can reach with WP-CLI: run the scripts there with `wp` and `--path=<webroot>`. Treat staging as production-adjacent: dry runs first, backup first.

### A DDEV copy of a real site

You need a database dump and the uploads folder from the live site. Getting them usually needs someone with server access. In a DDEV project for the site:

```bash
ddev start
```

**Import the database dump** (taken on the live server with `wp db export`):

```bash
ddev import-db --file=~/Backups/example/db-YYYY-MM-DD.sql
```

**Rewrite the URLs** with WP-CLI, never with SQL (serialized data breaks otherwise):

```bash
ddev wp search-replace 'https://www.example.edu' 'https://example-edu.ddev.site' \
  --skip-columns=guid --all-tables --report-changed-only
ddev wp cache flush
```

Why those flags:
- Replace the full origin (`https://www.example.edu`), not just the host name. A bare host name also rewrites visible link text and URLs encoded inside other URLs.
- `--skip-columns=guid` keeps post identifiers stable.
- `--all-tables` includes plugin tables, such as redirect plugins.

**Copy the uploads folder.** This is an operations step: whoever has server access does it and hands you the folder, or you skip it if you already have a copy. It strips file ownership, which does not exist on macOS:

```bash
rsync -avzhe ssh --no-perms --no-owner --no-group --progress \
  user@live-server:/path/to/webroot/wp-content/uploads/ \
  wp-content/uploads/
```

**Check you are on the copy, not the live site:**

```bash
ddev wp option get siteurl
```

It must show the `.ddev.site` address. If it shows the live address, stop.

If you copy only part of `uploads/` for a quicker test, the rest are skipped and listed as `no_file`.

---

## Adapting to your site

- **Site description:** pass `--site "..."` or set `ALT_SITE`.
- **Image routes:** `alt-audit.php` decides how to treat each image from its file name, shape and the parent post type. It treats a `person` post type as a headshot, and a file name containing "logo", "avatar", "chart" or "diagram" as that kind of image. Change that check if your site uses different post types or names.
- **Model:** `alt-generate.py` has a `MODEL` constant and a `--model` option.
- **Alt text that exists only inside block markup:** some themes store it only in block HTML. Recover that into the Media Library before step 5, so an editor's own words win over generated text. This repo does not include a script for it because it depends on the theme's blocks.

## Status of these scripts

The scripts were copied from ones used on a live WordPress site. Re-tested 2026-11-18 on a throwaway DDEV site (WordPress with PHP 8.x, DDEV 1.25.5, Python 3.9, `anthropic` 0.125.0) seeded by `rehearsal/seed-test-site.php`:

| Step | Result |
|---|---|
| `alt-audit.php` | Passed: 6 images routed as photo, person, logo, infographic |
| `alt-generate.py --dry-run` | Passed |
| `alt-generate.py` live (Message Batches) | Passed 2026-11-18 with `claude-sonnet-5-5` on 26 images (20 real photos, 6 synthetic). It flagged 2 chart-like images and 2 unreadable logos or avatars `needs_human`, rejected one 156-character alt, and named people without describing their appearance. One miss: a map whose file name had no chart word routed as `photo` and got a description that conveys no data |
| `alt-apply.php` dry run and `execute` | Passed: wrote 5, skipped the `needs_human` row, left an editor's existing alt alone, wrote nothing on a second run |
| `alt-to-blocks.php` dry run and `execute` | Passed: filled `alt=""` on a linked image in published block markup |
| Undo, step 6 (rollback list) | Passed |
| Undo, step 7 (database snapshot) | Passed |

The review gate (step 4) is by design a human step; nothing here replaces reading every row.
