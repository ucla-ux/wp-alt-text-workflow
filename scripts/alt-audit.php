<?php
/**
 * Inventory every Media Library image with no alt text, with enough context
 * to write one.
 *
 * WHY THIS EXISTS
 * ---------------
 * Images with no `_wp_attachment_image_alt` render unlabeled to a screen
 * reader. On a large site that can be thousands of images.
 *
 * Writing alt text needs two things a bare ID list does not give you: the
 * image itself, and what the page it appears on is ABOUT. Both are available:
 *
 *   - `post_parent` is usually set, and its post_type is as useful as
 *     its title. A `person` parent means a headshot, which wants the person's
 *     name, not a description of their face. An `infographic` cannot be
 *     described in 150 characters at all and must be flagged, not guessed at.
 *   - A `medium` rendition is normally on disk. Send that, not the full-size
 *     original.
 *
 * This script writes tmp/alt-manifest.json for alt-generate.py to consume.
 * It is READ-ONLY -- it never touches the database.
 *
 * The context lookup for parentless attachments scans post_content ONCE and
 * regexes every reference out, rather than running a LIKE query per image.
 * The per-image version takes minutes on a large site; this takes seconds.
 *
 * USAGE
 *   wp eval-file bin/alt-text/alt-audit.php                 # writes tmp/alt-manifest.json
 *   wp eval-file bin/alt-text/alt-audit.php tmp/other.json  # custom output path
 *   (prefix with `ddev` locally)
 */

$out_path = isset($args[0]) ? $args[0] : 'tmp/alt-manifest.json';
if (! preg_match('#\.json$#', $out_path)) {
	WP_CLI::error("Output path must end in .json (got '{$out_path}').");
}
if (! is_dir(dirname($out_path))) {
	WP_CLI::error('Output directory does not exist: ' . dirname($out_path));
}

global $wpdb;

$rows = $wpdb->get_results(
	"SELECT p.ID, p.post_title, p.post_excerpt, p.post_content, p.post_mime_type, p.post_parent
	   FROM {$wpdb->posts} p
	   LEFT JOIN {$wpdb->postmeta} m
	     ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
	  WHERE p.post_type = 'attachment'
	    AND p.post_mime_type LIKE 'image/%'
	    AND (m.meta_value IS NULL OR TRIM(m.meta_value) = '')
	  ORDER BY p.ID"
);

$need_context = array();
foreach ($rows as $row) {
	if (! $row->post_parent) {
		$need_context[ (int) $row->ID ] = true;
	}
}

// One pass over published content for the parentless ones.
$content_context = array();
if ($need_context) {
	$posts = $wpdb->get_results(
		"SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts}
		  WHERE post_status = 'publish'
		    AND (post_content LIKE '%wp-image-%' OR post_content LIKE '%mediaId%')"
	);
	foreach ($posts as $post) {
		if (! preg_match_all('/wp-image-(\d+)|"mediaId":(\d+)/', $post->post_content, $mm, PREG_SET_ORDER)) {
			continue;
		}
		foreach ($mm as $m) {
			$id = (int) (! empty($m[2]) ? $m[2] : $m[1]);
			if (isset($need_context[ $id ]) && ! isset($content_context[ $id ])) {
				$content_context[ $id ] = array('type' => $post->post_type, 'title' => $post->post_title);
			}
		}
	}

	// Featured-image use is context too, and cheaper to look up.
	$feat = $wpdb->get_results(
		"SELECT pm.meta_value AS att, p.post_type, p.post_title
		   FROM {$wpdb->postmeta} pm
		   JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish'
		  WHERE pm.meta_key = '_thumbnail_id'"
	);
	foreach ($feat as $f) {
		$id = (int) $f->att;
		if (isset($need_context[ $id ]) && ! isset($content_context[ $id ])) {
			$content_context[ $id ] = array('type' => $f->post_type, 'title' => $f->post_title);
		}
	}
}

$upload  = wp_upload_dir();
$basedir = $upload['basedir'];

