<?php
/**
 * Worklist of every Media Library image with no alt text, with links to the
 * posts that use it, so each one can be fixed without hunting.
 *
 * WHY THIS EXISTS
 * ---------------
 * A bare list of attachment IDs is not enough to fix alt text. This lists every
 * image with no alt text plus the posts that use it, so each can be fixed in
 * place: open `edit_url` to type the alt, `used_on_url` to see the image in
 * context. Read-only: it never writes to the database.
 *
 * WHAT IT WRITES (default base: reports/missing-alt, in this script's own folder)
 *   step-2-alt-find/reports/missing-alt.csv          images used on at least one post
 *   step-2-alt-find/reports/missing-alt.json         the same images PLUS orphans, machine readable
 *   step-2-alt-find/reports/missing-alt-orphans.csv  images no post uses (route = no_context)
 *
 * "Used on" means a published or private post that:
 *   parent   is the attachment's post_parent (not another attachment)
 *   content  references it in post_content (wp-image-N class or "mediaId":N)
 *   featured has it as _thumbnail_id
 *   acf      holds its ID in an ACF image field (a meta row with a field_ key
 *            companion), including repeater and flexible-content sub-fields
 * Not covered, so an image used ONLY this way lands in orphans: term or user
 * meta, widgets, options, theme-hardcoded files, and plain <img> tags with no
 * wp-image class. Check an orphan by hand before deleting anything.
 *
 * CSV: multi-value columns (used_on_*) are joined with "; " and line up
 * positionally, so the Nth URL goes with the Nth title. A title containing a
 * semicolon can make that ambiguous by eye; the JSON never has the problem.
 *
 * JSON also carries `image_rel` (file to send), `description` and `context` (first
 * use: type and title) for alt-generate.py. `used_on_url` is an ARRAY of URL strings, and `used_on` is an array of
 * objects (id, type, title, status, url, edit_url, linked, via[]) in the same
 * order. Orphans have both as empty arrays.
 *
 * Columns, CSV and JSON alike:
 *   id, route, edit_url, file_url, filename, title, mime, width, height,
 *   caption, parent_id, parent_status, used_on_count, linked (yes if any use
 *   wraps the image in a link, which is the Siteimprove failure), used_on_type,
 *   used_on_title, used_on_url, used_on_edit_url, alt (blank, to fill in)
 *
 * route (same names alt-generate.py uses): photo, person, logo, infographic,
 * skip_svg, no_file, no_context. SVGs are included. `no_context` here means
 * nothing found that uses the image; `skip_svg` and `no_file` win over it only
 * when the image is used somewhere.
 *
 * Sorted most-used first, so the fixes that reach the most pages come first.
 *
 * USAGE (run from the site's folder; see README.md)
 *   ddev wp eval-file bin/wp-alt-text-workflow/step-2-alt-find/alt-find.php    # DDEV site
 *   wp eval-file alt-find.php                   # plain WP-CLI
 *   ... alt-find.php some/dir/name                # other output base name
 *
 * Next step: alt-generate.py reads missing-alt.json, and also missing-alt.csv so a
 * person can delete rows or fix a `route` first. See ../step-3-alt-generate/README.md.
 */

if (! defined('WP_CLI') || ! WP_CLI) {
	exit; // Never run from the web.
}

// Default: reports/ next to this script, so reports stay with the step that made them.
$base = isset($args[0]) ? $args[0] : __DIR__ . '/reports/missing-alt';
$base = preg_replace('#\.(csv|json)$#', '', $base);
$shown = rtrim(str_replace(rtrim(getcwd(), '/') . '/', '', $base), '/'); // path for messages
if (! is_dir(dirname($base)) && ! wp_mkdir_p(dirname($base))) {
	WP_CLI::error('Cannot create output directory: ' . dirname($base));
}

global $wpdb;

// ---------------------------------------------------------------- the images
$rows = $wpdb->get_results(
	"SELECT p.ID, p.post_title, p.post_excerpt, p.post_mime_type, p.post_parent
	   FROM {$wpdb->posts} p
	   LEFT JOIN {$wpdb->postmeta} m
	     ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
	  WHERE p.post_type = 'attachment'
	    AND p.post_mime_type LIKE 'image/%'
	    AND (m.meta_value IS NULL OR TRIM(m.meta_value) = '')
	  ORDER BY p.ID"
);

