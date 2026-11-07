<?php
/**
 * Engagement for logged-in members: likes, bookmarks ("Save for later"), following writers
 * and tags, and the "Following" feed.
 *
 *   POST|DELETE /wp-json/portal/v1/likes/<post-id>            -> { liked, likes }
 *   POST|DELETE /wp-json/portal/v1/bookmarks/<post-id>        -> { bookmarked }
 *   POST|DELETE /wp-json/portal/v1/follows/author/<user-id>   -> { following, followers }
 *   POST|DELETE /wp-json/portal/v1/follows/tag/<term-id>      -> { following, followers }
 *   GET         /wp-json/portal/v1/me/bookmarks               saved stories
 *   GET         /wp-json/portal/v1/me/follows                 { authors[], tags[] }
 *   GET         /wp-json/portal/v1/me/feed                    newest stories from what I follow
 *   "portal_likes" (read-only) on every post in /wp/v2/posts
 *
 * Likes and bookmarks live in one table, wp_portal_marks (who, what kind, which post, when);
 * follows in wp_portal_follows (Task 9). The like count is also kept on the post (protected
 * meta _portal_likes) so pages and lists show it without counting every time.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

const PORTAL_LIKES_META = '_portal_likes';

/**
 * Mark kinds.
 *
 * @return string[]
 */
function portal_mark_types() {
	return array( 'like', 'bookmark' );
}

/**
 * Table name.
 *
 * @return string
 */
function portal_marks_table() {
	global $wpdb;
	return $wpdb->prefix . 'portal_marks';
}

/**
 * Create or update the marks table (safe to run again).
 */
function portal_install_marks_table() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table = portal_marks_table();
	dbDelta(
		"CREATE TABLE {$table} (
		user_id bigint(20) unsigned NOT NULL,
		mark varchar(20) NOT NULL,
		post_id bigint(20) unsigned NOT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (user_id,mark,post_id),
		KEY post (mark,post_id)
		) {$wpdb->get_charset_collate()};"
	);
}

/* ------------------------------------------------------------------ Likes and bookmarks */

/**
 * Add a like or bookmark. Doing it twice is harmless. Only public posts.
 *
 * @param int    $user_id User.
 * @param string $mark    like | bookmark.
 * @param int    $post_id Post.
 * @return true|WP_Error
 */
function portal_add_mark( $user_id, $mark, $post_id ) {
	global $wpdb;
	if ( ! in_array( $mark, portal_mark_types(), true ) || ! portal_is_public_post( get_post( $post_id ) ) ) {
		return new WP_Error( 'portal_not_found', __( 'Post not found.', 'portal' ), array( 'status' => 404 ) );
	}
	$table = portal_marks_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
	$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (user_id, mark, post_id, created_at) VALUES (%d, %s, %d, %s)", $user_id, $mark, $post_id, current_time( 'mysql', true ) ) );
	if ( 'like' === $mark ) {
		portal_refresh_like_count( $post_id );
	}
	return true;
}

/**
 * Remove a like or bookmark.
 *
 * @param int    $user_id User.
 * @param string $mark    like | bookmark.
 * @param int    $post_id Post.
 */
function portal_remove_mark( $user_id, $mark, $post_id ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$wpdb->delete(
		portal_marks_table(),
		array(
			'user_id' => (int) $user_id,
			'mark'    => $mark,
			'post_id' => (int) $post_id,
		),
		array( '%d', '%s', '%d' )
	);
	if ( 'like' === $mark ) {
		portal_refresh_like_count( $post_id );
	}
}

/**
 * Whether the user has liked / bookmarked the post.
 *
 * @param int    $user_id User.
 * @param string $mark    like | bookmark.
 * @param int    $post_id Post.
 * @return bool
 */
function portal_has_mark( $user_id, $mark, $post_id ) {
	global $wpdb;
	if ( ! $user_id ) {
		return false;
	}
	$table = portal_marks_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, primary key lookup.
	return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE user_id = %d AND mark = %s AND post_id = %d", $user_id, $mark, $post_id ) );
}

