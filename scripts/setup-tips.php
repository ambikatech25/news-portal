<?php
/**
 * Task 17D: the Send news page (/send-news/). Safe to run again.
 *
 *   docker compose run --rm -T wpcli eval-file /scripts/setup-tips.php
 *
 * The form, rules and editors' queue come from portal-core (includes/tips.php); the theme adds
 * "Send news" to the header and the My tips tab (inc/tips.php).
 *
 * @package Portal
 */

$portal_page = get_page_by_path( 'send-news' );
$portal_body = "<!-- wp:paragraph -->\n<p>Seen something people in your area should know about: an accident, a road closed, a water or power problem, a scam, a local achievement? Tell us. Our editors check every tip before anything is published.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[portal_tip_form]\n<!-- /wp:shortcode -->";

if ( ! $portal_page ) {
	$portal_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Send news',
			'post_name'    => 'send-news',
			'post_content' => $portal_body,
		),
		true
	);
	if ( is_wp_error( $portal_id ) ) {
		WP_CLI::error( $portal_id );
	}
	WP_CLI::log( 'Created the Send news page.' );
} else {
	$portal_id = $portal_page->ID;
	if ( false === strpos( $portal_page->post_content, '[portal_tip_form]' ) ) {
		wp_update_post(
			array(
				'ID'           => $portal_id,
				'post_content' => $portal_page->post_content . "\n\n<!-- wp:shortcode -->\n[portal_tip_form]\n<!-- /wp:shortcode -->",
			)
		);
		WP_CLI::log( 'Added the form to the existing Send news page.' );
	}
	if ( 'publish' !== $portal_page->post_status ) {
		wp_update_post(
			array(
				'ID'          => $portal_id,
				'post_status' => 'publish',
			)
		);
	}
}

// Daily privacy clean-up (phone numbers and declined photos after 90 days).
if ( ! wp_next_scheduled( 'portal_tips_cleanup' ) ) {
	wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'portal_tips_cleanup' );
}

WP_CLI::success( 'Send news page ready: ' . get_permalink( $portal_id ) );
