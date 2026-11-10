<?php
/**
 * Task 17: Wordfence and UpdraftPlus settings. Safe to run again.
 *
 *   docker compose run --rm -T wpcli eval-file /scripts/setup-security.php
 *
 * Owner steps that need a phone or an account (2FA, Google Drive) are in docs/security.md.
 *
 * @package Portal
 */

/* ---------- Wordfence */

if ( class_exists( 'wfConfig' ) ) {
	$portal_wf = array(
		'loginSec_maxFailures'           => 5,          // 5 wrong passwords in 4 hours -> locked out 4 hours (was 20)
		'loginSec_maxForgotPasswd'       => 5,          // 5 password-reset requests -> locked out (was 20)
		'loginSec_countFailMins'         => 240,
		'loginSec_lockoutMins'           => 240,
		'loginSec_lockInvalidUsers'      => 0,          // off: a member mistyping their own username must not be locked out
		'loginSec_userBlacklist'         => "admin\nadministrator", // bots try these first; nobody here uses them
		'loginSec_breachPasswds'         => 'pubs',     // block known-leaked passwords for everyone who can publish
		'loginSec_breachPasswds_enabled' => 1,
		'loginSec_strongPasswds'         => 'pubs',
		'loginSec_strongPasswds_enabled' => 1,
		'loginSec_maskLoginErrors'       => 1,          // do not say whether the username or the password was wrong
		'loginSec_blockAdminReg'         => 1,
		// Off since Task 17: profile addresses no longer contain login names (Task 9), and this
		// block stopped editors seeing a story's author in the block editor ("(No author)").
		'loginSec_disableAuthorScan'     => 0,
		'other_hideWPVersion'            => 1,
		'liveTrafficEnabled'             => 0,          // off: slows the site; the audit log covers admin actions
		'scheduledScansEnabled'          => 1,          // free automatic malware scan
		'alertEmails'                    => get_option( 'admin_email' ),
		'alertOn_critical'               => 1,
		'alertOn_update'                 => 1,
		'alertOn_block'                  => 0,          // no e-mail for every blocked bot
		'alertOn_loginLockout'           => 0,
	);
	foreach ( $portal_wf as $portal_key => $portal_value ) {
		wfConfig::set( $portal_key, $portal_value );
	}
	WP_CLI::log( 'Wordfence: login protection, alerts and scans set.' );
} else {
	WP_CLI::warning( 'Wordfence is not active.' );
}

/* ---------- UpdraftPlus backups */

update_option( 'updraft_interval', 'weekly' );          // files (themes, plugins, uploads): every week
update_option( 'updraft_interval_database', 'daily' ); // database (stories, users, comments): every day
update_option( 'updraft_retain', 4 );                   // keep 4 weekly file backups (a month)
update_option( 'updraft_retain_db', 14 );               // keep 14 daily database backups (two weeks)
update_option( 'updraft_service', array( 'googledrive' ) );
update_option( 'updraft_email', get_option( 'admin_email' ) ); // e-mail after each backup
// Do not back up caches and temporary files (they rebuild themselves).
update_option( 'updraft_include_others_exclude', 'upgrade,cache,updraft,backup*,*backups,mysql.sql,debug.log,ewww,litespeed' );
WP_CLI::log( 'UpdraftPlus: database daily (keep 14), files weekly (keep 4), to Google Drive (owner connects it).' );

WP_CLI::success( 'Security and backup settings saved.' );
