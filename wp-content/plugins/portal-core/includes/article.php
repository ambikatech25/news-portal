<?php
/**
 * Article helpers shared by the website and the future app: read time and related posts.
 *
 *   GET /wp-json/portal/v1/related/<post-id>   up to 4 related posts
 *   "portal_read_minutes" on every post in /wp/v2/posts
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Minutes needed to read a post (at least 1).
 *
 * Words are counted by splitting on spaces, not with str_word_count(): that PHP function
 * only understands English letters and would count a Hindi article as almost zero words.
 *
 * @param WP_Post $post Post.
 * @return int
 */
function portal_read_minutes( WP_Post $post ) {
	$text  = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
	$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
	$wpm   = max( 50, (int) apply_filters( 'portal_words_per_minute', 200 ) );
	return max( 1, (int) ceil( count( (array) $words ) / $wpm ) );
}

/**
 * Posts related to $post: the newest posts from the same categories, topped up with the
 * newest posts overall so the block is always full. The post itself is never included.
 *
 * @param WP_Post $post  Post.
 * @param int     $limit Number of posts.
 * @return WP_Post[]
 */
function portal_get_related_posts( WP_Post $post, $limit = 4 ) {
	$base = array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
		'has_password'        => false,
	);

	$posts = array();
	$cats  = wp_get_post_categories( $post->ID );
	if ( $cats ) {
		$posts = ( new WP_Query(
			$base + array(
				'posts_per_page' => $limit,
				'category__in'   => $cats,
				'post__not_in'   => array( $post->ID ),
			)
		) )->posts;
	}

	if ( count( $posts ) < $limit ) {
		$fill  = ( new WP_Query(
			$base + array(
				'posts_per_page' => $limit - count( $posts ),
				'post__not_in'   => array_merge( array( $post->ID ), wp_list_pluck( $posts, 'ID' ) ),
			)
		) )->posts;
		$posts = array_merge( $posts, $fill );
	}
	return $posts;
}

/**
 * REST: related posts route and the read-time field.
 */
function portal_register_article_routes() {
	register_rest_route(
		'portal/v1',
		'/related/(?P<id>\d+)',
		array(
			'methods'             => WP_REST_Server::READABLE,
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
				return rest_ensure_response( array_map( 'portal_feed_item', portal_get_related_posts( $post ) ) );
			},
		)
	);

	register_rest_field(
		'post',
		'portal_read_minutes',
		array(
			'get_callback' => static function ( $data ) {
				$post = get_post( (int) $data['id'] );
				return $post ? portal_read_minutes( $post ) : 1;
			},
			'schema'       => array(
				'description' => __( 'Minutes needed to read the post.', 'portal' ),
				'type'        => 'integer',
				'readonly'    => true,
				'context'     => array( 'view', 'edit' ),
			),
		)
	);
}
add_action( 'rest_api_init', 'portal_register_article_routes' );
