<?php
/**
 * Audit log (CLAUDE.md publishing rule 5): every admin action is recorded - who did what, to
 * what, when and why. Admins read it in wp-admin → Reports → Audit log, and can download it as
 * CSV (e.g. for a police or court request).
 *
 * What is recorded:
 *  - anything announced with do_action( 'portal_admin_action', $action, $type, $id, $by, $details )
 *    by portal-core: review decisions, writer requests, sensitive approvals, takedowns, bans ...
 *  - reports and grievances received;
 *  - WordPress's own sensitive actions: posts trashed/deleted, comments approved / marked spam /
 *    trashed by a moderator, role changes, deleted accounts.
 *
 * The log cannot be edited or deleted from the website: there is no edit or delete button,
 * on purpose. A record that admins can quietly change is not worth much as evidence.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Table name.
 *
 * @return string
 */
function portal_audit_table() {
	global $wpdb;
	return $wpdb->prefix . 'portal_audit_log';
}

/**
 * Create or update the table (safe to run again).
 */
function portal_install_audit_table() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table = portal_audit_table();
	dbDelta(
		"CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		action varchar(60) NOT NULL,
		object_type varchar(20) NOT NULL,
		object_id bigint(20) unsigned NOT NULL DEFAULT 0,
		actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
		details text NOT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY action (action),
		KEY object (object_type,object_id),
		KEY actor (actor_id),
		KEY created_at (created_at)
		) {$wpdb->get_charset_collate()};"
	);
}

/**
 * Human labels for actions (unknown actions are shown as they are).
 *
 * @return array<string,string>
 */
function portal_audit_labels() {
	return array(
		'report_received'              => __( 'Report received', 'portal' ),
		'reports_dismissed'            => __( 'Reports dismissed', 'portal' ),
		'post_taken_down'              => __( 'Story taken down', 'portal' ),
		'post_restored'                => __( 'Story restored', 'portal' ),
		'comment_taken_down'           => __( 'Comment taken down', 'portal' ),
		'user_banned'                  => __( 'Account suspended', 'portal' ),
		'user_unbanned'                => __( 'Account reinstated', 'portal' ),
		'grievance_received'           => __( 'Grievance received', 'portal' ),
		'post_approved'                => __( 'Story approved', 'portal' ),
		'post_changes_requested'       => __( 'Changes requested', 'portal' ),
		'post_rejected'                => __( 'Story rejected', 'portal' ),
		'writer_approved'              => __( 'Writer request approved', 'portal' ),
		'writer_rejected'              => __( 'Writer request turned down', 'portal' ),
		'sensitive_second_approval'    => __( 'Sensitive: second approval', 'portal' ),
		'sensitive_approval_withdrawn' => __( 'Sensitive: approval withdrawn', 'portal' ),
		'sensitive_cleared'            => __( 'Sensitive flag removed', 'portal' ),
		'post_trashed'                 => __( 'Story moved to trash', 'portal' ),
		'post_deleted'                 => __( 'Story deleted permanently', 'portal' ),
		'comment_approved'             => __( 'Comment approved', 'portal' ),
		'comment_spammed'              => __( 'Comment marked spam', 'portal' ),
		'comment_trashed'              => __( 'Comment moved to trash', 'portal' ),
		'user_role_changed'            => __( 'Role changed', 'portal' ),
		'user_deleted'                 => __( 'Account deleted', 'portal' ),
	);
}

/**
 * Write one entry.
 *
 * @param string $action  Action key.
 * @param string $type    Object type: post, comment, user, report, grievance.
 * @param int    $id      Object ID.
 * @param int    $actor   Who did it (0 = a visitor or the system).
 * @param array  $details Extra facts (reason, title at the time, old/new role ...).
 */