/**
 * Recount a post's likes and store the number on the post. A real count (not +1/-1),
 * so the number can never drift from the truth.
 *
 * @param int $post_id Post.
 * @return int
 */
function portal_refresh_like_count( $post_id ) {
	global $wpdb;
	$table = portal_marks_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, indexed.
	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE mark = 'like' AND post_id = %d", $post_id ) );
	update_post_meta( $post_id, PORTAL_LIKES_META, $count );
	return $count;
}

/**
 * Number of likes.
 *
 * @param int $post_id Post.
 * @return int
 */
function portal_get_likes( $post_id ) {
	return (int) get_post_meta( $post_id, PORTAL_LIKES_META, true );
}

/**
 * A user's bookmarked public posts, newest bookmark first.
 *
 * @param int $user_id User.
 * @param int $limit   Max.
 * @return WP_Post[]
 */
function portal_get_bookmarks( $user_id, $limit = 50 ) {
	global $wpdb;
	$table = portal_marks_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
	$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$table} WHERE user_id = %d AND mark = 'bookmark' ORDER BY created_at DESC LIMIT %d", $user_id, $limit ) );
	$posts = array();
	foreach ( $ids as $id ) {
		$post = get_post( (int) $id );
		if ( portal_is_public_post( $post ) ) {
			$posts[] = $post;
		}
	}
	return $posts;
}

/* ------------------------------------------------------------------ Following (writers and tags) */

/**
 * Follow a writer or a tag.
 *
 * @param int    $user_id Follower.
 * @param string $type    author | tag.
 * @param int    $id      Writer user ID or tag term ID.
 * @return true|WP_Error
 */
function portal_follow( $user_id, $type, $id ) {
	global $wpdb;
	if ( 'author' === $type ) {
		if ( (int) $user_id === (int) $id ) {
			return new WP_Error( 'portal_follow_self', __( 'You cannot follow yourself.', 'portal' ), array( 'status' => 400 ) );
		}
		return portal_follow_author( $user_id, $id ) ? true : new WP_Error( 'portal_not_found', __( 'Profile not found.', 'portal' ), array( 'status' => 404 ) );
	}
	$term = get_term( (int) $id, 'post_tag' );
	if ( 'tag' !== $type || ! $term instanceof WP_Term ) {
		return new WP_Error( 'portal_not_found', __( 'Tag not found.', 'portal' ), array( 'status' => 404 ) );
	}
	$table = portal_follows_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
	$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (user_id, object_type, object_id, created_at) VALUES (%d, 'tag', %d, %s)", $user_id, $term->term_id, current_time( 'mysql', true ) ) );
	return true;
}

/**
 * Stop following.
 *
 * @param int    $user_id Follower.
 * @param string $type    author | tag.
 * @param int    $id      Object ID.
 */
function portal_unfollow( $user_id, $type, $id ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$wpdb->delete(
		portal_follows_table(),
		array(
			'user_id'     => (int) $user_id,
			'object_type' => $type,
			'object_id'   => (int) $id,
		),
		array( '%d', '%s', '%d' )
	);
}

/**
 * Whether the user follows something.
 *
 * @param int    $user_id Follower.
 * @param string $type    author | tag.
 * @param int    $id      Object ID.
 * @return bool
 */
function portal_is_following( $user_id, $type, $id ) {
	global $wpdb;
	if ( ! $user_id ) {
		return false;
	}
	$table = portal_follows_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, primary key lookup.
	return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE user_id = %d AND object_type = %s AND object_id = %d", $user_id, $type, $id ) );
}

/**
 * Number of followers of a writer or tag.
 *
 * @param string $type author | tag.
 * @param int    $id   Object ID.
 * @return int
 */
