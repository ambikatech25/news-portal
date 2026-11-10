<?php
/**
 * Task 17: Rank Math settings. Safe to run again.
 *
 *   docker compose run --rm -T wpcli eval-file /scripts/setup-seo.php
 *
 * portal-core adds two rules on top (includes/seo.php): NewsArticle vs BlogPosting, and
 * noindex for My Account / Write / Edit story.
 *
 * @package Portal
 */

// Modules: only what a news site needs. Off: shop/forum integrations we do not use, and the
// AI and "instant indexing" modules that send content to outside services or need accounts.
update_option( 'rank_math_modules', array( 'sitemap', 'rich-snippet', 'link-counter', 'seo-analysis' ) );

$portal_titles = (array) get_option( 'rank-math-options-titles', array() );
$portal_titles = array_merge(
	$portal_titles,
	array(
		'title_separator'               => '|',
		'homepage_title'                => '%sitename% %sep% %sitedesc%',
		'homepage_description'          => 'CityCharcha: news and community voices from Chhattisgarh and central India - logistics, industry, coal and steel, crime and regional news.',
		'knowledgegraph_type'           => 'company',      // a news organisation, not a person
		'knowledgegraph_name'           => get_bloginfo( 'name' ),
		'website_name'                  => get_bloginfo( 'name' ), // was still "News Portal"
		'url_author_base'               => 'writer',       // Task 9: profile URLs are /writer/<name>/
		// Writer profiles in Google: yes (who wrote it builds trust; only writers have profiles, Task 9).
		'author_custom_robots'          => 'on',
		'author_robots'                 => array( 'index' ),
		'pt_post_default_rich_snippet'  => 'article',
		'pt_post_default_article_type'  => 'NewsArticle',  // portal-core switches Community Blogs to BlogPosting
		'pt_post_title'                 => '%title% %sep% %sitename%',
		'tax_post_tag_robots'           => array( 'noindex' ), // tag pages: thin, keep out of Google
		'pt_attachment_robots'          => array( 'noindex' ),
		'noindex_empty_taxonomies'      => 'on',
		'disable_date_archives'         => 'on',           // /2026/11/ pages add nothing for readers
	)
);
update_option( 'rank-math-options-titles', $portal_titles );

$portal_sitemap = (array) get_option( 'rank-math-options-sitemap', array() );
$portal_sitemap = array_merge(
	$portal_sitemap,
	array(
		'items_per_page'       => 200,
		'include_images'       => 'on',
		'pt_post_sitemap'      => 'on',
		'pt_page_sitemap'      => 'on',
		'tax_category_sitemap' => 'on',
		'tax_post_tag_sitemap' => 'off',
		'authors_sitemap'      => 'on',
		// No "exclude_roles": with one role in it, Rank Math builds a broken database query and
		// silently drops the whole writer sitemap (found in Task 17). Not needed anyway: the writer
		// sitemap only lists people with published stories, and members have none.
		'exclude_roles'        => array(),
	)
);
update_option( 'rank-math-options-sitemap', $portal_sitemap );

$portal_general = (array) get_option( 'rank-math-options-general', array() );
$portal_general = array_merge(
	$portal_general,
	array(
		'attachment_redirect_urls' => 'on', // image pages redirect to their story
		'breadcrumbs'              => 'off',
		'strip_category_base'      => 'off', // keep /crime/... story links exactly as they are
	)
);
update_option( 'rank-math-options-general', $portal_general );

// Rebuild URL rules once (sitemap addresses), and throw away Rank Math's cached sitemaps so the
// new settings show at once (it caches them, which hid the changes in testing).
update_option( 'portal_flush_rewrites', 1 );
if ( class_exists( '\RankMath\Sitemap\Cache' ) ) {
	\RankMath\Sitemap\Cache::invalidate_storage();
}

WP_CLI::success( 'Rank Math: news-site settings, sitemaps and modules saved.' );
