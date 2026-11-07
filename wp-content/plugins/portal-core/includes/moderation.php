<?php
/**
 * Moderation (CLAUDE.md rules 4 and 5): the Reports screen, taking content down, and
 * suspending accounts. Every action asks for a reason and is written to the audit log.
 *
 *   wp-admin → Reports              open reports, grouped per story/comment (Editors, Admins)
 *   wp-admin → Users → Suspend      suspend / reinstate an account (Admins)
 *
 * "Take down" never deletes: a story moves to the private status "Taken down" (evidence is kept,
 * and it can be restored); a comment moves to the trash. The writer is told why.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

const PORTAL_BANNED_META = 'portal_banned';

/**
 * "Taken down" status: never shown on the site, kept for evidence.
 */
function portal_register_takedown_status() {
	register_post_status(
		'portal_takedown',
		array(
			'label'                     => _x( 'Taken down', 'post status', 'portal' ),
			'protected'                 => true,
			'show_in_admin_all_list'    => false,
			'show_in_admin_status_list' => true,
			/* translators: %s: number. */
			'label_count'               => _n_noop( 'Taken down <span class="count">(%s)</span>', 'Taken down <span class="count">(%s)</span>', 'portal' ),
		)
	);
}
add_action( 'init', 'portal_register_takedown_status' );

/* ------------------------------------------------------------------ Reports: data */

/**
 * Close the open reports on an item.
 *
 * @param string $type   post | comment.
 * @param int    $id     Object ID.
 * @param string $status taken_down | dismissed.
 * @param string $note   Reason.
 * @param int    $by     Moderator.
 * @return int Reports closed.
 */
function portal_close_reports( $type, $id, $status, $note, $by ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	return (int) $wpdb->update(
		portal_reports_table(),
		array(
			'status'          => $status,
			'resolved_by'     => (int) $by,
			'resolved_at'     => current_time( 'mysql', true ),
			'resolution_note' => $note,
		),
		array(
			'object_type' => $type,
			'object_id'   => (int) $id,
			'status'      => 'open',
		),
		array( '%s', '%d', '%s', '%s' ),
		array( '%s', '%d', '%s' )
	);
}

/**
 * Reports grouped per item, for a status.
 *
 * @param string $status open | taken_down | dismissed.
 * @return array
 */
function portal_get_report_groups( $status = 'open' ) {
	global $wpdb;
	$table = portal_reports_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
	return $wpdb->get_results( $wpdb->prepare( "SELECT object_type, object_id, COUNT(*) AS reports, GROUP_CONCAT(DISTINCT reason) AS reasons, MIN(created_at) AS first_at, MAX(created_at) AS last_at, MAX(resolved_by) AS resolved_by, MAX(resolution_note) AS note FROM {$table} WHERE status = %s GROUP BY object_type, object_id ORDER BY reports DESC, last_at DESC LIMIT 200", $status ) );
}

/**
 * The latest written details for an item's reports.
 *
 * @param string $type Object type.
 * @param int    $id   Object ID.
 * @param string $status Status.
 * @return string[]
 */
function portal_get_report_details( $type, $id, $status ) {
	global $wpdb;
	$table = portal_reports_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
	return array_filter( (array) $wpdb->get_col( $wpdb->prepare( "SELECT details FROM {$table} WHERE object_type = %s AND object_id = %d AND status = %s AND details <> '' ORDER BY id DESC LIMIT 3", $type, $id, $status ) ) );
}

/**
 * Number of open reported items (for the menu bubble).
 *
 * @return int
 */
function portal_open_report_items() {
	global $wpdb;
	$table = portal_reports_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
	return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT object_type, object_id) FROM {$table} WHERE status = 'open'" );
}

/**
 * First report on an item: e-mail the editors (later reports on the same item do not, to
 * avoid a flood when many readers report the same story).
 *
 * @param int    $report_id Report.
 * @param string $type      Object type.
 * @param int    $id        Object ID.
 * @param string $reason    Reason slug.
 */
