<?php
/**
 * Sensitive stories need two editors (CLAUDE.md publishing rule 2).
 *
 * A story marked Sensitive (portal_sensitive: crime involving minors, sexual offences, ...)
 * can only BECOME published when a second approval is on record from an Editor/Admin who is
 * neither the person publishing nor the writer. So at least two different editors look at it.
 *
 *   POST   /wp-json/portal/v1/review/<id>/second-approval   give my second approval
 *   DELETE /wp-json/portal/v1/review/<id>/second-approval   withdraw it (the approver or an admin)
 *
 * The second approver (portal_second_approver) can never be typed in: it is only ever set to
 * the ID of the editor who presses "Give second approval" themselves (see post-meta.php).
 *
 * Enforced on every route:
 *  - block editor / app (REST): publishing is refused with a clear message;
 *  - anything else (quick links, other plugins, scripts run as a user): the story stays Pending.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

const PORTAL_SECOND_APPROVED_AT_META = 'portal_second_approved_at';

/**
 * Whether a story is marked Sensitive.
 *
 * @param int $post_id Post.
 * @return bool
 */
function portal_is_sensitive( $post_id ) {
	return (bool) get_post_meta( (int) $post_id, 'portal_sensitive', true );
}

/**
 * May $publisher publish this sensitive story now?
 *
 * @param int $post_id      Post.
 * @param int $publisher_id The person trying to publish.
 * @return array{ok:bool,reason:string,message:string,approver:int}
 */
function portal_second_approval_check( $post_id, $publisher_id ) {
	$approver = (int) get_post_meta( (int) $post_id, 'portal_second_approver', true );
	$author   = (int) get_post_field( 'post_author', (int) $post_id );
	$result   = array(
		'ok'       => false,
		'reason'   => '',
		'message'  => '',
		'approver' => $approver,
	);

	if ( ! $approver ) {
		$result['reason']  = 'none';
		$result['message'] = __( 'This story is marked Sensitive. A second editor must give their approval first (Review panel → Give second approval). Then it can be published.', 'portal' );
	} elseif ( ! get_userdata( $approver ) || ! user_can( $approver, PORTAL_CAP_EDITORIAL_FIELDS ) ) {
		$result['reason']  = 'invalid';
		$result['message'] = __( 'The second approval on this Sensitive story is no longer valid (that person is not an editor any more). Another editor must approve it again.', 'portal' );
	} elseif ( (int) $publisher_id === $approver ) {
		$result['reason']  = 'same';
		$result['message'] = __( 'You gave the second approval for this Sensitive story, so a different editor must publish it.', 'portal' );
	} elseif ( $approver === $author ) {
		$result['reason']  = 'author';
		$result['message'] = __( 'The writer of a Sensitive story cannot be its second approver. Another editor must approve it.', 'portal' );
	} else {
		$result['ok'] = true;
	}
	return $result;
}

/**
 * Give the second approval.
 *
 * @param int $post_id Post.
 * @param int $user_id Editor.
 * @return true|WP_Error
 */
function portal_give_second_approval( $post_id, $user_id ) {
	$post = get_post( (int) $post_id );
	if ( ! $post || 'post' !== $post->post_type ) {
		return new WP_Error( 'portal_not_found', __( 'Story not found.', 'portal' ), array( 'status' => 404 ) );
	}
	if ( ! portal_is_sensitive( $post->ID ) ) {
		return new WP_Error( 'portal_not_sensitive', __( 'This story is not marked Sensitive, so it does not need a second approval.', 'portal' ), array( 'status' => 400 ) );
	}
	if ( ! user_can( $user_id, PORTAL_CAP_EDITORIAL_FIELDS ) || ! user_can( $user_id, 'edit_post', $post->ID ) ) {
		return new WP_Error( 'rest_forbidden', __( 'Only editors can give a second approval.', 'portal' ), array( 'status' => 403 ) );
	}
	if ( (int) $post->post_author === (int) $user_id ) {
		return new WP_Error( 'portal_second_own', __( 'You wrote this story, so you cannot be its second approver.', 'portal' ), array( 'status' => 403 ) );
	}
	$current = (int) get_post_meta( $post->ID, 'portal_second_approver', true );
	if ( $current && $current !== (int) $user_id && get_userdata( $current ) && user_can( $current, PORTAL_CAP_EDITORIAL_FIELDS ) ) {
		return new WP_Error( 'portal_second_already', __( 'Another editor has already given the second approval.', 'portal' ), array( 'status' => 409 ) );
	}

	update_post_meta( $post->ID, 'portal_second_approver', (int) $user_id );
	update_post_meta( $post->ID, PORTAL_SECOND_APPROVED_AT_META, current_time( 'mysql', true ) );
	do_action( 'portal_admin_action', 'sensitive_second_approval', 'post', $post->ID, (int) $user_id );
	return true;
}

