# Alt text for WordPress with AI

Find images with no alt text, have AI draft it, review it as a person, and write
it back. Four steps, one folder each. **Always on a test copy of the site first.**

> AI drafts. A person decides. AI can guess at who people are and what they look like, and its guesses reflect stereotypes, so alt text should name a person only when the page does, describe only what is relevant, and always get a human read.

## The four steps

| Step | Folder | Does | Writes to WordPress? |
|---|---|---|---|
| **1. Prompt** | [`step-1-alt-prompt/`](./step-1-alt-prompt/) | One image, no install: upload it to an AI tool, paste a prompt | No |
| **2. Find** | [`step-2-alt-find/`](./step-2-alt-find/) | Lists images with no alt, where each is used, as CSV and JSON | No |
| **3. Generate** | [`step-3-alt-generate/`](./step-3-alt-generate/) | Sends those images to the Claude API, writes a draft CSV | No |
| **4. Import** | [`step-4-alt-import/`](./step-4-alt-import/) | Writes the reviewed CSV into the Media Library | Yes (dry run first) |

Step 1 is the easy way, for a handful of images. Steps 2 to 4 automate a whole
site. Each folder has a script, a README and a `SKILL.md` (so Claude Code can
run it for you).

```text
Step 2                      Step 3                       Step 4
alt-find.php  -> [review] -> alt-generate.py -> [review] -> alt-import.php
missing-alt.csv/.json        alt-draft.csv                  alt-reviewed.csv
delete rows, fix route       read every alt, edit,          -> Media Library
                             save as alt-reviewed.csv
```

Two human gates, both in a CSV editor such as Modern CSV. Each step saves its
reports in its own `reports/` folder, next to its script (for example
`step-2-alt-find/reports/`). Those folders are git-ignored.

## Set up a site (once)

Copy this repo into the test site's `bin/` folder and keep it out of the site's git:

```bash
cd ~/Sites/<your-test-site>
mkdir -p bin
cp -R ~/Git/wp-alt-text-workflow bin/wp-alt-text-workflow
echo 'bin/wp-alt-text-workflow/' >> .gitignore
```

## Run it

From the site's folder. DDEV shown; without DDEV, drop `ddev` and use `wp`.

```bash
# Step 2. Find (read-only)
ddev wp eval-file bin/wp-alt-text-workflow/step-2-alt-find/alt-find.php

# Step 3. Generate. One time: python3 -m venv ~/.venvs/alt-text && ~/.venvs/alt-text/bin/pip install anthropic
# Each terminal: export ANTHROPIC_API_KEY=...   (details in step-3-alt-generate/README.md)
~/.venvs/alt-text/bin/python bin/wp-alt-text-workflow/step-3-alt-generate/alt-generate.py --dry-run
~/.venvs/alt-text/bin/python bin/wp-alt-text-workflow/step-3-alt-generate/alt-generate.py --site "a university research institute" --limit 10

# Step 4. Import (dry run, then add `execute`)
ddev wp eval-file bin/wp-alt-text-workflow/step-4-alt-import/alt-import.php bin/wp-alt-text-workflow/step-3-alt-generate/reports/alt-reviewed.csv
ddev wp eval-file bin/wp-alt-text-workflow/step-4-alt-import/alt-import.php bin/wp-alt-text-workflow/step-3-alt-generate/reports/alt-reviewed.csv execute
```

Setup details, the people rule and undo are in each step's README.

## Assumptions

- Mac or Linux, **DDEV** (Docker or OrbStack) for WordPress and WP-CLI. Other setups work: drop `ddev`.
- **Python 3.9+** and an **Anthropic API key**, for step 3 only.
- A **test site**. The demo uses a disposable one: [`rehearsal/`](./rehearsal/). For a copy of a real site: [`rehearsal/real-site-copy.md`](./rehearsal/real-site-copy.md).
- Table prefix `wp_`.

## Safety

- Test copy first. Imports are a dry run until you add `execute`.
- Existing alt is never overwritten. Rows Claude could not describe (`needs_human`) are skipped.
- Every import saves a rollback list.
- **Images are sent to the Anthropic API.** Confirm your data-use rules and consent first.
- Never save `ANTHROPIC_API_KEY` in a file in a repo.

## See a real run (no install)

[`examples/rehearsal-run/`](./examples/rehearsal-run/): Claude's draft CSV, then the CSV after a person reviewed it, from 26 images on the rehearsal site.

## Status

Tested 2026-11-19 on a throwaway DDEV site (DDEV 1.25.5, Python 3.9, `anthropic` 0.125.0, model `claude-sonnet-5-5`), using the commands above from `bin/wp-alt-text-workflow/`: Step 2 find, Step 3 dry run, Step 4 dry run, `execute` (21 written, rollback list saved to `step-4-alt-import/reports/`) and undo from that list. A live Step 3 run of 2 images passed earlier the same day with the previous folder names; the full 26-image run passed 2026-11-18.
