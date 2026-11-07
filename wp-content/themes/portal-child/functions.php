<?php
/**
 * Portal Child theme functions.
 *
 * Look-and-feel code only. Business rules (roles, fields, publishing rules, what counts
 * as breaking or trending) belong in the portal-core plugin so they keep working if the
 * theme changes - and so the mobile app gets the same answers through the API.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

require_once get_stylesheet_directory() . '/inc/template-tags.php';
require_once get_stylesheet_directory() . '/inc/article.php';
require_once get_stylesheet_directory() . '/inc/account.php';

/**
 * Load the child theme's stylesheet after Kadence's main stylesheet.
 * The version is the file's last-change time, so browsers fetch it again after every edit.
 */
function portal_child_enqueue_styles() {
	wp_enqueue_style(
		'portal-child',
		get_stylesheet_uri(),
		array( 'kadence-global' ),
		(string) filemtime( get_stylesheet_directory() . '/style.css' )
	);
}
add_action( 'wp_enqueue_scripts', 'portal_child_enqueue_styles', 20 );

/**
 * Load child theme translations (Hindi-ready).
 */
function portal_child_setup() {
	load_child_theme_textdomain( 'portal', get_stylesheet_directory() . '/languages' );
}
add_action( 'after_setup_theme', 'portal_child_setup' );

/**
 * Red Breaking strip under the header, on every page, while there is breaking news.
 */
function portal_child_breaking_strip() {
	if ( ! function_exists( 'portal_get_breaking_posts' ) ) {
		return; // portal-core is off: show nothing rather than break the page.
	}
	$posts = portal_get_breaking_posts( 3 );
	if ( $posts ) {
		get_template_part( 'template-parts/portal/breaking', null, array( 'posts' => $posts ) );
	}
}
add_action( 'kadence_after_header', 'portal_child_breaking_strip' );
