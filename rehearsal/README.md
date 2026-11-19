# Rehearsal site

A disposable WordPress site with fake images, for practicing the whole workflow with nothing at stake.

Needs DDEV and Docker (or OrbStack). Run from an empty folder, not this repo:

```bash
mkdir alt-rehearsal && cd alt-rehearsal
ddev config --project-type=wordpress --docroot=. --project-name=alt-rehearsal --create-docroot
ddev start
ddev wp core download
ddev wp core install --url=https://alt-rehearsal.ddev.site --title="Alt Rehearsal" \
  --admin_user=admin --admin_password=choose-a-password --admin_email=you@example.test --skip-email
mkdir -p bin
cp -R /path/to/wp-alt-text-workflow/rehearsal bin/rehearsal
ddev wp eval-file bin/rehearsal/seed-test-site.php
```

(`ddev config` writes `wp-config.php` for you, so you do not need `wp config create`.)

Now follow the main README: **Set up a site (once)**, then Step 2. Expected Step 2 (`alt-find`) result:

| Route | Count |
|---|---|
| photo | 3 |
| person | 1 |
| logo | 1 |
| infographic | 1 |

Seven images exist (six with no alt, shown above); one already has alt written by an "editor", and `alt-import.php` must leave it alone. One post holds a linked image saved with `alt=""`, which `alt-fix-pages.php` fixes.

## See alt text in the Media Library (optional)

WordPress does not show alt text in the Media Library list. `alt-column.php` adds an **Alt text** column that shows **missing** in red when it is empty, so you can see the before and after of `alt-import.php`.

```bash
mkdir -p wp-content/mu-plugins
cp bin/rehearsal/alt-column.php wp-content/mu-plugins/
```

No activation needed. Open **Media → Library** in list view (the table icon), or `/wp-admin/upload.php?mode=list`. For test sites only; review it before using on a real site.

## Add your own photos (optional)

`seed-posts.sh` makes one post per photo, attaches the photo, and shows it in the post, with no alt text. Put photos in a folder inside the project and name them descriptively; the file name becomes the post title, which is the context Claude sees. Avoid names with `logo`, `avatar`, `chart` or `diagram` unless you want that route (`alt-find` reads file names).

```bash
cp bin/rehearsal/seed-posts.sh bin/rehearsal/   # already there if you copied the folder
bash bin/rehearsal/seed-posts.sh seed-photos
bash bin/rehearsal/seed-posts.sh --cleanup      # removes what it made (tagged seed-photo)
```

Photos go to the Anthropic API when you generate. Use images you have the right to send.

Delete it when done:

```bash
ddev delete -O alt-rehearsal
```

The seed script refuses to run unless the site URL ends in `.ddev.site`.
