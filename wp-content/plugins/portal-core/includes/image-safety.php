<?php
/**
 * Image safety (CLAUDE.md publishing rule 3): uploaded photos never keep hidden data such as the
 * GPS position where they were taken, the phone model, owner names or capture time.
 *
 * EWWW Image Optimizer (approved plugin) already removes metadata when it optimises an upload.
 * This file is the safety net that runs after it on EVERY upload (Media Library, the Write page,
 * profile photos, the app), because a photo with a reporter's or victim's location must never
 * slip through if EWWW is switched off, busy, or cannot run its tools on the server.
 *
 *  - JPEG: hidden-data blocks (EXIF, XMP, IPTC, comments) are cut out of the file without
 *    re-compressing the picture (no quality loss). If the photo carries a "rotate me" flag
 *    (phones do), the picture is turned upright first, then the flag is removed with the rest.
 *  - PNG, WebP, GIF, HEIC: cleaned with Imagick (when the file has any metadata at all).
 *  - Afterwards the file is checked again. If location data is still there, the upload is
 *    refused rather than published.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * JPEG segments that may hold personal data: APP1 (EXIF and XMP: GPS, camera, dates),
 * APP13 (IPTC/Photoshop: names, captions, places), COM (free-text comments).
 * Kept: APP0 (JFIF), APP2 (colour profile), APP14 (Adobe colour info): needed to show colours right.
 */
const PORTAL_JPEG_PRIVATE_MARKERS = array( 0xE1, 0xED, 0xFE );

/**
 * Clean an uploaded file. Hooked late so it runs after EWWW.
 *
 * @param array  $upload  { file, url, type } (or { error }).
 * @param string $context upload | sideload.
 * @return array
 */
function portal_clean_uploaded_image( $upload, $context = 'upload' ) {
	if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) || ! file_exists( $upload['file'] ) ) {
		return $upload;
	}
	$type = (string) ( $upload['type'] ?? '' );
	if ( 0 !== strpos( $type, 'image/' ) ) {
		return $upload;
	}

	$ok = portal_strip_image_metadata( $upload['file'], $type );
	if ( is_wp_error( $ok ) ) {
		wp_delete_file( $upload['file'] );
		return array( 'error' => $ok->get_error_message() );
	}
	return $upload;
}
add_filter( 'wp_handle_upload', 'portal_clean_uploaded_image', 999, 2 );

/**
 * Remove hidden data from an image file, in place.
 *
 * @param string $path File.
 * @param string $mime Mime type.
 * @return true|WP_Error
 */
