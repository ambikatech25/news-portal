<?php
/**
 * Review workflow: editors approve, request changes or reject a story; the writer is told why.
 *
 *   Statuses:  pending (In review) -> publish (Approved)
 *                                  -> portal_changes (Changes requested) -> writer edits -> pending
 *                                  -> portal_rejected (Rejected, final)
 *
 *   POST /wp-json/portal/v1/review/<post-id>  { decision: approve|changes|reject, note }  (Editors, Admins)
 *   GET  /wp-json/portal/v1/review/<post-id>  review history (editors and the story's writer)
 *
 * PublishPress (free) no longer ships custom statuses, so the two extra statuses are registered
 * here. PublishPress still sends the "story submitted for review" e-mail to editors
 * (workflow set up by scripts/setup-review.php); the decision e-mails, which must contain the
 * editor's reason, are sent by this file.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

const PORTAL_REVIEW_LOG_META = 'portal_review_log';

/**
 * Register the two statuses. Both are private: never shown on the website.
 */
function portal_register_review_statuses() {
	register_post_status(
		'portal_changes',
		array(
			'label'                     => _x( 'Changes requested', 'post status', 'portal' ),
			'protected'                 => true,
			'internal'                  => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of posts. */
			'label_count'               => _n_noop( 'Changes requested <span class="count">(%s)</span>', 'Changes requested <span class="count">(%s)</span>', 'portal' ),
		)
	);
	register_post_status(
		'portal_rejected',
		array(
			'label'                     => _x( 'Rejected', 'post status', 'portal' ),
			'protected'                 => true,
			'internal'                  => false,
			'show_in_admin_all_list'    => false,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of posts. */
			'label_count'               => _n_noop( 'Rejected <span class="count">(%s)</span>', 'Rejected <span class="count">(%s)</span>', 'portal' ),
		)
	);
}
add_action( 'init', 'portal_register_review_statuses' );

/**
 * Who may review: Editors and Admins (they can edit other people's posts).
 *
 * @param int $post_id Post.
 * @return bool
 */
function portal_can_review( $post_id ) {
	return current_user_can( 'edit_others_posts' ) && current_user_can( 'edit_post', $post_id );
}

/**
 * A story's review history, oldest first.
 *
 * @param int $post_id Post.
 * @return array<int,array{decision:string,note:string,by:int,at:string}>
 */
function portal_get_review_log( $post_id ) {
	$log = get_post_meta( $post_id, PORTAL_REVIEW_LOG_META, true );
	return is_array( $log ) ? $log : array();
}

/**
 * Make a review decision.
 *
 * @param int    $post_id  Post.
 * @param string $decision approve | changes | reject.
 * @param string $note     Reason (required for changes and reject).
 * @param int    $by       Editor's user ID.
 * @return array|WP_Error { status, log }.
 */
function portal_review_decide( $post_id, $decision, $note, $by ) {
	$post = get_post( $post_id );
	if ( ! $post || 'post' !== $post->post_type ) {
		return new WP_Error( 'portal_not_found', __( 'Story not found.', 'portal' ), array( 'status' => 404 ) );
	}
	if ( ! in_array( $post->post_status, array( 'pending', 'draft', 'portal_changes' ), true ) ) {
		return new WP_Error( 'portal_review_state', __( 'This story is not waiting for review.', 'portal' ), array( 'status' => 409 ) );
	}
	$note = trim( sanitize_textarea_field( $note ) );
	if ( in_array( $decision, array( 'changes', 'reject' ), true ) && mb_strlen( $note ) < 10 ) {
		return new WP_Error( 'portal_review_note', __( 'Please tell the writer why (at least 10 characters).', 'portal' ), array( 'status' => 400 ) );
	}

	$status = array(
		'approve' => 'publish',
		'changes' => 'portal_changes',
		'reject'  => 'portal_rejected',
	)[ $decision ] ?? null;
	if ( ! $status ) {
		return new WP_Error( 'portal_review_decision', __( 'Unknown decision.', 'portal' ), array( 'status' => 400 ) );
	}

	$result = wp_update_post(
		array(
			'ID'          => $post->ID,
			'post_status' => $status,
		),
		true
	);
	if ( is_wp_error( $result ) ) {
		$result->add_data( array( 'status' => 400 ) );
		return $result;
	}
	// Another rule (e.g. Task 14's second approval for sensitive stories) may have kept it from publishing.
	$now = get_post_status( $post->ID );
	if ( 'approve' === $decision && 'publish' !== $now && 'future' !== $now ) {
		return new WP_Error( 'portal_review_blocked', __( 'The story could not be published yet. Check the messages in the editor.', 'portal' ), array( 'status' => 409 ) );
	}

	$log   = portal_get_review_log( $post->ID );
	$log[] = array(
		'decision' => $decision,
		'note'     => $note,
		'by'       => (int) $by,
		'at'       => current_time( 'mysql', true ),
	);
	update_post_meta( $post->ID, PORTAL_REVIEW_LOG_META, $log );

	portal_review_email_writer( $post, $decision, $note );

	/** This action is documented in includes/writing.php (audit log, Task 16). */
	do_action(
		'portal_admin_action',
		array(
			'approve' => 'post_approved',
			'changes' => 'post_changes_requested',
			'reject'  => 'post_rejected',
		)[ $decision ],
		'post',
		$post->ID,
		(int) $by
	);

	return array(
		'status' => $now,
		'log'    => $log,
	);
}

