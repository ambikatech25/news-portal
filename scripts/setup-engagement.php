<?php
/**
 * Task 12: comment and spam-filter settings. Safe to run again.
 *
 *   docker compose run --rm -T wpcli eval-file /scripts/setup-engagement.php
 *
 * @package Portal
 */

// Only logged-in members comment (CLAUDE.md roles: visitors read, members comment).
update_option( 'comment_registration', 1 );
// A member's first comment waits for approval; once one is approved, the next ones appear at once.
update_option( 'comment_previously_approved', 1 );
update_option( 'comment_moderation', 0 );
// Two or more links in one comment: hold it for review (classic spam pattern).
update_option( 'comment_max_links', 2 );
// Replies, up to 3 levels deep (deeper threads are hard to read on phones).
update_option( 'thread_comments', 1 );
update_option( 'thread_comments_depth', 3 );
// Newest comments last, 30 per page.
update_option( 'page_comments', 1 );
update_option( 'comments_per_page', 30 );
update_option( 'default_comments_page', 'oldest' );
// Comments stay open on new posts (editors can close them per post).
update_option( 'default_comment_status', 'open' );
// No pingbacks/trackbacks: almost all are spam on news sites.
update_option( 'default_ping_status', 'closed' );
update_option( 'default_pingback_flag', 0 );

// Antispam Bee: everything runs on our own server, no data sent to outside services.
$portal_asb = (array) get_option( 'antispam_bee', array() );
$portal_asb = array_merge(
	$portal_asb,
	array(
		'flag_spam'         => 1, // keep spam in the Spam list (admins can rescue mistakes) ...
		'cronjob_enable'    => 1, // ... and delete it after
		'cronjob_interval'  => 30, // 30 days.
		'email_notify'      => 0, // no e-mail for every spam comment
		'no_notice'         => 1, // no "[MARKED AS SPAM]" note added to the comment text
		'dashboard_count'   => 1, // spam count on the dashboard
		'regexp_check'      => 1, // known spam patterns
		'spam_ip'           => 1, // IPs that already sent spam
		'time_check'        => 1, // comments sent faster than a human can type
		'bbcode_check'      => 1, // [url] codes
		'already_commented' => 1, // people with an approved comment skip the checks
		'gravatar_check'    => 0, // off: it would send commenters' e-mail hashes to Gravatar
		'country_code'      => 0, // off: needs an outside service
		'translate_api'     => 0, // off: needs an outside service
		'ignore_pings'      => 1,
		'reasons_enable'    => 0,
	)
);
update_option( 'antispam_bee', $portal_asb );

WP_CLI::success( 'Comment settings (members only, first comment approved by hand) and Antispam Bee saved.' );
