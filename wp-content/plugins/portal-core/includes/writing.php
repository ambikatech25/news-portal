<?php
/**
 * Writing from the website: the "Write a story" page, the Pending-review rule, and
 * "Become a writer" requests.
 *
 *   /write/                                  [portal_write] - right thing for each visitor
 *   GET  /wp-json/portal/v1/me/writer-request   my request (status none|pending|approved|rejected)
 *   POST /wp-json/portal/v1/me/writer-request   { reason, sample_url, agree: true }
 *
 * The story form itself is a WP User Frontend form (built by scripts/setup-writing.php).
 * The rules do not trust that form:
 *  - Publishing rule 1: anyone who cannot publish (Contributors) always ends up in
 *    Pending review, whatever route they use (WPUF would otherwise publish whatever
 *    status its form settings say).
 *  - Editor-only categories stay blocked by includes/restricted-categories.php.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

const PORTAL_WRITER_REQUEST_META = 'portal_writer_request';

/* ------------------------------------------------------------------ Rule 1: Pending review */

/**
 * People who cannot publish never publish: their "publish" becomes "pending".
 *
 * Based on the person doing the save (not the post's author), so an Editor approving a
 * Contributor's story publishes it normally. System tasks (no logged-in user: setup
 * scripts, scheduled jobs) are not restricted.
 *
 * @param array $data    Post data about to be saved.
 * @param array $postarr Raw post data.
 * @return array
 */
function portal_force_pending_for_contributors( $data, $postarr ) {
	if ( 'post' !== $data['post_type'] || ! in_array( $data['post_status'], array( 'publish', 'future' ), true ) ) {
		return $data;
	}
	if ( 0 === get_current_user_id() || current_user_can( 'publish_posts' ) ) {
		return $data;
	}
	$data['post_status'] = 'pending';
	return $data;
}
add_filter( 'wp_insert_post_data', 'portal_force_pending_for_contributors', 99, 2 );

/* ------------------------------------------------------------------ Writer requests */

/**
 * Days to wait before asking again after a request was turned down.
 *
 * @return int
 */
function portal_writer_retry_days() {
	return max( 0, (int) apply_filters( 'portal_writer_retry_days', 7 ) );
}

/**
 * A user's writer request.
 *
 * @param int $user_id User ID.
 * @return array{status:string,reason?:string,sample_url?:string,at?:string,decided_at?:string,decided_by?:int}
 */
function portal_get_writer_request( $user_id ) {
	$request = get_user_meta( (int) $user_id, PORTAL_WRITER_REQUEST_META, true );
	return is_array( $request ) && ! empty( $request['status'] ) ? $request : array( 'status' => 'none' );
}

/**
 * Send a "Become a writer" request.
 *
 * @param int    $user_id    User ID.
 * @param string $reason     Why they want to write (30-1000 characters).
 * @param string $sample_url Optional link to something they wrote.
 * @param bool   $agree      Agreed to the Community Guidelines.
 * @return array|WP_Error The stored request.
 */
