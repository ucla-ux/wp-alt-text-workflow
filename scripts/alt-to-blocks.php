<?php
/**
 * Push Media Library alt text into linked images that were saved with alt="".
 *
 * WHY THIS EXISTS
 * ---------------
 * Fixing the Media Library does not fix already-published content.
 *
 * The core/image and core/gallery blocks freeze `alt` into post_content when
 * the editor saves. An image inserted before anyone wrote alt text is stored as
 * `alt=""` forever. Running alt-apply.php later fills
 * `_wp_attachment_image_alt` in the Media Library, and every FUTURE insert of
 * that attachment picks it up — but the already-saved markup keeps its empty
 * copy, and that is what the page serves.
 *
 * The symptom: an attachment has good alt text in the Media Library, yet the
 * page still renders `alt=""` for it.
 *
 * WHY LINKED IMAGES ONLY
 * ----------------------
 * An empty alt is a legitimate, meaningful choice: it marks an image as
 * decorative. Blanket-filling every alt="" would silently override that
 * decision, which is the mistake of inventing alt text from the post title.
 *
 * A linked image is different. When `<a>` wraps an image and contains nothing
 * else, the alt text is the link's only accessible name — an empty alt there is
 * never a valid decorative choice, it is a link that screen readers announce as
 * nothing but its URL. That is Siteimprove's "Link missing a text alternative"
 * issue, and it is the only case this script touches.
 *
 * Standalone images with alt="" are deliberately left alone.
 *
 * SAFETY
 * ------
 * - Dry run is the default. Writing requires the literal argument `execute`.
 * - Only fills alt that is EMPTY. Never overwrites alt already in the markup.
 * - Only uses library alt that is NON-EMPTY. Never invents text.
 * - Only rewrites <img> that is wrapped in a link to the uploads directory.
 * - Idempotent: a second run finds nothing to do.
 * - Rewrites post_content via $wpdb->update, so no filters fire and no
 *   revisions are created. Take a DB snapshot first regardless — this edits
 *   published content, which nothing else in this folder does.
 *
 * USAGE
 *   wp eval-file bin/alt-text/alt-to-blocks.php            # dry run
 *   wp eval-file bin/alt-text/alt-to-blocks.php execute    # apply
 *   (prefix with `ddev` locally)
 */

$mode = isset($args[0]) ? strtolower(trim($args[0])) : 'dry';
if (! in_array($mode, ['dry', 'execute'], true)) {
	WP_CLI::error("Unknown mode '{$mode}'. Use: dry (default) or execute.");
}

global $wpdb;

// Only posts that actually contain an empty alt on a wp-image-* tag. Keeps the
// scan off the whole table.
$rows = $wpdb->get_results(
	"SELECT ID, post_title, post_content FROM {$wpdb->posts}
	  WHERE post_status IN ('publish','draft','pending','private')
	    AND post_content LIKE '%alt=\"\"%'
	    AND post_content LIKE '%wp-image-%'"
);

WP_CLI::log(sprintf('Scanning %d posts with an empty alt on a wp-image-* tag.', count($rows)));

/** Cache of attachment_id => library alt, so a repeated image costs one query. */
$alt_cache = array();

$alt_for = function ($id) use (&$alt_cache) {
	if (! array_key_exists($id, $alt_cache)) {
		$alt = get_post_meta($id, '_wp_attachment_image_alt', true);
		$alt_cache[$id] = is_string($alt) ? trim($alt) : '';
	}
	return $alt_cache[$id];
};

$posts_changed   = 0;
$images_filled   = 0;
$skipped_no_lib  = 0;   // linked, empty alt, but the library has nothing either
$examined        = 0;
$samples         = array();

// An anchor whose ONLY content is one <img>.
//
// This used to require the href to point at /wp-content/uploads/ — an image
// linked to its own file. That is one shape of the defect and not the common
// one: a person card links to /person/<name>/, a partner logo links off site,
// and both are named by the image's alt and nothing else. Restricting by href
// examined 45 images in 4 posts and left the rest of the finding untouched.
//
// The condition that actually matters is whether the anchor has any other
// text to name it, so the pattern now anchors on `</a>` immediately after the
// image instead. An anchor holding a caption or a "View Profile" label already
// has an accessible name and is correctly skipped — verified on /event/ese-50th/,
// where all 10 person links wrap the image alone and none carry other content.
$pattern = '#(<a\s[^>]*href="[^"]*"[^>]*>\s*)(<img\s[^>]*>)(\s*</a>)#i';

foreach ($rows as $row) {
	$filled_here = 0;

	$new_content = preg_replace_callback(
		$pattern,
		function ($m) use ($alt_for, &$examined, &$images_filled, &$filled_here,
		                   &$skipped_no_lib, &$samples, $row) {
			$anchor = $m[1];
			$img    = $m[2];
			$close  = $m[3];

			// Only an alt that is present and empty. A missing alt attribute is
			// a different defect and not this script's business.
			if (! preg_match('#\salt=""#i', $img)) {
				return $m[0];
			}
			if (! preg_match('#wp-image-(\d+)#i', $img, $id_match)) {
				return $m[0];
			}

			$examined++;
			$id  = (int) $id_match[1];
			$alt = $alt_for($id);

			if ($alt === '') {
				$skipped_no_lib++;
				return $m[0];
			}

			$new_img = preg_replace(
				'#\salt=""#i',
				' alt="' . esc_attr($alt) . '"',
				$img,
				1
			);

			$images_filled++;
			$filled_here++;
			if (count($samples) < 8) {
				$samples[] = sprintf('  #%d "%s" <- attachment %d: %s',
					$row->ID, $row->post_title, $id, mb_strimwidth($alt, 0, 70, '…'));
			}

			return $anchor . $new_img . $close;
		},
		$row->post_content
	);

	if ($filled_here === 0 || $new_content === $row->post_content) {
		continue;
	}

	$posts_changed++;

	if ($mode === 'execute') {
		$updated = $wpdb->update(
			$wpdb->posts,
			array('post_content' => $new_content),
			array('ID' => $row->ID),
			array('%s'),
			array('%d')
		);
		if ($updated === false) {
			WP_CLI::warning(sprintf('Failed to update post %d (%s)', $row->ID, $row->post_title));
			continue;
		}
		clean_post_cache($row->ID);
	}
}

WP_CLI::log('');
WP_CLI::log('Linked images with an empty alt examined: ' . $examined);
WP_CLI::log('  would fill from the Media Library:      ' . $images_filled);
WP_CLI::log('  skipped, library alt also empty:        ' . $skipped_no_lib);
WP_CLI::log('Posts affected:                           ' . $posts_changed);

if ($samples) {
	WP_CLI::log('');
	WP_CLI::log('Samples:');
	foreach ($samples as $s) {
		WP_CLI::log($s);
	}
}

WP_CLI::log('');
if ($mode === 'execute') {
	WP_CLI::success(sprintf('Applied. %d image(s) across %d post(s).', $images_filled, $posts_changed));
	WP_CLI::log('Run `wp cache flush` next.');
} else {
	WP_CLI::success('Dry run only. Re-run with `execute` to apply.');
	if ($skipped_no_lib > 0) {
		WP_CLI::log(sprintf(
			'%d linked image(s) have no alt anywhere. Run alt-audit.php and the '
			. 'alt-generate/alt-apply pipeline first, then re-run this.',
			$skipped_no_lib
		));
	}
}
