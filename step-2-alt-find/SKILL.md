---
name: alt-find
description: List every WordPress Media Library image with no alt text, with edit links and the posts that use each one, as CSV and JSON. Use when asked for images missing alt text, an alt text audit or worklist, on a local WordPress/DDEV site.
---

# alt-find

Read-only. Writes three files to `step-2-alt-find/reports/` in the site folder:

- `missing-alt.csv`: images used on at least one published or private post, most-used first. The send list for alt-generate
- `missing-alt-orphans.csv`: images no post uses (check by hand before deleting)
- `missing-alt.json`: both together, with `used_on_url` as an array, plus `image_rel` and `context` for alt-generate

## Steps

1. Work from the site's project folder (the one containing `.ddev/`). If it is not the current directory, ask which site.
2. If `bin/wp-alt-text-workflow/` is not in the site, copy the repo there (`cp -R <repo> bin/wp-alt-text-workflow`) and add `bin/wp-alt-text-workflow/` to the site's `.gitignore`. Then run:

   ```bash
   ddev wp eval-file bin/wp-alt-text-workflow/step-2-alt-find/alt-find.php
   ```

   For a site without DDEV, drop `ddev`.
3. Report the counts it prints (photo, person, logo, infographic, skip_svg, no_file, no_context) and where the files are. Do not paste the CSV.
4. Tell the user to open `missing-alt.csv` before the next step: delete rows to leave images out, fix a wrong `route`.

## Notes

- Never connect to any server; this is for local sites only.
- The CSV's `alt` column is blank. This script does not write alt text anywhere.
- Multi-value `used_on_*` columns are joined with `"; "` and line up by position.
- An image used only in widgets, options, term or user meta, or a plain `<img>` with no `wp-image` class shows as an orphan.
- Reports stay inside `bin/wp-alt-text-workflow/`, which should be in the site's `.gitignore`. Suggest it if it is not.