function portal_count_followers( $type, $id ) {
	global $wpdb;
	$table = portal_follows_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, indexed.
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE object_type = %s AND object_id = %d", $type, $id ) );
}

/**
 * What a user follows.
 *
 * @param int $user_id User.
 * @return array{author:int[],tag:int[]}
 */
function portal_get_follows( $user_id ) {
	global $wpdb;
	$table = portal_follows_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT object_type, object_id FROM {$table} WHERE user_id = %d ORDER BY created_at DESC", $user_id ) );
	$out  = array(
		'author' => array(),
		'tag'    => array(),
	);
	foreach ( $rows as $row ) {
		if ( isset( $out[ $row->object_type ] ) ) {
			$out[ $row->object_type ][] = (int) $row->object_id;
		}
	}
	return $out;
}

/**
 * Newest stories from followed writers and tags.
 *
 * @param int $user_id User.
 * @param int $limit   Max.
 * @return WP_Post[]
 */
function portal_get_following_feed( $user_id, $limit = 10 ) {
	$follows = portal_get_follows( $user_id );
	$base    = array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'has_password'        => false,
		'posts_per_page'      => $limit,
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
	);
	$posts   = array();
	// WordPress cannot ask "by these writers OR with these tags" in one query: ask twice, merge.
	if ( $follows['author'] ) {
		$posts = array_merge( $posts, ( new WP_Query( $base + array( 'author__in' => $follows['author'] ) ) )->posts );
	}
	if ( $follows['tag'] ) {
		$posts = array_merge( $posts, ( new WP_Query( $base + array( 'tag__in' => $follows['tag'] ) ) )->posts );
	}
	$unique = array();
	foreach ( $posts as $post ) {
		$unique[ $post->ID ] = $post;
	}
	usort( $unique, static fn( $a, $b ) => strcmp( $b->post_date_gmt, $a->post_date_gmt ) );
	return array_slice( array_values( $unique ), 0, $limit );
}

/* ------------------------------------------------------------------ Clean-up */

/**
 * Deleted post: remove its likes and bookmarks.
 *
 * @param int $post_id Post.
 */
function portal_delete_post_marks( $post_id ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$wpdb->delete( portal_marks_table(), array( 'post_id' => (int) $post_id ), array( '%d' ) );
}
add_action( 'deleted_post', 'portal_delete_post_marks' );

/**
 * Deleted user: remove their likes and bookmarks (and recount the posts they liked).
 *
 * @param int $user_id User.
 */
function portal_delete_user_marks( $user_id ) {
	global $wpdb;
	$table = portal_marks_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
	$liked = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$table} WHERE user_id = %d AND mark = 'like'", $user_id ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$wpdb->delete( $table, array( 'user_id' => (int) $user_id ), array( '%d' ) );
	foreach ( $liked as $post_id ) {
		portal_refresh_like_count( (int) $post_id );
	}
}
add_action( 'deleted_user', 'portal_delete_user_marks' );

/**
 * Deleted tag: remove its follows.
 *
 * @param int $term_id Term.
 */
function portal_delete_tag_follows( $term_id ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	$wpdb->delete(
		portal_follows_table(),
		array(
			'object_type' => 'tag',
			'object_id'   => (int) $term_id,
		),
		array( '%s', '%d' )
	);
}
add_action( 'delete_post_tag', 'portal_delete_tag_follows' );

/* ------------------------------------------------------------------ My Account tabs */

/**
 * "Saved" and "Following" tabs in My Account (WP User Frontend). The theme draws them.
 *
 * @param array $sections Tabs.
 * @return array
 */
function portal_engagement_account_sections( $sections ) {
	$sections['portal-saved']     = __( 'Saved', 'portal' );
	$sections['portal-following'] = __( 'Following', 'portal' );
	return $sections;
}
add_filter( 'wpuf_account_sections', 'portal_engagement_account_sections', 30 );

/* ------------------------------------------------------------------ API */

/**
 * REST routes.
 */