$want = array(); // attachment id => true
foreach ($rows as $row) {
	$want[ (int) $row->ID ] = true;
}
if (! $want) {
	WP_CLI::success('No images are missing alt text.');
	return;
}
$id_list = implode(',', array_keys($want)); // integers only

$skip_types = "'attachment','revision','nav_menu_item','customize_changeset','oembed_cache','acf-field','acf-field-group'";
$live       = "('publish','private')";

// used[attachment_id][post_id] = array('via' => set, 'linked' => bool)
$used = array();
$note = function ($att, $post_id, $via, $linked = false) use (&$used) {
	if (! isset($used[ $att ][ $post_id ])) {
		$used[ $att ][ $post_id ] = array('via' => array(), 'linked' => false);
	}
	$used[ $att ][ $post_id ]['via'][ $via ] = true;
	if ($linked) {
		$used[ $att ][ $post_id ]['linked'] = true;
	}
};

// parent -- resolved below with the other post lookups
$parent_of = array();
foreach ($rows as $row) {
	if ($row->post_parent) {
		$parent_of[ (int) $row->ID ] = (int) $row->post_parent;
	}
}

// featured image
$feat = $wpdb->get_results(
	"SELECT pm.post_id, pm.meta_value AS att
	   FROM {$wpdb->postmeta} pm
	   JOIN {$wpdb->posts} p ON p.ID = pm.post_id
	    AND p.post_status IN {$live} AND p.post_type NOT IN ({$skip_types})
	  WHERE pm.meta_key = '_thumbnail_id' AND pm.meta_value IN ({$id_list})"
);
foreach ($feat as $f) {
	$note((int) $f->att, (int) $f->post_id, 'featured');
}

// ACF image fields: a non-underscore meta key whose value is the ID and whose
// "_key" companion holds a field_ key. The companion check keeps a plain
// number field (a year, a count) from matching an attachment that shares it.
$acf = $wpdb->get_results(
	"SELECT m.post_id, m.meta_value AS att
	   FROM {$wpdb->postmeta} m
	   JOIN {$wpdb->postmeta} c
	     ON c.post_id = m.post_id
	    AND c.meta_key = CONCAT('_', m.meta_key)
	    AND LEFT(c.meta_value, 6) = 'field_'
	   JOIN {$wpdb->posts} p ON p.ID = m.post_id
	    AND p.post_status IN {$live} AND p.post_type NOT IN ({$skip_types})
	  WHERE LEFT(m.meta_key, 1) <> '_' AND m.meta_value IN ({$id_list})"
);
foreach ($acf as $a) {
	$note((int) $a->att, (int) $a->post_id, 'acf');
}

// post_content: scan once, pull every reference out, note which sit in a link.
$posts = $wpdb->get_results(
	"SELECT ID, post_content FROM {$wpdb->posts}
	  WHERE post_status IN {$live} AND post_type NOT IN ({$skip_types})
	    AND (post_content LIKE '%wp-image-%' OR post_content LIKE '%mediaId%')"
);
foreach ($posts as $post) {
	$content = $post->post_content;
	$linked  = array();
	if (false !== stripos($content, '<a') && preg_match_all('#<a\b[^>]*>.*?</a>#is', $content, $anchors)) {
		foreach ($anchors[0] as $anchor) {
			if (preg_match_all('/wp-image-(\d+)/', $anchor, $am)) {
				foreach ($am[1] as $aid) {
					$linked[ (int) $aid ] = true;
				}
			}
		}
	}
	if (preg_match_all('/wp-image-(\d+)|"mediaId":(\d+)/', $content, $mm, PREG_SET_ORDER)) {
		foreach ($mm as $m) {
			$id = (int) (! empty($m[2]) ? $m[2] : $m[1]);
			if (isset($want[ $id ])) {
				$note($id, (int) $post->ID, 'content', isset($linked[ $id ]));
			}
		}
	}
}
unset($posts);

