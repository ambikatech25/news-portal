<?php
/**
 * Community home (Task 17C): the data behind the /community/ page and the app.
 *
 *   - Editors' picks: community stories an editor switched "Editor's pick" on for (post meta
 *     portal_editors_pick, editors only, includes/post-meta.php). Newest picks first.
 *   - Trending writers: community writers ranked by reads + likes (a like counts as 10 reads)
 *     on their community stories from the last 30 days, topped up with all-time numbers.
 *     Suspended writers and people without a public profile never appear.
 *   - New series: newest series with at least one published part (Task 17A).
 *   - Newest community stories.
 *
 * The theme draws the page (page-community.php); everything here also feeds
 * GET /wp-json/portal/v1/community.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

const PORTAL_PICK_META      = 'portal_editors_pick';
const PORTAL_PICKED_AT_META = '_portal_picked_at';

/* ------------------------------------------------------------------ Basics */

/**
 * The Community Blogs category ID.
 *
 * @return int
 */
function portal_community_category_id() {
	$cat = get_category_by_slug( 'community-blogs' );
	return $cat ? (int) $cat->term_id : (int) get_option( 'default_category' );
}

/**
 * Whether a post is a published community story.
 *
 * @param WP_Post|int $post Post.
 * @return bool
 */
function portal_is_community_story( $post ) {
	$post = get_post( $post );
	return $post && portal_is_public_post( $post ) && has_category( portal_community_category_id(), $post );
}

/**
 * Address of the Community page (falls back to the Community Blogs category).
 *
 * @return string
 */
function portal_community_page_url() {
	$page = get_page_by_path( 'community' );
	if ( $page && 'publish' === $page->post_status ) {
		return (string) get_permalink( $page );
	}
	$link = get_category_link( portal_community_category_id() );
	return is_string( $link ) ? $link : home_url( '/' );
}

/**
 * Refresh the Community page in LiteSpeed's cache (live server) and forget the writer ranking.
 */
function portal_community_refresh() {
	delete_transient( 'portal_trending_writers' );
	do_action( 'litespeed_purge_url', portal_community_page_url() );
}

/* ------------------------------------------------------------------ Editors' picks */

/**
 * Remember which story is being deleted right now (or check it).
 *
 * @param int  $post_id Post ID.
 * @param bool $set     True to record it.
 * @return bool Whether this story is being deleted.
 */
function portal_community_deleting( $post_id, $set = false ) {
	static $deleting = 0;
	if ( $set ) {
		$deleting = (int) $post_id;
	}
	return $deleting && (int) $post_id === $deleting;
}
add_action( 'before_delete_post', static fn( $post_id ) => portal_community_deleting( (int) $post_id, true ) );

/**
 * When "Editor's pick" is switched on or off (block editor, Posts list, API, WP-CLI):
 * remember when, write it in the audit log, refresh the page.
 *
 * @param int    $meta_id    Unused.
 * @param int    $post_id    Post ID.
 * @param string $meta_key   Meta key.
 * @param mixed  $meta_value New value.
 */
function portal_pick_changed( $meta_id, $post_id, $meta_key, $meta_value = '' ) {
	if ( PORTAL_PICK_META !== $meta_key || portal_community_deleting( (int) $post_id ) ) {
		return; // a story being deleted loses its fields; that is not an editor's "unpick".
	}
	$on = 'deleted_post_meta' !== current_action() && rest_sanitize_boolean( $meta_value );
	if ( $on ) {
		update_post_meta( $post_id, PORTAL_PICKED_AT_META, time() );
	} else {
		delete_post_meta( $post_id, PORTAL_PICKED_AT_META );
	}
	do_action( 'portal_admin_action', $on ? 'pick' : 'unpick', 'post', (int) $post_id, get_current_user_id(), array() );
	portal_community_refresh();
}
add_action( 'added_post_meta', 'portal_pick_changed', 10, 4 );
add_action( 'updated_post_meta', 'portal_pick_changed', 10, 4 );
add_action( 'deleted_post_meta', 'portal_pick_changed', 10, 4 );

/**
 * The editors' picks: published community stories, newest pick first.
 *
 * @param int $limit How many.
 * @return WP_Post[]
 */
function portal_get_editors_picks( $limit = 4 ) {
	return ( new WP_Query(
		array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'cat'                 => portal_community_category_id(),
			'posts_per_page'      => $limit,
			'has_password'        => false,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'meta_query'          => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- few picks.
				'pick' => array(
					'key'   => PORTAL_PICK_META,
					'value' => '1',
				),
				'when' => array(
					'key'     => PORTAL_PICKED_AT_META,
					'compare' => 'EXISTS',
					'type'    => 'NUMERIC',
				),
			),
			'orderby'             => array(
				'when' => 'DESC',
				'date' => 'DESC',
			),
		)
	) )->posts;
}

