<?php
/**
 * Demo stories for checking the design locally. NEVER for the live site
 * (the script refuses to run unless WP_ENVIRONMENT_TYPE is "local").
 *
 * Everything it creates is tagged with the hidden meta _portal_demo, so "remove"
 * deletes exactly that and nothing else.
 *
 * Run from the project folder:
 *   docker compose run --rm -T wpcli eval-file /scripts/demo-content.php add
 *   docker compose run --rm -T wpcli eval-file /scripts/demo-content.php remove
 *
 * Pictures are plain coloured graphics drawn on the spot (no downloads).
 *
 * @package Portal
 */

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Demo content is only allowed on a local development site.' );
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

/**
 * Delete all demo posts, pictures and writers.
 */
function portal_demo_remove() {
	$ids = get_posts(
		array(
			'post_type'   => array( 'post', 'attachment' ),
			'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future', 'inherit', 'trash' ),
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_key'    => '_portal_demo',
			'meta_value'  => '1',
		)
	);
	foreach ( $ids as $id ) {
		if ( 'attachment' === get_post_type( $id ) ) {
			wp_delete_attachment( $id, true );
		} else {
			unstick_post( $id );
			wp_delete_post( $id, true );
		}
	}
	$users = get_users(
		array(
			'meta_key'   => '_portal_demo',
			'meta_value' => '1',
			'fields'     => 'ID',
		)
	);
	foreach ( $users as $user_id ) {
		wp_delete_user( (int) $user_id );
	}
	if ( taxonomy_exists( 'portal_series' ) ) {
		$series = get_terms(
			array(
				'taxonomy'   => 'portal_series',
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_key'   => '_portal_demo',
				'meta_value' => '1',
			)
		);
		foreach ( is_wp_error( $series ) ? array() : $series as $term_id ) {
			wp_delete_term( (int) $term_id, 'portal_series' );
		}
	}
	WP_CLI::log( sprintf( 'Removed %d demo posts/pictures and %d demo writers.', count( $ids ), count( $users ) ) );
}

/**
 * Draw a simple 1200x675 picture (colour gradient + soft circles) and return its path.
 *
 * @param int[] $rgb  Base colour.
 * @param int   $seed Variation.
 * @return string Temporary file path.
 */
function portal_demo_picture( array $rgb, $seed ) {
	$w  = 1200;
	$h  = 675;
	$im = imagecreatetruecolor( $w, $h );
	for ( $y = 0; $y < $h; $y++ ) {
		$t = $y / $h * 0.55;
		imageline( $im, 0, $y, $w, $y, imagecolorallocate( $im, (int) ( $rgb[0] * ( 1 - $t ) ), (int) ( $rgb[1] * ( 1 - $t ) ), (int) ( $rgb[2] * ( 1 - $t ) ) ) );
	}
	mt_srand( $seed );
	for ( $i = 0; $i < 7; $i++ ) {
		$size = mt_rand( 120, 520 );
		imagefilledellipse( $im, mt_rand( 0, $w ), mt_rand( 0, $h ), $size, $size, imagecolorallocatealpha( $im, 255, 255, 255, mt_rand( 95, 118 ) ) );
	}
	$file = wp_tempnam( 'portal-demo.jpg' );
	imagejpeg( $im, $file, 82 );
	imagedestroy( $im );
	return $file;
}

/**
 * Create the demo writers (Authors) for Community Blogs.
 *
 * @return int[] User IDs.
 */
function portal_demo_writers() {
	$ids = array();
	foreach ( array( 'Asha Verma', 'Rahul Sahu', 'Meena Patel' ) as $i => $name ) {
		$id = wp_insert_user(
			array(
				'user_login'   => 'portal_demo_writer_' . ( $i + 1 ),
				'user_email'   => 'demo-writer-' . ( $i + 1 ) . '@portal.test',
				'user_pass'    => wp_generate_password( 24 ),
				'display_name' => $name,
				'role'         => 'author',
				'description'  => 'Demo writer for local testing.',
			)
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id );
		}
		update_user_meta( $id, '_portal_demo', '1' );
		$ids[] = $id;
	}
	return $ids;
}

