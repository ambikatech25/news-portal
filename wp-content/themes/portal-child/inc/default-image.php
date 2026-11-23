<?php
/**
 * Default picture: a story without its own featured image shows a simple picture in its
 * section's colour (gradient with soft circles, no text), the same style as the demo pictures,
 * everywhere a picture is expected: home page cards, section and tag pages, related stories and
 * the story page itself. No empty gaps.
 *
 * The picture is a tiny SVG written into the page (no extra file to download). The circles are
 * placed from the story's ID, so neighbouring cards do not look identical.
 *
 * Only for reader pages; wp-admin and the REST API still show "no featured image", so editors
 * can see which stories need a real picture (the app uses its own default).
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the default picture applies to this post on this request.
 *
 * @param int|WP_Post|null $post Post.
 * @return bool
 */
function portal_child_uses_default_image( $post ) {
	if ( is_admin() || wp_is_json_request() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}
	$post = get_post( $post );
	return $post && 'post' === $post->post_type && ! get_post_thumbnail_id( $post );
}

/**
 * Stories without a picture count as having one (the default), so themes and our cards draw it.
 *
 * @param bool             $has  Has a featured image.
 * @param int|WP_Post|null $post Post.
 * @return bool
 */
function portal_child_has_default_image( $has, $post ) {
	return $has || portal_child_uses_default_image( $post );
}
add_filter( 'has_post_thumbnail', 'portal_child_has_default_image', 10, 2 );

/**
 * The story's section colour (the English section decides, so समाचार = News red).
 *
 * @param int $post_id Post ID.
 * @return int[] RGB.
 */
function portal_child_default_image_colour( $post_id ) {
	$colours = array(
		'news'            => array( 180, 30, 45 ),
		'region'          => array( 30, 110, 90 ),
		'industry'        => array( 70, 80, 105 ),
		'logistics'       => array( 200, 120, 30 ),
		'crime'           => array( 55, 45, 70 ),
		'community-blogs' => array( 40, 100, 170 ),
		'case-files'      => array( 90, 40, 40 ),
	);
	foreach ( get_the_category( $post_id ) as $cat ) {
		$en = function_exists( 'pll_get_term' ) ? pll_get_term( $cat->term_id, 'en' ) : 0;
		$en = $en ? get_term( $en, 'category' ) : $cat;
		if ( $en instanceof WP_Term && isset( $colours[ $en->slug ] ) ) {
			return $colours[ $en->slug ];
		}
	}
	return $colours['news'];
}

/**
 * The default picture as a small SVG (gradient in the section colour + soft circles).
 *
 * @param int $post_id Post ID.
 * @return string SVG markup.
 */
function portal_child_default_image_svg( $post_id ) {
	list( $r, $g, $b ) = portal_child_default_image_colour( $post_id );
	$top    = sprintf( '#%02x%02x%02x', $r, $g, $b );
	$bottom = sprintf( '#%02x%02x%02x', (int) ( $r * 0.45 ), (int) ( $g * 0.45 ), (int) ( $b * 0.45 ) );

	// Circles from the post ID: same story = same picture, neighbouring stories differ.
	$seed    = (int) $post_id * 2654435761 % 4294967296;
	$next    = static function ( $min, $max ) use ( &$seed ) {
		$seed = ( $seed * 1103515245 + 12345 ) % 2147483648;
		return $min + $seed % ( $max - $min + 1 );
	};
	$circles = '';
	for ( $i = 0; $i < 7; $i++ ) {
		$circles .= sprintf( '<circle cx="%d" cy="%d" r="%d" opacity="0.%02d"/>', $next( 0, 1200 ), $next( 0, 675 ), $next( 60, 260 ), $next( 8, 22 ) );
	}
	return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 675"><defs><linearGradient id="g" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . $top . '"/><stop offset="1" stop-color="' . $bottom . '"/></linearGradient></defs><rect width="1200" height="675" fill="url(#g)"/><g fill="#fff">' . $circles . '</g></svg>';
}

/**
 * The default picture's HTML, in place of the empty featured image.
 *
 * @param string       $html    Image HTML ('' when the story has none).
 * @param int          $post_id Post ID.
 * @param int          $thumb   Attachment ID.
 * @param string|int[] $size    Requested size.
 * @param string|array $attr    Attributes asked for (loading, fetchpriority…).
 * @return string
 */
function portal_child_default_image_html( $html, $post_id, $thumb, $size, $attr ) {
	if ( '' !== $html || ! portal_child_uses_default_image( $post_id ) ) {
		return $html;
	}
	$attr = wp_parse_args( $attr );
	$src  = 'data:image/svg+xml;base64,' . base64_encode( portal_child_default_image_svg( $post_id ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- encoding an image, not hiding code.
	return sprintf(
		'<img src="%s" width="1200" height="675" alt="" class="wp-post-image portal-default-image" decoding="async"%s>',
		esc_attr( $src ),
		isset( $attr['fetchpriority'] ) ? ' fetchpriority="' . esc_attr( $attr['fetchpriority'] ) . '"' : ''
	);
}
add_filter( 'post_thumbnail_html', 'portal_child_default_image_html', 10, 5 );
