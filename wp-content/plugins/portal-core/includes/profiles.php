<?php
/**
 * Writer profiles: public profile URL, who has one, profile photo, followers.
 *
 *   Profile page          /writer/<slug>/          (theme: author.php)
 *   GET    /wp-json/portal/v1/authors/<id>         public profile for the app
 *   POST   /wp-json/portal/v1/me/avatar            upload my profile photo (field "file")
 *   DELETE /wp-json/portal/v1/me/avatar            remove it (back to Gravatar)
 *
 * Security: WordPress normally builds the profile address from the LOGIN name
 * (/author/portaladmin/), which hands attackers half of what they need to log in.
 * Here the address is made from the public display name instead, and never equals the login.
 *
 * Followers live in wp_portal_follows (follower, what is followed, when). Task 9 shows the
 * count; the Follow button and follow/unfollow API come in Task 12, which also follows tags.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Profile photo: attachment ID in user meta.
 */
const PORTAL_AVATAR_META = 'portal_avatar_id';

/**
 * Largest profile photo accepted, in bytes (2 MB).
 */
const PORTAL_AVATAR_MAX_BYTES = 2097152;

/* ------------------------------------------------------------------ URL: /writer/<slug>/ */

/**
 * Use /writer/ instead of /author/ in profile URLs.
 */
function portal_set_author_base() {
	global $wp_rewrite;
	$wp_rewrite->author_base = sanitize_title( (string) apply_filters( 'portal_author_base', 'writer' ) );
}
add_action( 'init', 'portal_set_author_base', 0 );

/**
 * Rank Math has its own "Author Base" setting and applies it later in 'init' (priority 4),
 * which silently switched profile URLs back to /author/ once its settings were saved.
 * Answer its filter with our base, so profile links can never change by accident.
 *
 * @return string
 */
function portal_rank_math_author_base() {
	return sanitize_title( (string) apply_filters( 'portal_author_base', 'writer' ) );
}
add_filter( 'rank_math/author_base', 'portal_rank_math_author_base' );

/**
 * Rebuild WordPress's address rules after an upgrade. Done on wp_loaded (not init) so the
 * rules of every plugin are registered first, otherwise their pages would break.
 */
function portal_maybe_flush_rewrites() {
	if ( get_option( 'portal_flush_rewrites' ) ) {
		delete_option( 'portal_flush_rewrites' );
		flush_rewrite_rules( false );
	}
}
add_action( 'wp_loaded', 'portal_maybe_flush_rewrites' );

/**
 * The profile slug a user should have: their display name as a URL ("Asha Verma" ->
 * "asha-verma"), or "writer-<ID>" when that is not possible or would reveal the login.
 *
 * @param WP_User $user User.
 * @return string
 */
function portal_profile_slug_for( WP_User $user ) {
	$slug  = sanitize_title( $user->display_name );
	$login = sanitize_title( $user->user_login );
	// Names in Hindi become long %-codes; the column holds 50 characters. Use the fallback.
	if ( '' === $slug || $slug === $login || strlen( $slug ) > 40 || false !== strpos( $slug, '%' ) ) {
		return 'writer-' . $user->ID;
	}

	// Unique: asha-verma, asha-verma-2, ...
	$base = $slug;
	$n    = 2;
	while ( true ) {
		$other = get_user_by( 'slug', $slug );
		if ( ! $other || $other->ID === $user->ID ) {
			return $slug;
		}
		$slug = $base . '-' . $n++;
	}
}

/**
 * Give a user a safe profile slug when theirs still comes from the login name, or is the
 * "writer-<ID>" fallback and they have since set a proper display name. A good slug is
 * never changed again, so shared profile links keep working.
 *
 * @param int $user_id User ID.
 */
function portal_sync_profile_slug( $user_id ) {
	global $wpdb;
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return;
	}
	$current     = $user->user_nicename;
	$from_login  = sanitize_title( $user->user_login ) === $current || $user->user_login === $current;
	$is_fallback = 'writer-' . $user->ID === $current;
	if ( ! $from_login && ! $is_fallback ) {
		return;
	}
	$slug = portal_profile_slug_for( $user );
	if ( $slug !== $current ) {
		// Direct update: wp_update_user() here would fire profile_update again (endless loop).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- see above; cache cleared below.
		$wpdb->update( $wpdb->users, array( 'user_nicename' => $slug ), array( 'ID' => $user->ID ), array( '%s' ), array( '%d' ) );
		clean_user_cache( $user );
	}
}
add_action( 'user_register', 'portal_sync_profile_slug', 20 );
add_action( 'profile_update', 'portal_sync_profile_slug', 20 );

/**
 * Upgrade step: fix every existing user's slug and rebuild URLs once.
 */
