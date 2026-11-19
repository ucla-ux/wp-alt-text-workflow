# Get a test copy of a real site

The demo uses a disposable rehearsal site. These are other ways to get a place to practice. The steps in the main README are the same once you have a site and `wp` working.

- **DDEV with a copy of a real site** (below).
- **Another local tool** such as Local or `wp-env`: replace `ddev wp` with that tool's WP-CLI command (drop `ddev` from the script commands).
- **A staging site** you can reach with WP-CLI: run the scripts there with `wp` and `--path=<webroot>`. Treat staging as production-adjacent: dry runs first, backup first.

## A DDEV copy of a real site

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
