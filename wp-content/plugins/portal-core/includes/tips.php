<?php
/**
 * Reader news tips (Task 17D).
 *
 * Any signed-in member sends news (what, where, when, how they know, up to 4 photos, a video
 * link, an optional phone number). Editors check it in a private queue (wp-admin → Reader tips)
 * and either write it up as a News story (a draft, so every normal publishing rule still
 * applies) or decline it with a reason. The member is e-mailed and sees the result in
 * My Account → My tips. Members never publish into News or Crime themselves.
 *
 * Privacy and safety:
 *   - tips are never public (no page, no feed, no search, no public API);
 *   - "anonymous" means anonymous to READERS; editors always see who sent a tip (to check it);
 *   - the phone number is only shown to editors and is deleted 90 days after the tip is closed,
 *     together with the photos of declined tips;
 *   - photos lose their GPS/camera data on upload (includes/image-safety.php) and get random
 *     file names; video FILES are not accepted (their hidden location data cannot be removed on
 *     shared hosting), only links;
 *   - 5 tips a day per member, plus a hidden spam trap on the form.
 *
 * Data: post type "portal_tip" (status "private"), fields in protected post meta (_portal_tip_*).
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

const PORTAL_TIP_TYPE       = 'portal_tip';
const PORTAL_TIP_MAX_PHOTOS = 4;
const PORTAL_TIP_PER_DAY    = 5;
const PORTAL_TIP_KEEP_DAYS  = 90;

/* ------------------------------------------------------------------ Registration */

/**
 * The private "Reader tips" post type. Only Editors and Admins can see it; nobody creates tips
 * in wp-admin (they come from the Send news form or the app).
 */
function portal_register_tips() {
	register_post_type(
		PORTAL_TIP_TYPE,
		array(
			'labels'              => array(
				'name'          => __( 'Reader tips', 'portal' ),
				'singular_name' => __( 'Reader tip', 'portal' ),
				'menu_name'     => __( 'Reader tips', 'portal' ),
				'all_items'     => __( 'All tips', 'portal' ),
				'edit_item'     => __( 'Reader tip', 'portal' ),
				'search_items'  => __( 'Search tips', 'portal' ),
				'not_found'     => __( 'No tips yet.', 'portal' ),
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'menu_position'       => 26,
			'menu_icon'           => 'dashicons-megaphone',
			'supports'            => array( 'title' ),
			'rewrite'             => false,
			'query_var'           => false,
			'map_meta_cap'        => false,
			'capabilities'        => array(
				'edit_post'          => 'edit_others_posts',
				'read_post'          => 'edit_others_posts',
				'delete_post'        => 'delete_others_posts',
				'edit_posts'         => 'edit_others_posts',
				'edit_others_posts'  => 'edit_others_posts',
				'publish_posts'      => 'edit_others_posts',
				'read_private_posts' => 'edit_others_posts',
				'delete_posts'       => 'delete_others_posts',
				'create_posts'       => 'do_not_allow',
			),
		)
	);
}
add_action( 'init', 'portal_register_tips' );

/**
 * The Send news page is private (noindex, not in the sitemap), like My Account.
 *
 * @param string[] $slugs Page slugs.
 * @return string[]
 */
function portal_tips_private_page( $slugs ) {
	$slugs[] = 'send-news';
	return $slugs;
}
add_filter( 'portal_private_page_slugs', 'portal_tips_private_page' );

/* ------------------------------------------------------------------ Basics */

/**
 * The words for each state, as the member and editors see them.
 *
 * @return array<string,string>
 */
function portal_tip_states() {
	return array(
		'new'       => __( 'Received', 'portal' ),
		'checking'  => __( 'Being checked', 'portal' ),
		'published' => __( 'Published', 'portal' ),
		'declined'  => __( 'Not published', 'portal' ),
	);
}

/**
 * How the sender knows: key => words.
 *
 * @return array<string,string>
 */
function portal_tip_sources() {
	return array(
		'saw'       => __( 'I saw it myself', 'portal' ),
		'told'      => __( 'Someone told me', 'portal' ),
		'documents' => __( 'I have documents', 'portal' ),
	);
}

/**
 * A tip, or null.
 *
 * @param int|WP_Post $tip Tip.
 * @return WP_Post|null
 */
function portal_get_tip( $tip ) {
	$tip = get_post( $tip );
	return ( $tip && PORTAL_TIP_TYPE === $tip->post_type ) ? $tip : null;
}

/**
 * Reference shown to the member and editors.
 *
 * @param WP_Post $tip Tip.
 * @return string
 */
function portal_tip_reference( WP_Post $tip ) {
	return 'TIP-' . $tip->ID;
}

/**
 * A field of a tip.
 *
 * @param WP_Post $tip Tip.
 * @param string  $key Field (state, where, when, how, video, phone, credit, reason, story, photos).
 * @return mixed
 */
function portal_tip_get( WP_Post $tip, $key ) {
	$value = get_post_meta( $tip->ID, '_portal_tip_' . $key, true );
	if ( 'photos' === $key ) {
		return array_values( array_filter( array_map( 'intval', (array) $value ) ) );
	}
	if ( 'state' === $key ) {
		return $value ? $value : 'new';
	}
	return $value;
}

/**
 * Largest photo allowed: 8 MB, or less if the server's own limit is lower.
 *
 * @return int Bytes.
 */
function portal_tip_max_photo_bytes() {
	return (int) min( 8 * MB_IN_BYTES, wp_max_upload_size() );
}

/**
 * Turn PHP's "photos[]" upload shape into a plain list of files (empty slots dropped).
 *
 * @param array $field One $_FILES entry (single or multiple).
 * @return array[]
 */
function portal_tip_normalise_files( $field ) {
	if ( empty( $field ) || ! isset( $field['name'] ) ) {
		return array();
	}
	if ( ! is_array( $field['name'] ) ) {
		$field = array_map( static fn( $v ) => array( $v ), $field );
	}
	$files = array();
	foreach ( array_keys( $field['name'] ) as $i ) {
		if ( UPLOAD_ERR_NO_FILE === (int) ( $field['error'][ $i ] ?? UPLOAD_ERR_NO_FILE ) ) {
			continue;
		}
		$files[] = array(
			'name'     => (string) $field['name'][ $i ],
			'type'     => (string) ( $field['type'][ $i ] ?? '' ),
			'tmp_name' => (string) ( $field['tmp_name'][ $i ] ?? '' ),
			'error'    => (int) ( $field['error'][ $i ] ?? 0 ),
			'size'     => (int) ( $field['size'][ $i ] ?? 0 ),
		);
	}
	return $files;
}

/* ------------------------------------------------------------------ Sending a tip */

/**
 * Check and clean what a member typed. Returns the clean fields or an error.
 *
 * @param array $in Raw fields: title, details, where, when, how, video, phone, credit, consent.
 * @return array|WP_Error
 */
function portal_tip_clean_fields( array $in ) {
	$out = array(
		'title'   => trim( sanitize_text_field( (string) ( $in['title'] ?? '' ) ) ),
		'details' => trim( sanitize_textarea_field( (string) ( $in['details'] ?? '' ) ) ),
		'where'   => trim( sanitize_text_field( (string) ( $in['where'] ?? '' ) ) ),
		'when'    => trim( sanitize_text_field( (string) ( $in['when'] ?? '' ) ) ),
		'how'     => sanitize_key( (string) ( $in['how'] ?? '' ) ),
		'video'   => trim( (string) ( $in['video'] ?? '' ) ),
		'phone'   => preg_replace( '/[\s\-()]/', '', (string) ( $in['phone'] ?? '' ) ),
		'credit'  => 'name' === ( $in['credit'] ?? '' ) ? 'name' : 'anonymous',
	);
	$error = static fn( $code, $message ) => new WP_Error( $code, $message, array( 'status' => 400 ) );

	if ( mb_strlen( $out['title'] ) < 10 || mb_strlen( $out['title'] ) > 120 ) {
		return $error( 'portal_tip_title', __( 'Please say in one line what happened (10 to 120 characters).', 'portal' ) );
	}
	if ( mb_strlen( $out['details'] ) < 30 || mb_strlen( $out['details'] ) > 5000 ) {
		return $error( 'portal_tip_details', __( 'Please describe what happened in a few sentences (30 to 5,000 characters).', 'portal' ) );
	}
	if ( mb_strlen( $out['where'] ) < 2 || mb_strlen( $out['where'] ) > 80 ) {
		return $error( 'portal_tip_where', __( 'Please say where it happened (town or district).', 'portal' ) );
	}
	if ( '' !== $out['when'] ) {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $out['when'], wp_timezone() );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $out['when'] || $date > new DateTimeImmutable( 'tomorrow', wp_timezone() ) ) {
			return $error( 'portal_tip_when', __( 'Please choose the date it happened (not in the future).', 'portal' ) );
		}
	}
	if ( ! isset( portal_tip_sources()[ $out['how'] ] ) ) {
		return $error( 'portal_tip_how', __( 'Please tell us how you know about it.', 'portal' ) );
	}
	if ( '' !== $out['video'] ) {
		$url = esc_url_raw( $out['video'], array( 'http', 'https' ) );
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return $error( 'portal_tip_video', __( 'The video link does not look right. Please paste the full link (starting with https://).', 'portal' ) );
		}
		$out['video'] = $url;
	}
	if ( '' !== $out['phone'] ) {
		// Remove +91 / 91 / 0 only in front of a full 10-digit number: a mobile number can itself
		// start with 91 (91234 56780), which must not be cut.
		$digits = preg_replace( '/^(?:\+91|91|0)(?=\d{10}$)/', '', $out['phone'] );
		if ( ! preg_match( '/^[6-9]\d{9}$/', $digits ) ) {
			return $error( 'portal_tip_phone', __( 'Please give a 10-digit Indian mobile number, or leave it empty.', 'portal' ) );
		}
		$out['phone'] = '+91' . $digits;
	}
	if ( empty( $in['consent'] ) ) {
		return $error( 'portal_tip_consent', __( 'Please confirm that what you send is true to your knowledge and that you may share the photos.', 'portal' ) );
	}
	return $out;
}