/**
 * Withdraw a second approval (the approver themselves, or an admin).
 *
 * @param int $post_id Post.
 * @param int $user_id User.
 * @return true|WP_Error
 */
function portal_withdraw_second_approval( $post_id, $user_id ) {
	$current = (int) get_post_meta( (int) $post_id, 'portal_second_approver', true );
	if ( $current !== (int) $user_id && ! user_can( $user_id, 'manage_options' ) ) {
		return new WP_Error( 'rest_forbidden', __( 'Only the editor who approved (or an admin) can withdraw the approval.', 'portal' ), array( 'status' => 403 ) );
	}
	delete_post_meta( (int) $post_id, 'portal_second_approver' );
	delete_post_meta( (int) $post_id, PORTAL_SECOND_APPROVED_AT_META );
	do_action( 'portal_admin_action', 'sensitive_approval_withdrawn', 'post', (int) $post_id, (int) $user_id );
	return true;
}

/* ------------------------------------------------------------------ Enforcement */

/**
 * Block editor / app: refuse to publish a sensitive story without a valid second approval.
 * Checks the Sensitive value being saved in the same request too (ticking Sensitive and
 * pressing Publish in one go must not slip through).
 *
 * @param stdClass|WP_Error $prepared Post about to be saved.
 * @param WP_REST_Request   $request  Request.
 * @return stdClass|WP_Error
 */
function portal_rest_block_sensitive( $prepared, $request ) {
	if ( is_wp_error( $prepared ) ) {
		return $prepared;
	}
	$id     = empty( $prepared->ID ) ? 0 : (int) $prepared->ID;
	$old    = $id ? get_post_status( $id ) : 'new';
	$target = $prepared->post_status ?? $old;
	if ( ! in_array( $target, array( 'publish', 'future' ), true ) || in_array( $old, array( 'publish', 'future' ), true ) ) {
		return $prepared; // only the step INTO published is checked
	}
	$meta      = $request['meta'] ?? array();
	$sensitive = is_array( $meta ) && array_key_exists( 'portal_sensitive', $meta ) ? rest_sanitize_boolean( $meta['portal_sensitive'] ) : ( $id && portal_is_sensitive( $id ) );
	if ( ! $sensitive ) {
		return $prepared;
	}
	$check = $id ? portal_second_approval_check( $id, get_current_user_id() ) : array(
		'ok'      => false,
		'message' => __( 'This story is marked Sensitive. Save it first, then a second editor must give their approval (Review panel) before it can be published.', 'portal' ),
	);
	if ( $check['ok'] ) {
		return $prepared;
	}
	return new WP_Error( 'portal_sensitive_blocked', $check['message'], array( 'status' => 403 ) );
}
add_filter( 'rest_pre_insert_post', 'portal_rest_block_sensitive', 20, 2 );

/**
 * Every other route: a sensitive story without a valid second approval stays Pending.
 *
 * @param array $data    Post data.
 * @param array $postarr Raw data.
 * @return array
 */
