<?php
/**
 * Write generated alt text from a CSV into the Media Library.
 *
 * WHY THIS EXISTS
 * ---------------
 * alt-generate.py produces tmp/alt-results.csv. This puts it in the database --
 * on a local test copy first, and, once reviewed, on staging and production,
 * where it is run by hand against the same CSV.
 *
 * SAFETY
 * ------
 * - Dry run is the default. Writing requires the literal argument `execute`.
 * - Only writes where the library alt is EMPTY. Never overwrites alt an editor
 *   has already set -- that is the canonical copy, and the CSV may be stale by
 *   the time it reaches production.
 * - Skips any row flagged needs_human=1.
 * - Rejects rows over 150 characters rather than truncating them.
 * - Idempotent: a second run finds nothing to do.
 * - Records every ID it touched in tmp/alt-rollback-<timestamp>.json.
 *
 * USAGE
 *   wp eval-file bin/alt-text/alt-apply.php tmp/alt-results.csv            # dry run
 *   wp eval-file bin/alt-text/alt-apply.php tmp/alt-results.csv execute    # apply
 *   (prefix with `ddev` locally)
 */

$csv_path = isset($args[0]) ? $args[0] : '';
$mode     = isset($args[1]) ? strtolower(trim($args[1])) : 'dry';

if ('' === $csv_path) {
	WP_CLI::error('Usage: wp eval-file bin/alt-text/alt-apply.php <csv-path> [execute]');
}
if (! file_exists($csv_path)) {
	WP_CLI::error("CSV not found: {$csv_path}");
}
if (! in_array($mode, array('dry', 'execute'), true)) {
	WP_CLI::error("Unknown mode '{$mode}'. Use: dry (default) or execute.");
}

$MAX_LEN = 150;

$fh = fopen($csv_path, 'r');
$header = fgetcsv($fh, 0, ',', '"', '');
if (! $header) {
	WP_CLI::error('CSV is empty.');
}
$cols = array_flip($header);
foreach (array('id', 'alt', 'needs_human') as $required) {
	if (! isset($cols[ $required ])) {
		WP_CLI::error("CSV is missing a '{$required}' column.");
	}
}

$plan = array();
$skipped = array('has_alt' => 0, 'needs_human' => 0, 'too_long' => 0, 'empty' => 0, 'not_attachment' => 0, 'bad_lead' => 0);
$rows = 0;

while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
	if (count($row) < count($header)) {
		continue;
	}
	$rows++;
	$id  = (int) $row[ $cols['id'] ];
	$alt = trim($row[ $cols['alt'] ]);

	if (! empty($row[ $cols['needs_human'] ]) && '0' !== $row[ $cols['needs_human'] ]) {
		$skipped['needs_human']++;
		continue;
	}
	if ('' === $alt) {
		$skipped['empty']++;
		continue;
	}
	if (mb_strlen($alt) > $MAX_LEN) {
		$skipped['too_long']++;
		WP_CLI::warning(sprintf('Attachment %d: alt is %d characters, over the %d limit. Skipped.', $id, mb_strlen($alt), $MAX_LEN));
		continue;
	}
	if (preg_match('/^\s*(an?\s+)?(image|photo|photograph|picture|graphic)\s+(of|showing)\b/i', $alt)) {
		$skipped['bad_lead']++;
		WP_CLI::warning(sprintf('Attachment %d: alt starts with a forbidden lead-in. Skipped. ("%s")', $id, mb_substr($alt, 0, 40)));
		continue;
	}
	if (! $id || 'attachment' !== get_post_type($id)) {
		$skipped['not_attachment']++;
		continue;
	}

	$existing = trim((string) get_post_meta($id, '_wp_attachment_image_alt', true));
	if ('' !== $existing) {
		$skipped['has_alt']++;
		continue;
	}

	$plan[ $id ] = $alt;
}
fclose($fh);

$written = 0;
if ('execute' === $mode && $plan) {
	$stamp    = gmdate('Ymd-His');
	$rollback = dirname($csv_path) . "/alt-rollback-{$stamp}.json";
	file_put_contents($rollback, wp_json_encode(array(
		'written_at'  => gmdate('c'),
		'source_csv'  => $csv_path,
		'attachments' => array_keys($plan),
	), JSON_PRETTY_PRINT));

	foreach ($plan as $id => $alt) {
		update_post_meta($id, '_wp_attachment_image_alt', $alt);
		$written++;
	}
	WP_CLI::log("Rollback list: {$rollback}");
}

echo "\n";
echo ('execute' === $mode) ? "MODE: execute (alt text written)\n" : "MODE: dry run (no changes written)\n";
echo str_repeat('-', 68) . "\n";
printf("rows in CSV                            : %d\n", $rows);
printf("  flagged needs_human (left for a human): %d\n", $skipped['needs_human']);
printf("  library alt already set (left alone)  : %d\n", $skipped['has_alt']);
printf("  over %d characters (rejected)        : %d\n", $MAX_LEN, $skipped['too_long']);
printf("  forbidden lead-in (rejected)          : %d\n", $skipped['bad_lead']);
printf("  empty alt                             : %d\n", $skipped['empty']);
printf("  not an attachment on this site        : %d\n", $skipped['not_attachment']);
printf("  ready to write                        : %d\n", count($plan));
printf("  written                               : %d\n", $written);
echo str_repeat('-', 68) . "\n";

if ('execute' !== $mode && $plan) {
	$preview = array_slice($plan, 0, 10, true);
	echo "\n";
	foreach ($preview as $id => $alt) {
		printf("  %-7d %s\n", $id, mb_substr($alt, 0, 70));
	}
	if (count($plan) > count($preview)) {
		printf("  ... and %d more\n", count($plan) - count($preview));
	}
	echo "\nRe-run with `execute` to write these into the Media Library.\n";
}
echo "\n";
