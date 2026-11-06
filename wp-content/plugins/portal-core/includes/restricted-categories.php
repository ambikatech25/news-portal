<?php
/**
 * Editor-only categories (News, Crime, Case Files).
 *
 * Contributors and Authors must never be able to put a post in these categories.
 * Enforced on the server in two layers:
 *
 *  1. REST API gate: requests that add a restricted category are refused with a
 *     clear error (block editor, mobile app, any API client).
 *  2. Safety net: whenever categories are attached by any other route (quick edit,
 *     XML-RPC, other plugins), newly added restricted categories are removed again.
 *
 * Restricted categories that an Editor already set on a post are left alone, so an
 * Author editing their own post does not undo an Editor's decision.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Slugs of the editor-only categories.
 *
 * @return string[]
 */
function portal_restricted_category_slugs() {
	/**
	 * Filters the editor-only category slugs.
	 *
	 * @param string[] $slugs Category slugs.
	 */
	return (array) apply_filters( 'portal_restricted_category_slugs', array( 'news', 'crime', 'case-files' ) );
}

/**
 * Term IDs of the editor-only categories that exist on this site.
 *
 * @return int[]
 */
function portal_restricted_category_ids() {
	$ids = array();
	foreach ( portal_restricted_category_slugs() as $slug ) {
		$term = get_term_by( 'slug', $slug, 'category' );
		if ( $term ) {
			$ids[] = (int) $term->term_id;
		}
	}
	return $ids;
}

/**
 * Whether the current user may use the editor-only categories.
 *
 * @return bool
 */
function portal_current_user_can_use_restricted_categories() {
	return current_user_can( PORTAL_CAP_RESTRICTED_CATEGORIES );
}

/**
 * Comma-separated names of the given category IDs, for messages.
 *
 * @param int[] $term_ids Category IDs.
 * @return string
 */
function portal_category_names( $term_ids ) {
	$names = array();
	foreach ( $term_ids as $term_id ) {
		$term = get_term( $term_id, 'category' );
		if ( $term && ! is_wp_error( $term ) ) {
			$names[] = $term->name;
		}
	}
	return implode( ', ', $names );
}

/**
 * Layer 1: refuse REST requests that add an editor-only category.
 *
 * @param stdClass|WP_Error $prepared_post Post about to be saved.
 * @param WP_REST_Request   $request       The REST request.
 * @return stdClass|WP_Error
 */
function portal_rest_block_restricted_categories( $prepared_post, $request ) {
	if ( is_wp_error( $prepared_post ) || ! isset( $request['categories'] ) ) {
		return $prepared_post;
	}
	if ( portal_current_user_can_use_restricted_categories() ) {
		return $prepared_post;
	}

	$requested = array_map( 'absint', (array) $request['categories'] );
	$existing  = empty( $prepared_post->ID ) ? array() : array_map( 'absint', wp_get_post_categories( $prepared_post->ID ) );
	$blocked   = array_diff( array_intersect( $requested, portal_restricted_category_ids() ), $existing );

	if ( empty( $blocked ) ) {
		return $prepared_post;
	}

	return new WP_Error(
		'portal_restricted_category',
		sprintf(
			/* translators: %s: category names, e.g. "News, Crime". */
			__( 'Only editors can publish in: %s. Please choose a different category.', 'portal' ),
			portal_category_names( $blocked )
		),
		array( 'status' => 403 )
	);
}
add_filter( 'rest_pre_insert_post', 'portal_rest_block_restricted_categories', 10, 2 );

/**
 * Layer 2: remove editor-only categories that were just added by a user without permission.
 *
 * Runs after WordPress attaches terms to a post, whatever route the change came from.
 *
 * @param int    $object_id  Post ID.
 * @param array  $terms      Terms passed in (unused).
 * @param array  $tt_ids     Term taxonomy IDs now attached.
 * @param string $taxonomy   Taxonomy slug.
 * @param bool   $append     Whether terms were appended (unused).
 * @param array  $old_tt_ids Term taxonomy IDs attached before the change.
 */
function portal_enforce_restricted_categories( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
	static $running = false;

	if ( $running || 'category' !== $taxonomy || 'post' !== get_post_type( $object_id ) ) {
		return;
	}
	// No logged-in user = system task (setup scripts, cron). Only real users are restricted.
	if ( 0 === get_current_user_id() || portal_current_user_can_use_restricted_categories() ) {
		return;
	}

	$added_tt_ids = array_diff( array_map( 'intval', $tt_ids ), array_map( 'intval', $old_tt_ids ) );
	if ( empty( $added_tt_ids ) ) {
		return;
	}

	$remove = array();
	foreach ( portal_restricted_category_ids() as $term_id ) {
		$term = get_term( $term_id, 'category' );
		if ( $term && in_array( (int) $term->term_taxonomy_id, $added_tt_ids, true ) ) {
			$remove[] = $term_id;
		}
	}
	if ( empty( $remove ) ) {
		return;
	}

	$running = true;
	wp_remove_object_terms( $object_id, $remove, 'category' );
	if ( empty( wp_get_object_terms( $object_id, 'category', array( 'fields' => 'ids' ) ) ) ) {
		wp_set_object_terms( $object_id, array( (int) get_option( 'default_category' ) ), 'category' );
	}
	$running = false;
}
add_action( 'set_object_terms', 'portal_enforce_restricted_categories', 10, 6 );
