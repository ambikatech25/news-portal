<?php
/**
 * How the site sends e-mail.
 *
 *  - Local development: every e-mail goes to the Mailpit catcher in Docker
 *    (read them at http://localhost:8025); nothing reaches a real inbox.
 *  - Live site (Task 18): through a real CityCharcha mailbox (Hostinger e-mail) over SMTP, so
 *    sign-up links, review decisions and grievance acknowledgements do not land in spam.
 *    The mailbox details live ONLY in the server's wp-config.php (never in git):
 *
 *      define( 'PORTAL_SMTP_HOST', 'smtp.hostinger.com' );
 *      define( 'PORTAL_SMTP_PORT', 465 );
 *      define( 'PORTAL_SMTP_USER', 'no-reply@citycharcha.com' );
 *      define( 'PORTAL_SMTP_PASS', '...' );
 *      define( 'PORTAL_MAIL_FROM_NAME', 'CityCharcha' );
 *
 *    Without them, WordPress's normal mail() is used (works on Hostinger, but more often lands in spam).
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

if ( 'local' !== wp_get_environment_type() && defined( 'PORTAL_SMTP_HOST' ) && defined( 'PORTAL_SMTP_USER' ) && defined( 'PORTAL_SMTP_PASS' ) ) {
	add_filter( 'wp_mail_from', static fn() => (string) PORTAL_SMTP_USER );
	add_filter( 'wp_mail_from_name', static fn() => defined( 'PORTAL_MAIL_FROM_NAME' ) ? (string) PORTAL_MAIL_FROM_NAME : get_bloginfo( 'name' ) );
	add_action(
		'phpmailer_init',
		static function ( $mailer ) {
			$port = defined( 'PORTAL_SMTP_PORT' ) ? (int) PORTAL_SMTP_PORT : 465;
			// phpcs:disable WordPress.NamingConventions.ValidVariableName -- PHPMailer property names.
			$mailer->isSMTP();
			$mailer->Host       = (string) PORTAL_SMTP_HOST;
			$mailer->Port       = $port;
			$mailer->SMTPAuth   = true;
			$mailer->Username   = (string) PORTAL_SMTP_USER;
			$mailer->Password   = (string) PORTAL_SMTP_PASS;
			$mailer->SMTPSecure = 465 === $port ? 'ssl' : 'tls';
			// phpcs:enable
		}
	);
}

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
