# Step 4: Import the reviewed alt text

Writes the reviewed CSV into the WordPress Media Library as alt text. Dry run by
default. Works on any WP-CLI site; shown here on local DDEV.

## Use it

1. **Setup:** done in Step 2 (the repo is copied to `bin/wp-alt-text-workflow/` in the site).

2. **Dry run.** Read the counts and the first rows it prints. Nothing is written:

   ```bash
   cd ~/Sites/<site>
   ddev wp eval-file bin/wp-alt-text-workflow/step-4-alt-import/alt-import.php bin/wp-alt-text-workflow/step-3-alt-generate/reports/alt-reviewed.csv
   ```

3. **Apply.** Add the word `execute`, then flush the cache:

   ```bash
   ddev wp eval-file bin/wp-alt-text-workflow/step-4-alt-import/alt-import.php bin/wp-alt-text-workflow/step-3-alt-generate/reports/alt-reviewed.csv execute
   ddev wp cache flush
   ```

4. **Check.** Open Media → Library, a few pages, and the page source.

## Safety

- Only fills **empty** alt. An editor's words are never overwritten.
- Skips rows with `needs_human=1`. Rejects text over 150 characters.
- Safe to run twice: the second run finds nothing to do.
- Saves every id it changed to `step-4-alt-import/reports/alt-rollback-<timestamp>.json`.

## Undo

Straight after a run, before editors change anything:

```bash
jq -r '.attachments[]' bin/wp-alt-text-workflow/step-4-alt-import/reports/alt-rollback-YYYYMMDD-HHMMSS.json |
  xargs -I{} ddev wp post meta delete {} _wp_attachment_image_alt
```

## Pages that were already published (optional)

WordPress copies alt into the page when an editor saves, so filling the library
later does not change old pages. `alt-fix-pages.php` copies library alt into
**linked** images saved with `alt=""` (the Siteimprove issue "Link missing a
text alternative"). It edits page content, so snapshot first:

```bash
ddev export-db --file=bin/wp-alt-text-workflow/step-4-alt-import/reports/before-fix-pages.sql.gz
ddev wp eval-file bin/wp-alt-text-workflow/step-4-alt-import/alt-fix-pages.php # dry run
ddev wp eval-file bin/wp-alt-text-workflow/step-4-alt-import/alt-fix-pages.php execute
ddev wp cache flush
```

Undo: `ddev import-db --file=bin/wp-alt-text-workflow/step-4-alt-import/reports/before-fix-pages.sql.gz`.

## Staging and live

Only after the CSV is reviewed and the test copy looks right. Take a database
backup, copy over `alt-reviewed.csv` and the script, dry run, then `execute`.
Keep the repo copy, and so its `reports/`, outside the webroot on a server.