function portal_log_action( $action, $type, $id, $actor = 0, $details = array() ) {
	global $wpdb;
	// Keep a copy of the title: the story may be renamed or deleted later.
	if ( 'post' === $type && empty( $details['title'] ) && get_post( (int) $id ) ) {
		$details['title'] = get_the_title( (int) $id );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$wpdb->insert(
		portal_audit_table(),
		array(
			'action'      => sanitize_key( $action ),
			'object_type' => sanitize_key( $type ),
			'object_id'   => (int) $id,
			'actor_id'    => (int) $actor,
			'details'     => wp_json_encode( $details ),
			'created_at'  => current_time( 'mysql', true ),
		),
		array( '%s', '%s', '%d', '%d', '%s', '%s' )
	);
}

/* ------------------------------------------------------------------ What gets recorded */

add_action(
	'portal_admin_action',
	static function ( $action, $type, $id, $by, $details = array() ) {
		portal_log_action( $action, $type, $id, $by, (array) $details );
	},
	10,
	5
);

add_action(
	'portal_report_added',
	static function ( $report_id, $type, $id, $reason ) {
		portal_log_action(
			'report_received',
			$type,
			$id,
			get_current_user_id(),
			array(
				'report' => (int) $report_id,
				'reason' => $reason,
			)
		);
	},
	10,
	4
);

/**
 * Posts moved to the trash or deleted (by a logged-in person: system clean-ups are not admin actions).
 */
add_action(
	'trashed_post',
	static function ( $post_id ) {
		if ( get_current_user_id() && 'post' === get_post_type( $post_id ) ) {
			portal_log_action( 'post_trashed', 'post', $post_id, get_current_user_id() );
		}
	}
);
add_action(
	'before_delete_post',
	static function ( $post_id, $post = null ) {
		if ( get_current_user_id() && $post && 'post' === $post->post_type ) {
			portal_log_action( 'post_deleted', 'post', $post_id, get_current_user_id(), array( 'title' => $post->post_title ) );
		}
	},
	10,
	2
);

/**
 * Comments moderated by someone else than the commenter.
 */
add_action(
	'transition_comment_status',
	static function ( $new, $old, $comment ) {
		$actions = array(
			'approved' => 'comment_approved',
			'spam'     => 'comment_spammed',
			'trash'    => 'comment_trashed',
		);
		$user    = get_current_user_id();
		if ( isset( $actions[ $new ] ) && $user && (int) $comment->user_id !== $user && current_user_can( 'moderate_comments' ) ) {
			portal_log_action(
				$actions[ $new ],
				'comment',
				(int) $comment->comment_ID,
				$user,
				array(
					'post' => (int) $comment->comment_post_ID,
					'from' => $old,
				)
			);
		}
	},
	10,
	3
);

/**
 * Role changes (not the first role given when an account is created).
 */
add_action(
	'set_user_role',
	static function ( $user_id, $role, $old_roles ) {
		if ( $old_roles && array( $role ) !== array_values( $old_roles ) ) {
			portal_log_action(
				'user_role_changed',
				'user',
				$user_id,
				get_current_user_id(),
				array(
					'from' => implode( ',', $old_roles ),
					'to'   => $role,
				)
			);
		}
	},
	10,
	3
);

add_action(
	'delete_user',
	static function ( $user_id ) {
		$user = get_userdata( $user_id );
		portal_log_action( 'user_deleted', 'user', $user_id, get_current_user_id(), array( 'name' => $user ? $user->display_name : '' ) );
	}
);

/* ------------------------------------------------------------------ Reading the log */

/**
 * Query the log.
 *
 * @param array $args { action, object_type, object_id, actor, limit, offset }.
 * @return array{rows:array,total:int}
 */
function portal_get_audit_log( $args = array() ) {
	global $wpdb;
	$table  = portal_audit_table();
	$where  = array( '1=1' );
	$values = array();
	foreach ( array( 'action' => '%s', 'object_type' => '%s', 'object_id' => '%d' ) as $col => $fmt ) {
		if ( ! empty( $args[ $col ] ) ) {
			$where[]  = "$col = $fmt";
			$values[] = $args[ $col ];
		}
	}
	if ( ! empty( $args['actor'] ) ) {
		$where[]  = 'actor_id = %d';
		$values[] = (int) $args['actor'];
	}
	$sql_where = implode( ' AND ', $where );
	$limit     = max( 1, min( 5000, (int) ( $args['limit'] ?? 50 ) ) );
	$offset    = max( 0, (int) ( $args['offset'] ?? 0 ) );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- custom table, values prepared below.
	$total = (int) $wpdb->get_var( $values ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}", $values ) : "SELECT COUNT(*) FROM {$table}" );
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $values, array( $limit, $offset ) ) ) );
	// phpcs:enable
	return array(
		'rows'  => $rows,
		'total' => $total,
	);
}

/**
 * One readable line for an entry's object ("Story: Title", "Comment #12", "User: Name").
 *
 * @param object $row Log row.
 * @return string Plain text.
 */
function portal_audit_object_label( $row ) {
	$details = (array) json_decode( (string) $row->details, true );
	switch ( $row->object_type ) {
		case 'post':
			return sprintf( 'Story #%d: %s', $row->object_id, $details['title'] ?? get_the_title( (int) $row->object_id ) );
		case 'user':
			$user = get_userdata( (int) $row->object_id );
			return sprintf( 'User #%d: %s', $row->object_id, $user ? $user->display_name : ( $details['name'] ?? '-' ) );
		case 'comment':
			return sprintf( 'Comment #%d', $row->object_id );
		case 'grievance':
			return sprintf( 'Grievance GRV-%d', $row->object_id );
		default:
			return sprintf( '%s #%d', $row->object_type, $row->object_id );
	}
}

