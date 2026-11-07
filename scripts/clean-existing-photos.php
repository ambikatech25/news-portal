<?php
/**
 * Task 15: remove hidden data (GPS, camera, dates) from images that were uploaded before the
 * image-safety rule existed. New uploads are cleaned automatically; run this once (and again
 * after importing old content). Safe to run again.
 *
 *   docker compose run --rm -T wpcli eval-file /scripts/clean-existing-photos.php
 *
 * @package Portal
 */

if ( ! function_exists( 'portal_strip_image_metadata' ) ) {
	WP_CLI::error( 'portal-core must be active.' );
}

$portal_ids     = get_posts(
	array(
		'post_type'      => 'attachment',
		'post_mime_type' => 'image',
		'post_status'    => 'any',
		'numberposts'    => -1,
		'fields'         => 'ids',
	)
);
$portal_files   = 0;
$portal_cleaned = 0;
$portal_failed  = array();

foreach ( $portal_ids as $portal_id ) {
	$portal_main = get_attached_file( $portal_id );
	if ( ! $portal_main || ! file_exists( $portal_main ) ) {
		continue;
	}
	$portal_mime  = get_post_mime_type( $portal_id );
	$portal_paths = array( $portal_main );
	$portal_meta  = wp_get_attachment_metadata( $portal_id );
	$portal_dir   = dirname( $portal_main );
	foreach ( (array) ( $portal_meta['sizes'] ?? array() ) as $portal_size ) {
		$portal_paths[] = $portal_dir . '/' . $portal_size['file'];
	}
	if ( ! empty( $portal_meta['original_image'] ) ) {
		$portal_paths[] = $portal_dir . '/' . $portal_meta['original_image'];
	}

	foreach ( array_unique( $portal_paths ) as $portal_path ) {
		if ( ! file_exists( $portal_path ) ) {
			continue;
		}
		++$portal_files;
		$portal_before = md5_file( $portal_path );
		$portal_result = portal_strip_image_metadata( $portal_path, $portal_mime );
		if ( is_wp_error( $portal_result ) ) {
			$portal_failed[] = basename( $portal_path );
		} elseif ( md5_file( $portal_path ) !== $portal_before ) {
			++$portal_cleaned;
		}
	}
}

foreach ( $portal_failed as $portal_name ) {
	WP_CLI::warning( "Could not clean: $portal_name (re-upload it as a JPG or screenshot)" );
}
WP_CLI::success( sprintf( 'Checked %d image files in %d images; cleaned %d.', $portal_files, count( $portal_ids ), $portal_cleaned ) );
