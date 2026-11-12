<?php
/**
 * Series (Task 17A): a writer groups stories into a series (Part 1, 2, 3...) with a cover
 * photo and a short description.
 *
 * How it is stored (normal WordPress data, so the website, wp-admin and the future app share it):
 *   - a series is a term of the taxonomy "portal_series" (address /series/<name>/);
 *   - term meta "portal_series_owner" = the writer who made it, "portal_series_cover" = photo;
 *   - post meta "portal_series_part" = the story's place in the series (1, 2, 3...).
 *
 * Rules (enforced here, on every route: Write page, My Account, REST API, wp-admin):
 *   - anyone who can write (Contributor and up) can create a series, up to 30 each;
 *   - writers put only their OWN stories into their OWN series; editors can do anything;
 *   - a story belongs to at most one series;
 *   - readers only ever see published parts, numbered 1..N in order, so a part still waiting
 *     for review never shows up as a gap ("Part 3 of 2");
 *   - a series has no public page until at least one part is published.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

const PORTAL_SERIES_TAX            = 'portal_series';
const PORTAL_SERIES_PART_META      = 'portal_series_part';
const PORTAL_SERIES_OWNER_META     = 'portal_series_owner';
const PORTAL_SERIES_COVER_META     = 'portal_series_cover';
const PORTAL_SERIES_MAX_PER_WRITER = 30;

/* ------------------------------------------------------------------ Registration */

/**
 * Register the taxonomy and its fields.
 */
function portal_register_series() {
	register_taxonomy(
		PORTAL_SERIES_TAX,
		'post',
		array(
			'labels'             => array(
				'name'          => __( 'Series', 'portal' ),
				'singular_name' => __( 'Series', 'portal' ),
				'search_items'  => __( 'Search series', 'portal' ),
				'all_items'     => __( 'All series', 'portal' ),
				'edit_item'     => __( 'Edit series', 'portal' ),
				'update_item'   => __( 'Update series', 'portal' ),
				'add_new_item'  => __( 'Add new series', 'portal' ),
				'new_item_name' => __( 'New series name', 'portal' ),
				'not_found'     => __( 'No series found.', 'portal' ),
				'back_to_items' => __( '← Back to series', 'portal' ),
				'menu_name'     => __( 'Series', 'portal' ),
			),
			'public'             => true,
			'hierarchical'       => false,
			'show_ui'            => true,
			'show_admin_column'  => true,
			'show_tagcloud'      => false,
			'show_in_quick_edit' => false,
			'show_in_rest'       => true,
			'rest_base'          => 'series',
			'rewrite'            => array(
				'slug'       => 'series',
				'with_front' => false,
			),
			// Writers create series through portal-core (which records the owner), not through
			// wp-admin's term screens; editors manage every series there.
			'capabilities'       => array(
				'manage_terms' => 'edit_others_posts',
				'edit_terms'   => 'edit_others_posts',
				'delete_terms' => 'edit_others_posts',
				'assign_terms' => 'edit_posts',
			),
		)
	);

	register_post_meta(
		'post',
		PORTAL_SERIES_PART_META,
		array(
			'type'              => 'integer',
			'single'            => true,
			'default'           => 0,
			'description'       => __( 'Place of the story in its series (1, 2, 3...). Set automatically.', 'portal' ),
			'show_in_rest'      => true,
			'sanitize_callback' => 'absint',
			'auth_callback'     => static fn( $allowed, $key, $post_id ) => current_user_can( 'edit_post', $post_id ),
		)
	);

	foreach ( array( PORTAL_SERIES_OWNER_META, PORTAL_SERIES_COVER_META ) as $key ) {
		register_term_meta(
			PORTAL_SERIES_TAX,
			$key,
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => static fn() => current_user_can( 'edit_others_posts' ),
			)
		);
	}
}
add_action( 'init', 'portal_register_series', 9 );

/* ------------------------------------------------------------------ Reading */

/**
 * A series term, from an ID or term.
 *
 * @param int|WP_Term $term Term or ID.
 * @return WP_Term|null
 */
function portal_get_series( $term ) {
	$term = get_term( $term instanceof WP_Term ? $term->term_id : (int) $term, PORTAL_SERIES_TAX );
	return $term instanceof WP_Term ? $term : null;
}

/**
 * Who made a series.
 *
 * @param WP_Term $term Series.
 * @return int User ID (0 if unknown).
 */
function portal_series_owner( WP_Term $term ) {
	return (int) get_term_meta( $term->term_id, PORTAL_SERIES_OWNER_META, true );
}

/**
 * The series a story belongs to.
 *
 * @param int|WP_Post $post Post.
 * @return WP_Term|null
 */
function portal_post_series( $post ) {
	$post  = get_post( $post );
	$terms = $post ? get_the_terms( $post, PORTAL_SERIES_TAX ) : false;
	return ( $terms && ! is_wp_error( $terms ) ) ? $terms[0] : null;
}

/**
 * Every status a writer's story can be in (for the writer's own view of a series).
 *
 * @return string[]
 */
function portal_series_all_statuses() {
	return array( 'publish', 'future', 'pending', 'draft', 'portal_changes', 'portal_rejected', 'portal_takedown', 'private' );
}

