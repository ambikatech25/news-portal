<?php
/**
 * Task 17: LiteSpeed Cache settings. Safe to run again.
 *
 *   docker compose run --rm -T wpcli eval-file /scripts/setup-speed.php
 *
 * LiteSpeed's page cache only works on a LiteSpeed web server (Hostinger), not on the local
 * Docker (Apache), so these settings take effect on the live site. Check with the steps in
 * docs/speed.md after going live.
 *
 * @package Portal
 */

$portal_lsc = array(
	// Page cache for visitors.
	'cache'               => true,
	'cache-priv'          => true,   // logged-in pages cached privately per person (short time)
	'cache-ttl_priv'      => 1800,
	'cache-commenter'     => true,
	'cache-rest'          => false,  // never cache API answers (likes, follows, reports are personal)
	'cache-page_login'    => true,
	'cache-ttl_frontpage' => 1800,   // home page: at most 30 minutes old (it is also refreshed on every publish)
	'cache-ttl_pub'       => 21600,  // stories: 6 hours, so the Breaking strip never stays long after its 24 hours
	'cache-browser'       => true,   // pictures, CSS and JS kept by the reader's browser
	'cache-ttl_browser'   => 2592000,
	// Never cache these pages (forms with security tokens, personal pages).
	'cache-exc'           => array( '/grievance-officer/', '/account/', '/write/', '/edit-story/', '/wp-login.php' ),
	// Code and page size.
	'optm-css_min'        => true,
	'optm-js_min'         => true,
	'optm-html_min'       => true,
	'optm-css_comb'       => false,  // combining is slower on modern (HTTP/2) connections
	'optm-js_comb'        => false,
	'optm-js_defer'       => 0,      // off: deferring breaks WP User Frontend's inline scripts (Task 11)
	'optm-emoji_rm'       => true,   // the theme already removes it; harmless twice
	'optm-qs_rm'          => false,  // keep ?ver= so readers get new CSS after each change
	'optm-ggfonts_rm'     => false,
	// Pictures: our theme already lazy-loads them (and loads the top story first).
	'media-lazy'          => false,
	'media-iframe_lazy'   => true,
	// Image optimisation through QUIC.cloud needs an account: off (EWWW does it, Task 15).
	'img_optm-auto'       => false,
	// Heartbeat (WordPress's background ping): less often, saves server work.
	'misc-heartbeat_front'     => true,
	'misc-heartbeat_front_ttl' => 0,
	'misc-heartbeat_back'      => true,
	'misc-heartbeat_back_ttl'  => 120,
	'misc-heartbeat_editor'    => true,
	'misc-heartbeat_editor_ttl' => 60,
	// No "Guest mode" or crawler: they suit very large sites, and need extra server capacity.
	'guest'               => false,
	'crawler'             => false,
);

if ( class_exists( '\LiteSpeed\Conf' ) && method_exists( '\LiteSpeed\Conf', 'cls' ) ) {
	\LiteSpeed\Conf::cls()->update_confs( $portal_lsc );
} else {
	foreach ( $portal_lsc as $portal_key => $portal_value ) {
		update_option( 'litespeed.conf.' . $portal_key, $portal_value );
	}
}

WP_CLI::success( 'LiteSpeed Cache settings saved (they take effect on the LiteSpeed server at Hostinger).' );