function portal_upgrade_profiles() {
	foreach ( get_users( array( 'fields' => 'ID' ) ) as $user_id ) {
		portal_sync_profile_slug( (int) $user_id );
	}
	update_option( 'portal_flush_rewrites', 1 );
}

/* ------------------------------------------------------------------ Who has a public profile */

/**
 * Whether a user has a public profile page: writers (Contributor and up) and anyone with
 * published posts. Plain members do not, so signing up never puts a reader's name online.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function portal_has_public_profile( $user_id ) {
	$user = get_userdata( (int) $user_id );
	if ( ! $user ) {
		return false;
	}
	return user_can( $user, 'edit_posts' ) || count_user_posts( $user->ID, 'post', true ) > 0;
}

/**
 * Whether the user belongs to the news desk (Editors and Admins) rather than the community.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function portal_is_news_desk( $user_id ) {
	return user_can( (int) $user_id, PORTAL_CAP_EDITORIAL_FIELDS );
}

/**
 * Profile pages of users without a public profile answer "404 Not found".
 */
function portal_hide_private_profiles() {
	if ( is_author() && ! portal_has_public_profile( (int) get_queried_object_id() ) ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}
}
add_action( 'template_redirect', 'portal_hide_private_profiles', 1 );

/**
 * Profile pages list 12 posts per page (a grid of 2, 3 or 4 across divides evenly).
 *
 * @param WP_Query $query Query.
 */
function portal_profile_query( $query ) {
	if ( ! is_admin() && $query->is_main_query() && $query->is_author() ) {
		$query->set( 'posts_per_page', 12 );
		$query->set( 'post_type', 'post' );
	}
}
add_action( 'pre_get_posts', 'portal_profile_query' );

/* ------------------------------------------------------------------ Followers */

/**
 * Full name of the follows table.
 *
 * @return string
 */
function portal_follows_table() {
	global $wpdb;
	return $wpdb->prefix . 'portal_follows';
}

/**
 * Create or update the follows table (safe to run again).
 */
function portal_install_follows_table() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table = portal_follows_table();
	dbDelta(
		"CREATE TABLE {$table} (
		user_id bigint(20) unsigned NOT NULL,
		object_type varchar(20) NOT NULL,
		object_id bigint(20) unsigned NOT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (user_id,object_type,object_id),
		KEY object (object_type,object_id)
		) {$wpdb->get_charset_collate()};"
	);
}

/**
 * Make $user_id follow a writer. Following yourself, or someone without a public
 * profile, is refused. Following twice is harmless (counted once).
 *
 * @param int $user_id   Follower.
 * @param int $author_id Writer.
 * @return bool True when following afterwards.
 */
function portal_follow_author( $user_id, $author_id ) {
	global $wpdb;
	$user_id   = (int) $user_id;
	$author_id = (int) $author_id;
	if ( ! $user_id || $user_id === $author_id || ! get_userdata( $user_id ) || ! portal_has_public_profile( $author_id ) ) {
		return false;
	}
	$table = portal_follows_table();
	// INSERT IGNORE: the primary key makes a second follow a no-op instead of an error.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
	$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (user_id, object_type, object_id, created_at) VALUES (%d, 'author', %d, %s)", $user_id, $author_id, current_time( 'mysql', true ) ) );
	return true;
}

/**
 * Stop following a writer.
 *
 * @param int $user_id   Follower.
 * @param int $author_id Writer.
 */
function portal_unfollow_author( $user_id, $author_id ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$wpdb->delete(
		portal_follows_table(),
		array(
			'user_id'     => (int) $user_id,
			'object_type' => 'author',
			'object_id'   => (int) $author_id,
		),
		array( '%d', '%s', '%d' )
	);
}

/**
 * Number of followers of a writer.
 *
 * @param int $author_id Writer.
 * @return int
 */
function portal_get_follower_count( $author_id ) {
	global $wpdb;
	$table = portal_follows_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, indexed.
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE object_type = 'author' AND object_id = %d", (int) $author_id ) );
}

/**
 * When a user is deleted, remove what they followed and who followed them.
 *
 * @param int $user_id Deleted user.
 */
function portal_delete_user_follows( $user_id ) {
	global $wpdb;
	$table = portal_follows_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id = %d OR ( object_type = 'author' AND object_id = %d )", (int) $user_id, (int) $user_id ) );
}
add_action( 'deleted_user', 'portal_delete_user_follows' );

/* ------------------------------------------------------------------ Profile photo */

/**
 * User ID behind whatever get_avatar() was given (ID, email, user, post, comment).
 *
 * @param mixed $id_or_email Avatar subject.
 * @return int 0 if unknown.
 */