function portal_hold_sensitive( $data, $postarr ) {
	if ( 'post' !== $data['post_type'] || empty( $postarr['ID'] ) || ! in_array( $data['post_status'], array( 'publish', 'future' ), true ) ) {
		return $data;
	}
	$old = get_post_status( (int) $postarr['ID'] );
	if ( in_array( $old, array( 'publish', 'future' ), true ) || ! portal_is_sensitive( (int) $postarr['ID'] ) ) {
		return $data;
	}
	$check = portal_second_approval_check( (int) $postarr['ID'], get_current_user_id() );
	if ( ! $check['ok'] ) {
		$data['post_status'] = 'pending';
	}
	return $data;
}
add_filter( 'wp_insert_post_data', 'portal_hold_sensitive', 100, 2 );

/**
 * Switching Sensitive off clears any second approval (it would otherwise "carry over" if the
 * story is marked Sensitive again later) and is recorded for the audit log, so nobody can
 * quietly untick the box to get around the rule.
 *
 * @param int    $meta_id  Meta row.
 * @param int    $post_id  Post.
 * @param string $meta_key Key.
 * @param mixed  $value    New value.
 */
function portal_sensitive_switched( $meta_id, $post_id, $meta_key, $value = '' ) {
	if ( 'portal_sensitive' !== $meta_key || rest_sanitize_boolean( $value ) ) {
		return;
	}
	delete_post_meta( $post_id, 'portal_second_approver' );
	delete_post_meta( $post_id, PORTAL_SECOND_APPROVED_AT_META );
	if ( get_current_user_id() ) {
		do_action( 'portal_admin_action', 'sensitive_cleared', 'post', (int) $post_id, get_current_user_id() );
	}
}
add_action( 'updated_post_meta', 'portal_sensitive_switched', 10, 4 );
add_action( 'deleted_post_meta', static fn( $ids, $post_id, $key ) => portal_sensitive_switched( 0, $post_id, $key, '' ), 10, 3 );

/* ------------------------------------------------------------------ API */

/**
 * REST: give / withdraw the second approval.
 */
function portal_register_sensitive_routes() {
	register_rest_route(
		'portal/v1',
		'/review/(?P<id>\d+)/second-approval',
		array(
			'methods'             => array( 'POST', 'DELETE' ),
			'permission_callback' => static fn() => current_user_can( PORTAL_CAP_EDITORIAL_FIELDS ) ? true : new WP_Error( 'rest_forbidden', __( 'Only editors can give a second approval.', 'portal' ), array( 'status' => rest_authorization_required_code() ) ),
			'callback'            => static function ( WP_REST_Request $request ) {
				$id     = (int) $request['id'];
				$result = 'POST' === $request->get_method()
					? portal_give_second_approval( $id, get_current_user_id() )
					: portal_withdraw_second_approval( $id, get_current_user_id() );
				return is_wp_error( $result ) ? $result : rest_ensure_response( portal_review_response( $id ) );
			},
		)
	);
}
add_action( 'rest_api_init', 'portal_register_sensitive_routes' );

/**
 * Sensitive details for the Review panel and the app (added to portal_review_response()).
 *
 * @param int $post_id Post.
 * @return array
 */
function portal_sensitive_state( $post_id ) {
	$user     = get_current_user_id();
	$check    = portal_second_approval_check( $post_id, $user );
	$approver = $check['approver'];
	$author   = (int) get_post_field( 'post_author', $post_id );
	$is       = portal_is_sensitive( $post_id );
	return array(
		'is'          => $is,
		'approver'    => $approver && get_userdata( $approver ) ? array(
			'id'   => $approver,
			'name' => get_the_author_meta( 'display_name', $approver ),
			'at'   => mysql_to_rfc3339( (string) get_post_meta( $post_id, PORTAL_SECOND_APPROVED_AT_META, true ) ),
		) : null,
		'can_approve' => $is && ! $approver && current_user_can( PORTAL_CAP_EDITORIAL_FIELDS ) && $user !== $author,
		'can_publish' => ! $is || $check['ok'],
		'message'     => $is && ! $check['ok'] ? $check['message'] : '',
	);
}
