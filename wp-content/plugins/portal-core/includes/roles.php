<?php
/**
 * Roles and capabilities.
 *
 * Our role         WP role        Notes
 * Member           subscriber     read, comment, like, follow, bookmark, report
 * Writer           contributor    + upload_files (added here); posts always go to review
 * Trusted writer   author         publishes own posts directly
 * Editor           editor         + portal_use_restricted_categories (added here)
 * Admin            administrator  + portal_use_restricted_categories (added here)
 *
 * Role capabilities are stored in the database, so they are applied once
 * (on activation or when PORTAL_CORE_DB_VERSION changes), not on every page load.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Capability that allows posting in the editor-only categories (News, Crime, Case Files).
 */
const PORTAL_CAP_RESTRICTED_CATEGORIES = 'portal_use_restricted_categories';

/**
 * Add our capabilities to the built-in roles.
 */
function portal_setup_roles() {
	$contributor = get_role( 'contributor' );
	if ( $contributor ) {
		// Writers need to add photos to their posts.
		$contributor->add_cap( 'upload_files' );
	}

	foreach ( array( 'editor', 'administrator' ) as $role_name ) {
		$role = get_role( $role_name );
		if ( $role ) {
			$role->add_cap( PORTAL_CAP_RESTRICTED_CATEGORIES );
		}
	}
}

/**
 * Re-apply role setup when the plugin's DB version changes.
 */
function portal_maybe_upgrade() {
	if ( get_option( 'portal_core_db_version' ) === PORTAL_CORE_DB_VERSION ) {
		return;
	}

	portal_setup_roles();
	update_option( 'portal_core_db_version', PORTAL_CORE_DB_VERSION );
}
add_action( 'init', 'portal_maybe_upgrade', 5 );
