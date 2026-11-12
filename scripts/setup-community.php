<?php
/**
 * Task 17C: the Community page, and the main menu points to it. Safe to run again.
 *
 *   docker compose run --rm -T wpcli eval-file /scripts/setup-community.php
 *
 * The page's look and sections come from the theme (page-community.php) and portal-core
 * (includes/community.php); the page itself only holds the short intro, which editors can
 * change in wp-admin → Pages → Community.
 *
 * @package Portal
 */

// 1. The page (address /community/).
$portal_page = get_page_by_path( 'community' );
if ( ! $portal_page ) {
	$portal_page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Community',
			'post_name'    => 'community',
			'post_content' => "<!-- wp:paragraph -->\n<p>Stories, experiences and series written by people from Chhattisgarh and central India: drivers, shop owners, students, workers and neighbours. Every story is read by our editors before it is published.</p>\n<!-- /wp:paragraph -->",
		),
		true
	);
	if ( is_wp_error( $portal_page_id ) ) {
		WP_CLI::error( $portal_page_id );
	}
	WP_CLI::log( 'Created the Community page.' );
} else {
	$portal_page_id = $portal_page->ID;
	if ( 'publish' !== $portal_page->post_status ) {
		wp_update_post(
			array(
				'ID'          => $portal_page_id,
				'post_status' => 'publish',
			)
		);
	}
	WP_CLI::log( 'Community page already exists.' );
}

// 2. Main menu: the "Community Blogs" category item becomes "Community" (same place).
$portal_menu = wp_get_nav_menu_object( 'main-menu' );
$portal_cat  = get_category_by_slug( 'community-blogs' );
if ( $portal_menu && $portal_cat ) {
	$portal_has_page = false;
	foreach ( (array) wp_get_nav_menu_items( $portal_menu->term_id ) as $portal_item ) {
		if ( 'page' === $portal_item->object && (int) $portal_item->object_id === (int) $portal_page_id ) {
			$portal_has_page = true;
		}
	}
	foreach ( (array) wp_get_nav_menu_items( $portal_menu->term_id ) as $portal_item ) {
		if ( 'category' !== $portal_item->object || (int) $portal_item->object_id !== (int) $portal_cat->term_id ) {
			continue;
		}
		if ( $portal_has_page ) {
			wp_delete_post( $portal_item->ID, true ); // page already in the menu: drop the old link.
			continue;
		}
		wp_update_nav_menu_item(
			$portal_menu->term_id,
			$portal_item->ID,
			array(
				'menu-item-object-id' => $portal_page_id,
				'menu-item-object'    => 'page',
				'menu-item-type'      => 'post_type',
				'menu-item-title'     => 'Community',
				'menu-item-position'  => $portal_item->menu_order,
				'menu-item-status'    => 'publish',
			)
		);
		$portal_has_page = true;
	}
	if ( ! $portal_has_page ) {
		wp_update_nav_menu_item(
			$portal_menu->term_id,
			0,
			array(
				'menu-item-object-id' => $portal_page_id,
				'menu-item-object'    => 'page',
				'menu-item-type'      => 'post_type',
				'menu-item-title'     => 'Community',
				'menu-item-status'    => 'publish',
			)
		);
	}
	WP_CLI::log( 'Main menu: "Community" points to the Community page.' );
} else {
	WP_CLI::warning( 'Main menu or Community Blogs category not found: run setup-structure.sh first.' );
}

WP_CLI::success( 'Community page ready: ' . get_permalink( $portal_page_id ) );