/**
 * The stories of a series, in reading order (part number, then date).
 *
 * @param int|WP_Term $term     Series.
 * @param string[]    $statuses Statuses to include; default: only what readers may see.
 * @return WP_Post[]
 */
function portal_series_parts( $term, array $statuses = array( 'publish' ) ) {
	$term = portal_get_series( $term );
	if ( ! $term ) {
		return array();
	}
	$posts = get_posts(
		array(
			'post_type'        => 'post',
			'post_status'      => $statuses,
			'numberposts'      => 200,
			'orderby'          => 'date',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- small, indexed lookup.
				array(
					'taxonomy' => PORTAL_SERIES_TAX,
					'field'    => 'term_id',
					'terms'    => $term->term_id,
				),
			),
		)
	);
	if ( array( 'publish' ) === $statuses ) {
		$posts = array_values( array_filter( $posts, 'portal_is_public_post' ) ); // no password-protected parts.
	}
	// Stories without a number (e.g. added by an editor in wp-admin) go last, by date.
	usort(
		$posts,
		static function ( $a, $b ) {
			$pa = (int) get_post_meta( $a->ID, PORTAL_SERIES_PART_META, true ) ?: PHP_INT_MAX;
			$pb = (int) get_post_meta( $b->ID, PORTAL_SERIES_PART_META, true ) ?: PHP_INT_MAX;
			return array( $pa, $a->post_date_gmt, $a->ID ) <=> array( $pb, $b->post_date_gmt, $b->ID );
		}
	);
	return $posts;
}

/**
 * Where a published story sits in its series, as readers see it.
 *
 * @param int|WP_Post $post Post.
 * @return array{series:WP_Term,number:int,total:int,first:WP_Post,prev:?WP_Post,next:?WP_Post}|null
 */
function portal_series_position( $post ) {
	$post   = get_post( $post );
	$series = $post ? portal_post_series( $post ) : null;
	if ( ! $series || ! portal_is_public_post( $post ) ) {
		return null;
	}
	$parts = portal_series_parts( $series );
	$index = array_search( $post->ID, wp_list_pluck( $parts, 'ID' ), true );
	if ( false === $index ) {
		return null;
	}
	return array(
		'series' => $series,
		'number' => $index + 1,
		'total'  => count( $parts ),
		'first'  => $parts[0],
		'prev'   => $parts[ $index - 1 ] ?? null,
		'next'   => $parts[ $index + 1 ] ?? null,
	);
}

/**
 * Cover photo address, or '' when there is none.
 *
 * @param WP_Term $term Series.
 * @param string  $size Image size.
 * @return string
 */
function portal_series_cover_url( WP_Term $term, $size = 'medium_large' ) {
	$cover = (int) get_term_meta( $term->term_id, PORTAL_SERIES_COVER_META, true );
	$url   = $cover ? wp_get_attachment_image_url( $cover, $size ) : '';
	return $url ? (string) $url : '';
}

/**
 * Whether a series has a public page (at least one published part).
 * The term count only counts published stories (WordPress keeps it up to date).
 *
 * @param WP_Term $term Series.
 * @return bool
 */
function portal_series_is_public( WP_Term $term ) {
	return $term->count > 0;
}

/**
 * The series a user made.
 *
 * @param int $user_id User ID.
 * @return WP_Term[]
 */
function portal_get_user_series( $user_id ) {
	$terms = get_terms(
		array(
			'taxonomy'   => PORTAL_SERIES_TAX,
			'hide_empty' => false,
			'orderby'    => 'term_id',
			'order'      => 'DESC',
			'number'     => 100,
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- few series per site.
				array(
					'key'   => PORTAL_SERIES_OWNER_META,
					'value' => (int) $user_id,
					'type'  => 'NUMERIC',
				),
			),
		)
	);
	return is_wp_error( $terms ) ? array() : $terms;
}

/* ------------------------------------------------------------------ Permissions */

/**
 * Whether a user may edit a series (name, description, cover, order, delete).
 *
 * @param WP_Term $term    Series.
 * @param int     $user_id User ID.
 * @return bool
 */
function portal_can_manage_series( WP_Term $term, $user_id ) {
	$user_id = (int) $user_id;
	return $user_id && ( user_can( $user_id, 'edit_others_posts' ) || portal_series_owner( $term ) === $user_id );
}

/**
 * Whether a story may be put into a series by this user.
 * Editors: always. Writers: only their own story into their own series. With no user (a
 * scheduled job, WP-CLI) the series still has to belong to the story's writer.
 *
 * @param WP_Term $term    Series.
 * @param WP_Post $post    Story.
 * @param int     $user_id Acting user (0 = the system).
 * @return bool
 */
function portal_can_use_series( WP_Term $term, WP_Post $post, $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id && user_can( $user_id, 'edit_others_posts' ) ) {
		return true;
	}
	$owner = portal_series_owner( $term );
	if ( ! $owner || (int) $post->post_author !== $owner ) {
		return false;
	}
	return ! $user_id || $owner === $user_id;
}

/* ------------------------------------------------------------------ Changing */

/**
 * Check and clean a series name and description.
 *
 * @param string $name        Name.
 * @param string $description Description.
 * @return array{0:string,1:string}|WP_Error
 */