function portal_submit_writer_request( $user_id, $reason, $sample_url, $agree ) {
	$user = get_userdata( (int) $user_id );
	if ( ! $user ) {
		return new WP_Error( 'portal_writer_login', __( 'Please sign in first.', 'portal' ), array( 'status' => 401 ) );
	}
	if ( user_can( $user, 'edit_posts' ) ) {
		return new WP_Error( 'portal_writer_already', __( 'You can already write stories.', 'portal' ), array( 'status' => 400 ) );
	}

	$current = portal_get_writer_request( $user->ID );
	if ( 'pending' === $current['status'] ) {
		return new WP_Error( 'portal_writer_pending', __( 'Your request is already with our team.', 'portal' ), array( 'status' => 409 ) );
	}
	if ( 'rejected' === $current['status'] && ! empty( $current['decided_at'] ) ) {
		$wait_until = strtotime( $current['decided_at'] . ' UTC' ) + portal_writer_retry_days() * DAY_IN_SECONDS;
		if ( time() < $wait_until ) {
			return new WP_Error(
				'portal_writer_wait',
				/* translators: %s: date. */
				sprintf( __( 'You can send a new request after %s.', 'portal' ), wp_date( get_option( 'date_format' ), $wait_until ) ),
				array( 'status' => 429 )
			);
		}
	}

	$reason     = trim( sanitize_textarea_field( $reason ) );
	$sample_url = trim( (string) $sample_url );
	$length     = mb_strlen( $reason );
	if ( $length < 30 || $length > 1000 ) {
		return new WP_Error( 'portal_writer_reason', __( 'Please tell us in 30 to 1000 characters what you would like to write about.', 'portal' ), array( 'status' => 400 ) );
	}
	if ( '' !== $sample_url && ( ! wp_http_validate_url( $sample_url ) || ! preg_match( '#^https?://#i', $sample_url ) ) ) {
		return new WP_Error( 'portal_writer_url', __( 'The sample link does not look like a web address (it should start with https://).', 'portal' ), array( 'status' => 400 ) );
	}
	if ( ! $agree ) {
		return new WP_Error( 'portal_writer_agree', __( 'Please agree to the Community Guidelines.', 'portal' ), array( 'status' => 400 ) );
	}

	$request = array(
		'status'     => 'pending',
		'reason'     => $reason,
		'sample_url' => esc_url_raw( $sample_url ),
		'at'         => current_time( 'mysql', true ),
	);
	update_user_meta( $user->ID, PORTAL_WRITER_REQUEST_META, $request );

	wp_mail(
		get_option( 'admin_email' ),
		/* translators: %s: person's name. */
		sprintf( __( 'Writer request from %s', 'portal' ), $user->display_name ),
		sprintf(
			/* translators: 1: name, 2: reason, 3: sample link, 4: review link. */
			__( "%1\$s would like to write for the site.\n\nWhy:\n%2\$s\n\nSample: %3\$s\n\nApprove or turn down: %4\$s", 'portal' ),
			$user->display_name,
			$reason,
			$request['sample_url'] ? $request['sample_url'] : '-',
			admin_url( 'users.php?portal_writer_requests=pending' )
		)
	);

	do_action( 'portal_writer_request_submitted', $user->ID, $request );
	return $request;
}

/**
 * Approve or turn down a request. Approving makes the member a Contributor (their stories
 * still go to Pending review; promotion to Author stays a manual admin decision).
 *
 * @param int    $user_id  The person who asked.
 * @param string $decision approve | reject.
 * @param int    $by       Admin making the decision.
 * @return true|WP_Error
 */
function portal_decide_writer_request( $user_id, $decision, $by ) {
	$user    = get_userdata( (int) $user_id );
	$request = $user ? portal_get_writer_request( $user->ID ) : array( 'status' => 'none' );
	if ( ! $user || 'pending' !== $request['status'] ) {
		return new WP_Error( 'portal_writer_none', __( 'There is no open request for this person.', 'portal' ) );
	}

	$request['decided_at'] = current_time( 'mysql', true );
	$request['decided_by'] = (int) $by;

	if ( 'approve' === $decision ) {
		// Only ever promote a plain Member: never touch someone who already has a higher role.
		if ( ! user_can( $user, 'edit_posts' ) ) {
			$user->set_role( 'contributor' );
		}
		$request['status'] = 'approved';
		$subject           = __( 'You can now write for us', 'portal' );
		$body              = sprintf(
			/* translators: 1: name, 2: write page link. */
			__( "Hello %1\$s,\n\nGood news: your request was approved. You can now write stories here:\n%2\$s\n\nOur editors read every story before it is published.", 'portal' ),
			$user->display_name,
			home_url( '/write/' )
		);
	} else {
		$request['status'] = 'rejected';
		$subject           = __( 'About your writer request', 'portal' );
		$body              = sprintf(
			/* translators: 1: name, 2: days. */
			__( "Hello %1\$s,\n\nThank you for your interest. We cannot accept your writer request right now. You are welcome to ask again after %2\$d days.", 'portal' ),
			$user->display_name,
			portal_writer_retry_days()
		);
	}

	update_user_meta( $user->ID, PORTAL_WRITER_REQUEST_META, $request );
	wp_mail( $user->user_email, $subject, $body );

	/**
	 * Admin action, for the audit log (Task 16).
	 *
	 * @param string $action  writer_approved | writer_rejected.
	 * @param string $type    Object type.
	 * @param int    $id      Object ID.
	 * @param int    $by      Admin user ID.
	 */
	do_action( 'portal_admin_action', 'writer_' . $request['status'], 'user', $user->ID, (int) $by );
	return true;
}

