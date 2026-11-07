<?php
/**
 * Grievances (India's IT Rules, 2021: a Grievance Officer who acknowledges complaints and
 * resolves them within 15 days).
 *
 * The form itself is a Fluent Forms form on the Grievance Officer page (built by
 * scripts/setup-moderation.php); Fluent Forms stores every entry (wp-admin → Fluent Forms → Entries).
 * This file adds, on every new grievance:
 *  - an acknowledgement e-mail to the complainant with a reference number (GRV-<entry>),
 *  - an e-mail to the Grievance Officer with all the details,
 *  - an audit-log entry.
 *
 * Grievance Officer e-mail: option portal_grievance_email (falls back to the site admin e-mail).
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Grievance Officer's e-mail.
 *
 * @return string
 */
function portal_grievance_email() {
	$email = (string) get_option( 'portal_grievance_email', '' );
	return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
}

/**
 * Handle a new grievance.
 *
 * @param int    $entry_id Fluent Forms entry ID.
 * @param array  $data     Submitted fields.
 * @param object $form     Form.
 */
function portal_grievance_received( $entry_id, $data, $form ) {
	if ( (int) ( $form->id ?? 0 ) !== (int) get_option( 'portal_grievance_form_id' ) ) {
		return;
	}
	$ref     = 'GRV-' . (int) $entry_id;
	$names   = (array) ( $data['names'] ?? array() );
	$name    = trim( sanitize_text_field( ( $names['first_name'] ?? '' ) . ' ' . ( $names['last_name'] ?? '' ) ) );
	$email   = sanitize_email( (string) ( $data['email'] ?? '' ) );
	$type    = sanitize_text_field( (string) ( $data['complaint_type'] ?? '' ) );
	$url     = esc_url_raw( (string) ( $data['content_url'] ?? '' ) );
	$details = sanitize_textarea_field( (string) ( $data['details'] ?? '' ) );
	$site    = get_bloginfo( 'name' );

	if ( is_email( $email ) ) {
		wp_mail(
			$email,
			/* translators: 1: reference, 2: site name. */
			sprintf( __( 'We received your complaint (%1$s) - %2$s', 'portal' ), $ref, $site ),
			sprintf(
				/* translators: 1: name, 2: site, 3: reference, 4: date. */
				__( "Dear %1\$s,\n\nThank you for writing to the Grievance Officer of %2\$s.\n\nYour reference number: %3\$s\nReceived on: %4\$s (UTC)\n\nWe will look into your complaint and reply within 15 days, as required by India's Information Technology Rules, 2021. Please quote your reference number in any message about this complaint.\n\nGrievance Officer\n%2\$s", 'portal' ),
				$name ? $name : __( 'Sir/Madam', 'portal' ),
				$site,
				$ref,
				current_time( 'mysql', true )
			)
		);
	}

	wp_mail(
		portal_grievance_email(),
		/* translators: 1: reference, 2: type. */
		sprintf( __( 'New grievance %1$s: %2$s', 'portal' ), $ref, $type ),
		sprintf(
			/* translators: 1: ref, 2: name, 3: email, 4: type, 5: url, 6: details, 7: entries link. */
			__( "Reference: %1\$s\nFrom: %2\$s <%3\$s>\nType: %4\$s\nContent: %5\$s\n\n%6\$s\n\nReply within 15 days. All entries: %7\$s", 'portal' ),
			$ref,
			$name,
			$email,
			$type,
			$url ? $url : '-',
			$details,
			admin_url( 'admin.php?page=fluent_forms&route=entries&form_id=' . (int) $form->id )
		)
	);

	portal_log_action(
		'grievance_received',
		'grievance',
		(int) $entry_id,
		get_current_user_id(),
		array(
			'type' => $type,
			'url'  => $url,
		)
	);
}
add_action( 'fluentform/submission_inserted', 'portal_grievance_received', 20, 3 );