function portal_series_clean_fields( $name, $description ) {
	$name        = trim( sanitize_text_field( (string) $name ) );
	$description = trim( sanitize_textarea_field( (string) $description ) );
	if ( mb_strlen( $name ) < 3 || mb_strlen( $name ) > 80 ) {
		return new WP_Error( 'portal_series_name', __( 'The series name must be 3 to 80 characters long.', 'portal' ), array( 'status' => 400 ) );
	}
	if ( mb_strlen( $description ) > 600 ) {
		return new WP_Error( 'portal_series_description', __( 'The description can be at most 600 characters long.', 'portal' ), array( 'status' => 400 ) );
	}
	return array( $name, $description );
}

/**
 * Create a series for a writer.
 *
 * @param int    $user_id     Writer.
 * @param string $name        Name.
 * @param string $description Description.
 * @return int|WP_Error Term ID.
 */
function portal_create_series( $user_id, $name, $description = '' ) {
	if ( ! user_can( (int) $user_id, 'edit_posts' ) ) {
		return new WP_Error( 'portal_series_forbidden', __( 'Only writers can create a series. Ask to become a writer first.', 'portal' ), array( 'status' => 403 ) );
	}
	$fields = portal_series_clean_fields( $name, $description );
	if ( is_wp_error( $fields ) ) {
		return $fields;
	}
	if ( count( portal_get_user_series( $user_id ) ) >= PORTAL_SERIES_MAX_PER_WRITER ) {
		return new WP_Error( 'portal_series_limit', __( 'You already have 30 series. Please add new parts to an existing one.', 'portal' ), array( 'status' => 400 ) );
	}
	// Two writers may both call their series "My journey": each gets its own address.
	$slug   = sanitize_title( $fields[0] );
	$slug   = wp_unique_term_slug( '' !== $slug ? $slug : 'series', (object) array( 'taxonomy' => PORTAL_SERIES_TAX, 'parent' => 0 ) );
	$result = wp_insert_term(
		$fields[0],
		PORTAL_SERIES_TAX,
		array(
			'slug'        => $slug,
			'description' => $fields[1],
		)
	);
	if ( is_wp_error( $result ) ) {
		$result->add_data( array( 'status' => 400 ) );
		return $result;
	}
	update_term_meta( $result['term_id'], PORTAL_SERIES_OWNER_META, (int) $user_id );
	return (int) $result['term_id'];
}

/**
 * A series made in wp-admin (by an editor) belongs to whoever made it.
 *
 * @param int $term_id Term ID.
 */
function portal_series_default_owner( $term_id ) {
	if ( ! get_term_meta( $term_id, PORTAL_SERIES_OWNER_META, true ) && get_current_user_id() ) {
		update_term_meta( $term_id, PORTAL_SERIES_OWNER_META, get_current_user_id() );
	}
}
add_action( 'created_' . PORTAL_SERIES_TAX, 'portal_series_default_owner' );

/**
 * Change a series' name and description. The address stays the same, so shared links work.
 *
 * @param WP_Term $term        Series.
 * @param string  $name        Name.
 * @param string  $description Description.
 * @return true|WP_Error
 */
function portal_update_series( WP_Term $term, $name, $description ) {
	$fields = portal_series_clean_fields( $name, $description );
	if ( is_wp_error( $fields ) ) {
		return $fields;
	}
	$result = wp_update_term(
		$term->term_id,
		PORTAL_SERIES_TAX,
		array(
			'name'        => $fields[0],
			'description' => $fields[1],
		)
	);
	if ( is_wp_error( $result ) ) {
		$result->add_data( array( 'status' => 400 ) );
		return $result;
	}
	portal_series_purge( $term );
	return true;
}

/**
 * Put the parts of a series in a new order. Stories not listed keep their order, after the
 * listed ones. Numbers are rewritten as 1, 2, 3... so there are never gaps or duplicates.
 *
 * @param WP_Term $term     Series.
 * @param int[]   $post_ids Story IDs in the wanted order.
 */
function portal_reorder_series( WP_Term $term, array $post_ids ) {
	$known = wp_list_pluck( portal_series_parts( $term, portal_series_all_statuses() ), 'ID' );
	$order = array_values( array_unique( array_intersect( array_map( 'intval', $post_ids ), $known ) ) );
	foreach ( $known as $id ) {
		if ( ! in_array( $id, $order, true ) ) {
			$order[] = $id;
		}
	}
	foreach ( $order as $i => $id ) {
		update_post_meta( $id, PORTAL_SERIES_PART_META, $i + 1 );
	}
	portal_series_purge( $term );
}

/**
 * Put a story into a series (or take it out with $term_id = 0).
 *
 * @param int $post_id Story.
 * @param int $term_id Series, or 0 for none.
 * @param int $user_id Acting user.
 * @return true|WP_Error
 */
