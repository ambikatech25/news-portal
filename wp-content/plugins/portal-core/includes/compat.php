<?php
/**
 * Workarounds for bugs in third-party plugins. We never edit their files (updates would
 * undo it); each fix here is small, explained, and should be removed once fixed upstream.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * WP User Frontend (checked with its Frontend_Account::process_user_for_previewing_post):
 * to let writers preview their own drafts, it gives "edit_posts" when the logged-in user
 * is the author of the post in the address. For a LOGGED-OUT visitor on any page that is
 * not a single post, both IDs are 0, they "match", and the plugin grants edit_posts to
 * every permission check for the rest of that page load, whoever is being checked.
 *
 * Fix: on logged-out page loads only, take edit_posts away again from anyone whose own
 * roles do not have it. Logged-in writers previewing their drafts are not affected.
 *
 * @param array   $allcaps Capabilities after other filters.
 * @param array   $caps    Capabilities being checked.
 * @param array   $args    Check arguments.
 * @param WP_User $user    User being checked.
 * @return array
 */
function portal_fix_wpuf_preview_cap( $allcaps, $caps, $args, $user ) {
	if ( empty( $allcaps['edit_posts'] ) || is_user_logged_in() || ! $user instanceof WP_User ) {
		return $allcaps;
	}
	// get_role_caps() rebuilds the user's real capabilities from their roles, without filters.
	$real = $user->get_role_caps();
	if ( empty( $real['edit_posts'] ) ) {
		unset( $allcaps['edit_posts'] );
	}
	return $allcaps;
}
add_filter( 'user_has_cap', 'portal_fix_wpuf_preview_cap', 11, 4 );