// ------------------------------------------------- post details, one lookup
$post_ids = array_values(array_unique(array_merge(
	array_values($parent_of),
	array_reduce(array_map('array_keys', $used), 'array_merge', array())
)));
$info = array();
if ($post_ids) {
	foreach (array_chunk($post_ids, 1000) as $chunk) {
		$got = $wpdb->get_results(
			"SELECT ID, post_title, post_type, post_status FROM {$wpdb->posts} WHERE ID IN (" . implode(',', array_map('intval', $chunk)) . ')'
		);
		foreach ($got as $g) {
			$info[ (int) $g->ID ] = $g;
		}
	}
}

// A parent counts as a use only when it is a live, non-attachment post.
foreach ($parent_of as $att => $pid) {
	$p = $info[ $pid ] ?? null;
	if ($p && 'attachment' !== $p->post_type && in_array($p->post_status, array('publish', 'private'), true)) {
		$note($att, $pid, 'parent');
	}
}

// ------------------------------------------------------------- build records
$basedir = wp_upload_dir()['basedir'];
$edit    = function ($id) {
	return admin_url('post.php?post=' . (int) $id . '&action=edit');
};
$via_order = array('parent', 'featured', 'content', 'acf');

$records = array();
$counts  = array('photo' => 0, 'person' => 0, 'logo' => 0, 'infographic' => 0, 'skip_svg' => 0, 'no_file' => 0, 'no_context' => 0);

foreach ($rows as $row) {
	$id   = (int) $row->ID;
	$meta = wp_get_attachment_metadata($id);
	$file = (string) get_post_meta($id, '_wp_attached_file', true);
	$w    = (int) ($meta['width'] ?? 0);
	$h    = (int) ($meta['height'] ?? 0);

	$used_on = array();
	$any_link = false;
	foreach (($used[ $id ] ?? array()) as $pid => $u) {
		$p = $info[ $pid ] ?? null;
		if (! $p) {
			continue;
		}
		$via = array_values(array_filter($via_order, function ($v) use ($u) {
			return isset($u['via'][ $v ]);
		}));
		$any_link  = $any_link || $u['linked'];
		$used_on[] = array(
			'id'       => (int) $pid,
			'type'     => $p->post_type,
			'title'    => $p->post_title,
			'status'   => $p->post_status,
			'url'      => get_permalink((int) $pid),
			'edit_url' => $edit($pid),
			'linked'   => (bool) $u['linked'],
			'via'      => $via,
		);
	}
	// Parent first (the editor's own placement), then by post ID: stable output.
	$parent_id = (int) $row->post_parent;
	usort($used_on, function ($a, $b) use ($parent_id) {
		$pa = ($a['id'] === $parent_id) ? 0 : 1;
		$pb = ($b['id'] === $parent_id) ? 0 : 1;
		return $pa <=> $pb ?: $a['id'] <=> $b['id'];
	});

	$haystack = strtolower($row->post_title . ' ' . basename($file));
	$tall     = ($w > 0 && $h > 0 && ($h / $w) >= 2.0);
	$first    = $used_on[0]['type'] ?? '';

	if (! $used_on) {
		$route = 'no_context';
	} elseif ('image/svg+xml' === $row->post_mime_type) {
		$route = 'skip_svg';
	} elseif ('' === $file || ! file_exists($basedir . '/' . $file)) {
		$route = 'no_file';
	} elseif (preg_match('/\b(logo|logomark|wordmark|avatar|emblem|seal|icon)\b/', $haystack)) {
		$route = 'logo';
	} elseif (preg_match('/\b(infographic|chart|diagram|graph|timeline)\b/', $haystack) || $tall) {
		$route = 'infographic';
	} elseif ('person' === $first) {
		$route = 'person';
	} else {
		$route = 'photo';
	}
	$counts[ $route ]++;

	$parent_post = $info[ $parent_id ] ?? null;

	// The file alt-generate.py sends: the medium rendition if it exists, else
	// medium_large, large, then the original. Relative to the uploads folder, so
	// it also resolves on the host when this script runs inside a container.
	$image_rel = '';
	if (! empty($meta['file'])) {
		$subdir = trailingslashit(dirname($meta['file']));
		foreach (array('medium', 'medium_large', 'large') as $size) {
			if (! empty($meta['sizes'][ $size ]['file']) && file_exists($basedir . '/' . $subdir . $meta['sizes'][ $size ]['file'])) {
				$image_rel = $subdir . $meta['sizes'][ $size ]['file'];
				break;
			}
		}
		if ('' === $image_rel && file_exists($basedir . '/' . $meta['file'])) {
			$image_rel = $meta['file'];
		}
	}

	$records[] = array(
		'id'               => $id,
		'route'            => $route,
		'edit_url'         => $edit($id),
		'file_url'         => (string) wp_get_attachment_url($id),
		'filename'         => basename($file),
		'title'            => $row->post_title,
		'mime'             => $row->post_mime_type,
		'width'            => $w,
		'height'           => $h,
		'caption'          => trim(wp_strip_all_tags((string) $row->post_excerpt)),
		'parent_id'        => $parent_id,
		'parent_status'    => $parent_post ? $parent_post->post_status : '',
		'used_on_count'    => count($used_on),
		'linked'           => $any_link,
		'used_on_url'      => array_column($used_on, 'url'),
		'used_on'          => $used_on,
		'alt'              => '',
		// JSON only (not CSV columns): what alt-generate.py needs.
		'image_rel'        => $image_rel,
		'description'      => trim(wp_strip_all_tags((string) get_post_field('post_content', $id))),
		'context'          => $used_on ? array('type' => $used_on[0]['type'], 'title' => $used_on[0]['title']) : array(),
	);
}

