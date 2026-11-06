<?php
/**
 * Breaking news, trending stories and view counts.
 *
 * The website (home page, breaking strip) and the future mobile app use the same
 * functions, so "what counts as breaking / trending" is decided in one place:
 *
 *   GET  /wp-json/portal/v1/breaking         posts flagged Breaking, published in the last 24 hours
 *   GET  /wp-json/portal/v1/trending         most-read posts of the last 7 days
 *   POST /wp-json/portal/v1/views/<post-id>  count one read (sent by the article page)
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/*
 * Leading underscore = "protected" meta: hidden from the Custom Fields box and not
 * writable through /wp/v2/posts. Only portal_count_view() changes it.
 */
const PORTAL_VIEWS_META = '_portal_views';

/**
 * How long a Breaking post stays in the strip.
 *
 * @return int Hours.
 */
function portal_breaking_hours() {
	return max( 1, (int) apply_filters( 'portal_breaking_hours', 24 ) );
}

/**
 * Published posts flagged Breaking, newest first, from the last portal_breaking_hours().
 *
 * @param int $limit Maximum number of posts.
 * @return WP_Post[]
 */
function portal_get_breaking_posts( $limit = 3 ) {
	$query = new WP_Query(
		array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'meta_key'            => 'portal_breaking', // phpcs:ignore WordPress.DB.SlowDBQuery -- tiny result set.
			'meta_value'          => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
			'date_query'          => array(
				array(
					'column' => 'post_date_gmt',
					'after'  => gmdate( 'Y-m-d H:i:s', time() - portal_breaking_hours() * HOUR_IN_SECONDS ),
				),
			),
		)
	);
	return $query->posts;
}

/**
 * Most-read published posts of the last $days days.
 *
 * On a new or quiet site there may be fewer read posts than $limit; the list is then
 * topped up with the newest posts so the Trending box is never half empty.
 *
 * @param int $limit Number of posts.
 * @param int $days  Look-back window.
 * @return WP_Post[]
 */
function portal_get_trending_posts( $limit = 5, $days = 7 ) {
	$base = array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
	);

	$posts = ( new WP_Query(
		$base + array(
			'posts_per_page' => $limit,
			'date_query'     => array( array( 'after' => $days . ' days ago' ) ),
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'views' => array(
					'key'     => PORTAL_VIEWS_META,
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
			),
			'orderby'        => array(
				'views' => 'DESC',
				'date'  => 'DESC',
			),
		)
	) )->posts;

	if ( count( $posts ) < $limit ) {
		$fill  = ( new WP_Query(
			$base + array(
				'posts_per_page' => $limit - count( $posts ),
				'post__not_in'   => wp_list_pluck( $posts, 'ID' ),
			)
		) )->posts;
		$posts = array_merge( $posts, $fill );
	}
	return $posts;
}

/**
 * Number of counted reads of a post.
 *
 * @param int $post_id Post ID.
 * @return int
 */
function portal_get_views( $post_id ) {
	return (int) get_post_meta( $post_id, PORTAL_VIEWS_META, true );
}

/**
 * Add one read to a post.
 *
 * Uses a single SQL "+ 1" instead of read-then-write, so two readers arriving at the
 * same moment are both counted. It also fires no post-update hooks, so a read does
 * not make the page cache throw away the article.
 *
 * @param int $post_id Post ID.
 * @return int New total.
 */
function portal_count_view( $post_id ) {
	global $wpdb;
	add_post_meta( $post_id, PORTAL_VIEWS_META, 0, true ); // Creates the row only if missing.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic counter, cache cleared below.
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s",
			$post_id,
			PORTAL_VIEWS_META
		)
	);
	wp_cache_delete( $post_id, 'post_meta' );
	return portal_get_views( $post_id );
}

/**
 * Whether a post may be counted / listed publicly.
 *
 * @param WP_Post|null $post Post.
 * @return bool
 */
function portal_is_public_post( $post ) {
	return $post instanceof WP_Post
		&& 'post' === $post->post_type
		&& 'publish' === $post->post_status
		&& '' === $post->post_password;
}