$manifest = array();
$counts   = array('photo' => 0, 'person' => 0, 'logo' => 0, 'infographic' => 0, 'no_context' => 0, 'skip_svg' => 0, 'no_file' => 0);

foreach ($rows as $row) {
	$id   = (int) $row->ID;
	$meta = wp_get_attachment_metadata($id);

	// --- pick the rendition to send: medium, then medium_large, then original
	//
	// Store the path RELATIVE to the uploads basedir. This script runs inside
	// the DDEV container (/var/www/html/...) but alt-generate.py runs on
	// the host, where the same file is under wp-content/uploads/. An absolute
	// path recorded here is meaningless there.
	$image_rel = '';
	$dims      = array(0, 0);
	if (! empty($meta['file'])) {
		$subdir = trailingslashit(dirname($meta['file']));
		foreach (array('medium', 'medium_large', 'large') as $size) {
			if (! empty($meta['sizes'][ $size ]['file']) && file_exists($basedir . '/' . $subdir . $meta['sizes'][ $size ]['file'])) {
				$image_rel = $subdir . $meta['sizes'][ $size ]['file'];
				$dims      = array((int) $meta['sizes'][ $size ]['width'], (int) $meta['sizes'][ $size ]['height']);
				break;
			}
		}
		if ('' === $image_rel && file_exists($basedir . '/' . $meta['file'])) {
			$image_rel = $meta['file'];
			$dims      = array((int) ($meta['width'] ?? 0), (int) ($meta['height'] ?? 0));
		}
	}

	// --- context
	$context = null;
	if ($row->post_parent) {
		$parent = get_post($row->post_parent);
		// An attachment parented to another attachment is a broken pointer, not
		// context -- its "title" is just another filename.
		if ($parent && 'attachment' !== $parent->post_type) {
			$context = array('type' => $parent->post_type, 'title' => $parent->post_title);
		}
	}
	if (null === $context && isset($content_context[ $id ])) {
		$context = $content_context[ $id ];
	}

	// --- route
	$haystack = strtolower($row->post_title . ' ' . basename($meta['file'] ?? ''));
	$tall     = ($dims[0] > 0 && $dims[1] > 0 && ($dims[1] / $dims[0]) >= 2.0);

	if ('image/svg+xml' === $row->post_mime_type) {
		$route = 'skip_svg';
	} elseif ('' === $image_rel) {
		$route = 'no_file';
	} elseif (null === $context) {
		$route = 'no_context';
	} elseif (preg_match('/\b(logo|logomark|wordmark|avatar|emblem|seal|icon)\b/', $haystack)) {
		$route = 'logo';
	} elseif (preg_match('/\b(infographic|chart|diagram|graph|timeline)\b/', $haystack) || $tall) {
		$route = 'infographic';
	} elseif ('person' === $context['type']) {
		$route = 'person';
	} else {
		$route = 'photo';
	}
	$counts[ $route ]++;

	$manifest[] = array(
		'id'          => $id,
		'route'       => $route,
		'mime'        => $row->post_mime_type,
		'image_rel'   => $image_rel,
		'width'       => $dims[0],
		'height'      => $dims[1],
		'title'       => $row->post_title,
		'filename'    => basename($meta['file'] ?? ''),
		'caption'     => trim(wp_strip_all_tags((string) $row->post_excerpt)),
		'description' => mb_substr(trim(wp_strip_all_tags((string) $row->post_content)), 0, 400),
		'context'     => $context,
	);
}

file_put_contents($out_path, wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

echo "\n";
echo "Images with no alt text : " . count($manifest) . "\n";
echo str_repeat('-', 52) . "\n";
foreach ($counts as $route => $n) {
	printf("  %-14s %5d%s\n", $route, $n, in_array($route, array('skip_svg', 'no_context', 'no_file'), true) ? '   (never sent to the API)' : '');
}
echo str_repeat('-', 52) . "\n";
printf("Wrote %s (%s)\n\n", $out_path, size_format(filesize($out_path)));