function portal_strip_image_metadata( $path, $mime ) {
	if ( 'image/jpeg' === $mime ) {
		$orientation = portal_jpeg_orientation( $path );
		if ( $orientation > 1 ) {
			// The picture must be turned upright before the "rotate me" flag disappears.
			// Imagick first (keeps the colour profile); GD if this server's Imagick cannot read JPEG.
			$done = portal_imagick_clean( $path, true ) || portal_gd_jpeg_upright( $path, $orientation );
		} elseif ( portal_jpeg_has_private_data( $path ) ) {
			$done = portal_jpeg_strip_segments( $path );
		} else {
			return true; // nothing to remove (EWWW already did it, or there never was anything)
		}
	} elseif ( in_array( $mime, array( 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif', 'image/avif' ), true ) ) {
		$done = portal_imagick_has_metadata( $path ) ? portal_imagick_clean( $path, false ) : true;
	} else {
		return true;
	}

	if ( true !== $done || portal_image_still_has_location( $path, $mime ) ) {
		return new WP_Error(
			'portal_image_metadata',
			__( 'This photo could not be cleaned of its hidden location data, so it was not uploaded. Please take a screenshot of it, or save it again as JPG, and upload that.', 'portal' )
		);
	}
	return true;
}

/**
 * The JPEG "Orientation" flag (1 = already upright).
 *
 * @param string $path File.
 * @return int
 */
function portal_jpeg_orientation( $path ) {
	if ( ! function_exists( 'exif_read_data' ) ) {
		return 1;
	}
	$exif = @exif_read_data( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- damaged EXIF is expected in the wild.
	return (int) ( $exif['Orientation'] ?? 1 );
}

/**
 * Walk the JPEG header segments (they come before the picture data).
 *
 * @param string $data File bytes.
 * @return array<int,array{marker:int,start:int,length:int}>|null Null if not a JPEG.
 */
function portal_jpeg_segments( $data ) {
	if ( "\xFF\xD8" !== substr( $data, 0, 2 ) ) {
		return null;
	}
	$segments = array();
	$pos      = 2;
	$len      = strlen( $data );
	while ( $pos + 4 <= $len ) {
		if ( "\xFF" !== $data[ $pos ] ) {
			return null; // broken file
		}
		$marker = ord( $data[ $pos + 1 ] );
		if ( 0xFF === $marker ) { // padding
			++$pos;
			continue;
		}
		if ( 0xDA === $marker || 0xD9 === $marker ) { // start of picture data / end of image
			$segments[] = array(
				'marker' => $marker,
				'start'  => $pos,
				'length' => 0,
			);
			break;
		}
		$size       = unpack( 'n', substr( $data, $pos + 2, 2 ) )[1];
		$segments[] = array(
			'marker' => $marker,
			'start'  => $pos,
			'length' => $size + 2,
		);
		$pos       += $size + 2;
	}
	return $segments;
}

/**
 * Whether the JPEG has EXIF / XMP / IPTC / comment blocks.
 *
 * @param string $path File.
 * @return bool
 */
function portal_jpeg_has_private_data( $path ) {
	$segments = portal_jpeg_segments( (string) file_get_contents( $path, false, null, 0, 1048576 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local upload.
	foreach ( (array) $segments as $segment ) {
		if ( in_array( $segment['marker'], PORTAL_JPEG_PRIVATE_MARKERS, true ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Cut the private blocks out of a JPEG without touching the picture data (lossless).
 *
 * @param string $path File.
 * @return bool
 */
function portal_jpeg_strip_segments( $path ) {
	$data     = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local upload.
	$segments = portal_jpeg_segments( $data );
	if ( ! $segments ) {
		return false;
	}
	$out = "\xFF\xD8";
	foreach ( $segments as $segment ) {
		if ( 0xDA === $segment['marker'] || 0xD9 === $segment['marker'] ) {
			$out .= substr( $data, $segment['start'] ); // picture data and everything after, unchanged
			break;
		}
		if ( ! in_array( $segment['marker'], PORTAL_JPEG_PRIVATE_MARKERS, true ) ) {
			$out .= substr( $data, $segment['start'], $segment['length'] );
		}
	}
	return false !== file_put_contents( $path, $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local upload.
}

/**
 * Whether Imagick sees any metadata in the file.
 *
 * @param string $path File.
 * @return bool
 */
function portal_imagick_has_metadata( $path ) {
	if ( ! class_exists( 'Imagick' ) ) {
		return false;
	}
	try {
		$im    = new Imagick( $path );
		$props = array_filter(
			array_keys( $im->getImageProperties( '*' ) ),
			static fn( $k ) => preg_match( '/^(exif|xmp|iptc|tiff|gps|comment)/i', $k )
		);
		$profiles = array_diff( array_keys( $im->getImageProfiles( '*', false ) ), array( 'icc' ) );
		$im->clear();
		return (bool) ( $props || $profiles );
	} catch ( Exception $e ) {
		return false;
	}
}

/**
 * Re-save the image with Imagick without any metadata (the colour profile is kept).
 *
 * @param string $path   File.
 * @param bool   $orient Turn the picture upright using its orientation flag first.
 * @return bool
 */
function portal_imagick_clean( $path, $orient ) {
	if ( ! class_exists( 'Imagick' ) ) {
		return false;
	}
	// Imagick can be installed without support for a format (found in Task 15: the WP-CLI
	// container's Imagick cannot read JPEG). Check before trying.
	$format = strtoupper( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
	$format = 'JPG' === $format ? 'JPEG' : $format;
	if ( $format && ! Imagick::queryFormats( $format ) ) {
		return false;
	}
	try {
		$im      = new Imagick( $path );
		$quality = (int) $im->getImageCompressionQuality();
		if ( $orient ) {
			switch ( $im->getImageOrientation() ) {
				case Imagick::ORIENTATION_TOPRIGHT:
					$im->flopImage();
					break;
				case Imagick::ORIENTATION_BOTTOMRIGHT:
					$im->rotateImage( '#000', 180 );
					break;
				case Imagick::ORIENTATION_BOTTOMLEFT:
					$im->flipImage();
					break;
				case Imagick::ORIENTATION_LEFTTOP:
					$im->transposeImage();
					break;
				case Imagick::ORIENTATION_RIGHTTOP:
					$im->rotateImage( '#000', 90 );
					break;
				case Imagick::ORIENTATION_RIGHTBOTTOM:
					$im->transverseImage();
					break;
				case Imagick::ORIENTATION_LEFTBOTTOM:
					$im->rotateImage( '#000', 270 );
					break;
			}
			$im->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
		}
		$icc = $im->getImageProfiles( 'icc', true );
		$im->stripImage(); // removes EXIF, GPS, XMP, IPTC, comments ... and the colour profile,
		if ( ! empty( $icc['icc'] ) ) {
			$im->profileImage( 'icc', $icc['icc'] ); // ... so put the colour profile back.
		}
		if ( $quality > 0 ) {
			$im->setImageCompressionQuality( max( 82, $quality ) );
		}
		$im->writeImage( $path );
		$im->clear();
		return true;
	} catch ( Exception $e ) {
		return false;
	}
}

/**
 * Fallback without Imagick: turn a JPEG upright with GD and save it. GD never writes any
 * metadata, so the result is clean (the colour profile is lost, rarely visible on photos).
 *
 * @param string $path        File.
 * @param int    $orientation EXIF orientation (2-8).
 * @return bool
 */
function portal_gd_jpeg_upright( $path, $orientation ) {
	if ( ! function_exists( 'imagecreatefromjpeg' ) ) {
		return false;
	}
	$img = @imagecreatefromjpeg( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- broken files are expected in the wild.
	if ( ! $img ) {
		return false;
	}
	// GD's imagerotate() turns ANTI-clockwise, so "90 degrees clockwise" is -90.
	switch ( (int) $orientation ) {
		case 2:
			imageflip( $img, IMG_FLIP_HORIZONTAL );
			break;
		case 3:
			$img = imagerotate( $img, 180, 0 );
			break;
		case 4:
			imageflip( $img, IMG_FLIP_VERTICAL );
			break;
		case 5: // transpose = 90 clockwise, then mirror
			$img = imagerotate( $img, -90, 0 );
			imageflip( $img, IMG_FLIP_HORIZONTAL );
			break;
		case 6:
			$img = imagerotate( $img, -90, 0 );
			break;
		case 7: // transverse = 90 anti-clockwise, then mirror
			$img = imagerotate( $img, 90, 0 );
			imageflip( $img, IMG_FLIP_HORIZONTAL );
			break;
		case 8:
			$img = imagerotate( $img, 90, 0 );
			break;
	}
	$ok = $img && imagejpeg( $img, $path, 90 );
	if ( $img ) {
		imagedestroy( $img );
	}
	// GD stamps a comment block ("CREATOR: gd-jpeg ...") into every file it saves; remove it,
	// or the final check would (rightly) refuse the photo.
	return $ok && portal_jpeg_strip_segments( $path );
}

/**
 * Final check: is any location (GPS) or camera data still readable?
 *
 * @param string $path File.
 * @param string $mime Mime type.
 * @return bool
 */
function portal_image_still_has_location( $path, $mime ) {
	clearstatcache( true, $path );
	if ( 'image/jpeg' === $mime ) {
		return portal_jpeg_has_private_data( $path );
	}
	return portal_imagick_has_metadata( $path );
}