function portal_notify_first_report( $report_id, $type, $id, $reason ) {
	global $wpdb;
	$table = portal_reports_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
	$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE object_type = %s AND object_id = %d AND status = 'open'", $type, $id ) );
	if ( 1 !== $open ) {
		return;
	}
	$reasons = portal_report_reasons();
	$what    = 'post' === $type ? wp_strip_all_tags( get_the_title( $id ) ) : sprintf( __( 'Comment #%d', 'portal' ), $id );
	/* translators: %s: story title or comment number. */
	$subject = sprintf( __( 'New report: %s', 'portal' ), $what );
	$body    = sprintf(
		/* translators: 1: item, 2: reason, 3: link. */
		__( "A reader reported: %1\$s\nReason: %2\$s\n\nReview it (Take down / Dismiss):\n%3\$s", 'portal' ),
		$what,
		$reasons[ $reason ] ?? $reason,
		admin_url( 'admin.php?page=portal-reports' )
	);
	foreach ( get_users( array( 'role__in' => array( 'editor', 'administrator' ), 'fields' => array( 'user_email' ) ) ) as $editor ) {
		wp_mail( $editor->user_email, $subject, $body );
	}
}
add_action( 'portal_report_added', 'portal_notify_first_report', 20, 4 );

/* ------------------------------------------------------------------ Take down / restore / dismiss */

/**
 * Take a story or comment down.
 *
 * @param string $type   post | comment.
 * @param int    $id     Object ID.
 * @param string $reason Why (shown to the writer, kept in the log).
 * @param int    $by     Moderator.
 * @return true|WP_Error
 */