/**
 * Receive a tip from a member: check everything first, then save the tip and its photos
 * (all or nothing), tell the editors and send the member a receipt.
 *
 * @param int   $user_id Member.
 * @param array $fields  Raw fields (see portal_tip_clean_fields()).
 * @param array $files   Photos: a list of $_FILES-style entries.
 * @return int|WP_Error Tip ID.
 */
function portal_submit_tip( $user_id, array $fields, array $files ) {
	$user_id = (int) $user_id;
	if ( ! $user_id || ! get_userdata( $user_id ) ) {
		return new WP_Error( 'rest_not_logged_in', __( 'Please sign in first.', 'portal' ), array( 'status' => 401 ) );
	}
	$recent = get_posts(
		array(
			'post_type'   => PORTAL_TIP_TYPE,
			'post_status' => 'any',
			'author'      => $user_id,
			'date_query'  => array( array( 'after' => '24 hours ago' ) ),
			'fields'      => 'ids',
			'numberposts' => PORTAL_TIP_PER_DAY,
		)
	);
	if ( count( $recent ) >= PORTAL_TIP_PER_DAY ) {
		return new WP_Error( 'portal_tip_limit', __( 'You have sent 5 tips today. Please try again tomorrow, or write to us if it is urgent.', 'portal' ), array( 'status' => 429 ) );
	}

	$clean = portal_tip_clean_fields( $fields );
	if ( is_wp_error( $clean ) ) {
		return $clean;
	}
	if ( count( $files ) > PORTAL_TIP_MAX_PHOTOS ) {
		return new WP_Error( 'portal_tip_photos', __( 'Please send at most 4 photos.', 'portal' ), array( 'status' => 400 ) );
	}
	$checked = array();
	foreach ( $files as $file ) {
		$file = portal_check_photo_upload( $file, 'portal_tip_photo', portal_tip_max_photo_bytes() );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		// A random name: the original name ("IMG_20261112_Raipur.jpg") can give away when and where.
		$ext          = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		$file['name'] = 'tip-' . strtolower( wp_generate_password( 12, false ) ) . '.' . ( 'jpeg' === $ext ? 'jpg' : $ext );
		$checked[]    = $file;
	}

	$tip_id = wp_insert_post(
		array(
			'post_type'    => PORTAL_TIP_TYPE,
			'post_status'  => 'private',
			'post_author'  => $user_id,
			'post_title'   => $clean['title'],
			'post_content' => $clean['details'],
		),
		true
	);
	if ( is_wp_error( $tip_id ) ) {
		$tip_id->add_data( array( 'status' => 500 ) );
		return $tip_id;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$photos = array();
	foreach ( $checked as $file ) {
		$id = media_handle_sideload( $file, $tip_id, null, array( 'post_author' => $user_id ) );
		if ( is_wp_error( $id ) ) {
			foreach ( $photos as $done ) {
				wp_delete_attachment( $done, true );
			}
			wp_delete_post( $tip_id, true );
			$id->add_data( array( 'status' => 400 ) );
			return $id;
		}
		update_post_meta( $id, '_portal_tip_photo', $tip_id );
		$photos[] = (int) $id;
	}

	foreach ( array( 'where', 'when', 'how', 'video', 'phone', 'credit' ) as $key ) {
		if ( '' !== $clean[ $key ] ) {
			update_post_meta( $tip_id, '_portal_tip_' . $key, $clean[ $key ] );
		}
	}
	update_post_meta( $tip_id, '_portal_tip_photos', $photos );
	update_post_meta( $tip_id, '_portal_tip_state', 'new' );

	$tip = get_post( $tip_id );
	do_action( 'portal_admin_action', 'tip_received', 'tip', $tip_id, $user_id, array( 'title' => $clean['title'] ) );
	portal_tip_mail_editors( $tip );
	portal_tip_mail_member( $tip, 'received' );
	return (int) $tip_id;
}

/* ------------------------------------------------------------------ Editors' actions */

/**
 * "Being checked": an editor is working on it.
 *
 * @param WP_Post $tip Tip.
 * @param int     $by  Editor.
 * @return true|WP_Error
 */
function portal_tip_mark_checking( WP_Post $tip, $by ) {
	if ( ! in_array( portal_tip_get( $tip, 'state' ), array( 'new', 'declined' ), true ) ) {
		return new WP_Error( 'portal_tip_state', __( 'This tip is already being checked or published.', 'portal' ), array( 'status' => 409 ) );
	}
	update_post_meta( $tip->ID, '_portal_tip_state', 'checking' );
	delete_post_meta( $tip->ID, '_portal_tip_closed_at' );
	do_action( 'portal_admin_action', 'tip_checking', 'tip', $tip->ID, (int) $by, array( 'title' => $tip->post_title ) );
	return true;
}

/**
 * Decline a tip with a reason the member will read.
 *
 * @param WP_Post $tip    Tip.
 * @param string  $reason Reason (at least 10 characters).
 * @param int     $by     Editor.
 * @return true|WP_Error
 */
function portal_tip_decline( WP_Post $tip, $reason, $by ) {
	$reason = trim( sanitize_textarea_field( (string) $reason ) );
	if ( mb_strlen( $reason ) < 10 ) {
		return new WP_Error( 'portal_tip_reason', __( 'Please write a short reason for the reader (at least 10 characters).', 'portal' ), array( 'status' => 400 ) );
	}
	if ( 'published' === portal_tip_get( $tip, 'state' ) ) {
		return new WP_Error( 'portal_tip_state', __( 'This tip has already been published.', 'portal' ), array( 'status' => 409 ) );
	}
	update_post_meta( $tip->ID, '_portal_tip_state', 'declined' );
	update_post_meta( $tip->ID, '_portal_tip_reason', $reason );
	update_post_meta( $tip->ID, '_portal_tip_closed_at', time() );
	do_action( 'portal_admin_action', 'tip_declined', 'tip', $tip->ID, (int) $by, array( 'title' => $tip->post_title, 'reason' => $reason ) );
	portal_tip_mail_member( $tip, 'declined' );
	return true;
}

/**
 * Start a News story from a tip: a DRAFT by the editor in the News section, with the reader's
 * text, place and date, and the photos (first one as the main picture). The editor checks and
 * rewrites it; publishing it later marks the tip "Published" and tells the member.
 *
 * @param WP_Post $tip Tip.
 * @param int     $by  Editor.
 * @return int|WP_Error Story ID.
 */
function portal_tip_write_up( WP_Post $tip, $by ) {
	$existing = (int) portal_tip_get( $tip, 'story' );
	if ( $existing && get_post( $existing ) ) {
		return $existing;
	}
	$news   = get_category_by_slug( 'news' );
	$photos = portal_tip_get( $tip, 'photos' );
	$where  = (string) portal_tip_get( $tip, 'where' );
	$when   = (string) portal_tip_get( $tip, 'when' );

	$blocks = '';
	if ( $where || $when ) {
		$place   = trim( $where . ( $when ? ', ' . wp_date( get_option( 'date_format' ), strtotime( $when ) ) : '' ), ', ' );
		$blocks .= "<!-- wp:paragraph -->\n<p><strong>" . esc_html( $place ) . ":</strong></p>\n<!-- /wp:paragraph -->\n\n";
	}
	foreach ( preg_split( "/\n\s*\n/", $tip->post_content ) as $paragraph ) {
		if ( '' !== trim( $paragraph ) ) {
			$blocks .= "<!-- wp:paragraph -->\n<p>" . nl2br( esc_html( trim( $paragraph ) ) ) . "</p>\n<!-- /wp:paragraph -->\n\n";
		}
	}
	foreach ( array_slice( $photos, 1 ) as $photo ) {
		$src = wp_get_attachment_image_url( $photo, 'large' );
		if ( $src ) {
			$blocks .= '<!-- wp:image {"id":' . (int) $photo . ',"sizeSlug":"large"} -->' . "\n" . '<figure class="wp-block-image size-large"><img src="' . esc_url( $src ) . '" alt="" class="wp-image-' . (int) $photo . '"/></figure>' . "\n<!-- /wp:image -->\n\n";
		}
	}

	$story = wp_insert_post(
		array(
			'post_type'     => 'post',
			'post_status'   => 'draft',
			'post_author'   => (int) $by,
			'post_title'    => $tip->post_title,
			'post_content'  => $blocks,
			'post_category' => $news ? array( (int) $news->term_id ) : array(),
		),
		true
	);
	if ( is_wp_error( $story ) ) {
		$story->add_data( array( 'status' => 500 ) );
		return $story;
	}
	update_post_meta( $story, '_portal_tip_id', $tip->ID );
	foreach ( $photos as $photo ) {
		wp_update_post(
			array(
				'ID'          => $photo,
				'post_parent' => $story, // the story owns the photos now; the tip clean-up leaves them alone.
			)
		);
	}
	if ( $photos ) {
		set_post_thumbnail( $story, $photos[0] );
	}
	update_post_meta( $tip->ID, '_portal_tip_story', $story );
	if ( 'new' === portal_tip_get( $tip, 'state' ) || 'declined' === portal_tip_get( $tip, 'state' ) ) {
		update_post_meta( $tip->ID, '_portal_tip_state', 'checking' );
		delete_post_meta( $tip->ID, '_portal_tip_closed_at' );
	}
	do_action( 'portal_admin_action', 'tip_written_up', 'tip', $tip->ID, (int) $by, array( 'title' => $tip->post_title, 'story' => $story ) );
	return (int) $story;
}

/**
 * The story made from a tip was published: the tip is "Published" and the member is told.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post.
 */
function portal_tip_story_published( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status || 'publish' === $old_status || 'post' !== $post->post_type ) {
		return;
	}
	$tip = portal_get_tip( (int) get_post_meta( $post->ID, '_portal_tip_id', true ) );
	if ( ! $tip || 'published' === portal_tip_get( $tip, 'state' ) ) {
		return;
	}
	update_post_meta( $tip->ID, '_portal_tip_state', 'published' );
	update_post_meta( $tip->ID, '_portal_tip_story', $post->ID );
	update_post_meta( $tip->ID, '_portal_tip_closed_at', time() );
	do_action( 'portal_admin_action', 'tip_published', 'tip', $tip->ID, get_current_user_id(), array( 'title' => $tip->post_title, 'story' => $post->ID ) );
	portal_tip_mail_member( $tip, 'published' );
}
add_action( 'transition_post_status', 'portal_tip_story_published', 20, 3 );