/**
 * Tell the writer about the decision (with the editor's reason).
 *
 * @param WP_Post $post     Post.
 * @param string  $decision Decision.
 * @param string  $note     Reason.
 */
function portal_review_email_writer( WP_Post $post, $decision, $note ) {
	$writer = get_userdata( (int) $post->post_author );
	if ( ! $writer || (int) $writer->ID === get_current_user_id() ) {
		return; // nobody to tell, or the editor reviewed their own story
	}
	$title   = wp_strip_all_tags( get_the_title( $post ) );
	$account = function_exists( 'portal_account_url' ) && portal_account_url() ? add_query_arg( 'section', 'post', portal_account_url() ) : admin_url( 'edit.php' );

	if ( 'approve' === $decision ) {
		/* translators: %s: story title. */
		$subject = sprintf( __( 'Published: %s', 'portal' ), $title );
		/* translators: 1: name, 2: title, 3: link. */
		$body = sprintf( __( "Hello %1\$s,\n\nYour story \"%2\$s\" has been approved and published:\n%3\$s", 'portal' ), $writer->display_name, $title, get_permalink( $post ) );
		if ( '' !== $note ) {
			/* translators: %s: editor's note. */
			$body .= "\n\n" . sprintf( __( "Note from the editor:\n%s", 'portal' ), $note );
		}
	} elseif ( 'changes' === $decision ) {
		/* translators: %s: story title. */
		$subject = sprintf( __( 'Changes requested: %s', 'portal' ), $title );
		/* translators: 1: name, 2: title, 3: note, 4: link. */
		$body = sprintf( __( "Hello %1\$s,\n\nOur editors read your story \"%2\$s\" and ask for some changes before it can be published:\n\n%3\$s\n\nEdit it here (My Account > Posts), then submit it again:\n%4\$s", 'portal' ), $writer->display_name, $title, $note, $account );
	} else {
		/* translators: %s: story title. */
		$subject = sprintf( __( 'Not published: %s', 'portal' ), $title );
		/* translators: 1: name, 2: title, 3: note. */
		$body = sprintf( __( "Hello %1\$s,\n\nThank you for your story \"%2\$s\". We are not able to publish it, for this reason:\n\n%3\$s\n\nYou are welcome to write another story.", 'portal' ), $writer->display_name, $title, $note );
	}
	wp_mail( $writer->user_email, $subject, $body );
}

/**
 * Tell Editors and Admins when a story starts waiting for review (new, or resubmitted after
 * changes), whichever route it came by: website form, block editor or app.
 *
 * Sent here rather than by PublishPress: its notifications rendered the story title as
 * empty, so its "same e-mail within 10 minutes" filter skipped every message after the
 * first and editors would have missed stories (found in Task 13).
 *
 * @param string  $new  New status.
 * @param string  $old  Old status.
 * @param WP_Post $post Post.
 */