function portal_assign_series( $post_id, $term_id, $user_id ) {
	$post = get_post( $post_id );
	if ( ! $post || 'post' !== $post->post_type ) {
		return new WP_Error( 'portal_series_story', __( 'Story not found.', 'portal' ), array( 'status' => 404 ) );
	}
	if ( ! $term_id ) {
		wp_set_object_terms( $post->ID, array(), PORTAL_SERIES_TAX );
		return true;
	}
	$term = portal_get_series( $term_id );
	if ( ! $term ) {
		return new WP_Error( 'portal_series_missing', __( 'That series does not exist.', 'portal' ), array( 'status' => 404 ) );
	}
	if ( ! portal_can_use_series( $term, $post, $user_id ) ) {
		return new WP_Error( 'portal_series_not_yours', __( 'You can only add your own stories to your own series.', 'portal' ), array( 'status' => 403 ) );
	}
	$result = wp_set_object_terms( $post->ID, array( $term->term_id ), PORTAL_SERIES_TAX );
	return is_wp_error( $result ) ? $result : true;
}

/**
 * Safety net for every way a story's series can change (block editor, REST API, WP-CLI,
 * plugins): drop series the user may not use, keep at most one, and give the story the
 * next part number when it joins a series.
 *
 * @param int    $object_id  Post ID.
 * @param array  $terms      Requested terms.
 * @param array  $tt_ids     New term-taxonomy IDs.
 * @param string $taxonomy   Taxonomy.
 * @param bool   $append     Appended or replaced.
 * @param array  $old_tt_ids Term-taxonomy IDs before the change.
 */
function portal_guard_series_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
	static $busy = false;
	if ( PORTAL_SERIES_TAX !== $taxonomy || $busy ) {
		return;
	}
	$post = get_post( $object_id );
	if ( ! $post ) {
		return;
	}
	$old     = array_map( 'intval', (array) $old_tt_ids );
	$user    = get_current_user_id();
	$current = wp_get_object_terms( $post->ID, PORTAL_SERIES_TAX );
	$current = is_wp_error( $current ) ? array() : $current;

	$allowed = array();
	foreach ( $current as $term ) {
		$was_there = in_array( (int) $term->term_taxonomy_id, $old, true );
		if ( $was_there || portal_can_use_series( $term, $post, $user ) ) {
			$allowed[ $was_there ? 'old' : 'new' ][] = $term;
		}
	}
	// One series per story: the newly chosen one wins.
	$keep = $allowed['new'][0] ?? ( $allowed['old'][0] ?? null );

	if ( count( $current ) !== ( $keep ? 1 : 0 ) ) {
		$busy = true;
		wp_set_object_terms( $post->ID, $keep ? array( $keep->term_id ) : array(), PORTAL_SERIES_TAX );
		$busy = false;
	}

	$old_term_ids = array();
	foreach ( $old as $tt_id ) {
		$old_term = get_term_by( 'term_taxonomy_id', $tt_id, PORTAL_SERIES_TAX );
		if ( $old_term ) {
			$old_term_ids[] = (int) $old_term->term_id;
			portal_series_purge( $old_term );
		}
	}

	if ( ! $keep ) {
		delete_post_meta( $post->ID, PORTAL_SERIES_PART_META );
		return;
	}
	$joined = ! in_array( (int) $keep->term_id, $old_term_ids, true );
	if ( $joined || ! (int) get_post_meta( $post->ID, PORTAL_SERIES_PART_META, true ) ) {
		update_post_meta( $post->ID, PORTAL_SERIES_PART_META, portal_series_next_part( $keep, $post->ID ) );
	}
	portal_series_purge( $keep );
}
add_action( 'set_object_terms', 'portal_guard_series_terms', 10, 6 );

/**
 * The next free part number in a series (counts parts in every status).
 *
 * @param WP_Term $term    Series.
 * @param int     $exclude Story to leave out (the one being added).
 * @return int
 */
function portal_series_next_part( WP_Term $term, $exclude = 0 ) {
	$max = 0;
	foreach ( portal_series_parts( $term, portal_series_all_statuses() ) as $part ) {
		if ( (int) $part->ID !== (int) $exclude ) {
			$max = max( $max, (int) get_post_meta( $part->ID, PORTAL_SERIES_PART_META, true ) );
		}
	}
	return $max + 1;
}

/**
 * Save an uploaded photo as a series cover, replacing the old one. GPS/camera data is
 * removed on the way in like every upload (includes/image-safety.php).
 *
 * @param WP_Term $term    Series.
 * @param array   $file    One entry of $_FILES.
 * @param int     $user_id Uploader.
 * @return int|WP_Error Attachment ID.
 */
function portal_set_series_cover( WP_Term $term, array $file, $user_id ) {
	$file = portal_check_photo_upload( $file, 'portal_series_cover' );
	if ( is_wp_error( $file ) ) {
		return $file;
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
			/* translators: %s: series name. */
			'post_title'  => sprintf( __( 'Series cover: %s', 'portal' ), $term->name ),
		)
	);
	if ( is_wp_error( $attachment_id ) ) {
		$attachment_id->add_data( array( 'status' => 400 ) );
		return $attachment_id;
	}
	portal_remove_series_cover( $term );
	update_post_meta( $attachment_id, '_portal_series_cover_of', $term->term_id );
	update_term_meta( $term->term_id, PORTAL_SERIES_COVER_META, $attachment_id );
	portal_series_purge( $term );
	return $attachment_id;
}

/**
 * Remove a series cover (deletes the file too, if it was uploaded as this cover).
 *
 * @param WP_Term $term Series.
 */