/**
 * Short public summary of a post for the portal/v1 lists.
 *
 * @param WP_Post $post Post.
 * @return array
 */
function portal_feed_item( WP_Post $post ) {
	return array(
		'id'       => $post->ID,
		'title'    => get_the_title( $post ),
		'link'     => get_permalink( $post ),
		'date_gmt' => mysql_to_rfc3339( $post->post_date_gmt ),
		'image'    => get_the_post_thumbnail_url( $post, 'medium_large' ) ? get_the_post_thumbnail_url( $post, 'medium_large' ) : null,
		'views'    => portal_get_views( $post->ID ),
	);
}

/**
 * REST routes.
 */
function portal_register_feed_routes() {
	register_rest_route(
		'portal/v1',
		'/breaking',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => static function () {
				return rest_ensure_response( array_map( 'portal_feed_item', portal_get_breaking_posts( 5 ) ) );
			},
		)
	);

	register_rest_route(
		'portal/v1',
		'/trending',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'args'                => array(
				'per_page' => array(
					'type'    => 'integer',
					'default' => 5,
					'minimum' => 1,
					'maximum' => 20,
				),
			),
			'callback'            => static function ( WP_REST_Request $request ) {
				return rest_ensure_response( array_map( 'portal_feed_item', portal_get_trending_posts( (int) $request['per_page'] ) ) );
			},
		)
	);

	register_rest_route(
		'portal/v1',
		'/views/(?P<id>\d+)',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			// Public on purpose: readers are counted whether or not they are logged in.
			'permission_callback' => '__return_true',
			'args'                => array(
				'id' => array(
					'type'     => 'integer',
					'required' => true,
				),
			),
			'callback'            => static function ( WP_REST_Request $request ) {
				$post = get_post( (int) $request['id'] );
				if ( ! portal_is_public_post( $post ) ) {
					return new WP_Error( 'portal_not_found', __( 'Post not found.', 'portal' ), array( 'status' => 404 ) );
				}
				return rest_ensure_response(
					array(
						'id'    => $post->ID,
						'views' => portal_count_view( $post->ID ),
					)
				);
			},
		)
	);

	// Read-only "portal_views" on every post in /wp/v2/posts (the app can show it).
	register_rest_field(
		'post',
		'portal_views',
		array(
			'get_callback' => static function ( $data ) {
				return portal_get_views( (int) $data['id'] );
			},
			'schema'       => array(
				'description' => __( 'Number of counted reads.', 'portal' ),
				'type'        => 'integer',
				'readonly'    => true,
				'context'     => array( 'view', 'edit' ),
			),
		)
	);
}
add_action( 'rest_api_init', 'portal_register_feed_routes' );

/**
 * On an article page, count the read from the browser.
 *
 * Done in JavaScript (not PHP) because the live site serves cached pages: PHP would
 * not run for most readers, but this small script does. sessionStorage stops a reader
 * from being counted again when they reload the page.
 */
function portal_enqueue_view_counter() {
	if ( ! is_singular( 'post' ) || is_preview() || ! portal_is_public_post( get_queried_object() ) ) {
		return;
	}
	$id  = get_queried_object_id();
	$js  = sprintf(
		'(function(u,k){try{if(sessionStorage.getItem(k)){return;}sessionStorage.setItem(k,"1");}catch(e){}'
		. 'if(window.fetch){fetch(u,{method:"POST",credentials:"omit",keepalive:true}).catch(function(){});}})(%s,%s);',
		wp_json_encode( rest_url( 'portal/v1/views/' . $id ) ),
		wp_json_encode( 'portal_viewed_' . $id )
	);
	wp_register_script( 'portal-views', false, array(), PORTAL_CORE_VERSION, array( 'in_footer' => true ) );
	wp_enqueue_script( 'portal-views' );
	wp_add_inline_script( 'portal-views', $js );
}
add_action( 'wp_enqueue_scripts', 'portal_enqueue_view_counter' );
