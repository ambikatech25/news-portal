<?php
/**
 * Task 13: review workflow settings. Safe to run again.
 *
 *   docker compose run --rm -T wpcli eval-file /scripts/setup-review.php
 *
 * E-mails about reviews are sent by portal-core (includes/review.php):
 *  - "Waiting for review" to Editors and Admins,
 *  - Approved / Changes requested / Rejected (with the reason) to the writer.
 *
 * PublishPress's own notification rules are switched OFF (set to draft): on this site they
 * rendered story titles as empty, so PublishPress treated every message as a duplicate and
 * skipped it; its default rules also e-mailed the admin on every single save.
 * PublishPress stays active for the Content Board, Calendar and Editorial Comments.
 *
 * @package Portal
 */

if ( ! defined( 'PUBLISHPRESS_NOTIF_POST_TYPE_WORKFLOW' ) ) {
	WP_CLI::error( 'PublishPress must be active.' );
}

$portal_off = 0;
foreach ( get_posts( array( 'post_type' => PUBLISHPRESS_NOTIF_POST_TYPE_WORKFLOW, 'post_status' => 'publish', 'numberposts' => -1 ) ) as $portal_wf ) {
	wp_update_post(
		array(
			'ID'          => $portal_wf->ID,
			'post_status' => 'draft',
		)
	);
	++$portal_off;
}

WP_CLI::success( sprintf( '%d PublishPress notification rules switched off; portal-core sends review e-mails.', $portal_off ) );
