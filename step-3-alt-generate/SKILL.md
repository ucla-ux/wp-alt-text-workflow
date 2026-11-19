---
name: alt-generate
description: Draft alt text with the Claude API for the images alt-find listed, writing a CSV for human review. Use after alt-find, when asked to generate alt text for a WordPress site's missing-alt images. Sends images to Anthropic.
---

# alt-generate

Reads `step-2-alt-find/reports/missing-alt.json` (and `missing-alt.csv` as the send list). Writes `step-3-alt-generate/reports/alt-draft.csv`. Never touches WordPress.

## Steps

1. Work from the site's project folder. Confirm `step-2-alt-find/reports/missing-alt.json` exists; if not, run alt-find first.
2. **Ask before sending anything.** Images go to the Anthropic API. Confirm with the user that this site's images may be sent, and that they have reviewed `missing-alt.csv` (deleted rows are left out; `route` edits are honored).
3. Dry run first, from the site folder (the repo copy is in `bin/wp-alt-text-workflow/`; see alt-find):

   ```bash
   ~/.venvs/alt-text/bin/python bin/wp-alt-text-workflow/step-3-alt-generate/alt-generate.py --dry-run
   ```
4. Then a small real batch (`--limit 10`, plus `--site "<what the site is>"`). It needs `ANTHROPIC_API_KEY` in the environment. Never write the key to a file or print it.
5. Report counts, cost and `needs_human` rows. Do not paste the CSV.
6. Tell the user to read **every** row in `alt-draft.csv`, edit, and save as `step-3-alt-generate/reports/alt-reviewed.csv`. Do not edit alt text for them unless asked.

## Notes

- The people rule in the script's system prompt is the point of the review step. Do not weaken it.
- Resumable: ids already in the draft are skipped.
- Local sites only. Never connect to any server.