function portal_notify_editors_waiting( $new, $old, $post ) {
	if ( 'pending' !== $new || 'pending' === $old || 'post' !== $post->post_type ) {
		return;
	}
	$writer = get_userdata( (int) $post->post_author );
	$title  = wp_strip_all_tags( get_the_title( $post ) );
	/* translators: %s: story title. */
	$subject = sprintf( __( 'Waiting for review: %s', 'portal' ), $title );
	$body    = sprintf(
		/* translators: 1: title, 2: writer, 3: resubmitted note, 4: edit link. */
		__( "A story is waiting for review.\n\nTitle: %1\$s\nWriter: %2\$s%3\$s\n\nOpen it and use the Review panel (Approve / Request changes / Reject):\n%4\$s", 'portal' ),
		$title,
		$writer ? $writer->display_name : '-',
		'portal_changes' === $old ? "\n" . __( '(Resubmitted after changes were requested.)', 'portal' ) : '',
		admin_url( 'post.php?post=' . $post->ID . '&action=edit' )
	);
	$editors = get_users(
		array(
			'role__in' => array( 'editor', 'administrator' ),
			'fields'   => array( 'ID', 'user_email' ),
		)
	);
	foreach ( $editors as $editor ) {
		if ( (int) $editor->ID !== get_current_user_id() ) { // no e-mail about your own action
			wp_mail( $editor->user_email, $subject, $body );
		}
	}
}
add_action( 'transition_post_status', 'portal_notify_editors_waiting', 10, 3 );

/**
 * When the writer edits a story that came back with "Changes requested" and saves it, it goes
 * back into review (Pending) instead of staying in the writer's hands forever.
 *
 * @param array $data    Post data.
 * @param array $postarr Raw data.
 * @return array
 */
function portal_review_resubmit( $data, $postarr ) {
	if ( 'post' !== $data['post_type'] || empty( $postarr['ID'] ) || current_user_can( 'edit_others_posts' ) ) {
		return $data;
	}
	$old = get_post_status( (int) $postarr['ID'] );
	if ( 'portal_changes' === $old && in_array( $data['post_status'], array( 'portal_changes', 'publish', 'pending' ), true ) ) {
		$data['post_status'] = 'pending';
	}
	return $data;
}
add_filter( 'wp_insert_post_data', 'portal_review_resubmit', 98, 2 );

/**
 * Rejected stories are final for the writer: they can no longer edit or resubmit them
 * (editors still can).
 *
 * @param string[] $caps    Required capabilities.
 * @param string   $cap     Capability checked.
 * @param int      $user_id User.
 * @param array    $args    Extra (post ID).
 * @return string[]
 */
function portal_lock_rejected( $caps, $cap, $user_id, $args ) {
	if ( 'edit_post' !== $cap || empty( $args[0] ) ) {
		return $caps;
	}
	$post = get_post( (int) $args[0] );
	if ( $post && 'portal_rejected' === $post->post_status && ! user_can( $user_id, 'edit_others_posts' ) ) {
		return array( 'do_not_allow' );
	}
	return $caps;
}
add_filter( 'map_meta_cap', 'portal_lock_rejected', 10, 4 );

/* ------------------------------------------------------------------ wp-admin: Posts list buttons */

/**
 * Posts list: "Approve" for stories waiting for review (quick path for editors). Changes and
 * rejections need a reason, so those happen in the editor's Review panel.
 *
 * @param array   $actions Row links.
 * @param WP_Post $post    Post.
 * @return array
 */
function portal_review_row_actions( $actions, $post ) {
	if ( 'post' === $post->post_type && 'pending' === $post->post_status && portal_can_review( $post->ID ) ) {
		$url                      = wp_nonce_url( admin_url( 'admin-post.php?action=portal_review_approve&post=' . $post->ID ), 'portal_review_' . $post->ID );
		$actions['portal_review'] = '<a href="' . esc_url( $url ) . '"><strong>' . esc_html__( 'Approve & publish', 'portal' ) . '</strong></a>';
		$actions['portal_review_more'] = '<a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html__( 'Review (changes / reject)', 'portal' ) . '</a>';
	}
	return $actions;
}
add_filter( 'post_row_actions', 'portal_review_row_actions', 10, 2 );

/**
 * Handle the list's "Approve & publish" link.
 */