/**
 * Create the demo stories.
 */
function portal_demo_add() {
	$admin   = get_user_by( 'login', getenv( 'WP_ADMIN_USER' ) ? getenv( 'WP_ADMIN_USER' ) : 'portaladmin' );
	$desk    = $admin ? $admin->ID : 1;
	$writers = portal_demo_writers();
	$body    = "<!-- wp:paragraph -->\n<p>This is demo text so the page layout can be checked. It is not a real news report and will be removed before launch.</p>\n<!-- /wp:paragraph -->\n\n"
		. "<!-- wp:paragraph -->\n<p>A real story would explain what happened, where and when, who is affected, and what happens next. Editors add the facts, quotes and sources here.</p>\n<!-- /wp:paragraph -->\n\n"
		. "<!-- wp:paragraph -->\n<p>Readers can follow the writer, bookmark the story and report it if something looks wrong.</p>\n<!-- /wp:paragraph -->";

	// Category => base picture colour.
	$colours = array(
		'news'            => array( 180, 30, 45 ),
		'region'          => array( 30, 110, 90 ),
		'industry'        => array( 70, 80, 105 ),
		'logistics'       => array( 200, 120, 30 ),
		'crime'           => array( 55, 45, 70 ),
		'community-blogs' => array( 40, 100, 170 ),
	);

	// Title, category, hours ago, flags.
	$stories = array(
		array( 'Heavy rain alert for central Chhattisgarh today', 'news', 1, array( 'breaking' => true ) ),
		array( 'Freight corridor link to Raipur nears completion', 'logistics', 3, array( 'sticky' => true ) ),
		array( 'Raipur smart city projects reach final phase', 'news', 5, array() ),
		array( 'New bus routes announced for Nava Raipur', 'news', 20, array() ),
		array( 'Power tariff hearing scheduled next month', 'news', 30, array() ),
		array( 'Assembly to discuss road safety bill this session', 'news', 52, array() ),
		array( 'Bilaspur: new flyover opens to traffic', 'region', 4, array() ),
		array( 'Korba villages get solar street lights', 'region', 26, array() ),
		array( 'Durg-Bhilai water supply upgrade begins', 'region', 45, array() ),
		array( 'Bastar tourism festival dates announced', 'region', 70, array() ),
		array( 'Steel plants report higher output this quarter', 'industry', 6, array() ),
		array( 'Coal dispatch from Korba fields rises', 'industry', 28, array() ),
		array( 'Sponge iron units seek stable power supply', 'industry', 50, array() ),
		array( 'Scrap prices steady as demand holds: full analysis', 'industry', 75, array( 'premium' => true ) ),
		array( 'Rail rake availability improves for steel', 'logistics', 22, array() ),
		array( 'Truckers\' association meets on toll charges', 'logistics', 40, array() ),
		array( 'New warehouse park proposed on NH-53', 'logistics', 66, array() ),
		array( 'Diesel price change: what it means for fleets', 'logistics', 90, array() ),
		array( 'Cyber cell warns of fake parcel delivery calls', 'crime', 2, array( 'breaking' => true ) ),
		array( 'Stolen trucks recovered in joint operation', 'crime', 18, array( 'verified' => true ) ),
		array( 'Police step up night patrols in city markets', 'crime', 36, array() ),
		array( 'Helpline launched for online fraud victims', 'crime', 60, array() ),
		array( 'My first week driving a truck on NH-130', 'community-blogs', 8, array( 'writer' => 0 ) ),
		array( 'Five things I learned running a small fabrication shop', 'community-blogs', 27, array( 'writer' => 1 ) ),
		array( 'Why Raipur needs more cycle lanes', 'community-blogs', 48, array( 'writer' => 2 ) ),
		array( 'A weekend at Chitrakote falls', 'community-blogs', 80, array( 'writer' => 0 ) ),
		// Task 17A: a 3-part series (oldest first = Part 1).
		array( 'Truck diaries: leaving Raipur before dawn', 'community-blogs', 100, array( 'writer' => 0, 'series' => 'Truck diaries' ) ),
		array( 'Truck diaries: a night at a dhaba near Sambalpur', 'community-blogs', 60, array( 'writer' => 0, 'series' => 'Truck diaries' ) ),
		array( 'Truck diaries: unloading at Paradip port', 'community-blogs', 12, array( 'writer' => 0, 'series' => 'Truck diaries' ) ),
	);
	$series_parts = array();

	mt_srand( 7 ); // Same "random" view counts every run.
	foreach ( $stories as $n => list( $title, $slug, $hours, $flags ) ) {
		$cat     = get_category_by_slug( $slug );
		$post_id = wp_insert_post(
			array(
				'post_title'    => $title,
				'post_content'  => $body,
				'post_status'   => 'publish',
				'post_author'   => isset( $flags['writer'] ) ? $writers[ $flags['writer'] ] : $desk,
				'post_category' => array( $cat ? $cat->term_id : 1 ),
				'post_date'     => wp_date( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			WP_CLI::error( $post_id );
		}
		update_post_meta( $post_id, '_portal_demo', '1' );
		update_post_meta( $post_id, '_portal_views', mt_rand( 5, 900 ) );
		update_post_meta( $post_id, 'portal_breaking', ! empty( $flags['breaking'] ) );
		update_post_meta( $post_id, 'portal_is_premium', ! empty( $flags['premium'] ) );
		if ( ! empty( $flags['verified'] ) ) {
			update_post_meta( $post_id, 'portal_verified', true );
			update_post_meta( $post_id, 'portal_fir_number', 'DEMO-101/2026' );
			update_post_meta( $post_id, 'portal_police_station', 'Demo Police Station' );
		}
		if ( ! empty( $flags['sticky'] ) ) {
			stick_post( $post_id );
		}
		if ( ! empty( $flags['series'] ) ) {
			$series_parts[ $flags['series'] ][] = array( $post_id, $writers[ $flags['writer'] ] );
		}

		// Picture.
		$tmp = portal_demo_picture( $colours[ $slug ], $n + 1 );
		$att = media_handle_sideload(
			array(
				'name'     => sanitize_title( $title ) . '.jpg',
				'tmp_name' => $tmp,
			),
			$post_id,
			$title
		);
		if ( is_wp_error( $att ) ) {
			WP_CLI::warning( 'Picture failed for "' . $title . '": ' . $att->get_error_message() );
			continue;
		}
		update_post_meta( $att, '_portal_demo', '1' );
		set_post_thumbnail( $post_id, $att );
		WP_CLI::log( 'Added: ' . $title );
	}

	// Series (Task 17A): created as the writer would, parts added in order.
	foreach ( $series_parts as $name => $parts ) {
		if ( ! function_exists( 'portal_create_series' ) ) {
			break;
		}
		$term_id = portal_create_series( $parts[0][1], $name, 'Demo series: a driver\'s notes from the road, told in parts.' );
		if ( is_wp_error( $term_id ) ) {
			WP_CLI::warning( 'Series failed: ' . $term_id->get_error_message() );
			continue;
		}
		update_term_meta( $term_id, '_portal_demo', '1' );
		foreach ( $parts as list( $post_id ) ) {
			wp_set_object_terms( $post_id, array( $term_id ), 'portal_series' );
		}
		WP_CLI::log( 'Added series: ' . $name );
	}
}

$portal_action = $args[0] ?? 'add';
portal_demo_remove(); // "add" starts clean too, so running it twice never doubles up.
if ( 'add' === $portal_action ) {
	portal_demo_add();
}
WP_CLI::success( 'Demo content: ' . $portal_action . ' done.' );
