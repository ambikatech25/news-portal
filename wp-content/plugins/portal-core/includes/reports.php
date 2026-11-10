<?php
/**
 * Reports: readers flag a post (or a comment) for the admins.
 *
 *   GET  /wp-json/portal/v1/report-reasons   the reasons a reader can pick
 *   POST /wp-json/portal/v1/reports          { object_type: post|comment, object_id, reason, details }
 *
 * Reports are kept in their own table, wp_portal_reports, not as posts: they are private
 * records that admins count and filter, and must never appear on the site or in search.
 * The admin screen that lists them comes in Task 16.
 *
 * Privacy: for logged-out readers we never store the IP address itself, only a keyed
 * hash of it. That is enough to spot one person sending the same report again, but
 * cannot be turned back into the address.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Most reports one reader may send in an hour.
 */
const PORTAL_REPORTS_PER_HOUR = 10;

/**
 * Full table name.
 *
 * @return string
 */
function portal_reports_table() {
	global $wpdb;
	return $wpdb->prefix . 'portal_reports';
}

/**
 * Create or update the table. Safe to run again (dbDelta only applies differences).
 */
function portal_install_reports_table() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = portal_reports_table();
	$charset = $wpdb->get_charset_collate();
	// dbDelta is picky: two spaces after PRIMARY KEY, one field per line.
	dbDelta(
		"CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		object_type varchar(20) NOT NULL,
		object_id bigint(20) unsigned NOT NULL,
		reason varchar(40) NOT NULL,
		details text NOT NULL,
		reporter_id bigint(20) unsigned NOT NULL DEFAULT 0,
		reporter_hash char(64) NOT NULL,
		status varchar(20) NOT NULL DEFAULT 'open',
		created_at datetime NOT NULL,
		resolved_by bigint(20) unsigned NOT NULL DEFAULT 0,
		resolved_at datetime DEFAULT NULL,
		resolution_note text,
		PRIMARY KEY  (id),
		KEY object (object_type,object_id),
		KEY status (status)
		) {$charset};"
	);
}

/**
 * Reasons a reader can choose: slug => label.
 *
 * @return array<string,string>
 */
function portal_report_reasons() {
	return (array) apply_filters(
		'portal_report_reasons',
		array(
			'false_info'      => __( 'False or misleading information', 'portal' ),
			'victim_identity' => __( 'Identifies a victim, a minor or a witness', 'portal' ),
			'abuse'           => __( 'Hate, abuse or harassment', 'portal' ),
			'privacy'         => __( 'Shares my private information', 'portal' ),
			'copyright'       => __( 'Copied without permission', 'portal' ),
			'spam'            => __( 'Spam or advertising', 'portal' ),
			'other'           => __( 'Something else', 'portal' ),
		)
	);
}

/**
 * Whether the thing being reported exists and is public.
 *
 * @param string $type post|comment.
 * @param int    $id   ID.
 * @return bool
 */
function portal_reportable_exists( $type, $id ) {
	if ( 'post' === $type ) {
		return portal_is_public_post( get_post( $id ) );
	}
	if ( 'comment' === $type ) {
		$comment = get_comment( $id );
		return $comment && '1' === $comment->comment_approved && portal_is_public_post( get_post( $comment->comment_post_ID ) );
	}
	return false;
}

/**
 * Anonymous but stable ID for the person reporting (user ID or IP, hashed with a site secret).
 *
 * @return string 64 hex characters.
 */
function portal_reporter_hash() {
	$user_id = get_current_user_id();
	if ( $user_id ) {
		$who = 'user:' . $user_id;
	} else {
		// Real visitor address, also behind Cloudflare (includes/security.php).
		$who = 'ip:' . ( function_exists( 'portal_client_ip' ) ? portal_client_ip() : '' );
	}
	return hash_hmac( 'sha256', $who, wp_salt( 'auth' ) );
}