function portal_avatar_user_id( $id_or_email ) {
	if ( is_numeric( $id_or_email ) ) {
		return (int) $id_or_email;
	}
	if ( $id_or_email instanceof WP_User ) {
		return $id_or_email->ID;
	}
	if ( $id_or_email instanceof WP_Post ) {
		return (int) $id_or_email->post_author;
	}
	if ( $id_or_email instanceof WP_Comment ) {
		return (int) $id_or_email->user_id;
	}
	if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
		$user = get_user_by( 'email', $id_or_email );
		return $user ? $user->ID : 0;
	}
	return 0;
}

/**
 * Use the uploaded profile photo instead of Gravatar, everywhere WordPress shows avatars.
 *
 * @param array $args        Avatar data.
 * @param mixed $id_or_email Avatar subject.
 * @return array
 */
function portal_avatar_data( $args, $id_or_email ) {
	$user_id = portal_avatar_user_id( $id_or_email );
	$photo   = $user_id ? (int) get_user_meta( $user_id, PORTAL_AVATAR_META, true ) : 0;
	if ( ! $photo ) {
		return $args;
	}
	// 150px "thumbnail" is a square crop; bigger avatars (and 2x screens) need "medium".
	$size = ( (int) ( $args['size'] ?? 96 ) ) * 2 <= 150 ? 'thumbnail' : 'medium';
	$url  = wp_get_attachment_image_url( $photo, $size );
	if ( $url ) {
		$args['url']          = $url;
		$args['found_avatar'] = true;
	}
	return $args;
}
add_filter( 'pre_get_avatar_data', 'portal_avatar_data', 10, 2 );

/**
 * Save an uploaded file as a user's profile photo, replacing the old one.
 *
 * @param int   $user_id User ID.
 * @param array $file    One entry of $_FILES: name, type, tmp_name, error, size.
 * @return int|WP_Error Attachment ID.
 */