/* ------------------------------------------------------------------ Admin screen (Users) */

/**
 * Users list: "Writer requests (N)" link at the top.
 *
 * @param array $views Links.
 * @return array
 */
function portal_writer_requests_view( $views ) {
	if ( ! current_user_can( 'promote_users' ) ) {
		return $views;
	}
	$count = count(
		get_users(
			array(
				'meta_key'   => PORTAL_WRITER_REQUEST_META, // phpcs:ignore WordPress.DB.SlowDBQuery -- small admin query.
				'meta_value' => '"pending"', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare' => 'LIKE',
				'fields'     => 'ID',
			)
		)
	);
	// phpcs:ignore WordPress.Security.NonceVerification -- read-only filter link.
	$current = isset( $_GET['portal_writer_requests'] );
	$views['portal_writer_requests'] = sprintf(
		'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
		esc_url( admin_url( 'users.php?portal_writer_requests=pending' ) ),
		$current ? ' class="current" aria-current="page"' : '',
		esc_html__( 'Writer requests', 'portal' ),
		$count
	);
	return $views;
}
add_filter( 'views_users', 'portal_writer_requests_view' );

/**
 * Users list: when "Writer requests" is chosen, show only people with an open request.
 *
 * @param array $args User query arguments.
 * @return array
 */
function portal_writer_requests_filter( $args ) {
	// phpcs:ignore WordPress.Security.NonceVerification -- read-only filter.
	if ( isset( $_GET['portal_writer_requests'] ) && current_user_can( 'promote_users' ) ) {
		$args['meta_key']     = PORTAL_WRITER_REQUEST_META; // phpcs:ignore WordPress.DB.SlowDBQuery
		$args['meta_value']   = '"pending"'; // phpcs:ignore WordPress.DB.SlowDBQuery
		$args['meta_compare'] = 'LIKE';
	}
	return $args;
}
add_filter( 'users_list_table_query_args', 'portal_writer_requests_filter' );

/**
 * Users list: "Writer request" column.
 *
 * @param array $columns Columns.
 * @return array
 */
function portal_writer_request_column( $columns ) {
	$columns['portal_writer_request'] = __( 'Writer request', 'portal' );
	return $columns;
}
add_filter( 'manage_users_columns', 'portal_writer_request_column' );

/**
 * Users list: column content (status, reason, sample link, Approve / Turn down).
 *
 * @param string $output  Current output.
 * @param string $column  Column key.
 * @param int    $user_id User ID.
 * @return string
 */
function portal_writer_request_column_content( $output, $column, $user_id ) {
	if ( 'portal_writer_request' !== $column ) {
		return $output;
	}
	$request = portal_get_writer_request( $user_id );
	$labels  = array(
		'none'     => '—',
		'pending'  => __( 'Waiting', 'portal' ),
		'approved' => __( 'Approved', 'portal' ),
		'rejected' => __( 'Turned down', 'portal' ),
	);
	$html = '<strong>' . esc_html( $labels[ $request['status'] ] ?? '—' ) . '</strong>';
	if ( 'pending' === $request['status'] ) {
		$html .= '<br><span class="description">' . esc_html( wp_trim_words( $request['reason'], 30 ) ) . '</span>';
		if ( ! empty( $request['sample_url'] ) ) {
			$html .= '<br><a href="' . esc_url( $request['sample_url'] ) . '" target="_blank" rel="noopener noreferrer nofollow">' . esc_html__( 'Sample', 'portal' ) . '</a>';
		}
		if ( current_user_can( 'promote_users' ) ) {
			foreach ( array( 'approve' => __( 'Approve', 'portal' ), 'reject' => __( 'Turn down', 'portal' ) ) as $decision => $label ) {
				$url   = wp_nonce_url( admin_url( 'admin-post.php?action=portal_writer_decide&decision=' . $decision . '&user=' . (int) $user_id ), 'portal_writer_decide_' . (int) $user_id );
				$html .= '<br><a href="' . esc_url( $url ) . '" class="portal-writer-' . esc_attr( $decision ) . '">' . esc_html( $label ) . '</a>';
			}
		}
	}
	return $html;
}
add_filter( 'manage_users_custom_column', 'portal_writer_request_column_content', 10, 3 );

