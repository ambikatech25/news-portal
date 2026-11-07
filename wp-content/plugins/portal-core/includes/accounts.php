<?php
/**
 * Accounts: sign-up consent, consent record, where people land after login, and the
 * profile photo box on the My Account page.
 *
 * Sign-up and login use WordPress's own pages (wp-login.php):
 *  - e-mail sign-up sends a "set your password" link, so only real e-mail addresses get
 *    an account (WP User Frontend's free sign-up form skips that check);
 *  - Wordfence's brute-force protection and staff two-factor login work on these pages;
 *  - Nextend Social Login adds the "Continue with Google" button there by itself.
 *
 * Consent: nobody gets an account without agreeing to the Terms of Use and Privacy Policy.
 * E-mail sign-ups tick our checkbox; Google sign-ups tick Nextend's terms box (switched on
 * by scripts/setup-accounts.php). Either way we keep a record (when, how, which version of
 * each page) in user meta "portal_consent", as proof under the DPDP Act.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

const PORTAL_CONSENT_META = 'portal_consent';

/**
 * Terms of Use and Privacy Policy page URLs and their "version" (last-changed time).
 *
 * @return array{terms_url:string,privacy_url:string,terms_version:string,privacy_version:string}
 */
function portal_legal_pages() {
	$terms   = get_page_by_path( 'terms-of-use' );
	$privacy = (int) get_option( 'wp_page_for_privacy_policy' );
	return array(
		'terms_url'       => $terms ? get_permalink( $terms ) : '',
		'privacy_url'     => get_privacy_policy_url(),
		'terms_version'   => $terms ? $terms->post_modified_gmt : '',
		'privacy_version' => $privacy ? (string) get_post_field( 'post_modified_gmt', $privacy ) : '',
	);
}

/**
 * The "I agree" sentence with links, used on our form and in Nextend's Google sign-up step.
 *
 * @return string HTML.
 */
