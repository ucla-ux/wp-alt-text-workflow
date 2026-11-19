---
name: alt-import
description: Write a reviewed alt-text CSV into the WordPress Media Library with WP-CLI, dry run first, with a rollback list. Use after alt-generate and a human review, on a local WordPress/DDEV site.
---

# alt-import

Writes `step-3-alt-generate/reports/alt-reviewed.csv` (columns `id`, `alt`, `needs_human`) into `_wp_attachment_image_alt`. Only fills empty alt; skips `needs_human=1` and rows over 150 characters.

## Steps

1. Work from the site's project folder. Check `ddev wp option get siteurl` shows the `.ddev.site` address. If it shows a live address, stop.
2. Confirm the user has read every row of the draft and saved `step-3-alt-generate/reports/alt-reviewed.csv`. Do not review for them and then import.
3. Dry run, from the site folder (the repo copy is in `bin/wp-alt-text-workflow/`), and report the counts:

   ```bash
   ddev wp eval-file bin/wp-alt-text-workflow/step-4-alt-import/alt-import.php bin/wp-alt-text-workflow/step-3-alt-generate/reports/alt-reviewed.csv
   ```
4. **Only when the user says to apply**, add `execute`, then `ddev wp cache flush`. Report what was written and the rollback file path.
5. `alt-fix-pages.php` (same folder) is optional and edits post content: snapshot with `ddev export-db` first, dry run, then ask.

## Notes

- Never connect to any server; local sites only. For staging or live, give the user the commands.
- Undo: delete `_wp_attachment_image_alt` for every id in the rollback JSON.