/**
 * Approve / Turn down links (admins only, with a security token).
 */
function portal_writer_decide_post() {
	$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
	check_admin_referer( 'portal_writer_decide_' . $user_id );
	if ( ! current_user_can( 'promote_users' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'portal' ), 403 );
	}
	$decision = isset( $_GET['decision'] ) && 'approve' === $_GET['decision'] ? 'approve' : 'reject';
	$result   = portal_decide_writer_request( $user_id, $decision, get_current_user_id() );
	$done     = is_wp_error( $result ) ? 'none' : $decision;
	wp_safe_redirect( admin_url( 'users.php?portal_writer_requests=pending&portal_writer_done=' . $done ) );
	exit;
}
add_action( 'admin_post_portal_writer_decide', 'portal_writer_decide_post' );

/**
 * Notice after a decision.
 */
function portal_writer_decide_notice() {
	// phpcs:ignore WordPress.Security.NonceVerification -- only chooses which message to show.
	$done     = isset( $_GET['portal_writer_done'] ) ? sanitize_key( wp_unslash( $_GET['portal_writer_done'] ) ) : '';
	$messages = array(
		'approve' => __( 'Approved: the person is now a Contributor and has been e-mailed.', 'portal' ),
		'reject'  => __( 'Turned down: the person has been e-mailed.', 'portal' ),
		'none'    => __( 'That request was already decided.', 'portal' ),
	);
	if ( isset( $messages[ $done ] ) ) {
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', 'none' === $done ? 'warning' : 'success', esc_html( $messages[ $done ] ) );
	}
}
add_action( 'admin_notices', 'portal_writer_decide_notice' );

/* ------------------------------------------------------------------ The /write/ page */

/**
 * [portal_write]: sign-in prompt, "Become a writer" request, or the story form.
 *
 * @return string HTML.
 */