/**
 * "Pick for Community" / "Remove from picks" under each published community story in
 * wp-admin → Posts (editors only), so picking takes one click.
 *
 * @param array   $actions Row links.
 * @param WP_Post $post    Post.
 * @return array
 */
function portal_pick_row_action( $actions, $post ) {
	if ( 'post' !== $post->post_type || ! current_user_can( PORTAL_CAP_EDITORIAL_FIELDS ) || ! current_user_can( 'edit_post', $post->ID ) || ! portal_is_community_story( $post ) ) {
		return $actions;
	}
	$picked = (bool) get_post_meta( $post->ID, PORTAL_PICK_META, true );
	$url    = wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'portal_pick',
				'post'   => $post->ID,
				'pick'   => $picked ? 0 : 1,
			),
			admin_url( 'admin-post.php' )
		),
		'portal_pick_' . $post->ID
	);
	$actions['portal_pick'] = '<a href="' . esc_url( $url ) . '">' . esc_html( $picked ? __( 'Remove from picks', 'portal' ) : __( 'Pick for Community', 'portal' ) ) . '</a>';
	return $actions;
}
add_filter( 'post_row_actions', 'portal_pick_row_action', 10, 2 );

/**
 * Handle the one-click pick link.
 */
function portal_pick_handle() {
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
	check_admin_referer( 'portal_pick_' . $post_id );
	if ( ! current_user_can( PORTAL_CAP_EDITORIAL_FIELDS ) || ! current_user_can( 'edit_post', $post_id ) || ! portal_is_community_story( $post_id ) ) {
		wp_die( esc_html__( 'You cannot pick this story. Only published community stories can be picked, by editors.', 'portal' ), '', array( 'response' => 403 ) );
	}
	if ( ! empty( $_GET['pick'] ) ) {
		update_post_meta( $post_id, PORTAL_PICK_META, true );
	} else {
		delete_post_meta( $post_id, PORTAL_PICK_META );
	}
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php' ) );
	exit;
}
add_action( 'admin_post_portal_pick', 'portal_pick_handle' );

/**
 * "— Editor's pick" after the title in wp-admin → Posts.
 *
 * @param array   $states Post states.
 * @param WP_Post $post   Post.
 * @return array
 */
function portal_pick_post_state( $states, $post ) {
	if ( 'post' === $post->post_type && get_post_meta( $post->ID, PORTAL_PICK_META, true ) ) {
		$states['portal_pick'] = __( "Editor's pick", 'portal' );
	}
	return $states;
}
add_filter( 'display_post_states', 'portal_pick_post_state', 10, 2 );

/* ------------------------------------------------------------------ Trending writers */

/**
 * Community writers ranked by reads + 10 × likes on their community stories.
 * Last 30 days first; all-time numbers fill any empty places. Cached for an hour.
 *
 * @param int $limit How many.
 * @return array<int,array{id:int,score:int,stories:int}>
 */
function portal_get_trending_writers( $limit = 6 ) {
	$cached = get_transient( 'portal_trending_writers' );
	if ( is_array( $cached ) ) {
		return array_slice( $cached, 0, $limit );
	}

	$ranked = array();
	foreach ( array( 30, 0 ) as $days ) {
		$args = array(
			'post_type'        => 'post',
			'post_status'      => 'publish',
			'cat'              => portal_community_category_id(),
			'numberposts'      => 500,
			'has_password'     => false,
			'suppress_filters' => true,
		);
		if ( $days ) {
			$args['date_query'] = array( array( 'after' => $days . ' days ago' ) );
		}
		$scores = array();
		foreach ( get_posts( $args ) as $post ) {
			$author = (int) $post->post_author;
			if ( ! isset( $scores[ $author ] ) ) {
				$scores[ $author ] = array(
					'id'      => $author,
					'score'   => 0,
					'stories' => 0,
				);
			}
			$scores[ $author ]['score']  += portal_get_views( $post->ID ) + 10 * portal_get_likes( $post->ID );
			$scores[ $author ]['stories'] += 1;
		}
		usort( $scores, static fn( $a, $b ) => array( $b['score'], $b['stories'] ) <=> array( $a['score'], $a['stories'] ) );
		foreach ( $scores as $row ) {
			if ( ! isset( $ranked[ $row['id'] ] ) && portal_has_public_profile( $row['id'] ) && ! portal_is_banned( $row['id'] ) ) {
				$ranked[ $row['id'] ] = $row;
			}
		}
		if ( count( $ranked ) >= 12 ) {
			break;
		}
	}
	$ranked = array_slice( array_values( $ranked ), 0, 12 );
	set_transient( 'portal_trending_writers', $ranked, HOUR_IN_SECONDS );
	return array_slice( $ranked, 0, $limit );
}