function portal_remove_series_cover( WP_Term $term ) {
	$old = (int) get_term_meta( $term->term_id, PORTAL_SERIES_COVER_META, true );
	if ( $old && (int) get_post_meta( $old, '_portal_series_cover_of', true ) === (int) $term->term_id ) {
		wp_delete_attachment( $old, true );
	}
	delete_term_meta( $term->term_id, PORTAL_SERIES_COVER_META );
	portal_series_purge( $term );
}

/**
 * Delete a series. Its stories stay published; they just are not a series any more.
 *
 * @param WP_Term $term Series.
 * @return true|WP_Error
 */
function portal_delete_series( WP_Term $term ) {
	$result = wp_delete_term( $term->term_id, PORTAL_SERIES_TAX );
	return ( true === $result ) ? true : new WP_Error( 'portal_series_delete', __( 'The series could not be deleted.', 'portal' ), array( 'status' => 500 ) );
}

/**
 * Before any series is deleted (also from wp-admin): remove its cover and part numbers.
 *
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Taxonomy.
 */
function portal_series_before_delete( $term_id, $taxonomy ) {
	if ( PORTAL_SERIES_TAX !== $taxonomy ) {
		return;
	}
	$term = portal_get_series( $term_id );
	if ( ! $term ) {
		return;
	}
	portal_series_purge( $term );
	foreach ( portal_series_parts( $term, portal_series_all_statuses() ) as $part ) {
		delete_post_meta( $part->ID, PORTAL_SERIES_PART_META );
	}
	portal_remove_series_cover( $term );
}
add_action( 'pre_delete_term', 'portal_series_before_delete', 10, 2 );

/**
 * Ask LiteSpeed Cache (live server) to refresh every page of a series, so "Part X of Y" and
 * "Next part →" are right on the older parts as soon as a new part is published.
 * Does nothing where LiteSpeed is not running.
 *
 * @param WP_Term $term Series.
 */
function portal_series_purge( WP_Term $term ) {
	foreach ( portal_series_parts( $term ) as $part ) {
		do_action( 'litespeed_purge_post', $part->ID );
	}
	$link = get_term_link( $term );
	if ( is_string( $link ) ) {
		do_action( 'litespeed_purge_url', $link );
	}
}

/**
 * A part was published, unpublished or taken down: refresh the other parts' pages.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post.
 */
function portal_series_status_changed( $new_status, $old_status, $post ) {
	if ( $new_status === $old_status || 'post' !== $post->post_type || ( 'publish' !== $new_status && 'publish' !== $old_status ) ) {
		return;
	}
	$series = portal_post_series( $post );
	if ( $series ) {
		portal_series_purge( $series );
	}
}
add_action( 'transition_post_status', 'portal_series_status_changed', 10, 3 );

/* ------------------------------------------------------------------ Series page */

/**
 * A series with no published part has no public page yet (its writer and editors can
 * still open it, to check how it looks).
 */
function portal_series_hide_empty() {
	if ( ! is_tax( PORTAL_SERIES_TAX ) ) {
		return;
	}
	$term = get_queried_object();
	if ( $term instanceof WP_Term && ! portal_series_is_public( $term ) && ! portal_can_manage_series( $term, get_current_user_id() ) ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}
}
add_action( 'template_redirect', 'portal_series_hide_empty', 1 );

/**
 * The series page lists all parts itself, so the main query is one page.
 *
 * @param WP_Query $query Query.
 */
function portal_series_main_query( $query ) {
	if ( ! is_admin() && $query->is_main_query() && $query->is_tax( PORTAL_SERIES_TAX ) ) {
		$query->set( 'posts_per_page', 200 );
	}
}
add_action( 'pre_get_posts', 'portal_series_main_query' );

/* ------------------------------------------------------------------ Write page */

/**
 * "Series" box at the end of the Write a story form (WP User Frontend).
 *
 * @param int $form_id Form ID.
 * @param int $post_id Story being edited (0 for a new story).
 */