function portal_write_shortcode() {
	if ( ! is_user_logged_in() ) {
		ob_start();
		?>
		<div class="portal-write-box">
			<h2><?php esc_html_e( 'Write for us', 'portal' ); ?></h2>
			<p><?php esc_html_e( 'Share news, experiences and ideas from Chhattisgarh and central India. Sign in or create a free account to get started.', 'portal' ); ?></p>
			<p class="portal-write-box__actions">
				<a class="portal-button" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Sign in', 'portal' ); ?></a>
				<a class="portal-button portal-button--ghost" href="<?php echo esc_url( wp_registration_url() ); ?>"><?php esc_html_e( 'Create account', 'portal' ); ?></a>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	if ( current_user_can( 'edit_posts' ) ) {
		$form_id = (int) get_option( 'portal_post_form_id' );
		$intro   = current_user_can( 'publish_posts' )
			? __( 'Your story is published when you submit it.', 'portal' )
			: __( 'Our editors read every story before it is published. You will find it under My Account → Posts.', 'portal' );
		return '<p class="portal-write-intro">' . esc_html( $intro ) . '</p>'
			. ( function_exists( 'portal_import_box' ) ? portal_import_box() : '' )
			. ( $form_id ? do_shortcode( '[wpuf_form id="' . $form_id . '"]' ) : '' );
	}

	return portal_writer_request_box();
}
add_shortcode( 'portal_write', 'portal_write_shortcode' );

/**
 * The "Become a writer" box for members: status or the request form.
 *
 * @return string HTML.
 */
function portal_writer_request_box() {
	$request = portal_get_writer_request( get_current_user_id() );
	// phpcs:ignore WordPress.Security.NonceVerification -- only chooses which message to show.
	$error = isset( $_GET['portal_writer_error'] ) ? sanitize_text_field( wp_unslash( $_GET['portal_writer_error'] ) ) : '';
	$rules = get_page_by_path( 'community-guidelines' );
	ob_start();
	?>
	<div class="portal-write-box">
		<h2><?php esc_html_e( 'Become a writer', 'portal' ); ?></h2>
		<?php if ( 'pending' === $request['status'] ) : ?>
			<p class="portal-write-box__status" role="status"><?php esc_html_e( 'Thank you! Your request is with our team. We will e-mail you when it has been reviewed.', 'portal' ); ?></p>
		<?php else : ?>
			<?php if ( 'rejected' === $request['status'] ) : ?>
				<p class="portal-write-box__status"><?php esc_html_e( 'Your last request was not accepted. You can ask again a week after it was reviewed.', 'portal' ); ?></p>
			<?php endif; ?>
			<p><?php esc_html_e( 'Members can apply to write. Tell us a little about yourself; an admin will review your request.', 'portal' ); ?></p>
			<?php if ( $error ) : ?>
				<p class="portal-write-box__error" role="alert"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="portal-writer-form">
				<input type="hidden" name="action" value="portal_writer_request">
				<?php wp_nonce_field( 'portal_writer_request' ); ?>
				<label>
					<?php esc_html_e( 'What would you like to write about, and why?', 'portal' ); ?>
					<textarea name="reason" rows="5" minlength="30" maxlength="1000" required></textarea>
				</label>
				<label>
					<?php esc_html_e( 'Link to something you wrote (optional)', 'portal' ); ?>
					<input type="url" name="sample_url" placeholder="https://">
				</label>
				<label class="portal-writer-form__agree">
					<input type="checkbox" name="agree" value="1" required>
					<span>
						<?php
						printf(
							/* translators: %s: Community Guidelines link. */
							esc_html__( 'I have read and will follow the %s.', 'portal' ),
							'<a href="' . esc_url( $rules ? get_permalink( $rules ) : home_url( '/' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Community Guidelines', 'portal' ) . '</a>'
						);
						?>
					</span>
				</label>
				<button type="submit" class="portal-button"><?php esc_html_e( 'Send request', 'portal' ); ?></button>
			</form>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Handle the request form.
 */
function portal_writer_request_post() {
	check_admin_referer( 'portal_writer_request' );
	$back   = wp_get_referer() ? wp_get_referer() : home_url( '/write/' );
	$back   = remove_query_arg( 'portal_writer_error', $back );
	$result = portal_submit_writer_request(
		get_current_user_id(),
		isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '',
		isset( $_POST['sample_url'] ) ? esc_url_raw( wp_unslash( $_POST['sample_url'] ) ) : '',
		! empty( $_POST['agree'] )
	);
	if ( is_wp_error( $result ) ) {
		$back = add_query_arg( 'portal_writer_error', rawurlencode( $result->get_error_message() ), $back );
	}
	wp_safe_redirect( $back );
	exit;
}
add_action( 'admin_post_portal_writer_request', 'portal_writer_request_post' );

/* ------------------------------------------------------------------ API for the app */

/**
 * REST: my writer request.
 */
function portal_register_writing_routes() {
	register_rest_route(
		'portal/v1',
		'/me/writer-request',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'is_user_logged_in',
				'callback'            => static function () {
					$request = portal_get_writer_request( get_current_user_id() );
					return rest_ensure_response(
						array(
							'status'     => $request['status'],
							'can_write'  => current_user_can( 'edit_posts' ),
							'at'         => $request['at'] ?? null,
							'decided_at' => $request['decided_at'] ?? null,
						)
					);
				},
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'is_user_logged_in',
				'args'                => array(
					'reason'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'sample_url' => array(
						'type'    => 'string',
						'default' => '',
					),
					'agree'      => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					$result = portal_submit_writer_request( get_current_user_id(), (string) $request['reason'], (string) $request['sample_url'], (bool) $request['agree'] );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					return new WP_REST_Response( array( 'status' => $result['status'] ), 201 );
				},
			),
		)
	);
}
add_action( 'rest_api_init', 'portal_register_writing_routes' );