function portal_set_avatar( $user_id, array $file ) {
	// The server itself (PHP's upload limit) can turn a big file away before we see it.
	if ( in_array( (int) ( $file['error'] ?? 0 ), array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
		return new WP_Error( 'portal_avatar_size', __( 'The photo is too large. The limit is 2 MB.', 'portal' ), array( 'status' => 400 ) );
	}
	if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
		return new WP_Error( 'portal_avatar_upload', __( 'The photo did not upload. Please try again.', 'portal' ), array( 'status' => 400 ) );
	}
	if ( (int) $file['size'] > PORTAL_AVATAR_MAX_BYTES ) {
		return new WP_Error( 'portal_avatar_size', __( 'The photo is too large. The limit is 2 MB.', 'portal' ), array( 'status' => 400 ) );
	}
	// Check the real file content, not just the name: a renamed script must not get through.
	$mimes = array(
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
		'webp'     => 'image/webp',
	);
	$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $mimes );
	if ( empty( $check['type'] ) || ! @getimagesize( $file['tmp_name'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- invalid files are expected here.
		return new WP_Error( 'portal_avatar_type', __( 'Please choose a JPG, PNG or WebP photo.', 'portal' ), array( 'status' => 400 ) );
	}
	if ( ! empty( $check['proper_filename'] ) ) {
		$file['name'] = $check['proper_filename'];
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$attachment_id = media_handle_sideload(
		$file,
		0,
		null,
		array(
			'post_author' => (int) $user_id,
			'post_title'  => sprintf( 'Profile photo %d', (int) $user_id ),
		)
	);
	if ( is_wp_error( $attachment_id ) ) {
		$attachment_id->add_data( array( 'status' => 400 ) );
		return $attachment_id;
	}

	portal_remove_avatar( $user_id );
	update_post_meta( $attachment_id, '_portal_avatar_of', (int) $user_id );
	update_user_meta( $user_id, PORTAL_AVATAR_META, $attachment_id );
	return $attachment_id;
}

/**
 * Remove a user's profile photo (deletes the file too). Gravatar is used again.
 *
 * @param int $user_id User ID.
 */
function portal_remove_avatar( $user_id ) {
	$old = (int) get_user_meta( $user_id, PORTAL_AVATAR_META, true );
	if ( $old && (int) get_post_meta( $old, '_portal_avatar_of', true ) === (int) $user_id ) {
		wp_delete_attachment( $old, true );
	}
	delete_user_meta( $user_id, PORTAL_AVATAR_META );
}

/**
 * wp-admin profile form: allow file uploads.
 */
function portal_profile_form_enctype() {
	echo ' enctype="multipart/form-data"';
}
add_action( 'user_edit_form_tag', 'portal_profile_form_enctype' );

/**
 * wp-admin profile form: "Profile photo" field.
 *
 * @param WP_User $user User being edited.
 */
function portal_profile_photo_field( $user ) {
	$photo = (int) get_user_meta( $user->ID, PORTAL_AVATAR_META, true );
	?>
	<h2><?php esc_html_e( 'Profile photo', 'portal' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th><label for="portal_avatar"><?php esc_html_e( 'Photo', 'portal' ); ?></label></th>
			<td>
				<?php echo get_avatar( $user->ID, 96 ); ?>
				<p><input type="file" name="portal_avatar" id="portal_avatar" accept="image/jpeg,image/png,image/webp"></p>
				<p class="description"><?php esc_html_e( 'JPG, PNG or WebP, up to 2 MB. A square photo of your face works best. Shown on your profile page and next to your stories.', 'portal' ); ?></p>
				<?php if ( $photo ) : ?>
					<p><label><input type="checkbox" name="portal_avatar_remove" value="1"> <?php esc_html_e( 'Remove my photo', 'portal' ); ?></label></p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'portal_profile_photo_field' );
add_action( 'edit_user_profile', 'portal_profile_photo_field' );

/**
 * wp-admin profile form: save the photo. WordPress has already checked the form's
 * security token (nonce) before these hooks run; we check the permission.
 *
 * @param int $user_id User being saved.
 */
function portal_profile_photo_save( $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification -- verified by core (update-user_<id>).
	if ( ! empty( $_POST['portal_avatar_remove'] ) ) {
		portal_remove_avatar( $user_id );
	}
	if ( ! empty( $_FILES['portal_avatar']['name'] ) ) {
		$result = portal_set_avatar( $user_id, $_FILES['portal_avatar'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked in portal_set_avatar().
		if ( is_wp_error( $result ) ) {
			// Shown at the top of the profile screen after saving.
			add_action(
				'user_profile_update_errors',
				static function ( $errors ) use ( $result ) {
					$errors->add( 'portal_avatar', $result->get_error_message() );
				}
			);
		}
	}
	// phpcs:enable
}
add_action( 'personal_options_update', 'portal_profile_photo_save' );
add_action( 'edit_user_profile_update', 'portal_profile_photo_save' );

/* ------------------------------------------------------------------ Public profile data + API */

/**
 * Public profile of a writer (website and app use the same data).
 *
 * @param int $user_id User ID.
 * @return array|null Null when the user has no public profile.
 */
function portal_get_profile( $user_id ) {
	if ( ! portal_has_public_profile( $user_id ) ) {
		return null;
	}
	$user = get_userdata( (int) $user_id );
	return array(
		'id'        => $user->ID,
		'name'      => $user->display_name,
		'slug'      => $user->user_nicename,
		'link'      => get_author_posts_url( $user->ID ),
		'avatar'    => get_avatar_url( $user->ID, array( 'size' => 192 ) ),
		'bio'       => wp_strip_all_tags( $user->description ),
		'website'   => esc_url_raw( $user->user_url ),
		'desk'      => portal_is_news_desk( $user->ID ) ? 'news' : 'community',
		'posts'     => (int) count_user_posts( $user->ID, 'post', true ),
		'followers' => portal_get_follower_count( $user->ID ),
		'joined'    => mysql_to_rfc3339( $user->user_registered ),
	);
}

/**
 * REST routes.
 */
function portal_register_profile_routes() {
	register_rest_route(
		'portal/v1',
		'/authors/(?P<id>\d+)',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => static function ( WP_REST_Request $request ) {
				$profile = portal_get_profile( (int) $request['id'] );
				if ( ! $profile ) {
					return new WP_Error( 'portal_not_found', __( 'Profile not found.', 'portal' ), array( 'status' => 404 ) );
				}
				return rest_ensure_response( $profile );
			},
		)
	);

	register_rest_route(
		'portal/v1',
		'/me/avatar',
		array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'is_user_logged_in',
				'callback'            => static function ( WP_REST_Request $request ) {
					$files = $request->get_file_params();
					if ( empty( $files['file'] ) ) {
						return new WP_Error( 'portal_avatar_missing', __( 'Send the photo in a field named "file".', 'portal' ), array( 'status' => 400 ) );
					}
					$result = portal_set_avatar( get_current_user_id(), $files['file'] );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					return rest_ensure_response( array( 'avatar' => get_avatar_url( get_current_user_id(), array( 'size' => 192 ) ) ) );
				},
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'permission_callback' => 'is_user_logged_in',
				'callback'            => static function () {
					portal_remove_avatar( get_current_user_id() );
					return rest_ensure_response( array( 'avatar' => get_avatar_url( get_current_user_id(), array( 'size' => 192 ) ) ) );
				},
			),
		)
	);
}
add_action( 'rest_api_init', 'portal_register_profile_routes' );
