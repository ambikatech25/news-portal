<?php
/**
 * Test helper (used by tests/test-image-safety.ps1): what is left in a stored photo?
 *
 *   wp eval-file /scripts/test-photo-info.php <attachment-id>
 *
 * Prints JSON: size, GPS / camera data present, private JPEG blocks present (main file and every
 * resized copy), the colour of the top-left and top-right corners (to check the picture was
 * turned upright, not left sideways), and a fingerprint of the picture data itself.
 *
 * @package Portal
 */

$portal_id   = (int) ( $args[0] ?? 0 );
$portal_file = get_attached_file( $portal_id );
if ( ! $portal_file || ! file_exists( $portal_file ) ) {
	echo wp_json_encode( array( 'error' => 'not found' ) );
	return;
}

$portal_exif = @exif_read_data( $portal_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
$portal_size = getimagesize( $portal_file );
$portal_meta = wp_get_attachment_metadata( $portal_id );

$portal_sizes_clean = true;
foreach ( (array) ( $portal_meta['sizes'] ?? array() ) as $portal_s ) {
	$portal_p = dirname( $portal_file ) . '/' . $portal_s['file'];
	if ( file_exists( $portal_p ) && portal_jpeg_has_private_data( $portal_p ) ) {
		$portal_sizes_clean = false;
	}
}

// GD, not Imagick: the WP-CLI container's Imagick cannot read JPEG.
$portal_gd     = imagecreatefromjpeg( $portal_file );
$portal_corner = static function ( $x, $y ) use ( $portal_gd ) {
	$rgb = imagecolorat( $portal_gd, $x, $y );
	return sprintf( '#%02x%02x%02x', ( $rgb >> 16 ) & 0xFF, ( $rgb >> 8 ) & 0xFF, $rgb & 0xFF );
};

// Fingerprint of the compressed picture data (from the start-of-scan marker to the end).
$portal_data = (string) file_get_contents( $portal_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
$portal_sos  = strpos( $portal_data, "\xFF\xDA" );

echo wp_json_encode(
	array(
		'width'          => $portal_size[0],
		'height'         => $portal_size[1],
		'gps'            => isset( $portal_exif['GPSLatitude'] ) || isset( $portal_exif['GPSLongitude'] ),
		'make'           => $portal_exif['Make'] ?? '',
		'model'          => $portal_exif['Model'] ?? '',
		'orientation'    => (int) ( $portal_exif['Orientation'] ?? 1 ),
		'private_blocks' => portal_jpeg_has_private_data( $portal_file ),
		'sizes_clean'    => $portal_sizes_clean,
		'sizes'          => count( (array) ( $portal_meta['sizes'] ?? array() ) ),
		'top_left'       => $portal_corner( 5, 5 ),
		'top_right'      => $portal_corner( $portal_size[0] - 6, 5 ),
		'picture_md5'    => false === $portal_sos ? '' : md5( substr( $portal_data, $portal_sos ) ),
	)
);