usort($records, function ($a, $b) {
	return $b['used_on_count'] <=> $a['used_on_count'] ?: $a['id'] <=> $b['id'];
});

// -------------------------------------------------------------------- output
$columns = array(
	'id', 'route', 'edit_url', 'file_url', 'filename', 'title', 'mime', 'width', 'height',
	'caption', 'parent_id', 'parent_status', 'used_on_count', 'linked',
	'used_on_type', 'used_on_title', 'used_on_url', 'used_on_edit_url', 'alt',
);

$to_row = function ($r) {
	$join = function ($key) use ($r) {
		return implode('; ', array_column($r['used_on'], $key));
	};
	return array(
		$r['id'], $r['route'], $r['edit_url'], $r['file_url'], $r['filename'], $r['title'], $r['mime'],
		$r['width'] ?: '', $r['height'] ?: '', $r['caption'], $r['parent_id'] ?: '', $r['parent_status'],
		$r['used_on_count'], $r['linked'] ? 'yes' : 'no',
		$join('type'), $join('title'), $join('url'), $join('edit_url'), '',
	);
};

$write_csv = function ($path, $list) use ($columns, $to_row) {
	$fh = fopen($path, 'w');
	if (! $fh) {
		WP_CLI::error("Cannot write {$path}");
	}
	fputcsv($fh, $columns, ',', '"', '');
	foreach ($list as $r) {
		fputcsv($fh, $to_row($r), ',', '"', '');
	}
	fclose($fh);
};

$used_list   = array_values(array_filter($records, function ($r) { return $r['used_on_count'] > 0; }));
$orphan_list = array_values(array_filter($records, function ($r) { return 0 === $r['used_on_count']; }));

$write_csv($base . '.csv', $used_list);
$write_csv($base . '-orphans.csv', $orphan_list);
file_put_contents(
	$base . '.json',
	wp_json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);

echo "\n";
echo "Images with no alt text : " . count($records) . "\n";
echo str_repeat('-', 52) . "\n";
foreach ($counts as $route => $n) {
	printf("  %-14s %5d\n", $route, $n);
}
echo str_repeat('-', 52) . "\n";
printf("  used on a post   %5d   -> %s.csv\n", count($used_list), $shown);
printf("  orphans          %5d   -> %s-orphans.csv\n", count($orphan_list), $shown);
printf("  all, as JSON     %5d   -> %s.json\n\n", count($records), $shown);