function portal_take_down( $type, $id, $reason, $by ) {
	$reason = trim( sanitize_textarea_field( $reason ) );
	if ( mb_strlen( $reason ) < 5 ) {
		return new WP_Error( 'portal_takedown_reason', __( 'Please give a reason (at least 5 characters).', 'portal' ), array( 'status' => 400 ) );
	}

	if ( 'post' === $type ) {
		$post = get_post( (int) $id );
		if ( ! $post || 'post' !== $post->post_type || ! user_can( $by, 'edit_post', $post->ID ) || ! user_can( $by, 'edit_others_posts' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You cannot take this story down.', 'portal' ), array( 'status' => 403 ) );
		}
		$old = $post->post_status;
		$res = wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => 'portal_takedown',
			),
			true
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		update_post_meta(
			$post->ID,
			'portal_takedown',
			array(
				'reason' => $reason,
				'by'     => (int) $by,
				'at'     => current_time( 'mysql', true ),
				'was'    => $old,
			)
		);
		$writer = get_userdata( (int) $post->post_author );
		if ( $writer && (int) $writer->ID !== (int) $by ) {
			wp_mail(
				$writer->user_email,
				/* translators: %s: title. */
				sprintf( __( 'Your story was taken down: %s', 'portal' ), wp_strip_all_tags( $post->post_title ) ),
				sprintf(
					/* translators: 1: name, 2: title, 3: reason. */
					__( "Hello %1\$s,\n\nYour story \"%2\$s\" has been removed from the website.\n\nReason:\n%3\$s\n\nIf you think this is a mistake, please contact our Grievance Officer.", 'portal' ),
					$writer->display_name,
					wp_strip_all_tags( $post->post_title ),
					$reason
				)
			);
		}
	} elseif ( 'comment' === $type ) {
		$comment = get_comment( (int) $id );
		if ( ! $comment || ! user_can( $by, 'moderate_comments' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You cannot take this comment down.', 'portal' ), array( 'status' => 403 ) );
		}
		update_comment_meta( $comment->comment_ID, 'portal_takedown_reason', $reason );
		wp_set_comment_status( $comment->comment_ID, 'trash' );
	} else {
		return new WP_Error( 'portal_takedown_type', __( 'Unknown item.', 'portal' ), array( 'status' => 400 ) );
	}

	$closed = portal_close_reports( $type, $id, 'taken_down', $reason, $by );
	do_action(
		'portal_admin_action',
		'post' === $type ? 'post_taken_down' : 'comment_taken_down',
		$type,
		(int) $id,
		(int) $by,
		array(
			'reason'  => $reason,
			'reports' => $closed,
		)
	);
	return true;
}

/**
 * Restore a story that was taken down (back to published; Sensitive rules still apply).
 *
 * @param int $post_id Post.
 * @param int $by      Moderator.
 * @return true|WP_Error
 */
function portal_restore_post( $post_id, $by ) {
	$post = get_post( (int) $post_id );
	if ( ! $post || 'portal_takedown' !== $post->post_status || ! user_can( $by, 'edit_others_posts' ) ) {
		return new WP_Error( 'portal_restore', __( 'This story is not taken down, or you cannot restore it.', 'portal' ), array( 'status' => 400 ) );
	}
	$res = wp_update_post(
		array(
			'ID'          => $post->ID,
			'post_status' => 'publish',
		),
		true
	);
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	if ( 'publish' !== get_post_status( $post->ID ) ) {
		return new WP_Error( 'portal_restore_blocked', __( 'The story could not be published again (it may need a second approval as a Sensitive story).', 'portal' ), array( 'status' => 409 ) );
	}
	delete_post_meta( $post->ID, 'portal_takedown' );
	do_action( 'portal_admin_action', 'post_restored', 'post', $post->ID, (int) $by );
	return true;
}

/**
 * Dismiss the open reports on an item (content stays up).
 *
 * @param string $type Object type.
 * @param int    $id   Object ID.
 * @param string $note Why.
 * @param int    $by   Moderator.
 * @return true|WP_Error
 */
function portal_dismiss_reports( $type, $id, $note, $by ) {
	$note   = trim( sanitize_textarea_field( $note ) );
	$closed = portal_close_reports( $type, $id, 'dismissed', $note, $by );
	if ( ! $closed ) {
		return new WP_Error( 'portal_dismiss', __( 'There are no open reports for this item.', 'portal' ), array( 'status' => 400 ) );
	}
	do_action(
		'portal_admin_action',
		'reports_dismissed',
		$type,
		(int) $id,
		(int) $by,
		array(
			'note'    => $note,
			'reports' => $closed,
		)
	);
	return true;
}

/* ------------------------------------------------------------------ Suspending accounts */

/**
 * Whether an account is suspended.
 *
 * @param int $user_id User.
 * @return bool
 */
function portal_is_banned( $user_id ) {
	return ! empty( get_user_meta( (int) $user_id, PORTAL_BANNED_META, true ) );
}

/**
 * Suspend an account: no login, and any open sessions end now.
 *
 * @param int    $user_id User to suspend.
 * @param string $reason  Why.
 * @param int    $by      Admin.
 * @return true|WP_Error
 */
function portal_ban_user( $user_id, $reason, $by ) {
	$user   = get_userdata( (int) $user_id );
	$reason = trim( sanitize_textarea_field( $reason ) );
	if ( ! $user || ! user_can( $by, 'promote_users' ) ) {
		return new WP_Error( 'rest_forbidden', __( 'Only administrators can suspend accounts.', 'portal' ), array( 'status' => 403 ) );
	}
	if ( (int) $user->ID === (int) $by || user_can( $user, 'manage_options' ) ) {
		return new WP_Error( 'portal_ban_admin', __( 'Administrators (and you yourself) cannot be suspended here.', 'portal' ), array( 'status' => 400 ) );
	}
	if ( mb_strlen( $reason ) < 5 ) {
		return new WP_Error( 'portal_ban_reason', __( 'Please give a reason (at least 5 characters).', 'portal' ), array( 'status' => 400 ) );
	}
	update_user_meta(
		$user->ID,
		PORTAL_BANNED_META,
		array(
			'reason' => $reason,
			'by'     => (int) $by,
			'at'     => current_time( 'mysql', true ),
		)
	);
	WP_Session_Tokens::get_instance( $user->ID )->destroy_all(); // log them out everywhere, now
	do_action( 'portal_admin_action', 'user_banned', 'user', $user->ID, (int) $by, array( 'reason' => $reason ) );
	return true;
}

/**
 * Reinstate a suspended account.
 *
 * @param int $user_id User.
 * @param int $by      Admin.
 * @return true|WP_Error
 */
function portal_unban_user( $user_id, $by ) {
	if ( ! user_can( $by, 'promote_users' ) || ! portal_is_banned( $user_id ) ) {
		return new WP_Error( 'portal_unban', __( 'This account is not suspended, or you cannot reinstate it.', 'portal' ), array( 'status' => 400 ) );
	}
	delete_user_meta( (int) $user_id, PORTAL_BANNED_META );
	do_action( 'portal_admin_action', 'user_unbanned', 'user', (int) $user_id, (int) $by );
	return true;
}

/**
 * Suspended accounts cannot log in (password or Google).
 *
 * @param WP_User|WP_Error|null $user User being logged in.
 * @return WP_User|WP_Error|null
 */
function portal_block_banned_login( $user ) {
	if ( $user instanceof WP_User && portal_is_banned( $user->ID ) ) {
		return new WP_Error( 'portal_banned', __( '<strong>Error:</strong> This account has been suspended. If you think this is a mistake, please contact our Grievance Officer.', 'portal' ) );
	}
	return $user;
}
add_filter( 'authenticate', 'portal_block_banned_login', 100 );

/**
 * A suspended account is treated as logged out everywhere (pages, REST API, app), even if a
 * login cookie or token still exists somewhere (e.g. a Google login made before the suspension).
 *
 * @param int|false $user_id Detected user.
 * @return int|false
 */
function portal_ignore_banned_user( $user_id ) {
	return ( $user_id && portal_is_banned( (int) $user_id ) ) ? 0 : $user_id;
}
add_filter( 'determine_current_user', 'portal_ignore_banned_user', 100 );

/* ------------------------------------------------------------------ wp-admin screens */

/**
 * Menu: Reports (with the number of open items) and Audit log.
 */
function portal_moderation_menu() {
	$open  = portal_open_report_items();
	$label = __( 'Reports', 'portal' ) . ( $open ? ' <span class="awaiting-mod count-' . $open . '"><span class="pending-count">' . number_format_i18n( $open ) . '</span></span>' : '' );
	add_menu_page( __( 'Reports', 'portal' ), $label, 'edit_others_posts', 'portal-reports', 'portal_reports_page', 'dashicons-flag', 26 );
	add_submenu_page( 'portal-reports', __( 'Reports', 'portal' ), __( 'Reports', 'portal' ), 'edit_others_posts', 'portal-reports', 'portal_reports_page' );
	add_submenu_page( 'portal-reports', __( 'Audit log', 'portal' ), __( 'Audit log', 'portal' ), 'manage_options', 'portal-audit-log', 'portal_audit_log_page' );
	// Hidden page for the Suspend form (opened from the Users list).
	add_submenu_page( '', __( 'Suspend account', 'portal' ), '', 'promote_users', 'portal-suspend', 'portal_suspend_page' );
}
add_action( 'admin_menu', 'portal_moderation_menu' );

/**
 * Link and short text for a reported item.
 *
 * @param string $type Object type.
 * @param int    $id   Object ID.
 * @return string HTML.
 */
function portal_report_item_html( $type, $id ) {
	if ( 'post' === $type ) {
		$post = get_post( $id );
		if ( ! $post ) {
			return esc_html( sprintf( __( 'Story #%d (deleted)', 'portal' ), $id ) );
		}
		$view = 'publish' === $post->post_status ? ' · <a href="' . esc_url( get_permalink( $post ) ) . '" target="_blank">' . esc_html__( 'View', 'portal' ) . '</a>' : '';
		return '<strong><a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></strong><br><span class="description">' . esc_html__( 'Story', 'portal' ) . ' · ' . esc_html( get_post_status_object( $post->post_status )->label ?? $post->post_status ) . '</span>' . $view;
	}
	$comment = get_comment( $id );
	if ( ! $comment ) {
		return esc_html( sprintf( __( 'Comment #%d (deleted)', 'portal' ), $id ) );
	}
	return '<strong>' . esc_html__( 'Comment by', 'portal' ) . ' ' . esc_html( $comment->comment_author ) . '</strong>: “' . esc_html( wp_trim_words( $comment->comment_content, 25 ) ) . '”<br><span class="description">' . esc_html__( 'on', 'portal' ) . ' <a href="' . esc_url( get_permalink( $comment->comment_post_ID ) ) . '" target="_blank">' . esc_html( get_the_title( $comment->comment_post_ID ) ) . '</a></span>';
}

/**
 * The Reports screen.
 */
function portal_reports_page() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( esc_html__( 'You are not allowed to see reports.', 'portal' ), 403 );
	}
	// phpcs:disable WordPress.Security.NonceVerification -- read-only view choices.
	$status = isset( $_GET['status'] ) && in_array( $_GET['status'], array( 'open', 'taken_down', 'dismissed' ), true ) ? sanitize_key( $_GET['status'] ) : 'open';
	$done   = isset( $_GET['portal_done'] ) ? sanitize_key( wp_unslash( $_GET['portal_done'] ) ) : '';
	$error  = isset( $_GET['portal_error'] ) ? sanitize_text_field( wp_unslash( $_GET['portal_error'] ) ) : '';
	// phpcs:enable
	$groups  = portal_get_report_groups( $status );
	$reasons = portal_report_reasons();
	$tabs    = array(
		'open'       => __( 'Open', 'portal' ),
		'taken_down' => __( 'Taken down', 'portal' ),
		'dismissed'  => __( 'Dismissed', 'portal' ),
	);
	$done_msg = array(
		'takedown' => __( 'Taken down. The reports are closed and the action is in the audit log.', 'portal' ),
		'dismiss'  => __( 'Reports dismissed. The content stays up; the decision is in the audit log.', 'portal' ),
		'restore'  => __( 'Story restored and published again.', 'portal' ),
	);
	?>
	<div class="wrap portal-reports">
		<h1><?php esc_html_e( 'Reports', 'portal' ); ?></h1>
		<p><?php esc_html_e( 'What readers reported. Each row is one story or comment, with all its reports. Every decision needs a reason and is written to the audit log.', 'portal' ); ?></p>
		<?php if ( isset( $done_msg[ $done ] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $done_msg[ $done ] ); ?></p></div>
		<?php endif; ?>
		<?php if ( $error ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endif; ?>
		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<a class="nav-tab <?php echo $key === $status ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=portal-reports&status=' . $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<table class="widefat striped" style="margin-top:12px">
			<thead><tr>
				<th style="width:34%"><?php esc_html_e( 'Reported item', 'portal' ); ?></th>
				<th><?php esc_html_e( 'Reports', 'portal' ); ?></th>
				<th style="width:28%"><?php esc_html_e( 'Reasons and readers’ notes', 'portal' ); ?></th>
				<th style="width:26%"><?php echo esc_html( 'open' === $status ? __( 'Decide', 'portal' ) : __( 'Decision', 'portal' ) ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $groups ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'Nothing here.', 'portal' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $groups as $g ) : ?>
				<tr class="portal-report-row" data-type="<?php echo esc_attr( $g->object_type ); ?>" data-id="<?php echo esc_attr( $g->object_id ); ?>">
					<td><?php echo wp_kses_post( portal_report_item_html( $g->object_type, (int) $g->object_id ) ); ?></td>
					<td><strong><?php echo esc_html( number_format_i18n( $g->reports ) ); ?></strong><br><span class="description"><?php echo esc_html( $g->last_at ); ?> UTC</span></td>
					<td>
						<?php
						$labels = array_map( static fn( $r ) => $reasons[ $r ] ?? $r, explode( ',', (string) $g->reasons ) );
						echo esc_html( implode( ' · ', $labels ) );
						foreach ( portal_get_report_details( $g->object_type, (int) $g->object_id, $status ) as $detail ) {
							echo '<br><em>“' . esc_html( wp_trim_words( $detail, 30 ) ) . '”</em>';
						}
						?>
					</td>
					<td>
						<?php if ( 'open' === $status ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="portal_report_decide">
								<input type="hidden" name="object_type" value="<?php echo esc_attr( $g->object_type ); ?>">
								<input type="hidden" name="object_id" value="<?php echo esc_attr( $g->object_id ); ?>">
								<?php wp_nonce_field( 'portal_report_decide_' . $g->object_type . '_' . $g->object_id ); ?>
								<textarea name="reason" rows="2" style="width:100%" placeholder="<?php esc_attr_e( 'Reason (required, kept in the audit log)', 'portal' ); ?>" required minlength="5"></textarea>
								<p style="margin:6px 0 0;display:flex;gap:6px;flex-wrap:wrap">
									<button class="button button-primary" name="decision" value="takedown"><?php esc_html_e( 'Take down', 'portal' ); ?></button>
									<button class="button" name="decision" value="dismiss"><?php esc_html_e( 'Dismiss (keep up)', 'portal' ); ?></button>
								</p>
							</form>
						<?php else : ?>
							<?php echo esc_html( portal_audit_actor_label( (int) $g->resolved_by ) ); ?>:
							<em><?php echo esc_html( (string) $g->note ); ?></em>
							<?php if ( 'taken_down' === $status && 'post' === $g->object_type && 'portal_takedown' === get_post_status( (int) $g->object_id ) ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:6px">
									<input type="hidden" name="action" value="portal_report_decide">
									<input type="hidden" name="object_type" value="post">
									<input type="hidden" name="object_id" value="<?php echo esc_attr( $g->object_id ); ?>">
									<?php wp_nonce_field( 'portal_report_decide_post_' . $g->object_id ); ?>
									<button class="button" name="decision" value="restore"><?php esc_html_e( 'Restore story', 'portal' ); ?></button>
								</form>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * Handle Take down / Dismiss / Restore from the Reports screen.
 */
function portal_report_decide_post() {
	$type = isset( $_POST['object_type'] ) && 'comment' === $_POST['object_type'] ? 'comment' : 'post';
	$id   = isset( $_POST['object_id'] ) ? absint( $_POST['object_id'] ) : 0;
	check_admin_referer( 'portal_report_decide_' . $type . '_' . $id );
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'portal' ), 403 );
	}
	$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
	$reason   = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
	$by       = get_current_user_id();

	if ( 'takedown' === $decision ) {
		$result = portal_take_down( $type, $id, $reason, $by );
	} elseif ( 'dismiss' === $decision ) {
		$result = mb_strlen( trim( $reason ) ) < 5
			? new WP_Error( 'portal_dismiss_reason', __( 'Please give a reason (at least 5 characters).', 'portal' ) )
			: portal_dismiss_reports( $type, $id, $reason, $by );
	} elseif ( 'restore' === $decision ) {
		$result = portal_restore_post( $id, $by );
	} else {
		$result = new WP_Error( 'portal_decision', __( 'Unknown decision.', 'portal' ) );
	}

	$args = is_wp_error( $result ) ? array( 'portal_error' => rawurlencode( $result->get_error_message() ) ) : array( 'portal_done' => $decision );
	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=portal-reports' ) ) );
	exit;
}
add_action( 'admin_post_portal_report_decide', 'portal_report_decide_post' );

/* ------------------------------------------------------------------ Users list: Suspend */

/**
 * Users list: "Suspend" / "Reinstate" links (admins).
 *
 * @param array   $actions Links.
 * @param WP_User $user    User.
 * @return array
 */
function portal_suspend_row_action( $actions, $user ) {
	if ( ! current_user_can( 'promote_users' ) || $user->ID === get_current_user_id() || user_can( $user, 'manage_options' ) ) {
		return $actions;
	}
	if ( portal_is_banned( $user->ID ) ) {
		$url                      = wp_nonce_url( admin_url( 'admin-post.php?action=portal_unban&user=' . $user->ID ), 'portal_unban_' . $user->ID );
		$actions['portal_unban'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Reinstate', 'portal' ) . '</a>';
	} else {
		$actions['portal_ban'] = '<a href="' . esc_url( admin_url( 'admin.php?page=portal-suspend&user=' . $user->ID ) ) . '" style="color:#b32d2e">' . esc_html__( 'Suspend', 'portal' ) . '</a>';
	}
	return $actions;
}
add_filter( 'user_row_actions', 'portal_suspend_row_action', 10, 2 );

/**
 * Users list: show "Suspended" next to the name.
 *
 * @param string $output  Column output.
 * @param string $column  Column.
 * @param int    $user_id User.
 * @return string
 */
function portal_suspended_marker( $output, $column, $user_id ) {
	if ( 'portal_writer_request' === $column && portal_is_banned( $user_id ) ) {
		$ban     = get_user_meta( $user_id, PORTAL_BANNED_META, true );
		$output .= '<br><strong style="color:#b32d2e">' . esc_html__( 'Suspended', 'portal' ) . '</strong> <span class="description">' . esc_html( wp_trim_words( $ban['reason'] ?? '', 12 ) ) . '</span>';
	}
	return $output;
}
add_filter( 'manage_users_custom_column', 'portal_suspended_marker', 20, 3 );

/**
 * The Suspend form.
 */
function portal_suspend_page() {
	$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- shows a form only.
	$user    = get_userdata( $user_id );
	if ( ! $user || ! current_user_can( 'promote_users' ) ) {
		wp_die( esc_html__( 'User not found.', 'portal' ) );
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html( sprintf( __( 'Suspend %s', 'portal' ), $user->display_name ) ); ?></h1>
		<p><?php esc_html_e( 'A suspended account cannot log in (with a password or Google), and is logged out everywhere at once. Their stories and comments stay as they are; take them down separately if needed.', 'portal' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="portal_ban">
			<input type="hidden" name="user" value="<?php echo esc_attr( (string) $user->ID ); ?>">
			<?php wp_nonce_field( 'portal_ban_' . $user->ID ); ?>
			<p><label for="portal-ban-reason"><strong><?php esc_html_e( 'Reason (kept in the audit log)', 'portal' ); ?></strong></label><br>
			<textarea id="portal-ban-reason" name="reason" rows="3" cols="60" required minlength="5"></textarea></p>
			<?php submit_button( __( 'Suspend account', 'portal' ), 'primary' ); ?>
		</form>
	</div>
	<?php
}

/**
 * Handle Suspend.
 */
function portal_ban_post() {
	$user_id = isset( $_POST['user'] ) ? absint( $_POST['user'] ) : 0;
	check_admin_referer( 'portal_ban_' . $user_id );
	$result = portal_ban_user( $user_id, isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '', get_current_user_id() );
	if ( is_wp_error( $result ) ) {
		wp_die( esc_html( $result->get_error_message() ), '', array( 'back_link' => true ) );
	}
	wp_safe_redirect( admin_url( 'users.php?portal_suspended=1' ) );
	exit;
}
add_action( 'admin_post_portal_ban', 'portal_ban_post' );

/**
 * Handle Reinstate.
 */
function portal_unban_get() {
	$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
	check_admin_referer( 'portal_unban_' . $user_id );
	$result = portal_unban_user( $user_id, get_current_user_id() );
	if ( is_wp_error( $result ) ) {
		wp_die( esc_html( $result->get_error_message() ), '', array( 'back_link' => true ) );
	}
	wp_safe_redirect( admin_url( 'users.php?portal_reinstated=1' ) );
	exit;
}
add_action( 'admin_post_portal_unban', 'portal_unban_get' );
