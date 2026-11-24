<?php
/**
 * SEO rules on top of Rank Math (configured by scripts/setup-seo.php).
 *
 *  - Structured data: news-desk stories are "NewsArticle", Community Blogs posts "BlogPosting".
 *    Honest labels matter: NewsArticle is what Google News / Top Stories look for, and community
 *    opinion should not pretend to be newsroom reporting.
 *  - Pages that are only for logged-in people (My Account, Write, Edit story) never appear in
 *    search results.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * NewsArticle or BlogPosting, per story.
 *
 * @param array $entity Rank Math article schema.
 * @return array
 */
function portal_article_schema_type( $entity ) {
	$post = get_post();
	// This hook sees every schema piece (organisation, web page, breadcrumbs ...): change articles only.
	if ( $post && 'post' === $post->post_type && in_array( $entity['@type'] ?? '', array( 'Article', 'BlogPosting', 'NewsArticle' ), true ) ) {
		$blog_ids        = function_exists( 'portal_category_ids_all' ) ? portal_category_ids_all( 'community-blogs' ) : array();
		$is_blog         = $blog_ids && has_category( $blog_ids, $post ) && count( wp_get_post_categories( $post->ID ) ) === 1;
		$entity['@type'] = $is_blog ? 'BlogPosting' : 'NewsArticle';
	}
	return $entity;
}
// Rank Math builds a story's DEFAULT schema through the "article" hook, and stories with their
// own saved schema through the general hook, so listen to both (found in Task 17).
add_filter( 'rank_math/snippet/rich_snippet_article_entity', 'portal_article_schema_type' );
add_filter( 'rank_math/snippet/rich_snippet_entity', 'portal_article_schema_type' );

/**
 * Slugs of pages only for logged-in people.
 *
 * @return string[]
 */
function portal_private_page_slugs() {
	return (array) apply_filters( 'portal_private_page_slugs', array( 'account', 'write', 'edit-story' ) );
}

/**
 * "noindex" for those pages (Rank Math's robots meta, and WordPress's own as a fallback).
 *
 * @param array $robots Robots directives.
 * @return array
 */
function portal_noindex_private_pages( $robots ) {
	if ( is_page( portal_private_page_slugs() ) ) {
		$robots['index']  = 'noindex';
		$robots['follow'] = 'follow';
		unset( $robots['noindex'] );
	}
	return $robots;
}
add_filter( 'rank_math/frontend/robots', 'portal_noindex_private_pages' );
add_filter(
	'wp_robots',
	static function ( $robots ) {
		if ( is_page( portal_private_page_slugs() ) ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}
		return $robots;
	}
);

/**
 * Leave those pages out of the sitemap too (returning nothing drops the sitemap line).
 *
 * @param array|false $url  Sitemap entry.
 * @param string      $type post | user | term.
 * @param object      $item The post, user or term.
 * @return array|false
 */
function portal_sitemap_skip_private_pages( $url, $type, $item ) {
	// Rank Math passes plain database rows here, not WP_Post objects: check the fields.
	if ( 'post' === $type && is_object( $item ) && 'page' === ( $item->post_type ?? '' ) && in_array( $item->post_name ?? '', portal_private_page_slugs(), true ) ) {
		return false;
	}
	return $url;
}
add_filter( 'rank_math/sitemap/entry', 'portal_sitemap_skip_private_pages', 10, 3 );

/* ------------------------------------------------------------------ Link previews (share cards) */

/*
 * When a story is shared on WhatsApp, Facebook, X, Telegram or LinkedIn, the app shows a card:
 * picture, headline, two or three lines, link. The apps build it from the Open Graph tags Rank
 * Math writes. Two additions:
 *   - a 1200x630 copy of each photo just for sharing (the size these apps expect; small enough
 *     for WhatsApp, which skips pictures over about 300 KB);
 *   - a picture in the story's section colour when the story has no photo, so the card is never
 *     text-only (also for the home page and section pages).
 */

/**
 * The share-sized copy of every uploaded photo.
 */
function portal_share_image_size() {
	add_image_size( 'portal_share', 1200, 630, true );
}
add_action( 'after_setup_theme', 'portal_share_image_size' );

/**
 * Rank Math tries the share size first.
 *
 * @param string[] $sizes Image sizes, in order of preference.
 * @return string[]
 */
function portal_share_image_sizes( $sizes ) {
	return array_values( array_unique( array_merge( array( 'portal_share' ), (array) $sizes ) ) );
}
add_filter( 'rank_math/opengraph/image_sizes', 'portal_share_image_sizes' );

/**
 * The English section slug that decides a page's share picture colour ('news' by default).
 *
 * @return string
 */
function portal_share_section() {
	$cats = array();
	if ( is_singular( 'post' ) ) {
		$cats = get_the_category( get_queried_object_id() );
	} elseif ( is_category() ) {
		$cats = array( get_queried_object() );
	}
	foreach ( $cats as $cat ) {
		$en = function_exists( 'pll_get_term' ) ? pll_get_term( $cat->term_id, 'en' ) : 0;
		$en = $en ? get_term( $en, 'category' ) : $cat;
		if ( $en instanceof WP_Term && file_exists( PORTAL_CORE_DIR . 'assets/share/share-' . $en->slug . '.jpg' ) ) {
			return $en->slug;
		}
	}
	return 'news';
}

/**
 * No picture found by Rank Math: add the section-colour share picture.
 *
 * @param object $image Rank Math image object (has_images(), add_image()).
 */
function portal_share_fallback_image( $image ) {
	if ( ! is_object( $image ) || ! method_exists( $image, 'has_images' ) || $image->has_images() ) {
		return;
	}
	$image->add_image(
		array(
			'url'    => PORTAL_CORE_URL . 'assets/share/share-' . portal_share_section() . '.jpg',
			'width'  => 1200,
			'height' => 630,
			'alt'    => get_bloginfo( 'name' ),
			'type'   => 'image/jpeg',
		)
	);
}
add_action( 'rank_math/opengraph/facebook/add_additional_images', 'portal_share_fallback_image' );
add_action( 'rank_math/opengraph/twitter/add_additional_images', 'portal_share_fallback_image' );

/**
 * A cut-short share description ends with "…", so it reads as a teaser, not a broken sentence.
 *
 * @param string $text Description.
 * @return string
 */
function portal_share_description_ending( $text ) {
	$text = trim( (string) $text );
	if ( '' === $text || preg_match( '/[.!?।…"”\')]$/u', $text ) ) {
		return $text;
	}
	return rtrim( $text, ',;:-– ' ) . '…';
}
add_filter( 'rank_math/opengraph/facebook/og_description', 'portal_share_description_ending' );
add_filter( 'rank_math/opengraph/twitter/twitter_description', 'portal_share_description_ending' );