function portal_register_engagement_routes() {
	$logged_in = static function () {
		return is_user_logged_in() ? true : new WP_Error( 'rest_not_logged_in', __( 'Please sign in first.', 'portal' ), array( 'status' => 401 ) );
	};
	$id_arg    = array(
		'id' => array(
			'type'     => 'integer',
			'required' => true,
		),
	);

	foreach ( array( 'likes' => 'like', 'bookmarks' => 'bookmark' ) as $route => $mark ) {
		register_rest_route(
			'portal/v1',
			'/' . $route . '/(?P<id>\d+)',
			array(
				'methods'             => array( 'POST', 'DELETE' ),
				'permission_callback' => $logged_in,
				'args'                => $id_arg,
				'callback'            => static function ( WP_REST_Request $request ) use ( $mark ) {
					$user = get_current_user_id();
					$id   = (int) $request['id'];
					if ( 'POST' === $request->get_method() ) {
						$result = portal_add_mark( $user, $mark, $id );
						if ( is_wp_error( $result ) ) {
							return $result;
						}
					} else {
						portal_remove_mark( $user, $mark, $id );
					}
					$on = portal_has_mark( $user, $mark, $id );
					return rest_ensure_response(
						'like' === $mark
							? array(
								'liked' => $on,
								'likes' => portal_get_likes( $id ),
							)
							: array( 'bookmarked' => $on )
					);
				},
			)
		);
	}

	register_rest_route(
		'portal/v1',
		'/follows/(?P<type>author|tag)/(?P<id>\d+)',
		array(
			'methods'             => array( 'POST', 'DELETE' ),
			'permission_callback' => $logged_in,
			'args'                => $id_arg,
			'callback'            => static function ( WP_REST_Request $request ) {
				$user = get_current_user_id();
				$type = $request['type'];
				$id   = (int) $request['id'];
				if ( 'POST' === $request->get_method() ) {
					$result = portal_follow( $user, $type, $id );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				} else {
					portal_unfollow( $user, $type, $id );
				}
				return rest_ensure_response(
					array(
						'following' => portal_is_following( $user, $type, $id ),
						'followers' => portal_count_followers( $type, $id ),
					)
				);
			},
		)
	);

	register_rest_route(
		'portal/v1',
		'/me/bookmarks',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => $logged_in,
			'callback'            => static fn() => rest_ensure_response( array_map( 'portal_feed_item', portal_get_bookmarks( get_current_user_id() ) ) ),
		)
	);

	register_rest_route(
		'portal/v1',
		'/me/feed',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => $logged_in,
			'callback'            => static fn() => rest_ensure_response( array_map( 'portal_feed_item', portal_get_following_feed( get_current_user_id(), 20 ) ) ),
		)
	);

	register_rest_route(
		'portal/v1',
		'/me/follows',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => $logged_in,
			'callback'            => static function () {
				$follows = portal_get_follows( get_current_user_id() );
				$tags    = array();
				foreach ( $follows['tag'] as $term_id ) {
					$term = get_term( $term_id, 'post_tag' );
					if ( $term instanceof WP_Term ) {
						$tags[] = array(
							'id'   => $term->term_id,
							'name' => $term->name,
							'link' => get_term_link( $term ),
						);
					}
				}
				return rest_ensure_response(
					array(
						'authors' => array_values( array_filter( array_map( 'portal_get_profile', $follows['author'] ) ) ),
						'tags'    => $tags,
					)
				);
			},
		)
	);

	register_rest_field(
		'post',
		'portal_likes',
		array(
			'get_callback' => static fn( $data ) => portal_get_likes( (int) $data['id'] ),
			'schema'       => array(
				'description' => __( 'Number of likes.', 'portal' ),
				'type'        => 'integer',
				'readonly'    => true,
				'context'     => array( 'view', 'edit' ),
			),
		)
	);
}
add_action( 'rest_api_init', 'portal_register_engagement_routes' );
