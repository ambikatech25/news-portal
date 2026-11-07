<?php
/**
 * Local development only: send every e-mail to the Mailpit catcher in Docker
 * (read them at http://localhost:8025). On any other site this file does nothing;
 * real e-mail sending is set up for the live site in Task 18.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

if ( 'local' === wp_get_environment_type() ) {
	// WordPress would send from "wordpress@localhost", which the mailer rejects as invalid
	// (no dot in the domain), so nothing would be sent at all. .test is reserved for testing.
	add_filter( 'wp_mail_from', static fn() => 'no-reply@portal.test' );

	add_action(
		'phpmailer_init',
		static function ( $mailer ) {
			$mailer->isSMTP();
			$mailer->Host     = (string) apply_filters( 'portal_local_smtp_host', 'mailpit' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$mailer->Port     = 1025; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$mailer->SMTPAuth = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}
	);
}
