<?php
/**
 * Accounts (looks only): branded login / sign-up page and the "Sign in" / "My account"
 * link in the header menu. The rules (consent, redirects) live in portal-core.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Brand colour from the Kadence palette (Customizer), with our red as fallback.
 *
 * @return string Hex colour.
 */
function portal_child_accent_color() {
	$palette = json_decode( (string) get_option( 'kadence_global_palette', '' ), true );
	foreach ( (array) ( $palette['palette'] ?? array() ) as $color ) {
		if ( 'palette1' === ( $color['slug'] ?? '' ) && preg_match( '/^#[0-9a-f]{3,8}$/i', $color['color'] ?? '' ) ) {
			return $color['color'];
		}
	}
	return '#C8102E';
}

/**
 * Login / sign-up / password pages: our stylesheet and brand colour.
 */
function portal_child_login_styles() {
	$path = '/assets/css/login.css';
	wp_enqueue_style( 'portal-login', get_stylesheet_directory_uri() . $path, array( 'login' ), (string) filemtime( get_stylesheet_directory() . $path ) );
	wp_add_inline_style( 'portal-login', ':root{--portal-accent:' . portal_child_accent_color() . ';}' );
}
add_action( 'login_enqueue_scripts', 'portal_child_login_styles' );

/**
 * The logo above the form links to our home page and shows the site name (not WordPress).
 */
add_filter( 'login_headerurl', static fn() => home_url( '/' ) );
add_filter( 'login_headertext', static fn() => get_bloginfo( 'name' ) );

/**
 * "Sign in" (visitors) or "My account" (logged in) at the end of the main and mobile menus.
 *
 * Logged-out pages are cached on the live site, which is fine: every visitor sees
 * "Sign in". Logged-in pages are never cached.
 *
 * @param string   $items Menu HTML.
 * @param stdClass $args  Menu arguments.
 * @return string
 */
function portal_child_account_menu_item( $items, $args ) {
	if ( ! in_array( $args->theme_location ?? '', array( 'primary', 'mobile' ), true ) ) {
		return $items;
	}
	if ( is_user_logged_in() ) {
		$url   = function_exists( 'portal_account_url' ) && portal_account_url() ? portal_account_url() : admin_url( 'profile.php' );
		$label = __( 'My account', 'portal' );
	} else {
		$url   = wp_login_url( is_singular() ? get_permalink() : home_url( '/' ) );
		$label = __( 'Sign in', 'portal' );
	}
	// "Write" for everyone: visitors are invited to sign up, members to apply, writers get the form.
	$write = get_page_by_path( 'write' );
	if ( $write ) {
		$items .= '<li class="menu-item portal-write-link"><a href="' . esc_url( get_permalink( $write ) ) . '">' . esc_html__( 'Write', 'portal' ) . '</a></li>';
	}
	return $items . '<li class="menu-item portal-account-link"><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
}
add_filter( 'wp_nav_menu_items', 'portal_child_account_menu_item', 10, 2 );
