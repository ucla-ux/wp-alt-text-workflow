<?php
/**
 * Plugin Name: Alt Text Column (rehearsal)
 * Description: Adds an "Alt text" column to Media > Library (list view). Shows "missing" in red when empty. For test sites.
 *
 * Install as a must-use plugin (no activation needed):
 *   mkdir -p wp-content/mu-plugins
 *   cp bin/rehearsal/alt-column.php wp-content/mu-plugins/
 */

add_filter( 'manage_media_columns', function ( $columns ) {
	$out = array();
	foreach ( $columns as $key => $label ) {
		$out[ $key ] = $label;
		if ( 'title' === $key ) {
			$out['alt_text'] = 'Alt text';
		}
	}
	if ( ! isset( $out['alt_text'] ) ) {
		$out['alt_text'] = 'Alt text';
	}
	return $out;
} );

add_action( 'manage_media_custom_column', function ( $column, $post_id ) {
	if ( 'alt_text' !== $column ) {
		return;
	}
	if ( ! wp_attachment_is_image( $post_id ) ) {
		echo '<span style="color:#646970">n/a</span>';
		return;
	}
	$alt = trim( (string) get_post_meta( $post_id, '_wp_attachment_image_alt', true ) );
	if ( '' === $alt ) {
		echo '<strong style="color:#b32d2e">missing</strong>';
	} else {
		echo esc_html( $alt );
	}
}, 10, 2 );

add_action( 'admin_head-upload.php', function () {
	echo '<style>.column-alt_text{width:22%}</style>';
} );