function portal_series_form_field( $form_id, $post_id = 0 ) {
	if ( (int) $form_id !== (int) get_option( 'portal_post_form_id' ) || ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	$current = $post_id ? portal_post_series( $post_id ) : null;
	$mine    = portal_get_user_series( get_current_user_id() );
	if ( $current && ! in_array( $current->term_id, wp_list_pluck( $mine, 'term_id' ), true ) ) {
		$mine[] = $current; // an editor's story in someone else's series: keep it selectable.
	}
	?>
	<li class="wpuf-el portal-series-field">
		<div class="wpuf-label"><label for="portal-series-choice"><?php esc_html_e( 'Series (optional)', 'portal' ); ?></label></div>
		<div class="wpuf-fields">
			<?php wp_nonce_field( 'portal_series_pick', 'portal_series_nonce' ); ?>
			<select id="portal-series-choice" name="portal_series_choice">
				<option value="0"><?php esc_html_e( 'Not part of a series', 'portal' ); ?></option>
				<?php foreach ( $mine as $series ) : ?>
					<option value="<?php echo esc_attr( (string) $series->term_id ); ?>" <?php selected( $current && $current->term_id === $series->term_id ); ?>><?php echo esc_html( $series->name ); ?></option>
				<?php endforeach; ?>
				<option value="new"><?php esc_html_e( '+ Start a new series…', 'portal' ); ?></option>
			</select>
			<p class="portal-series-field__new">
				<label for="portal-series-new"><?php esc_html_e( 'New series name', 'portal' ); ?></label>
				<input type="text" id="portal-series-new" name="portal_series_new" maxlength="80" placeholder="<?php esc_attr_e( 'e.g. Raipur\'s old markets', 'portal' ); ?>">
			</p>
			<span class="wpuf-help"><?php esc_html_e( 'Writing in parts? Put each part in the same series. Readers get "Part 1 of 3" and a "Next part" button. Cover photo, description and order: My Account → My series.', 'portal' ); ?></span>
		</div>
	</li>
	<?php
}
add_action( 'wpuf_add_post_form_bottom', static fn( $form_id ) => portal_series_form_field( $form_id ), 10, 1 );
add_action( 'wpuf_edit_post_form_bottom', static fn( $form_id, $post_id ) => portal_series_form_field( $form_id, (int) $post_id ), 10, 2 );

/**
 * What the writer picked in the Series box: null (box not sent), 0 (none), a term ID, or
 * array( 'new' => name ).
 *
 * @return int|array|null
 */
function portal_series_form_choice() {
	if ( ! isset( $_POST['portal_series_choice'], $_POST['portal_series_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['portal_series_nonce'] ) ), 'portal_series_pick' ) ) {
		return null;
	}
	$choice = sanitize_key( wp_unslash( $_POST['portal_series_choice'] ) );
	if ( 'new' === $choice ) {
		return array( 'new' => isset( $_POST['portal_series_new'] ) ? sanitize_text_field( wp_unslash( $_POST['portal_series_new'] ) ) : '' );
	}
	return absint( $choice );
}

/**
 * Stop the form with a clear message before anything is saved, if the series choice is wrong.
 *
 * @param string $error Error from earlier checks.
 * @return string
 */
function portal_series_form_validate( $error ) {
	$choice = portal_series_form_choice();
	if ( '' !== $error || null === $choice || 0 === $choice ) {
		return $error;
	}
	if ( is_array( $choice ) ) {
		$fields = portal_series_clean_fields( $choice['new'], '' );
		if ( is_wp_error( $fields ) ) {
			return $fields->get_error_message();
		}
		if ( count( portal_get_user_series( get_current_user_id() ) ) >= PORTAL_SERIES_MAX_PER_WRITER ) {
			return __( 'You already have 30 series. Please add new parts to an existing one.', 'portal' );
		}
		return $error;
	}
	$term = portal_get_series( $choice );
	if ( ! $term ) {
		return __( 'That series does not exist.', 'portal' );
	}
	if ( ! current_user_can( 'edit_others_posts' ) && portal_series_owner( $term ) !== get_current_user_id() ) {
		return __( 'You can only add your own stories to your own series.', 'portal' );
	}
	return $error;
}
add_filter( 'wpuf_add_post_validate', 'portal_series_form_validate' );
add_filter( 'wpuf_update_post_validate', 'portal_series_form_validate' );

/**
 * Save the Series box after the form saved the story.
 *
 * @param int $post_id Story.
 * @param int $form_id Form.
 */
function portal_series_form_save( $post_id, $form_id ) {
	if ( (int) $form_id !== (int) get_option( 'portal_post_form_id' ) ) {
		return;
	}
	$choice = portal_series_form_choice();
	if ( null === $choice ) {
		return;
	}
	$user = get_current_user_id();
	if ( is_array( $choice ) ) {
		$choice = portal_create_series( $user, $choice['new'] );
		if ( is_wp_error( $choice ) ) {
			return; // already checked in portal_series_form_validate().
		}
	}
	portal_assign_series( $post_id, (int) $choice, $user );
}
add_action( 'wpuf_add_post_after_insert', 'portal_series_form_save', 10, 2 );
add_action( 'wpuf_edit_post_after_update', 'portal_series_form_save', 10, 2 );

/* ------------------------------------------------------------------ My Account → My series */

/**
 * "My series" tab for writers. The theme draws it; the buttons post to portal_series_action.
 *
 * @param array $sections Tabs.
 * @return array
 */
function portal_series_account_section( $sections ) {
	if ( current_user_can( 'edit_posts' ) ) {
		$sections['portal-series'] = __( 'My series', 'portal' );
	}
	return $sections;
}
add_filter( 'wpuf_account_sections', 'portal_series_account_section', 31 );

/**
 * Address of the My series tab, with an optional message code.
 *
 * @param string $message Message code.
 * @return string
 */
function portal_series_account_url( $message = '' ) {
	$url = add_query_arg( 'section', 'portal-series', portal_account_url() ? portal_account_url() : home_url( '/' ) );
	return $message ? add_query_arg( 'portal_series_msg', $message, $url ) : $url;
}

/**
 * Handle the forms on My Account → My series (create, save, cover, delete).
 * Plain forms that work without JavaScript; each checks a nonce and the user's rights.
 */
function portal_series_handle_action() {
	$do = isset( $_POST['portal_do'] ) ? sanitize_key( wp_unslash( $_POST['portal_do'] ) ) : '';
	check_admin_referer( 'portal_series_' . $do );
	$user = get_current_user_id();

	if ( 'create' === $do ) {
		$result = portal_create_series(
			$user,
			isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned in portal_series_clean_fields().
			isset( $_POST['description'] ) ? wp_unslash( $_POST['description'] ) : '' // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned in portal_series_clean_fields().
		);
		portal_series_redirect( $result, 'created' );
	}

	$term = portal_get_series( isset( $_POST['series'] ) ? absint( $_POST['series'] ) : 0 );
	if ( ! $term || ! portal_can_manage_series( $term, $user ) ) {
		portal_series_redirect( new WP_Error( 'portal_series_forbidden', '' ), '' );
	}

	switch ( $do ) {
		case 'save':
			$result = portal_update_series(
				$term,
				isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned in portal_series_clean_fields().
				isset( $_POST['description'] ) ? wp_unslash( $_POST['description'] ) : '' // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned in portal_series_clean_fields().
			);
			if ( true === $result && isset( $_POST['part'] ) && is_array( $_POST['part'] ) ) {
				// part[story ID] = wanted number; sort by number, keep current order on ties.
				$wanted = array_map( 'absint', wp_unslash( $_POST['part'] ) );
				$ids    = array_map( 'absint', array_keys( $wanted ) );
				$pos    = array_flip( $ids );
				usort( $ids, static fn( $a, $b ) => array( $wanted[ $a ] ?: PHP_INT_MAX, $pos[ $a ] ) <=> array( $wanted[ $b ] ?: PHP_INT_MAX, $pos[ $b ] ) );
				portal_reorder_series( $term, $ids );
			}
			portal_series_redirect( $result, 'saved' );
			break;
		case 'cover':
			$file   = isset( $_FILES['cover'] ) && is_array( $_FILES['cover'] ) ? $_FILES['cover'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked in portal_check_photo_upload().
			$result = portal_set_series_cover( $term, $file, $user );
			portal_series_redirect( $result, 'cover' );
			break;
		case 'uncover':
			portal_remove_series_cover( $term );
			portal_series_redirect( true, 'uncover' );
			break;
		case 'delete':
			portal_series_redirect( portal_delete_series( $term ), 'deleted' );
			break;
	}
	portal_series_redirect( new WP_Error( 'portal_series_unknown', '' ), '' );
}
add_action( 'admin_post_portal_series_action', 'portal_series_handle_action' );

/**
 * Back to My series with a message. Error messages travel as a short-lived note for the user.
 *
 * @param mixed  $result  True/ID, or WP_Error.
 * @param string $success Message code on success.
 */
function portal_series_redirect( $result, $success ) {
	if ( is_wp_error( $result ) ) {
		$message = $result->get_error_message() ? $result->get_error_message() : __( 'You cannot change that series.', 'portal' );
		set_transient( 'portal_series_error_' . get_current_user_id(), $message, 120 );
		$success = 'error';
	}
	wp_safe_redirect( portal_series_account_url( $success ) );
	exit;
}

/**
 * The error message waiting for the current user (read once).
 *
 * @return string
 */
function portal_series_take_error() {
	$key     = 'portal_series_error_' . get_current_user_id();
	$message = (string) get_transient( $key );
	delete_transient( $key );
	return $message;
}

/* ------------------------------------------------------------------ API */

/**
 * Public summary of a series for the API (and the theme).
 *
 * @param WP_Term $term     Series.
 * @param string  $audience "public" (published parts only) or "owner" (every status).
 * @return array
 */
function portal_series_item( WP_Term $term, $audience = 'public' ) {
	$owner = portal_series_owner( $term );
	$parts = portal_series_parts( $term, 'owner' === $audience ? portal_series_all_statuses() : array( 'publish' ) );
	$list  = array();
	$n     = 0;
	foreach ( $parts as $part ) {
		$item = portal_feed_item( $part );
		if ( 'owner' === $audience ) {
			$item['status'] = $part->post_status;
			$item['part']   = (int) get_post_meta( $part->ID, PORTAL_SERIES_PART_META, true );
		} else {
			$item['part'] = ++$n;
		}
		$list[] = $item;
	}
	return array(
		'id'          => $term->term_id,
		'name'        => $term->name,
		'slug'        => $term->slug,
		'link'        => get_term_link( $term ),
		'description' => $term->description,
		'cover'       => portal_series_cover_url( $term ) ? portal_series_cover_url( $term ) : null,
		'writer'      => ( $owner && function_exists( 'portal_get_profile' ) ) ? portal_get_profile( $owner ) : null,
		'parts_count' => (int) $term->count,
		'parts'       => $list,
	);
}

/**
 * REST routes.
 */
function portal_register_series_routes() {
	$writer = static function () {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_not_logged_in', __( 'Please sign in first.', 'portal' ), array( 'status' => 401 ) );
		}
		return current_user_can( 'edit_posts' ) ? true : new WP_Error( 'portal_series_forbidden', __( 'Only writers can create a series. Ask to become a writer first.', 'portal' ), array( 'status' => 403 ) );
	};
	$manage = static function ( WP_REST_Request $request ) {
		$term = portal_get_series( (int) $request['id'] );
		if ( ! $term ) {
			return new WP_Error( 'portal_series_missing', __( 'That series does not exist.', 'portal' ), array( 'status' => 404 ) );
		}
		return portal_can_manage_series( $term, get_current_user_id() ) ? true : new WP_Error( 'portal_series_forbidden', __( 'You cannot change that series.', 'portal' ), array( 'status' => 403 ) );
	};
	$text   = array(
		'name'        => array(
			'type'     => 'string',
			'required' => true,
		),
		'description' => array(
			'type'    => 'string',
			'default' => '',
		),
	);

	register_rest_route(
		'portal/v1',
		'/series/(?P<id>\d+)',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					$term = portal_get_series( (int) $request['id'] );
					if ( ! $term || ( ! portal_series_is_public( $term ) && ! portal_can_manage_series( $term, get_current_user_id() ) ) ) {
						return new WP_Error( 'portal_series_missing', __( 'That series does not exist.', 'portal' ), array( 'status' => 404 ) );
					}
					return rest_ensure_response( portal_series_item( $term ) );
				},
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => $manage,
				'args'                => array_merge(
					$text,
					array(
						'order' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Story IDs in reading order.', 'portal' ),
						),
					)
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					$term   = portal_get_series( (int) $request['id'] );
					$result = portal_update_series( $term, $request['name'], $request['description'] );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					if ( is_array( $request['order'] ) ) {
						portal_reorder_series( $term, $request['order'] );
					}
					return rest_ensure_response( portal_series_item( portal_get_series( $term->term_id ), 'owner' ) );
				},
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'permission_callback' => $manage,
				'callback'            => static function ( WP_REST_Request $request ) {
					$result = portal_delete_series( portal_get_series( (int) $request['id'] ) );
					return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'deleted' => true ) );
				},
			),
		)
	);

	register_rest_route(
		'portal/v1',
		'/series/(?P<id>\d+)/cover',
		array(
			'methods'             => array( 'POST', 'DELETE' ),
			'permission_callback' => $manage,
			'callback'            => static function ( WP_REST_Request $request ) {
				$term = portal_get_series( (int) $request['id'] );
				if ( 'DELETE' === $request->get_method() ) {
					portal_remove_series_cover( $term );
				} else {
					$files  = $request->get_file_params();
					$result = portal_set_series_cover( $term, isset( $files['cover'] ) ? (array) $files['cover'] : array(), get_current_user_id() );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
				return rest_ensure_response( array( 'cover' => portal_series_cover_url( $term ) ? portal_series_cover_url( $term ) : null ) );
			},
		)
	);

	register_rest_route(
		'portal/v1',
		'/me/series',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $writer,
				'callback'            => static fn() => rest_ensure_response(
					array_map( static fn( $term ) => portal_series_item( $term, 'owner' ), portal_get_user_series( get_current_user_id() ) )
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $writer,
				'args'                => $text,
				'callback'            => static function ( WP_REST_Request $request ) {
					$result = portal_create_series( get_current_user_id(), $request['name'], $request['description'] );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					$response = rest_ensure_response( portal_series_item( portal_get_series( $result ), 'owner' ) );
					$response->set_status( 201 );
					return $response;
				},
			),
		)
	);

	// Put a story into a series, or take it out (series = 0). Gives clear errors, unlike the
	// plain "series" field of /wp/v2/posts (where the safety net silently drops a wrong series).
	register_rest_route(
		'portal/v1',
		'/stories/(?P<id>\d+)/series',
		array(
			'methods'             => array( 'POST', 'PUT' ),
			'permission_callback' => static function ( WP_REST_Request $request ) {
				return current_user_can( 'edit_post', (int) $request['id'] ) ? true : new WP_Error( 'rest_forbidden', __( 'You cannot change this story.', 'portal' ), array( 'status' => is_user_logged_in() ? 403 : 401 ) );
			},
			'args'                => array(
				'series' => array(
					'type'     => 'integer',
					'required' => true,
					'minimum'  => 0,
				),
			),
			'callback'            => static function ( WP_REST_Request $request ) {
				$result = portal_assign_series( (int) $request['id'], (int) $request['series'], get_current_user_id() );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$series = portal_post_series( (int) $request['id'] );
				return rest_ensure_response(
					array(
						'series' => $series ? $series->term_id : 0,
						'part'   => (int) get_post_meta( (int) $request['id'], PORTAL_SERIES_PART_META, true ),
					)
				);
			},
		)
	);

	register_rest_field(
		'post',
		'portal_series',
		array(
			'get_callback' => static function ( $data ) {
				$at = portal_series_position( (int) $data['id'] );
				if ( ! $at ) {
					return null;
				}
				return array(
					'id'     => $at['series']->term_id,
					'name'   => $at['series']->name,
					'link'   => get_term_link( $at['series'] ),
					'number' => $at['number'],
					'total'  => $at['total'],
					'prev'   => $at['prev'] ? $at['prev']->ID : null,
					'next'   => $at['next'] ? $at['next']->ID : null,
				);
			},
			'schema'       => array(
				'description' => __( 'The series this story belongs to, as readers see it: "Part number of total".', 'portal' ),
				'type'        => array( 'object', 'null' ),
				'readonly'    => true,
				'context'     => array( 'view', 'edit' ),
			),
		)
	);
}
add_action( 'rest_api_init', 'portal_register_series_routes' );