/**
 * The credit line at the end of a story made from a tip.
 *
 * @param int|WP_Post $post Story.
 * @return string '' when the story did not come from a tip.
 */
function portal_tip_credit_line( $post ) {
	$post = get_post( $post );
	$tip  = $post ? portal_get_tip( (int) get_post_meta( $post->ID, '_portal_tip_id', true ) ) : null;
	if ( ! $tip ) {
		return '';
	}
	$sender = get_userdata( (int) $tip->post_author );
	if ( 'name' === portal_tip_get( $tip, 'credit' ) && $sender ) {
		/* translators: %s: reader's name. */
		return sprintf( __( 'Based on a report by %s, a CityCharcha reader.', 'portal' ), $sender->display_name );
	}
	return __( 'Based on a reader report.', 'portal' );
}

/* ------------------------------------------------------------------ E-mails */

/**
 * Tell editors a new tip came in (no phone number in e-mails: they get forwarded).
 *
 * @param WP_Post $tip Tip.
 */
function portal_tip_mail_editors( WP_Post $tip ) {
	/* translators: 1: reference, 2: headline. */
	$subject = sprintf( __( 'New reader tip %1$s: %2$s', 'portal' ), portal_tip_reference( $tip ), $tip->post_title );
	$sources = portal_tip_sources();
	$body    = sprintf(
		/* translators: 1: headline, 2: place, 3: how they know, 4: number of photos, 5: link. */
		__( "A reader sent news.\n\nWhat happened: %1\$s\nWhere: %2\$s\nHow they know: %3\$s\nPhotos: %4\$d\n\nCheck it before anything is published:\n%5\$s", 'portal' ),
		$tip->post_title,
		(string) portal_tip_get( $tip, 'where' ),
		$sources[ portal_tip_get( $tip, 'how' ) ] ?? '-',
		count( portal_tip_get( $tip, 'photos' ) ),
		portal_tip_admin_url( $tip )
	);
	$editors = get_users(
		array(
			'role__in' => array( 'editor', 'administrator' ),
			'fields'   => array( 'ID', 'user_email' ),
		)
	);
	foreach ( $editors as $editor ) {
		wp_mail( $editor->user_email, $subject, $body );
	}
}

