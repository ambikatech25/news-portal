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
require_once get_stylesheet_directory() . '/inc/engagement.php';
require_once get_stylesheet_directory() . '/inc/series.php';
require_once get_stylesheet_directory() . '/inc/reading.php';

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

/**
 * Speed (Task 17): no emoji script and styles. WordPress loads them on every page so very old
 * browsers can show emoji pictures; every phone and browser today shows emoji by itself.
 */
function portal_child_remove_emoji() {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	add_filter( 'emoji_svg_url', '__return_false' );
}
add_action( 'init', 'portal_child_remove_emoji' );
