<?php
/**
 * Plugin Name: Portal Core
 * Description: Custom rules for the portal (roles, custom fields, publishing rules, REST API).
 * Version:     0.1.0
 * Requires PHP: 8.2
 * Text Domain: portal
 * Domain Path: /languages
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

define( 'PORTAL_CORE_VERSION', '0.1.0' );
define( 'PORTAL_CORE_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Load translations (Hindi-ready).
 */
function portal_load_textdomain() {
	load_plugin_textdomain( 'portal', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'portal_load_textdomain' );
