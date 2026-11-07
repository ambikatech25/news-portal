<?php
/**
 * Task 10: sign-up and login settings. Safe to run again.
 *
 *   docker compose run --rm -T wpcli eval-file /scripts/setup-accounts.php
 *
 * - Anyone can sign up; new accounts are Members (Subscriber role).
 * - "My Account" page (WP User Frontend) for editing your profile on the website.
 * - Members do not see wp-admin or the admin bar; writers and staff still do.
 * - Nextend Social Login (Google): agreeing to Terms + Privacy is compulsory at sign-up.
 *
 * Google's Client ID and Secret are NOT set here: the owner pastes them into
 * Settings > Nextend Social Login > Google (see docs/accounts.md).
 *
 * @package Portal
 */

// Sign-up open, new accounts are Members.
update_option( 'users_can_register', 1 );
update_option( 'default_role', 'subscriber' );

// My Account page.
$portal_account = get_page_by_path( 'account' );
if ( ! $portal_account ) {
	$portal_account_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'My Account',
			'post_name'    => 'account',
			'post_content' => "<!-- wp:shortcode -->\n[portal_profile_photo]\n<!-- /wp:shortcode -->\n\n<!-- wp:shortcode -->\n[wpuf_account]\n<!-- /wp:shortcode -->",
		)
	);
} else {
	$portal_account_id = $portal_account->ID;
}

// WP User Frontend: which page is My Account, open on Edit Profile, no subscription tab.
$portal_my = (array) get_option( 'wpuf_my_account', array() );
$portal_my['account_page']            = (string) $portal_account_id;
$portal_my['account_page_active_tab'] = 'edit-profile';
$portal_my['show_subscriptions']      = 'off';
update_option( 'wpuf_my_account', $portal_my );

// WP User Frontend: admin bar and wp-admin only for people who write (Contributor and up).
// Members are sent back to the website if they open wp-admin (AJAX and uploads still work).
$portal_general = (array) get_option( 'wpuf_general', array() );
$portal_general['show_admin_bar'] = array( 'administrator', 'editor', 'author', 'contributor' );
$portal_general['admin_access']   = 'edit_posts';
update_option( 'wpuf_general', $portal_general );

// WP User Frontend: keep WordPress's own login and sign-up pages (they verify e-mail and
// work with Wordfence); do not swap in WPUF's forms.
$portal_profile                           = (array) get_option( 'wpuf_profile', array() );
$portal_profile['register_link_override'] = 'off';
update_option( 'wpuf_profile', $portal_profile );

// Nextend Social Login: compulsory consent box at Google sign-up, using our own sentence,
// buttons on the login and sign-up pages, new Google users land on My Account.
if ( class_exists( 'NextendSocialLogin' ) && function_exists( 'portal_consent_label' ) ) {
	NextendSocialLogin::$settings->update(
		array(
			'terms_show'             => '1',
			'terms'                  => portal_consent_label(),
			'show_login_form'        => 'show',
			'show_registration_form' => 'show',
			'default_redirect_reg'   => get_permalink( $portal_account_id ),
		)
	);
}

WP_CLI::success( sprintf( 'Sign-up open (Members), My Account page #%d, WP User Frontend and Nextend settings saved.', $portal_account_id ) );
