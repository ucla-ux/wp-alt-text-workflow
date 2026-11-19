<?php
/**
 * Seed a THROWAWAY WordPress site with images that exercise every route in
 * the alt-text workflow. Fake content only; safe to share.
 *
 *   ddev wp eval-file rehearsal/seed-test-site.php
 *
 * Creates (all with empty alt unless noted):
 *   - 2 photos attached to posts            -> route "photo"
 *   - 1 headshot attached to a "person"     -> route "person"
 *   - 1 logo                                -> route "logo"
 *   - 1 chart                               -> route "infographic"
 *   - 1 photo with alt an editor wrote      -> must never be overwritten
 *   - 1 linked image in a published post whose block markup has alt=""
 *     (the case alt-to-blocks.php exists for)
 *
 * Never run this on a real site. It refuses unless the site URL ends .ddev.site.
 */
if ( ! preg_match( '#\.ddev\.site#', get_option( 'siteurl' ) ) ) {
	WP_CLI::error( 'Refusing: this does not look like a DDEV test site.' );
}
require_once ABSPATH . 'wp-admin/includes/image.php';

function rehearsal_image( $slug, $draw ) {
	$im = imagecreatetruecolor( 1200, 800 );
	$draw( $im );
	$upload = wp_upload_dir();
	$file   = $upload['path'] . '/' . $slug . '.png';
	imagepng( $im, $file );
	return $file;
}
function rehearsal_attach( $file, $title, $parent = 0, $alt = '' ) {
	$id = wp_insert_attachment( array(
		'post_title'     => $title,
		'post_mime_type' => 'image/png',
		'post_status'    => 'inherit',
	), $file, $parent );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
	if ( $alt ) {
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	}
	return $id;
}
function c( $im, $r, $g, $b ) { return imagecolorallocate( $im, $r, $g, $b ); }

$study = wp_insert_post( array( 'post_title' => 'Soil carbon study', 'post_status' => 'publish', 'post_type' => 'post' ) );
$field = wp_insert_post( array( 'post_title' => 'Fieldwork in the river valley', 'post_status' => 'publish', 'post_type' => 'post' ) );
$ada   = wp_insert_post( array( 'post_title' => 'Ada Lovelace', 'post_status' => 'publish', 'post_type' => 'person' ) );
$part  = wp_insert_post( array( 'post_title' => 'Partners', 'post_status' => 'publish', 'post_type' => 'page' ) );

$soil = rehearsal_image( 'soil-sample', function ( $im ) {
	imagefill( $im, 0, 0, c( $im, 120, 85, 55 ) );
	imagefilledrectangle( $im, 0, 0, 1200, 260, c( $im, 150, 190, 230 ) );   // sky
	imagefilledellipse( $im, 600, 560, 520, 300, c( $im, 70, 45, 25 ) );     // dark soil patch
	imagefilledrectangle( $im, 560, 330, 640, 520, c( $im, 200, 200, 205 ) ); // sampling tube
} );
$valley = rehearsal_image( 'river-valley', function ( $im ) {
	imagefill( $im, 0, 0, c( $im, 120, 170, 90 ) );
	imagefilledrectangle( $im, 0, 0, 1200, 300, c( $im, 160, 200, 240 ) );
	imagefilledpolygon( $im, array( 0, 400, 300, 220, 600, 400 ), c( $im, 110, 120, 110 ) );
	imagefilledpolygon( $im, array( 500, 800, 640, 300, 760, 800 ), c( $im, 60, 130, 200 ) ); // river
} );
$head = rehearsal_image( 'ada-lovelace-portrait', function ( $im ) {
	imagefill( $im, 0, 0, c( $im, 215, 215, 225 ) );
	imagefilledellipse( $im, 600, 330, 300, 360, c( $im, 225, 190, 160 ) );
	imagefilledellipse( $im, 600, 760, 700, 600, c( $im, 50, 60, 110 ) );
} );
$logo = rehearsal_image( 'acme-foundation-logo', function ( $im ) {
	imagefill( $im, 0, 0, c( $im, 255, 255, 255 ) );
	imagefilledellipse( $im, 600, 400, 500, 500, c( $im, 200, 40, 40 ) );
	imagestring( $im, 5, 560, 392, 'ACME', c( $im, 255, 255, 255 ) );
} );
$chart = rehearsal_image( 'rainfall-chart', function ( $im ) {
	imagefill( $im, 0, 0, c( $im, 255, 255, 255 ) );
	foreach ( array( 300, 480, 200, 560 ) as $i => $h ) {
		imagefilledrectangle( $im, 150 + $i * 250, 750 - $h, 300 + $i * 250, 750, c( $im, 40, 100, 180 ) );
	}
} );
$edited = rehearsal_image( 'campus-walk', function ( $im ) {
	imagefill( $im, 0, 0, c( $im, 180, 210, 170 ) );
	imagefilledrectangle( $im, 0, 500, 1200, 800, c( $im, 170, 170, 170 ) );
} );

rehearsal_attach( $soil, 'Soil sample', $study );
rehearsal_attach( $valley, 'River valley', $field );
rehearsal_attach( $head, 'Ada Lovelace portrait', $ada );
rehearsal_attach( $logo, 'Acme Foundation logo', $part );
rehearsal_attach( $chart, 'Rainfall chart', $study );
rehearsal_attach( $edited, 'Campus walk', $field, 'Students walk across the quad (written by an editor)' );

// A linked image saved with alt="" in block markup, before the library had alt.
$linked_id = rehearsal_attach( rehearsal_image( 'linked-event', function ( $im ) {
	imagefill( $im, 0, 0, c( $im, 240, 220, 150 ) );
	imagefilledrectangle( $im, 300, 200, 900, 600, c( $im, 120, 60, 150 ) );
} ), 'Event poster', 0 );
$src = wp_get_attachment_image_url( $linked_id, 'medium' );
wp_insert_post( array(
	'post_title'   => 'Spring event',
	'post_status'  => 'publish',
	'post_type'    => 'post',
	'post_content' => "<!-- wp:image {\"id\":{$linked_id},\"sizeSlug\":\"medium\",\"linkDestination\":\"custom\"} -->\n"
		. "<figure class=\"wp-block-image size-medium\"><a href=\"https://example.test/event\"><img src=\"{$src}\" alt=\"\" class=\"wp-image-{$linked_id}\"/></a></figure>\n<!-- /wp:image -->",
) );

WP_CLI::success( 'Seeded. Run alt-audit.php next.' );