function portal_consent_label() {
	$pages = portal_legal_pages();
	return sprintf(
		/* translators: 1: Terms of Use link, 2: Privacy Policy link. */
		__( 'I am at least 18 years old and I agree to the %1$s and the %2$s.', 'portal' ),
		'<a href="' . esc_url( $pages['terms_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Terms of Use', 'portal' ) . '</a>',
		'<a href="' . esc_url( $pages['privacy_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Privacy Policy', 'portal' ) . '</a>'
	);
}

/**
 * Sign-up form: consent checkbox and a hidden trap field for bots.
 */
function portal_register_form_fields() {
	// phpcs:ignore WordPress.Security.NonceVerification -- re-displaying the visitor's own choice.
	$checked = ! empty( $_POST['portal_consent'] );
	?>
	<p class="portal-consent">
		<label>
			<input type="checkbox" name="portal_consent" value="1" <?php checked( $checked ); ?> required>
			<span><?php echo wp_kses( portal_consent_label(), array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ); ?></span>
		</label>
	</p>
	<?php // People never see or fill this; simple bots fill every field and are refused. ?>
	<p class="portal-hp" aria-hidden="true">
		<label for="portal_website"><?php esc_html_e( 'Leave this empty', 'portal' ); ?></label>
		<input type="text" name="portal_website" id="portal_website" value="" tabindex="-1" autocomplete="off">
	</p>
	<?php
}
add_action( 'register_form', 'portal_register_form_fields' );

/**
 * Sign-up form: refuse without consent, or when the bot trap was filled.
 *
 * @param WP_Error $errors Errors so far.
 * @return WP_Error
 */
function portal_register_form_check( $errors ) {
	// phpcs:disable WordPress.Security.NonceVerification -- wp-login.php's sign-up form has no nonce; these are checks, not actions.
	if ( ! empty( $_POST['portal_website'] ) ) {
		$errors->add( 'portal_bot', __( '<strong>Error:</strong> Sign-up could not be completed.', 'portal' ) );
	}
	if ( empty( $_POST['portal_consent'] ) ) {
		$errors->add( 'portal_consent', __( '<strong>Error:</strong> Please agree to the Terms of Use and Privacy Policy to create an account.', 'portal' ) );
	}
	// phpcs:enable
	return $errors;
}
add_filter( 'registration_errors', 'portal_register_form_check' );

/**
 * Store a consent record for a user.
 *
 * @param int    $user_id User ID.
 * @param string $method  email | google.
 */
function portal_record_consent( $user_id, $method ) {
	$pages = portal_legal_pages();
	update_user_meta(
		$user_id,
		PORTAL_CONSENT_META,
		array(
			'at'              => current_time( 'mysql', true ),
			'method'          => $method,
			'terms_version'   => $pages['terms_version'],
			'privacy_version' => $pages['privacy_version'],
		)
	);
}

/**
 * E-mail sign-up: record consent (the form check above already required it).
 *
 * @param int $user_id New user.
 */
function portal_consent_on_register( $user_id ) {
	// phpcs:ignore WordPress.Security.NonceVerification -- see portal_register_form_check().
	if ( ! empty( $_POST['portal_consent'] ) ) {
		portal_record_consent( $user_id, 'email' );
	}
}
add_action( 'user_register', 'portal_consent_on_register' );

/**
 * Google sign-up (Nextend): record consent. Nextend only creates the account after the
 * person ticked its terms box, which setup-accounts.php makes compulsory.
 *
 * @param int $user_id New user.
 */
function portal_consent_on_social_register( $user_id ) {
	if ( class_exists( 'NextendSocialLogin' ) && '1' === (string) NextendSocialLogin::$settings->get( 'terms_show' ) ) {
		portal_record_consent( $user_id, 'google' );
	}
}
add_action( 'nsl_register_new_user', 'portal_consent_on_social_register' );

/**
 * Show the consent record on the user's wp-admin screen (for grievance handling).
 *
 * @param WP_User $user User.
 */
function portal_show_consent_record( $user ) {
	if ( ! current_user_can( 'list_users' ) ) {
		return;
	}
	$consent = get_user_meta( $user->ID, PORTAL_CONSENT_META, true );
	?>
	<h2><?php esc_html_e( 'Consent', 'portal' ); ?></h2>
	<p>
		<?php
		if ( is_array( $consent ) && ! empty( $consent['at'] ) ) {
			echo esc_html(
				sprintf(
					/* translators: 1: date and time (UTC), 2: e-mail or Google. */
					__( 'Agreed to the Terms of Use and Privacy Policy on %1$s UTC, when signing up with %2$s.', 'portal' ),
					$consent['at'],
					'google' === $consent['method'] ? 'Google' : __( 'e-mail', 'portal' )
				)
			);
		} else {
			esc_html_e( 'No consent record (account created by an administrator or before Task 10).', 'portal' );
		}
		?>
	</p>
	<?php
}
add_action( 'show_user_profile', 'portal_show_consent_record', 20 );
add_action( 'edit_user_profile', 'portal_show_consent_record', 20 );

/* ------------------------------------------------------------------ After login */

/**
 * URL of the My Account page (WP User Frontend's [wpuf_account] page).
 *
 * @return string Empty if not set up.
 */
function portal_account_url() {
	$page = function_exists( 'wpuf_get_option' ) ? (int) wpuf_get_option( 'account_page', 'wpuf_my_account', 0 ) : 0;
	return $page ? (string) get_permalink( $page ) : '';
}

/**
 * Members (who cannot write) go to My Account after logging in, not to wp-admin.
 * A link they were following (e.g. "log in to comment") is still respected.
 *
 * @param string           $redirect_to Requested destination.
 * @param string           $requested   Raw requested destination.
 * @param WP_User|WP_Error $user        User.
 * @return string
 */
function portal_login_redirect( $redirect_to, $requested, $user ) {
	if ( ! $user instanceof WP_User || user_can( $user, 'edit_posts' ) ) {
		return $redirect_to;
	}
	$to_admin = '' === $requested || 0 === strpos( $redirect_to, admin_url() );
	$account  = portal_account_url();
	return ( $to_admin && $account ) ? $account : $redirect_to;
}
add_filter( 'login_redirect', 'portal_login_redirect', 20, 3 );

/**
 * My Account menu (WP User Frontend): no Billing Address or Subscription tabs (Premium
 * payments go through Razorpay, Task 20), and no Posts / Submit Post for members who
 * cannot write yet ("Become a writer" comes in Task 11).
 *
 * @param array $sections Tab key => label.
 * @return array
 */
function portal_account_sections( $sections ) {
	unset( $sections['billing-address'], $sections['subscription'] );
	if ( ! current_user_can( 'edit_posts' ) ) {
		unset( $sections['post'], $sections['submit-post'] );
	}
	return $sections;
}
add_filter( 'wpuf_account_sections', 'portal_account_sections', 20 );

/* ------------------------------------------------------------------ Photo box on My Account */

/**
 * [portal_profile_photo] - change or remove your profile photo, from the website.
 * Uses the same checks as the wp-admin field and the app API (portal_set_avatar()).
 *
 * @return string HTML.
 */
function portal_profile_photo_shortcode() {
	if ( ! is_user_logged_in() ) {
		return '';
	}
	$user_id = get_current_user_id();
	$has     = (bool) get_user_meta( $user_id, PORTAL_AVATAR_META, true );
	// phpcs:ignore WordPress.Security.NonceVerification -- only reads which message to show.
	$status = isset( $_GET['portal_photo'] ) ? sanitize_key( wp_unslash( $_GET['portal_photo'] ) ) : '';
	$errors = array(
		'type'  => __( 'Please choose a JPG, PNG or WebP photo.', 'portal' ),
		'size'  => __( 'The photo is too large. The limit is 2 MB.', 'portal' ),
		'error' => __( 'The photo did not upload. Please try again.', 'portal' ),
	);
	ob_start();
	?>
	<section class="portal-photo-box" aria-labelledby="portal-photo-title">
		<?php echo get_avatar( $user_id, 96, '', '', array( 'loading' => 'eager' ) ); ?>
		<div class="portal-photo-box__body">
			<h2 id="portal-photo-title"><?php esc_html_e( 'Profile photo', 'portal' ); ?></h2>
			<?php if ( 'saved' === $status ) : ?>
				<p class="portal-photo-box__msg is-ok" role="status"><?php esc_html_e( 'Photo saved.', 'portal' ); ?></p>
			<?php elseif ( 'removed' === $status ) : ?>
				<p class="portal-photo-box__msg is-ok" role="status"><?php esc_html_e( 'Photo removed.', 'portal' ); ?></p>
			<?php elseif ( isset( $errors[ $status ] ) ) : ?>
				<p class="portal-photo-box__msg is-error" role="alert"><?php echo esc_html( $errors[ $status ] ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="portal_profile_photo">
				<?php wp_nonce_field( 'portal_profile_photo' ); ?>
				<input type="file" name="portal_avatar" accept="image/jpeg,image/png,image/webp" required>
				<button type="submit"><?php esc_html_e( 'Upload photo', 'portal' ); ?></button>
			</form>
			<p class="portal-photo-box__help"><?php esc_html_e( 'JPG, PNG or WebP, up to 2 MB.', 'portal' ); ?></p>
			<?php if ( $has ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="portal_profile_photo">
					<input type="hidden" name="portal_remove" value="1">
					<?php wp_nonce_field( 'portal_profile_photo' ); ?>
					<button type="submit" class="portal-photo-box__remove"><?php esc_html_e( 'Remove photo', 'portal' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'portal_profile_photo', 'portal_profile_photo_shortcode' );

/**
 * Handle the photo box form (admin-post.php works for every logged-in user, including members).
 */
function portal_profile_photo_post() {
	check_admin_referer( 'portal_profile_photo' );
	$user_id = get_current_user_id();
	$back    = wp_get_referer() ? wp_get_referer() : home_url( '/' );

	if ( ! empty( $_POST['portal_remove'] ) ) {
		portal_remove_avatar( $user_id );
		$status = 'removed';
	} elseif ( ! empty( $_FILES['portal_avatar']['name'] ) ) {
		$result = portal_set_avatar( $user_id, $_FILES['portal_avatar'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked in portal_set_avatar().
		$codes  = array(
			'portal_avatar_type' => 'type',
			'portal_avatar_size' => 'size',
		);
		$status = is_wp_error( $result ) ? ( $codes[ $result->get_error_code() ] ?? 'error' ) : 'saved';
	} else {
		$status = 'error';
	}
	wp_safe_redirect( add_query_arg( 'portal_photo', $status, remove_query_arg( 'portal_photo', $back ) ) );
	exit;
}
add_action( 'admin_post_portal_profile_photo', 'portal_profile_photo_post' );
