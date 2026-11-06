<?php
/**
 * Portal Child theme functions.
 *
 * Look-and-feel code only. Business rules (roles, fields, publishing rules)
 * belong in the portal-core plugin so they keep working if the theme changes.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Load the child theme's stylesheet after Kadence's main stylesheet.
 */
function portal_child_enqueue_styles() {
	wp_enqueue_style(
		'portal-child',
		get_stylesheet_uri(),
		array( 'kadence-global' ),
		wp_get_theme()->get( 'Version' )
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