/**
 * Details as short readable text ("reason: ..., from: ...").
 *
 * @param object $row Log row.
 * @return string
 */
function portal_audit_details_text( $row ) {
	$details = (array) json_decode( (string) $row->details, true );
	unset( $details['title'], $details['name'] );
	$parts = array();
	foreach ( $details as $key => $value ) {
		$parts[] = $key . ': ' . ( is_scalar( $value ) ? $value : wp_json_encode( $value ) );
	}
	return implode( '; ', $parts );
}

/**
 * Who did it, as text.
 *
 * @param int $actor User ID.
 * @return string
 */
function portal_audit_actor_label( $actor ) {
	if ( ! $actor ) {
		return __( 'Visitor / system', 'portal' );
	}
	$user = get_userdata( (int) $actor );
	return $user ? $user->display_name . ' (#' . $user->ID . ')' : '#' . (int) $actor;
}

/**
 * Audit log screen (admins only).
 */
function portal_audit_log_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Only administrators can read the audit log.', 'portal' ), 403 );
	}
	// phpcs:disable WordPress.Security.NonceVerification -- read-only filters.
	$action = isset( $_GET['log_action'] ) ? sanitize_key( wp_unslash( $_GET['log_action'] ) ) : '';
	$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
	// phpcs:enable
	$per    = 50;
	$data   = portal_get_audit_log(
		array(
			'action' => $action,
			'limit'  => $per,
			'offset' => ( $paged - 1 ) * $per,
		)
	);
	$labels = portal_audit_labels();
	$csv    = wp_nonce_url( admin_url( 'admin-post.php?action=portal_audit_csv&log_action=' . rawurlencode( $action ) ), 'portal_audit_csv' );
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Audit log', 'portal' ); ?></h1>
		<a class="page-title-action" href="<?php echo esc_url( $csv ); ?>"><?php esc_html_e( 'Download CSV', 'portal' ); ?></a>
		<p><?php esc_html_e( 'Every admin action, newest first. Entries cannot be changed or deleted from the website.', 'portal' ); ?></p>
		<form method="get">
			<input type="hidden" name="page" value="portal-audit-log">
			<select name="log_action">
				<option value=""><?php esc_html_e( 'All actions', 'portal' ); ?></option>
				<?php foreach ( $labels as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $action, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'portal' ), 'secondary', '', false ); ?>
		</form>
		<table class="widefat striped portal-audit-table" style="margin-top:12px">
			<thead><tr>
				<th><?php esc_html_e( 'When (UTC)', 'portal' ); ?></th>
				<th><?php esc_html_e( 'Who', 'portal' ); ?></th>
				<th><?php esc_html_e( 'Action', 'portal' ); ?></th>
				<th><?php esc_html_e( 'What', 'portal' ); ?></th>
				<th><?php esc_html_e( 'Details', 'portal' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $data['rows'] ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'Nothing recorded yet.', 'portal' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $data['rows'] as $row ) : ?>
				<tr data-action="<?php echo esc_attr( $row->action ); ?>">
					<td><?php echo esc_html( $row->created_at ); ?></td>
					<td><?php echo esc_html( portal_audit_actor_label( (int) $row->actor_id ) ); ?></td>
					<td><strong><?php echo esc_html( $labels[ $row->action ] ?? $row->action ); ?></strong></td>
					<td><?php echo esc_html( portal_audit_object_label( $row ) ); ?></td>
					<td><?php echo esc_html( portal_audit_details_text( $row ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $data['total'] / $per );
		if ( $pages > 1 ) {
			echo '<p class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'total' => $pages, 'current' => $paged ) ) ) . '</p>';
		}
		?>
	</div>
	<?php
}

/**
 * CSV download of the log (admins only).
 */
function portal_audit_csv() {
	check_admin_referer( 'portal_audit_csv' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Only administrators can download the audit log.', 'portal' ), 403 );
	}
	$action = isset( $_GET['log_action'] ) ? sanitize_key( wp_unslash( $_GET['log_action'] ) ) : '';
	$data   = portal_get_audit_log(
		array(
			'action' => $action,
			'limit'  => 5000,
		)
	);
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="citycharcha-audit-log-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming a download.
	fputcsv( $out, array( 'id', 'when_utc', 'action', 'who', 'what', 'details' ) );
	foreach ( $data['rows'] as $row ) {
		fputcsv( $out, array( $row->id, $row->created_at, $row->action, portal_audit_actor_label( (int) $row->actor_id ), portal_audit_object_label( $row ), portal_audit_details_text( $row ) ) );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}
add_action( 'admin_post_portal_audit_csv', 'portal_audit_csv' );