/**
 * Store a report.
 *
 * @param string $type    post|comment.
 * @param int    $id      Object ID.
 * @param string $reason  Reason slug.
 * @param string $details Reader's note.
 * @return array|WP_Error { id, duplicate }.
 */
function portal_add_report( $type, $id, $reason, $details ) {
	global $wpdb;
	$table = portal_reports_table();
	$hash  = portal_reporter_hash();

	// Same reader, same item, still open: count it once.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
	$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE object_type = %s AND object_id = %d AND reporter_hash = %s AND status = 'open' LIMIT 1", $type, $id, $hash ) );
	if ( $existing ) {
		return array(
			'id'        => (int) $existing,
			'duplicate' => true,
		);
	}

	$rate_key = 'portal_report_rate_' . substr( $hash, 0, 32 );
	$sent     = (int) get_transient( $rate_key );
	if ( $sent >= PORTAL_REPORTS_PER_HOUR ) {
		return new WP_Error( 'portal_report_limit', __( 'You have sent many reports. Please try again in an hour.', 'portal' ), array( 'status' => 429 ) );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$ok = $wpdb->insert(
		$table,
		array(
			'object_type'   => $type,
			'object_id'     => $id,
			'reason'        => $reason,
			'details'       => $details,
			'reporter_id'   => get_current_user_id(),
			'reporter_hash' => $hash,
			'status'        => 'open',
			'created_at'    => current_time( 'mysql', true ),
		),
		array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
	);
	if ( ! $ok ) {
		return new WP_Error( 'portal_report_failed', __( 'The report could not be saved. Please try again.', 'portal' ), array( 'status' => 500 ) );
	}
	set_transient( $rate_key, $sent + 1, HOUR_IN_SECONDS );

	/**
	 * Fires after a new report is stored (Task 16 uses it for e-mail alerts and the log).
	 */
	do_action( 'portal_report_added', (int) $wpdb->insert_id, $type, $id, $reason );

	return array(
		'id'        => (int) $wpdb->insert_id,
		'duplicate' => false,
	);
}

/**
 * REST routes.
 */
function portal_register_report_routes() {
	register_rest_route(
		'portal/v1',
		'/report-reasons',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => static function () {
				$out = array();
				foreach ( portal_report_reasons() as $slug => $label ) {
					$out[] = array(
						'slug'  => $slug,
						'label' => $label,
					);
				}
				return rest_ensure_response( $out );
			},
		)
	);

	register_rest_route(
		'portal/v1',
		'/reports',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			// Public on purpose: anyone, logged in or not, must be able to complain.
			'permission_callback' => '__return_true',
			'args'                => array(
				'object_type' => array(
					'type'     => 'string',
					'enum'     => array( 'post', 'comment' ),
					'required' => true,
				),
				'object_id'   => array(
					'type'     => 'integer',
					'minimum'  => 1,
					'required' => true,
				),
				'reason'      => array(
					'type'     => 'string',
					'enum'     => array_keys( portal_report_reasons() ),
					'required' => true,
				),
				'details'     => array(
					'type'              => 'string',
					'default'           => '',
					'maxLength'         => 1000,
					// With our own sanitize_callback WordPress skips its schema checks unless
					// asked, so maxLength would be ignored without this line.
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
			),
			'callback'            => static function ( WP_REST_Request $request ) {
				$type = $request['object_type'];
				$id   = (int) $request['object_id'];
				if ( ! portal_reportable_exists( $type, $id ) ) {
					return new WP_Error( 'portal_not_found', __( 'This item cannot be reported.', 'portal' ), array( 'status' => 404 ) );
				}
				$result = portal_add_report( $type, $id, $request['reason'], (string) $request['details'] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return new WP_REST_Response(
					array(
						'received'  => true,
						'duplicate' => $result['duplicate'],
						'message'   => __( 'Thank you. Our team will review this report.', 'portal' ),
					),
					$result['duplicate'] ? 200 : 201
				);
			},
		)
	);
}
add_action( 'rest_api_init', 'portal_register_report_routes' );