/**
 * A community story was published or unpublished: refresh the page and the ranking.
 * A writer was suspended: drop them from the ranking at once.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post.
 */
function portal_community_status_changed( $new_status, $old_status, $post ) {
	if ( $new_status !== $old_status && 'post' === $post->post_type && ( 'publish' === $new_status || 'publish' === $old_status ) && has_category( portal_community_category_id(), $post ) ) {
		portal_community_refresh();
	}
}
add_action( 'transition_post_status', 'portal_community_status_changed', 10, 3 );

/**
 * Forget the ranking when a writer is suspended or reinstated.
 *
 * @param int    $meta_id  Unused.
 * @param int    $user_id  Unused.
 * @param string $meta_key Meta key.
 */
function portal_community_ban_changed( $meta_id, $user_id, $meta_key ) {
	if ( defined( 'PORTAL_BANNED_META' ) && PORTAL_BANNED_META === $meta_key ) {
		portal_community_refresh();
	}
}
add_action( 'added_user_meta', 'portal_community_ban_changed', 10, 3 );
add_action( 'updated_user_meta', 'portal_community_ban_changed', 10, 3 );
add_action( 'deleted_user_meta', 'portal_community_ban_changed', 10, 3 );

/* ------------------------------------------------------------------ Series and newest */

/**
 * Newest series with at least one published part.
 *
 * @param int $limit How many.
 * @return WP_Term[]
 */
function portal_get_new_series( $limit = 4 ) {
	if ( ! defined( 'PORTAL_SERIES_TAX' ) ) {
		return array();
	}
	$terms = get_terms(
		array(
			'taxonomy'   => PORTAL_SERIES_TAX,
			'hide_empty' => true, // counts only published parts.
			'orderby'    => 'term_id',
			'order'      => 'DESC',
			'number'     => $limit,
		)
	);
	return is_wp_error( $terms ) ? array() : $terms;
}

/**
 * Cover of a series, or the photo of its first published part.
 *
 * @param WP_Term $term Series.
 * @return string
 */
function portal_series_card_image( WP_Term $term ) {
	$cover = portal_series_cover_url( $term );
	if ( $cover ) {
		return $cover;
	}
	$parts = portal_series_parts( $term );
	$url   = $parts ? get_the_post_thumbnail_url( $parts[0], 'medium_large' ) : '';
	return $url ? (string) $url : '';
}

/**
 * Newest community stories.
 *
 * @param int   $limit   How many.
 * @param int[] $exclude Story IDs already shown (e.g. the picks).
 * @return WP_Post[]
 */
function portal_get_community_latest( $limit = 12, array $exclude = array() ) {
	return ( new WP_Query(
		array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'cat'                 => portal_community_category_id(),
			'posts_per_page'      => $limit,
			'post__not_in'        => array_map( 'intval', $exclude ),
			'has_password'        => false,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		)
	) )->posts;
}

/* ------------------------------------------------------------------ API */

/**
 * Everything on the Community page, in one call (for the app).
 */
function portal_register_community_routes() {
	register_rest_route(
		'portal/v1',
		'/community',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => static function () {
				$picks   = portal_get_editors_picks( 4 );
				$writers = array();
				foreach ( portal_get_trending_writers( 6 ) as $row ) {
					$profile = portal_get_profile( $row['id'] );
					if ( $profile ) {
						$writers[] = $profile + array( 'score' => $row['score'] );
					}
				}
				$series = array();
				foreach ( portal_get_new_series( 4 ) as $term ) {
					$owner    = portal_series_owner( $term );
					$series[] = array(
						'id'          => $term->term_id,
						'name'        => $term->name,
						'link'        => get_term_link( $term ),
						'image'       => portal_series_card_image( $term ) ? portal_series_card_image( $term ) : null,
						'writer'      => $owner ? portal_get_profile( $owner ) : null,
						'parts_count' => (int) $term->count,
					);
				}
				return rest_ensure_response(
					array(
						'picks'   => array_map( 'portal_feed_item', $picks ),
						'writers' => $writers,
						'series'  => $series,
						'latest'  => array_map( 'portal_feed_item', portal_get_community_latest( 12, wp_list_pluck( $picks, 'ID' ) ) ),
						'link'    => portal_community_page_url(),
					)
				);
			},
		)
	);
}
add_action( 'rest_api_init', 'portal_register_community_routes' );
