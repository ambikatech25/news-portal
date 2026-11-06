<?php
/**
 * Plugin Name: Portal Core
 * Description: Custom rules for the portal (roles, custom fields, publishing rules, REST API).
 * Version:     0.3.0
 * Requires PHP: 8.2
 * Text Domain: portal
 * Domain Path: /languages
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

define( 'PORTAL_CORE_VERSION', '0.3.0' );
define( 'PORTAL_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'PORTAL_CORE_DIR', plugin_dir_path( __FILE__ ) );

/*
 * Bump this when roles/capabilities change, so portal_maybe_upgrade() re-applies them
 * on sites where the plugin is already active (activation hooks only run once).
 */
define( 'PORTAL_CORE_DB_VERSION', '2' );

require_once PORTAL_CORE_DIR . 'includes/roles.php';
require_once PORTAL_CORE_DIR . 'includes/restricted-categories.php';
require_once PORTAL_CORE_DIR . 'includes/post-meta.php';

register_activation_hook( __FILE__, 'portal_setup_roles' );

/**
 * Load translations (Hindi-ready).
 */
function portal_load_textdomain() {
	load_plugin_textdomain( 'portal', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'portal_load_textdomain' );
