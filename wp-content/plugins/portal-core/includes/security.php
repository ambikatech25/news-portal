<?php
/**
 * Small security hardening that no approved plugin covers (Wordfence does the firewall,
 * login protection and scans; see scripts/setup-security.php).
 *
 *  - Security headers on every page and on the login page.
 *  - XML-RPC switched off: an old remote-control door that this site does not use (the app
 *    uses the REST API) and that attackers use for password guessing and pingback abuse.
 *  - The WordPress version is not advertised in the page code.
 *  - The visitor's real IP address, also behind Cloudflare (portal_client_ip()).
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Security headers.
 */
function portal_security_headers() {
	if ( headers_sent() ) {
		return;
	}
	header( 'X-Content-Type-Options: nosniff' );                        // browsers must not guess file types
	header( 'X-Frame-Options: SAMEORIGIN' );                            // no showing our pages inside other sites (clickjacking)
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );       // other sites see only "citycharcha.com", not the full page address
	header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(self)' );
	if ( is_ssl() ) {
		header( 'Strict-Transport-Security: max-age=31536000' );       // live site: always HTTPS for a year
	}
}
add_action( 'send_headers', 'portal_security_headers' );
add_action( 'login_init', 'portal_security_headers' );
add_action( 'admin_init', 'portal_security_headers' );

/**
 * XML-RPC off: no methods at all, and no "X-Pingback" advert.
 */
add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter( 'xmlrpc_methods', '__return_empty_array' );
add_filter(
	'wp_headers',
	static function ( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
);
add_filter( 'pings_open', '__return_false' );

/**
 * Do not advertise the WordPress version.
 */
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );

/**
 * The visitor's IP address. Behind Cloudflare (Task 17 guide) every request seems to come
 * from Cloudflare, so the real address is in a header. Wordfence already knows how this site
 * is set up (Wordfence → All Options → "How does Wordfence get IPs"), so ask Wordfence first.
 *
 * @return string
 */
function portal_client_ip() {
	if ( class_exists( 'wfUtils' ) && method_exists( 'wfUtils', 'getIP' ) ) {
		$ip = (string) wfUtils::getIP();
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}
	}
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}