/**
 * E-mail the member: receipt, published (with the link) or declined (with the reason).
 *
 * @param WP_Post $tip  Tip.
 * @param string  $kind received | published | declined.
 */
function portal_tip_mail_member( WP_Post $tip, $kind ) {
	$member = get_userdata( (int) $tip->post_author );
	if ( ! $member ) {
		return;
	}
	$ref  = portal_tip_reference( $tip );
	$mine = portal_tips_account_url();
	switch ( $kind ) {
		case 'published':
			$story = (int) portal_tip_get( $tip, 'story' );
			/* translators: %s: reference. */
			$subject = sprintf( __( 'Your news tip %s is published', 'portal' ), $ref );
			/* translators: 1: name, 2: headline, 3: story link. */
			$body = sprintf( __( "Hello %1\$s,\n\nThank you. Our editors checked your news tip \"%2\$s\" and published a story based on it:\n%3\$s", 'portal' ), $member->display_name, $tip->post_title, get_permalink( $story ) );
			break;
		case 'declined':
			/* translators: %s: reference. */
			$subject = sprintf( __( 'About your news tip %s', 'portal' ), $ref );
			/* translators: 1: name, 2: headline, 3: reason, 4: My tips link. */
			$body = sprintf( __( "Hello %1\$s,\n\nThank you for sending \"%2\$s\". Our editors decided not to publish it.\n\nReason: %3\$s\n\nPlease keep sending news. Your tips: %4\$s", 'portal' ), $member->display_name, $tip->post_title, (string) portal_tip_get( $tip, 'reason' ), $mine );
			break;
		default:
			/* translators: %s: reference. */
			$subject = sprintf( __( 'We received your news tip %s', 'portal' ), $ref );
			/* translators: 1: name, 2: headline, 3: reference, 4: My tips link. */
			$body = sprintf( __( "Hello %1\$s,\n\nThank you for sending news: \"%2\$s\" (reference %3\$s).\n\nOur editors will check it before anything is published. You will get an e-mail when it is published or if we decide not to publish it.\n\nYour tips: %4\$s", 'portal' ), $member->display_name, $tip->post_title, $ref, $mine );
	}
	wp_mail( $member->user_email, $subject, $body );
}

/* ------------------------------------------------------------------ My tips */

/**
 * Address of My Account → My tips.
 *
 * @param array $args Extra query arguments.
 * @return string
 */
function portal_tips_account_url( array $args = array() ) {
	$base = portal_account_url() ? portal_account_url() : home_url( '/' );
	return add_query_arg( array_merge( array( 'section' => 'portal-tips' ), $args ), $base );
}

/**
 * "My tips" tab in My Account, for every signed-in member.
 *
 * @param array $sections Tabs.
 * @return array
 */
function portal_tips_account_section( $sections ) {
	$sections['portal-tips'] = __( 'My tips', 'portal' );
	return $sections;
}
add_filter( 'wpuf_account_sections', 'portal_tips_account_section', 32 );

/**
 * A member's own tips, newest first, as they may see them (no editor notes, no phone).
 *
 * @param int $user_id Member.
 * @return array[]
 */
function portal_get_user_tips( $user_id ) {
	$items = array();
	$tips  = get_posts(
		array(
			'post_type'   => PORTAL_TIP_TYPE,
			'post_status' => 'any',
			'author'      => (int) $user_id,
			'numberposts' => 50,
			'orderby'     => 'date',
			'order'       => 'DESC',
		)
	);
	foreach ( $tips as $tip ) {
		$state = portal_tip_get( $tip, 'state' );
		$story = (int) portal_tip_get( $tip, 'story' );
		$items[] = array(
			'id'          => $tip->ID,
			'reference'   => portal_tip_reference( $tip ),
			'title'       => $tip->post_title,
			'date_gmt'    => mysql_to_rfc3339( $tip->post_date_gmt ),
			'state'       => $state,
			'state_label' => portal_tip_states()[ $state ] ?? $state,
			'reason'      => 'declined' === $state ? (string) portal_tip_get( $tip, 'reason' ) : '',
			'story'       => ( 'published' === $state && $story && 'publish' === get_post_status( $story ) ) ? get_permalink( $story ) : '',
		);
	}
	return $items;
}

/* ------------------------------------------------------------------ Send news form */

/**
 * [portal_tip_form]: the Send news form. Visitors are asked to sign in or sign up first.
 *
 * @return string
 */
