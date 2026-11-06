<?php
/**
 * Small helpers used by the home page and story cards.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Category sections shown on the home page, in order (category slugs).
 *
 * @return string[]
 */
function portal_child_home_sections() {
	return (array) apply_filters( 'portal_child_home_sections', array( 'news', 'region', 'industry', 'logistics', 'crime' ) );
}

/**
 * Published posts. A thin wrapper so every home page query is lean:
 * no total count (no pagination needed) and sticky posts are not pushed to the front.
 *
 * @param array $args WP_Query arguments.
 * @return WP_Post[]
 */
function portal_child_get_posts( array $args ) {
	$defaults = array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
	);
	return ( new WP_Query( wp_parse_args( $args, $defaults ) ) )->posts;
}

/**
 * ID of the Community Blogs category (0 if missing).
 *
 * @return int
 */
function portal_child_blog_category_id() {
	$cat = get_category_by_slug( 'community-blogs' );
	return $cat ? (int) $cat->term_id : 0;
}

/**
 * The top story: the newest post an editor marked "Stick to the top of the blog",
 * otherwise the newest news-desk post (not a Community Blog).
 *
 * @return WP_Post|null
 */
function portal_child_top_story() {
	$sticky = array_filter( array_map( 'absint', (array) get_option( 'sticky_posts', array() ) ) );
	if ( $sticky ) {
		$posts = portal_child_get_posts(
			array(
				'post__in'       => $sticky,
				'posts_per_page' => 1,
			)
		);
		if ( $posts ) {
			return $posts[0];
		}
	}
	$posts = portal_child_get_posts(
		array(
			'posts_per_page'   => 1,
			'category__not_in' => array( portal_child_blog_category_id() ),
		)
	);
	return $posts[0] ?? null;
}

/**
 * "2 hours ago" for posts from the last day, otherwise the date.
 *
 * @param WP_Post $post Post.
 * @return string
 */
function portal_child_time_label( WP_Post $post ) {
	$time = get_post_timestamp( $post );
	if ( $time && ( time() - $time ) < DAY_IN_SECONDS ) {
		/* translators: %s: time difference, e.g. "2 hours". */
		return sprintf( __( '%s ago', 'portal' ), human_time_diff( $time ) );
	}
	return get_the_date( '', $post );
}

/**
 * Print the Verified / Premium badges for a post (nothing if neither applies).
 *
 * @param WP_Post $post Post.
 */
function portal_child_badges( WP_Post $post ) {
	$badges = array();
	if ( get_post_meta( $post->ID, 'portal_verified', true ) ) {
		$badges[] = '<span class="portal-badge portal-badge--verified">&#10003; ' . esc_html__( 'Verified', 'portal' ) . '</span>';
	}
	if ( get_post_meta( $post->ID, 'portal_is_premium', true ) ) {
		$badges[] = '<span class="portal-badge portal-badge--premium">' . esc_html__( 'Premium', 'portal' ) . '</span>';
	}
	if ( $badges ) {
		echo '<span class="portal-badges">' . implode( '', $badges ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above.
	}
}
