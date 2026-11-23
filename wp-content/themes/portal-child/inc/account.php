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
 * The account menu at the end of the header (Task 19 UI, 23 Nov 2026).
 *
 * Desktop: profile photo + name (or a person icon + "Sign in" for visitors) that opens a small
 * menu on hover, click or keyboard focus. Phone drawer: the same links listed directly.
 *   Logged in: My account, Edit profile, Send news, Write a story, Sign out.
 *   Visitors:  Create account, Send news, Write a story (those pages invite them to sign in).
 *
 * Logged-out pages are cached on the live site, which is fine: every visitor sees the same
 * "Sign in" menu. Logged-in pages are never cached.
 *
 * @return array{label: string, url: string, photo: string, links: array<int, array{0: string, 1: string}>}
 */
function portal_child_account_menu_data() {
	$page  = static function ( $slug ) {
		$found = function_exists( 'portal_page' ) ? portal_page( $slug ) : get_page_by_path( $slug );
		return $found ? (string) get_permalink( $found ) : '';
	};
	$links = array();
	if ( is_user_logged_in() ) {
		$user    = wp_get_current_user();
		$account = function_exists( 'portal_account_url' ) && portal_account_url() ? portal_account_url() : admin_url( 'profile.php' );
		$links[] = array( __( 'My account', 'portal' ), $account );
		$links[] = array( __( 'Edit profile', 'portal' ), add_query_arg( 'section', 'edit-profile', $account ) );
		$data    = array(
			'label' => $user->display_name,
			'url'   => $account,
			'photo' => get_avatar( $user->ID, 28, '', '', array( 'class' => 'portal-account-menu__photo' ) ),
		);
	} else {
		$data = array(
			'label' => __( 'Sign in', 'portal' ),
			'url'   => wp_login_url( is_singular() ? get_permalink() : home_url( '/' ) ),
			'photo' => '<svg class="portal-account-menu__icon" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>',
		);
		if ( get_option( 'users_can_register' ) ) {
			$links[] = array( __( 'Create account', 'portal' ), wp_registration_url() );
		}
	}
	if ( $page( 'send-news' ) ) {
		$links[] = array( __( 'Send news', 'portal' ), $page( 'send-news' ) );
	}
	if ( $page( 'write' ) ) {
		$links[] = array( __( 'Write a story', 'portal' ), $page( 'write' ) );
	}
	if ( is_user_logged_in() ) {
		$links[] = array( __( 'Sign out', 'portal' ), wp_logout_url( home_url( '/' ) ), 'signout' );
	}
	$data['links'] = $links;
	return $data;
}

/**
 * Add the account menu to the main (desktop) and mobile menus.
 *
 * @param string   $items Menu HTML.
 * @param stdClass $args  Menu arguments.
 * @return string
 */
function portal_child_account_menu_item( $items, $args ) {
	$location = $args->theme_location ?? '';
	if ( ! in_array( $location, array( 'primary', 'mobile' ), true ) ) {
		return $items;
	}
	$menu = portal_child_account_menu_data();
	$head = '<a class="portal-account-menu__trigger" href="' . esc_url( $menu['url'] ) . '">' . $menu['photo'] . '<span class="portal-account-menu__name">' . esc_html( $menu['label'] ) . '</span></a>';

	if ( 'mobile' === $location ) { // phone drawer: everything visible, no hover.
		$items .= '<li class="menu-item portal-account-link portal-account-menu portal-account-menu--flat">' . $head . '</li>';
		foreach ( $menu['links'] as $link ) {
			$items .= '<li class="menu-item portal-account-menu__item"><a href="' . esc_url( $link[1] ) . '">' . esc_html( $link[0] ) . '</a></li>';
		}
		return $items;
	}

	$list = '';
	foreach ( $menu['links'] as $link ) {
		$class = empty( $link[2] ) ? '' : ' class="portal-account-menu__' . $link[2] . '"';
		$list .= '<li' . $class . '><a href="' . esc_url( $link[1] ) . '">' . esc_html( $link[0] ) . '</a></li>';
	}
	return $items . '<li class="menu-item portal-account-link portal-account-menu">' . $head
		. '<button type="button" class="portal-account-menu__toggle" aria-expanded="false" aria-label="' . esc_attr__( 'Account menu', 'portal' ) . '"><span aria-hidden="true">&#9662;</span></button>'
		. '<ul class="portal-account-menu__panel">' . $list . '</ul></li>';
}
add_filter( 'wp_nav_menu_items', 'portal_child_account_menu_item', 10, 2 );

/**
 * Tiny script for the account menu: the arrow button opens/closes it (touch screens, keyboard),
 * a click outside or Esc closes it. Hover and keyboard focus also open it (CSS).
 */
function portal_child_account_menu_script() {
	echo "<script>(function(){document.addEventListener('click',function(e){var t=e.target.closest('.portal-account-menu__toggle');document.querySelectorAll('.portal-account-menu.is-open').forEach(function(m){if(!t||m!==t.parentNode){m.classList.remove('is-open');m.querySelector('.portal-account-menu__toggle').setAttribute('aria-expanded','false');}});if(t){var m=t.parentNode,o=m.classList.toggle('is-open');t.setAttribute('aria-expanded',o?'true':'false');}});document.addEventListener('keydown',function(e){if(e.key==='Escape'){document.querySelectorAll('.portal-account-menu.is-open').forEach(function(m){m.classList.remove('is-open');m.querySelector('.portal-account-menu__toggle').setAttribute('aria-expanded','false');});}});})();</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed script, no data.
}
add_action( 'wp_footer', 'portal_child_account_menu_script' );

/**
 * The language switch (हिन्दी / English, from Polylang) goes last, after the account menu, styled
 * as a small pill: it changes the whole site's language, it is not a section.
 *
 * @param string   $items Menu HTML.
 * @param stdClass $args  Menu arguments.
 * @return string
 */
function portal_child_language_item_last( $items, $args ) {
	if ( ! in_array( $args->theme_location ?? '', array( 'primary', 'mobile' ), true ) ) {
		return $items;
	}
	if ( ! preg_match( '#<li[^>]*\blang-item\b[^>]*>.*?</li>#s', $items, $match ) ) {
		return $items;
	}
	$item = preg_replace( '#class="#', 'class="portal-lang-link ', $match[0], 1 );
	return str_replace( $match[0], '', $items ) . $item;
}
add_filter( 'wp_nav_menu_items', 'portal_child_language_item_last', 30, 2 );