function portal_review_approve_link() {
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
	check_admin_referer( 'portal_review_' . $post_id );
	if ( ! portal_can_review( $post_id ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'portal' ), 403 );
	}
	$result = portal_review_decide( $post_id, 'approve', '', get_current_user_id() );
	$flag   = is_wp_error( $result ) ? 'portal_review_error=' . rawurlencode( $result->get_error_message() ) : 'portal_review_done=approve';
	wp_safe_redirect( admin_url( 'edit.php?post_status=pending&' . $flag ) );
	exit;
}
add_action( 'admin_post_portal_review_approve', 'portal_review_approve_link' );

/**
 * Notices after a decision from the list.
 */
function portal_review_notices() {
	// phpcs:disable WordPress.Security.NonceVerification -- only chooses which message to show.
	if ( isset( $_GET['portal_review_done'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Approved and published. The writer has been e-mailed.', 'portal' ) . '</p></div>';
	} elseif ( isset( $_GET['portal_review_error'] ) ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['portal_review_error'] ) ) ) . '</p></div>';
	}
	// phpcs:enable
}
add_action( 'admin_notices', 'portal_review_notices' );

/* ------------------------------------------------------------------ Block editor: Review panel */

/**
 * Review panel script (decision buttons for editors, feedback for writers).
 */
function portal_review_editor_assets() {
	$screen = get_current_screen();
	if ( ! $screen || 'post' !== $screen->post_type ) {
		return;
	}
	$path = 'assets/js/review-panel.js';
	wp_enqueue_script( 'portal-review-panel', PORTAL_CORE_URL . $path, array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n' ), (string) filemtime( PORTAL_CORE_DIR . $path ), true );
	wp_localize_script( 'portal-review-panel', 'portalReview', array( 'canReview' => current_user_can( 'edit_others_posts' ) ) );
	wp_set_script_translations( 'portal-review-panel', 'portal' );
}
add_action( 'enqueue_block_editor_assets', 'portal_review_editor_assets' );

/* ------------------------------------------------------------------ API */

/**
 * REST routes.
 */
function portal_register_review_routes() {
	register_rest_route(
		'portal/v1',
		'/review/(?P<id>\d+)',
		array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => static fn( WP_REST_Request $r ) => portal_can_review( (int) $r['id'] ) ? true : new WP_Error( 'rest_forbidden', __( 'Only editors can review stories.', 'portal' ), array( 'status' => rest_authorization_required_code() ) ),
				'args'                => array(
					'decision' => array(
						'type'     => 'string',
						'enum'     => array( 'approve', 'changes', 'reject' ),
						'required' => true,
					),
					'note'     => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					$result = portal_review_decide( (int) $request['id'], $request['decision'], (string) $request['note'], get_current_user_id() );
					return is_wp_error( $result ) ? $result : rest_ensure_response( portal_review_response( (int) $request['id'] ) );
				},
			),
			array(
				'methods'             => WP_REST_Server::READABLE,
				// The story's own writer may read it too (that is how they see the reason).
				'permission_callback' => static function ( WP_REST_Request $r ) {
					$post = get_post( (int) $r['id'] );
					return $post && ( portal_can_review( $post->ID ) || (int) $post->post_author === get_current_user_id() );
				},
				'callback'            => static fn( WP_REST_Request $r ) => rest_ensure_response( portal_review_response( (int) $r['id'] ) ),
			),
		)
	);
}
add_action( 'rest_api_init', 'portal_register_review_routes' );

/**
 * Status + readable history.
 *
 * @param int $post_id Post.
 * @return array
 */
function portal_review_response( $post_id ) {
	$labels = array(
		'approve' => __( 'Approved', 'portal' ),
		'changes' => __( 'Changes requested', 'portal' ),
		'reject'  => __( 'Rejected', 'portal' ),
	);
	$history = array();
	foreach ( portal_get_review_log( $post_id ) as $entry ) {
		$history[] = array(
			'decision' => $entry['decision'],
			'label'    => $labels[ $entry['decision'] ] ?? $entry['decision'],
			'note'     => $entry['note'],
			'by'       => get_the_author_meta( 'display_name', $entry['by'] ),
			'at'       => mysql_to_rfc3339( $entry['at'] ),
		);
	}
	return array(
		'status'  => get_post_status( $post_id ),
		'history' => $history,
	);
}
