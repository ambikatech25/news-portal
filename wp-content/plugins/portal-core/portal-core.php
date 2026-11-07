<?php
/**
 * Plugin Name: Portal Core
 * Description: Custom rules for the portal (roles, custom fields, publishing rules, REST API).
 * Version:     0.10.0
 * Requires PHP: 8.2
 * Text Domain: portal
 * Domain Path: /languages
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

define( 'PORTAL_CORE_VERSION', '0.10.0' );
define( 'PORTAL_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'PORTAL_CORE_DIR', plugin_dir_path( __FILE__ ) );

/*
 * Bump this when roles/capabilities or our database tables change, so portal_maybe_upgrade()
 * re-applies them on sites where the plugin is already active (activation hooks only run once).
 */
define( 'PORTAL_CORE_DB_VERSION', '5' );

require_once PORTAL_CORE_DIR . 'includes/roles.php';
require_once PORTAL_CORE_DIR . 'includes/restricted-categories.php';
require_once PORTAL_CORE_DIR . 'includes/post-meta.php';
require_once PORTAL_CORE_DIR . 'includes/feeds.php';
require_once PORTAL_CORE_DIR . 'includes/article.php';
require_once PORTAL_CORE_DIR . 'includes/reports.php';
require_once PORTAL_CORE_DIR . 'includes/profiles.php';
require_once PORTAL_CORE_DIR . 'includes/compat.php';
require_once PORTAL_CORE_DIR . 'includes/accounts.php';
require_once PORTAL_CORE_DIR . 'includes/writing.php';
require_once PORTAL_CORE_DIR . 'includes/story-import.php';
require_once PORTAL_CORE_DIR . 'includes/engagement.php';
require_once PORTAL_CORE_DIR . 'includes/review.php';
require_once PORTAL_CORE_DIR . 'includes/local-mail.php';

register_activation_hook( __FILE__, 'portal_setup_roles' );
register_activation_hook( __FILE__, 'portal_install_reports_table' );
register_activation_hook( __FILE__, 'portal_install_follows_table' );
register_activation_hook( __FILE__, 'portal_upgrade_profiles' );
register_activation_hook( __FILE__, 'portal_install_marks_table' );

/**
 * Load translations (Hindi-ready).
 */
function portal_load_textdomain() {
	load_plugin_textdomain( 'portal', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'portal_load_textdomain' );