function portal_tip_form_shortcode() {
	if ( ! is_user_logged_in() ) {
		$here = get_permalink();
		return '<div class="portal-write-box"><h2>' . esc_html__( 'Sign in to send news', 'portal' ) . '</h2><p>'
			. esc_html__( 'Seen something our readers should know about? Sign in or create a free account, then tell us what happened. Our editors check every tip before anything is published.', 'portal' )
			. '</p><p class="portal-write-box__actions"><a class="portal-button" href="' . esc_url( wp_login_url( $here ) ) . '">' . esc_html__( 'Sign in', 'portal' ) . '</a> '
			. '<a class="portal-button portal-button--ghost" href="' . esc_url( wp_registration_url() ) . '">' . esc_html__( 'Create an account', 'portal' ) . '</a></p></div>';
	}

	$user  = get_current_user_id();
	$saved = get_transient( 'portal_tip_form_' . $user );
	delete_transient( 'portal_tip_form_' . $user );
	$old   = is_array( $saved ) ? $saved['fields'] : array();
	$error = is_array( $saved ) ? $saved['error'] : '';
	$val   = static fn( $key ) => esc_attr( (string) ( $old[ $key ] ?? '' ) );
	$max   = size_format( portal_tip_max_photo_bytes() );

	ob_start();
	?>
	<form class="portal-tip-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
		<input type="hidden" name="action" value="portal_send_tip">
		<?php wp_nonce_field( 'portal_send_tip', 'portal_tip_nonce' ); ?>
		<?php if ( $error ) : ?>
			<p class="portal-notice portal-notice--error" role="alert"><?php echo esc_html( $error ); ?></p>
		<?php endif; ?>
		<p class="portal-tip-form__intro"><?php esc_html_e( 'Our editors read every tip and check it before anything is published. Please send only what you know to be true. For emergencies call 112 first.', 'portal' ); ?></p>

		<p><label for="portal-tip-title"><?php esc_html_e( 'What happened? (one line)', 'portal' ); ?></label>
			<input type="text" id="portal-tip-title" name="title" required minlength="10" maxlength="120" value="<?php echo $val( 'title' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $val. ?>" placeholder="<?php esc_attr_e( 'e.g. Water pipeline burst near Telibandha, road closed', 'portal' ); ?>"></p>

		<p><label for="portal-tip-details"><?php esc_html_e( 'Details', 'portal' ); ?></label>
			<textarea id="portal-tip-details" name="details" required minlength="30" maxlength="5000" rows="7" placeholder="<?php esc_attr_e( 'What did you see or hear? When did it start? Who is affected? Anything officials said?', 'portal' ); ?>"><?php echo esc_textarea( (string) ( $old['details'] ?? '' ) ); ?></textarea></p>

		<div class="portal-tip-form__row">
			<p><label for="portal-tip-where"><?php esc_html_e( 'Where? (town or district)', 'portal' ); ?></label>
				<input type="text" id="portal-tip-where" name="where" required maxlength="80" list="portal-tip-places" value="<?php echo $val( 'where' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $val. ?>"></p>
			<p><label for="portal-tip-when"><?php esc_html_e( 'When? (date)', 'portal' ); ?></label>
				<input type="date" id="portal-tip-when" name="when" max="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" value="<?php echo $val( 'when' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $val. ?>"></p>
		</div>
		<datalist id="portal-tip-places">
			<?php foreach ( array( 'Raipur', 'Bilaspur', 'Durg', 'Bhilai', 'Korba', 'Rajnandgaon', 'Raigarh', 'Jagdalpur', 'Ambikapur', 'Dhamtari', 'Mahasamund', 'Janjgir-Champa', 'Kanker', 'Kawardha', 'Bastar', 'Dantewada', 'Surajpur', 'Balod', 'Bemetara', 'Mungeli' ) as $portal_place ) : ?>
				<option value="<?php echo esc_attr( $portal_place ); ?>"></option>
			<?php endforeach; ?>
		</datalist>

		<fieldset><legend><?php esc_html_e( 'How do you know?', 'portal' ); ?></legend>
			<?php foreach ( portal_tip_sources() as $portal_key => $portal_label ) : ?>
				<label class="portal-tip-form__choice"><input type="radio" name="how" value="<?php echo esc_attr( $portal_key ); ?>" required <?php checked( ( $old['how'] ?? '' ) === $portal_key ); ?>> <?php echo esc_html( $portal_label ); ?></label>
			<?php endforeach; ?>
		</fieldset>

		<p><label for="portal-tip-photos">
			<?php
			/* translators: %s: size limit, e.g. "8 MB". */
			echo esc_html( sprintf( __( 'Photos (optional, up to 4, JPG/PNG/WebP, %s each)', 'portal' ), $max ) );
			?>
			</label>
			<input type="file" id="portal-tip-photos" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
			<span class="portal-tip-form__help"><?php esc_html_e( 'We remove the hidden location data from photos. Do not send photos of injured people, children or victims\' faces.', 'portal' ); ?></span></p>

		<p><label for="portal-tip-video"><?php esc_html_e( 'Video link (optional)', 'portal' ); ?></label>
			<input type="url" id="portal-tip-video" name="video" maxlength="500" value="<?php echo $val( 'video' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $val. ?>" placeholder="https://">
			<span class="portal-tip-form__help"><?php esc_html_e( 'Upload the video to YouTube (unlisted) or Google Drive and paste the link. Video files can reveal where they were filmed, so we do not take them directly.', 'portal' ); ?></span></p>

		<p><label for="portal-tip-phone"><?php esc_html_e( 'Mobile number (optional, only our editors see it)', 'portal' ); ?></label>
			<input type="tel" id="portal-tip-phone" name="phone" maxlength="16" inputmode="tel" autocomplete="tel" value="<?php echo $val( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $val. ?>" placeholder="98765 43210">
			<span class="portal-tip-form__help"><?php esc_html_e( 'So an editor can call you to check details. Deleted 90 days after we close your tip.', 'portal' ); ?></span></p>

		<fieldset><legend><?php esc_html_e( 'If we publish a story', 'portal' ); ?></legend>
			<label class="portal-tip-form__choice"><input type="radio" name="credit" value="anonymous" <?php checked( 'name' !== ( $old['credit'] ?? '' ) ); ?>> <?php esc_html_e( 'Keep me anonymous ("Based on a reader report")', 'portal' ); ?></label>
			<label class="portal-tip-form__choice"><input type="radio" name="credit" value="name" <?php checked( 'name' === ( $old['credit'] ?? '' ) ); ?>> <?php esc_html_e( 'Credit me by name', 'portal' ); ?></label>
			<span class="portal-tip-form__help"><?php esc_html_e( 'Anonymous means readers will not see your name. Our editors always know who sent a tip.', 'portal' ); ?></span>
		</fieldset>

		<?php // Spam trap: people never see or fill this box; bots do. ?>
		<p class="portal-tip-form__trap" aria-hidden="true"><label>Website <input type="text" name="portal_website" tabindex="-1" autocomplete="off"></label></p>

		<p><label class="portal-tip-form__choice"><input type="checkbox" name="consent" value="1" required> <?php esc_html_e( 'What I am sending is true to my knowledge, I may share these photos, and I understand the editors may contact me and may decide not to publish it.', 'portal' ); ?></label></p>

		<p><button type="submit" class="portal-button"><?php esc_html_e( 'Send news', 'portal' ); ?></button></p>
	</form>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'portal_tip_form', 'portal_tip_form_shortcode' );

