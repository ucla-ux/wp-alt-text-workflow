# Step 2: Find images with no alt text

Lists every WordPress image with no alt text, with a link to fix it and every
post that uses it. Read-only. Works on any local DDEV WordPress site.

## Use it

1. **Per site, once:** copy this repo into the site's `bin/` folder and add it to the site's `.gitignore`.

   ```bash
   cd ~/Sites/<site>
   mkdir -p bin
   cp -R ~/Git/wp-alt-text-workflow bin/wp-alt-text-workflow
   echo 'bin/wp-alt-text-workflow/' >> .gitignore
   ```

2. **Run it** from the site's folder (or ask Claude Code: "run alt-find").

   ```bash
   ddev wp eval-file bin/wp-alt-text-workflow/step-2-alt-find/alt-find.php
   ```

   Not using DDEV? Drop `ddev`.

3. **Before the next step, review the CSV.** Open `step-2-alt-find/reports/missing-alt.csv` in Modern CSV.
   - Delete a row to leave that image out of the next step.
   - Fix a wrong `route` (a map routed as `photo` should be `infographic`).
   - Nothing to change? Leave it. Everything is sent.

## What you get (in `step-2-alt-find/reports/` inside the site)

| File | Contents |
|---|---|
| `missing-alt.csv` | Images used on a post, most-used first. **This is the send list** |
| `missing-alt-orphans.csv` | Images no post uses |
| `missing-alt.json` | Everything, for the next step (`used_on_url` is an array) |

Key columns: `route` (how Claude will be asked), `edit_url` (type the alt here
by hand), `used_on_url` (every post using it, `"; "` separated), `linked`
(image is inside a link).

Routes: `photo`, `person`, `logo`, `infographic` are sent to Claude. `skip_svg`,
`no_file`, `no_context` are never sent: write those by hand.

## Notes

- Reports stay inside `bin/wp-alt-text-workflow/`, which is in the site's `.gitignore`. They name your pages and images, so keep them out of git.
- SVGs are included. Orphans may still be used in widgets, options or term meta.
- To use it as a Claude Code skill: `ln -s ~/Git/wp-alt-text-workflow/step-2-alt-find ~/.claude/skills/alt-find`.
- Next: [Step 3](../step-3-alt-generate/).
