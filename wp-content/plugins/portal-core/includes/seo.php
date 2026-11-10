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
		$blog            = get_category_by_slug( 'community-blogs' );
		$is_blog         = $blog && has_category( $blog->term_id, $post ) && count( wp_get_post_categories( $post->ID ) ) === 1;
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
