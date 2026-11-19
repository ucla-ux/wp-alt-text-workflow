#!/usr/bin/env bash
# Create one draft-free published post per photo, with the photo attached to it
# and shown in its content, so the alt-text audit has context to work with.
#
# Run from the root of a DDEV WordPress TEST site (not this repo):
#   bash bin/rehearsal/seed-posts.sh seed-photos            # import every image in ./seed-photos
#   bash bin/rehearsal/seed-posts.sh --cleanup              # delete what this script made
#
# The photo folder must be INSIDE the project (DDEV only sees the project folder).
# Name files descriptively: "river-valley-fieldwork.jpg" becomes the post title
# "River valley fieldwork". The audit also reads file names to pick a route, so
# put "logo" or "chart" in those file names, and use a "person" post type for
# headshots (see the main README).
#
# Everything created is tagged "seed-photo" so --cleanup can remove it.
# Images are NOT given alt text; that is the point.

set -euo pipefail

TAG="seed-photo"

if ! ddev wp option get siteurl 2>/dev/null | grep -q '\.ddev\.site'; then
  echo "Refusing: this does not look like a DDEV test site." >&2
  exit 1
fi

if [ "${1:-}" = "--cleanup" ]; then
  ids=$(ddev wp post list --post_type=post --tag="$TAG" --field=ID --post_status=any | tr '\n' ' ')
  if [ -z "$ids" ]; then echo "Nothing to clean up."; exit 0; fi
  for id in $ids; do
    for att in $(ddev wp post list --post_type=attachment --post_parent="$id" --field=ID); do
      ddev wp post delete "$att" --force >/dev/null
    done
    ddev wp post delete "$id" --force >/dev/null
  done
  echo "Deleted posts tagged $TAG and their images."
  exit 0
fi

DIR="${1:-}"
if [ -z "$DIR" ] || [ ! -d "$DIR" ]; then
  echo "Usage: bash bin/rehearsal/seed-posts.sh <folder-of-photos-inside-project>" >&2
  exit 1
fi
case "$(cd "$DIR" && pwd)" in
  "$(pwd)"/*) ;;
  *) echo "The photo folder must be inside this project folder." >&2; exit 1 ;;
esac

count=0
shopt -s nullglob nocaseglob
for f in "$DIR"/*.jpg "$DIR"/*.jpeg "$DIR"/*.png "$DIR"/*.webp; do
  base=$(basename "$f")
  stem="${base%.*}"
  title=$(echo "$stem" | tr '_-' '  ' | sed -E 's/^ +| +$//g; s/ +/ /g')
  title="$(echo "${title:0:1}" | tr '[:lower:]' '[:upper:]')${title:1}"

  post_id=$(ddev wp post create --post_type=post --post_status=publish \
    --post_title="$title" --tags_input="$TAG" --porcelain)
  att_id=$(ddev wp media import "$f" --post_id="$post_id" --porcelain)
  src=$(ddev wp post get "$att_id" --field=guid)

  content="<!-- wp:image {\"id\":${att_id},\"sizeSlug\":\"large\"} -->
<figure class=\"wp-block-image size-large\"><img src=\"${src}\" alt=\"\" class=\"wp-image-${att_id}\"/></figure>
<!-- /wp:image -->"
  ddev wp post update "$post_id" --post_content="$content" >/dev/null

  count=$((count + 1))
  printf '%2d  post %-4s image %-4s %s\n' "$count" "$post_id" "$att_id" "$title"
done

echo
echo "Created $count posts. Next: ddev wp eval-file bin/wp-alt-text-workflow/step-2-alt-find/alt-find.php (see the repo README)"