/**
 * Handle the Send news form. Errors go back to the form with what was typed (photos have to
 * be chosen again: browsers never refill file boxes).
 */
function portal_tip_form_handle() {
	if ( ! isset( $_POST['portal_tip_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['portal_tip_nonce'] ) ), 'portal_send_tip' ) ) {
		wp_die( esc_html__( 'This form has expired. Please go back, reload the page and try again.', 'portal' ), '', array( 'response' => 403 ) );
	}
	$back = wp_get_referer() ? wp_get_referer() : home_url( '/send-news/' );
	if ( ! empty( $_POST['portal_website'] ) ) {
		wp_safe_redirect( portal_tips_account_url() ); // a bot: pretend it worked.
		exit;
	}
	$fields = array();
	foreach ( array( 'title', 'details', 'where', 'when', 'how', 'video', 'phone', 'credit', 'consent' ) as $key ) {
		$fields[ $key ] = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned in portal_tip_clean_fields().
	}
	$files  = portal_tip_normalise_files( isset( $_FILES['photos'] ) ? $_FILES['photos'] : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked in portal_check_photo_upload().
	$result = portal_submit_tip( get_current_user_id(), $fields, $files );
	if ( is_wp_error( $result ) ) {
		unset( $fields['consent'] );
		set_transient(
			'portal_tip_form_' . get_current_user_id(),
			array(
				'error'  => $result->get_error_message(),
				'fields' => array_map( static fn( $v ) => is_string( $v ) ? $v : '', $fields ),
			),
			10 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( remove_query_arg( 'tip_sent', $back ) );
		exit;
	}
	wp_safe_redirect( portal_tips_account_url( array( 'tip_sent' => $result ) ) );
	exit;
}
add_action( 'admin_post_portal_send_tip', 'portal_tip_form_handle' );

/**
 * A visitor whose session ended sends the form: back to sign in.
 */
function portal_tip_form_logged_out() {
	wp_safe_redirect( wp_login_url( home_url( '/send-news/' ) ) );
	exit;
}
add_action( 'admin_post_nopriv_portal_send_tip', 'portal_tip_form_logged_out' );

/* ------------------------------------------------------------------ Editors' screen */

/**
 * Address of the editors' screen for one tip.
 *
 * @param WP_Post $tip Tip.
 * @return string
 */
function portal_tip_admin_url( WP_Post $tip ) {
	return admin_url( 'admin.php?page=portal-tip&tip=' . $tip->ID );
}

/**
 * The hidden "one tip" screen, and the count of new tips on the menu.
 */
function portal_tips_menu() {
	add_submenu_page( '', __( 'Reader tip', 'portal' ), '', 'edit_others_posts', 'portal-tip', 'portal_tip_page' );

	global $menu;
	$new = portal_tips_count_new();
	if ( ! $new || ! is_array( $menu ) ) {
		return;
	}
	foreach ( $menu as $i => $item ) {
		if ( 'edit.php?post_type=' . PORTAL_TIP_TYPE === ( $item[2] ?? '' ) ) {
			$menu[ $i ][0] .= ' <span class="awaiting-mod"><span class="pending-count">' . (int) $new . '</span></span>'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- adding the count bubble.
		}
	}
}
add_action( 'admin_menu', 'portal_tips_menu' );

/**
 * Number of tips nobody has looked at yet.
 *
 * @return int
 */
function portal_tips_count_new() {
	return count(
		get_posts(
			array(
				'post_type'   => PORTAL_TIP_TYPE,
				'post_status' => 'private',
				'numberposts' => 99,
				'fields'      => 'ids',
				'meta_key'    => '_portal_tip_state', // phpcs:ignore WordPress.DB.SlowDBQuery -- small list.
				'meta_value'  => 'new', // phpcs:ignore WordPress.DB.SlowDBQuery -- small list.
			)
		)
	);
}

/**
 * Opening a tip from the list goes to our screen (not WordPress's editing form).
 *
 * @param string $link    Edit link.
 * @param int    $post_id Post ID.
 * @return string
 */
function portal_tip_edit_link( $link, $post_id ) {
	$tip = portal_get_tip( $post_id );
	return $tip ? portal_tip_admin_url( $tip ) : $link;
}
add_filter( 'get_edit_post_link', 'portal_tip_edit_link', 10, 2 );

/**
 * WordPress's own editing form for a tip is never used: send editors to our screen.
 */
function portal_tip_redirect_editor() {
	// Only the "edit" screen: Trash / Restore links go through post.php too and must keep working.
	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- only a redirect.
	$tip    = portal_get_tip( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- only a redirect.
	if ( $tip && 'edit' === $action ) {
		wp_safe_redirect( portal_tip_admin_url( $tip ) );
		exit;
	}
}
add_action( 'load-post.php', 'portal_tip_redirect_editor' );

/**
 * List columns in wp-admin → Reader tips.
 *
 * @param array $columns Columns.
 * @return array
 */
function portal_tip_columns( $columns ) {
	return array(
		'cb'           => $columns['cb'] ?? '',
		'title'        => __( 'What happened', 'portal' ),
		'portal_ref'   => __( 'Reference', 'portal' ),
		'portal_from'  => __( 'Sent by', 'portal' ),
		'portal_where' => __( 'Where', 'portal' ),
		'portal_state' => __( 'State', 'portal' ),
		'portal_pics'  => __( 'Photos', 'portal' ),
		'date'         => __( 'Received', 'portal' ),
	);
}
add_filter( 'manage_' . PORTAL_TIP_TYPE . '_posts_columns', 'portal_tip_columns' );

/**
 * Fill the list columns.
 *
 * @param string $column  Column.
 * @param int    $post_id Tip ID.
 */
function portal_tip_column( $column, $post_id ) {
	$tip = portal_get_tip( $post_id );
	if ( ! $tip ) {
		return;
	}
	switch ( $column ) {
		case 'portal_ref':
			echo esc_html( portal_tip_reference( $tip ) );
			break;
		case 'portal_from':
			$sender = get_userdata( (int) $tip->post_author );
			echo esc_html( $sender ? $sender->display_name : '-' );
			echo 'name' === portal_tip_get( $tip, 'credit' ) ? '' : ' <em>(' . esc_html__( 'anonymous to readers', 'portal' ) . ')</em>';
			break;
		case 'portal_where':
			echo esc_html( (string) portal_tip_get( $tip, 'where' ) );
			break;
		case 'portal_state':
			$state = portal_tip_get( $tip, 'state' );
			echo '<strong>' . esc_html( portal_tip_states()[ $state ] ?? $state ) . '</strong>';
			break;
		case 'portal_pics':
			echo esc_html( (string) count( portal_tip_get( $tip, 'photos' ) ) );
			break;
	}
}
add_action( 'manage_' . PORTAL_TIP_TYPE . '_posts_custom_column', 'portal_tip_column', 10, 2 );

/**
 * Row links: only "Open" and "Trash" (no Quick Edit: tips are the reader's own words).
 *
 * @param array   $actions Links.
 * @param WP_Post $post    Post.
 * @return array
 */
function portal_tip_row_actions( $actions, $post ) {
	if ( PORTAL_TIP_TYPE !== $post->post_type ) {
		return $actions;
	}
	$keep = array( 'portal_open' => '<a href="' . esc_url( portal_tip_admin_url( $post ) ) . '">' . esc_html__( 'Open', 'portal' ) . '</a>' );
	if ( isset( $actions['trash'] ) ) {
		$keep['trash'] = $actions['trash'];
	}
	return $keep;
}
add_filter( 'post_row_actions', 'portal_tip_row_actions', 10, 2 );
add_filter( 'bulk_actions-edit-' . PORTAL_TIP_TYPE, static fn( $actions ) => array_intersect_key( $actions, array( 'trash' => 1 ) ) );

/**
 * The editors' screen for one tip: everything the reader sent, and the three actions.
 */
function portal_tip_page() {
	$tip = portal_get_tip( isset( $_GET['tip'] ) ? absint( $_GET['tip'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- display only.
	if ( ! $tip || ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( esc_html__( 'Tip not found.', 'portal' ) );
	}
	$state   = portal_tip_get( $tip, 'state' );
	$sender  = get_userdata( (int) $tip->post_author );
	$story   = (int) portal_tip_get( $tip, 'story' );
	$phone   = (string) portal_tip_get( $tip, 'phone' );
	$video   = (string) portal_tip_get( $tip, 'video' );
	$when    = (string) portal_tip_get( $tip, 'when' );
	$message = isset( $_GET['portal_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['portal_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
	$form    = static function ( $do, $label, $class = 'button' ) use ( $tip ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 8px 0">';
		echo '<input type="hidden" name="action" value="portal_tip_action"><input type="hidden" name="tip" value="' . esc_attr( (string) $tip->ID ) . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		wp_nonce_field( 'portal_tip_' . $do . '_' . $tip->ID );
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	};
	?>
	<div class="wrap">
		<h1><?php echo esc_html( portal_tip_reference( $tip ) . ': ' . $tip->post_title ); ?></h1>
		<p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . PORTAL_TIP_TYPE ) ); ?>">&larr; <?php esc_html_e( 'All tips', 'portal' ); ?></a></p>
		<?php if ( $message ) : ?>
			<div class="notice notice-info"><p><?php echo esc_html( $message ); ?></p></div>
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'State', 'portal' ); ?></th><td><strong><?php echo esc_html( portal_tip_states()[ $state ] ?? $state ); ?></strong>
				<?php if ( 'declined' === $state ) : ?>
					<br><?php echo esc_html( __( 'Reason sent to the reader:', 'portal' ) . ' ' . portal_tip_get( $tip, 'reason' ) ); ?>
				<?php endif; ?>
				<?php if ( $story && get_post( $story ) ) : ?>
					<br><a href="<?php echo esc_url( (string) get_edit_post_link( $story ) ); ?>"><?php echo esc_html( 'publish' === get_post_status( $story ) ? __( 'Open the published story', 'portal' ) : __( 'Open the draft story', 'portal' ) ); ?></a>
				<?php endif; ?>
			</td></tr>
			<tr><th><?php esc_html_e( 'Sent by', 'portal' ); ?></th><td>
				<?php if ( $sender ) : ?>
					<a href="<?php echo esc_url( get_edit_user_link( $sender->ID ) ); ?>"><?php echo esc_html( $sender->display_name ); ?></a> &lt;<?php echo esc_html( $sender->user_email ); ?>&gt;
				<?php endif; ?>
				<br><?php echo esc_html( 'name' === portal_tip_get( $tip, 'credit' ) ? __( 'Wants to be credited by name.', 'portal' ) : __( 'Wants to stay anonymous to readers.', 'portal' ) ); ?>
			</td></tr>
			<tr><th><?php esc_html_e( 'Mobile', 'portal' ); ?></th><td><?php echo $phone ? '<a href="' . esc_url( 'tel:' . $phone ) . '">' . esc_html( $phone ) . '</a>' : '&mdash;'; ?>
				<p class="description"><?php esc_html_e( 'For checking only. Never publish it. Deleted 90 days after the tip is closed.', 'portal' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Received', 'portal' ); ?></th><td><?php echo esc_html( get_the_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $tip ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Where / when', 'portal' ); ?></th><td><?php echo esc_html( (string) portal_tip_get( $tip, 'where' ) . ( $when ? ', ' . wp_date( get_option( 'date_format' ), strtotime( $when ) ) : '' ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'How they know', 'portal' ); ?></th><td><?php echo esc_html( portal_tip_sources()[ portal_tip_get( $tip, 'how' ) ] ?? '-' ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Details', 'portal' ); ?></th><td><?php echo wp_kses_post( wpautop( esc_html( $tip->post_content ) ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Video link', 'portal' ); ?></th><td><?php echo $video ? '<a href="' . esc_url( $video ) . '" target="_blank" rel="noopener noreferrer nofollow">' . esc_html( $video ) . '</a>' : '&mdash;'; ?></td></tr>
			<tr><th><?php esc_html_e( 'Photos', 'portal' ); ?></th><td>
				<?php
				$photos = portal_tip_get( $tip, 'photos' );
				if ( ! $photos ) {
					echo '&mdash;';
				}
				foreach ( $photos as $photo ) {
					$full = wp_get_attachment_url( $photo );
					if ( $full ) {
						echo '<a href="' . esc_url( $full ) . '" target="_blank" rel="noopener" style="display:inline-block;margin:0 8px 8px 0">' . wp_get_attachment_image( $photo, 'thumbnail' ) . '</a>';
					}
				}
				?>
				<p class="description"><?php esc_html_e( 'Location data was removed on upload. Blur faces of victims and children and number plates before publishing (see the blurring guide).', 'portal' ); ?></p>
			</td></tr>
		</table>

		<h2><?php esc_html_e( 'What to do', 'portal' ); ?></h2>
		<p>
			<?php
			if ( 'new' === $state ) {
				$form( 'checking', __( 'I am checking it', 'portal' ) );
			}
			if ( 'published' !== $state ) {
				$form( 'writeup', $story ? __( 'Open the News story', 'portal' ) : __( 'Write it up as a News story', 'portal' ), 'button button-primary' );
			}
			?>
		</p>
		<?php if ( ! in_array( $state, array( 'published', 'declined' ), true ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="portal_tip_action"><input type="hidden" name="tip" value="<?php echo esc_attr( (string) $tip->ID ); ?>"><input type="hidden" name="do" value="decline">
				<?php wp_nonce_field( 'portal_tip_decline_' . $tip->ID ); ?>
				<p><label for="portal-tip-reason"><strong><?php esc_html_e( 'Not publishing it? Reason for the reader:', 'portal' ); ?></strong></label><br>
					<textarea id="portal-tip-reason" name="reason" rows="3" cols="70" required minlength="10" placeholder="<?php esc_attr_e( 'e.g. We could not confirm this with the police or another source.', 'portal' ); ?>"></textarea></p>
				<p><button type="submit" class="button"><?php esc_html_e( 'Decline and e-mail the reader', 'portal' ); ?></button></p>
			</form>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( '"Write it up" makes a draft in News with the reader\'s text and photos. Check the facts, call the reader if needed, rewrite, and publish as usual. Sensitive stories still need a second editor. The reader is told automatically when the story is published.', 'portal' ); ?></p>
	</div>
	<?php
}

/**
 * Handle the buttons on the editors' screen.
 */
function portal_tip_action_handle() {
	$tip = portal_get_tip( isset( $_POST['tip'] ) ? absint( $_POST['tip'] ) : 0 );
	$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
	if ( ! $tip ) {
		wp_die( esc_html__( 'Tip not found.', 'portal' ) );
	}
	check_admin_referer( 'portal_tip_' . $do . '_' . $tip->ID );
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( esc_html__( 'Only editors can handle tips.', 'portal' ), '', array( 'response' => 403 ) );
	}
	$by = get_current_user_id();
	switch ( $do ) {
		case 'checking':
			$result = portal_tip_mark_checking( $tip, $by );
			$done   = __( 'Marked as being checked. The reader sees "Being checked" in My tips.', 'portal' );
			break;
		case 'writeup':
			$result = portal_tip_write_up( $tip, $by );
			if ( ! is_wp_error( $result ) ) {
				wp_safe_redirect( (string) get_edit_post_link( $result, 'url' ) );
				exit;
			}
			break;
		case 'decline':
			$result = portal_tip_decline( $tip, isset( $_POST['reason'] ) ? wp_unslash( $_POST['reason'] ) : '', $by ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned in portal_tip_decline().
			$done   = __( 'Declined. The reader has been e-mailed the reason.', 'portal' );
			break;
		default:
			$result = new WP_Error( 'portal_tip_unknown', __( 'Unknown action.', 'portal' ) );
	}
	$text = is_wp_error( $result ) ? $result->get_error_message() : $done;
	wp_safe_redirect( add_query_arg( 'portal_msg', rawurlencode( $text ), portal_tip_admin_url( $tip ) ) );
	exit;
}
add_action( 'admin_post_portal_tip_action', 'portal_tip_action_handle' );

/**
 * A story made from a tip is deleted before it was published: the tip can be written up again.
 *
 * @param int $post_id Post ID.
 */
function portal_tip_story_deleted( $post_id ) {
	$tip = portal_get_tip( (int) get_post_meta( $post_id, '_portal_tip_id', true ) );
	if ( $tip && 'published' !== portal_tip_get( $tip, 'state' ) ) {
		delete_post_meta( $tip->ID, '_portal_tip_story' );
	}
}
add_action( 'before_delete_post', 'portal_tip_story_deleted' );

/* ------------------------------------------------------------------ Clean-up (privacy) */

/**
 * Daily: 90 days after a tip is closed, delete the phone number; for declined tips also the
 * photos (published tips' photos belong to the story and stay).
 */
function portal_tips_cleanup() {
	$old = get_posts(
		array(
			'post_type'   => PORTAL_TIP_TYPE,
			'post_status' => 'any',
			'numberposts' => 200,
			'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- daily job.
				array(
					'key'     => '_portal_tip_closed_at',
					'value'   => time() - PORTAL_TIP_KEEP_DAYS * DAY_IN_SECONDS,
					'compare' => '<',
					'type'    => 'NUMERIC',
				),
			),
		)
	);
	foreach ( $old as $tip ) {
		delete_post_meta( $tip->ID, '_portal_tip_phone' );
		if ( 'declined' === portal_tip_get( $tip, 'state' ) ) {
			foreach ( portal_tip_get( $tip, 'photos' ) as $photo ) {
				if ( (int) wp_get_post_parent_id( $photo ) === (int) $tip->ID ) {
					wp_delete_attachment( $photo, true );
				}
			}
			delete_post_meta( $tip->ID, '_portal_tip_photos' );
		}
	}
}
add_action( 'portal_tips_cleanup', 'portal_tips_cleanup' );

/**
 * Make sure the daily clean-up is scheduled.
 */
function portal_tips_schedule() {
	if ( ! wp_next_scheduled( 'portal_tips_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'portal_tips_cleanup' );
	}
}
add_action( 'init', 'portal_tips_schedule' );

/**
 * Deleting a tip (Trash → Delete permanently) also deletes its photos that no story uses.
 *
 * @param int $post_id Post ID.
 */
function portal_tip_deleted( $post_id ) {
	$tip = portal_get_tip( $post_id );
	if ( ! $tip ) {
		return;
	}
	foreach ( portal_tip_get( $tip, 'photos' ) as $photo ) {
		if ( (int) wp_get_post_parent_id( $photo ) === (int) $tip->ID ) {
			wp_delete_attachment( $photo, true );
		}
	}
}
add_action( 'before_delete_post', 'portal_tip_deleted', 5 );

/* ------------------------------------------------------------------ API (for the app) */

/**
 * POST /portal/v1/tips (send a tip; fields as on the form, photos as "photos[]") and
 * GET /portal/v1/me/tips (your own tips and what happened to them).
 */
function portal_register_tip_routes() {
	$logged_in = static fn() => is_user_logged_in() ? true : new WP_Error( 'rest_not_logged_in', __( 'Please sign in first.', 'portal' ), array( 'status' => 401 ) );

	register_rest_route(
		'portal/v1',
		'/tips',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => $logged_in,
			'callback'            => static function ( WP_REST_Request $request ) {
				$fields = array();
				foreach ( array( 'title', 'details', 'where', 'when', 'how', 'video', 'phone', 'credit', 'consent' ) as $key ) {
					$fields[ $key ] = (string) $request->get_param( $key );
				}
				$files  = $request->get_file_params();
				$result = portal_submit_tip( get_current_user_id(), $fields, portal_tip_normalise_files( $files['photos'] ?? array() ) );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$tip      = get_post( $result );
				$response = rest_ensure_response(
					array(
						'id'        => $tip->ID,
						'reference' => portal_tip_reference( $tip ),
						'state'     => 'new',
					)
				);
				$response->set_status( 201 );
				return $response;
			},
		)
	);

	register_rest_route(
		'portal/v1',
		'/me/tips',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => $logged_in,
			'callback'            => static fn() => rest_ensure_response( portal_get_user_tips( get_current_user_id() ) ),
		)
	);
}
add_action( 'rest_api_init', 'portal_register_tip_routes' );
