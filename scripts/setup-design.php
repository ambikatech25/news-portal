<?php
/**
 * Tasks 7-8: brand colours (saved as Kadence's own palette setting) and article page options.
 *
 * Because this is a theme setting (not CSS), the owner can still fine-tune every colour
 * later in Appearance > Customize > Colors. Safe to run again; it resets the 9 main colours.
 *
 * Run from the project folder:
 *   docker compose run --rm -T wpcli eval-file /scripts/setup-design.php
 *
 * @package Portal
 */

$portal_brand = array(
	'palette1' => '#C8102E', // Accent: links, buttons, Breaking strip, section rules.
	'palette2' => '#9B0C22', // Accent hover.
	'palette3' => '#111827', // Headlines, strongest text.
	'palette4' => '#1F2937', // Body text.
	'palette5' => '#4B5563', // Secondary text.
	'palette6' => '#6B7280', // Muted text.
	'palette7' => '#E5E7EB', // Borders.
	'palette8' => '#F3F4F6', // Light backgrounds.
	'palette9' => '#FFFFFF', // White.
);

$portal_saved = json_decode( (string) get_option( 'kadence_global_palette', '' ), true );
if ( ! is_array( $portal_saved ) || empty( $portal_saved['palette'] ) ) {
	// Nothing saved yet: start from Kadence's built-in default.
	$portal_saved = json_decode( \Kadence\kadence()->get_default_palette(), true );
}

foreach ( $portal_saved['palette'] as $portal_i => $portal_color ) {
	if ( isset( $portal_brand[ $portal_color['slug'] ] ) ) {
		$portal_saved['palette'][ $portal_i ]['color'] = $portal_brand[ $portal_color['slug'] ];
	}
}
$portal_saved['active'] = 'palette';

update_option( 'kadence_global_palette', wp_json_encode( $portal_saved ) );

// White page background (Kadence's default is the light-grey palette8).
set_theme_mod( 'site_background', array( 'desktop' => array( 'color' => 'palette9' ) ) );

// Show the site name in the header on phones and tablets too (Kadence hides it on tablets by default).
set_theme_mod(
	'logo_layout',
	array(
		'include' => array(
			'mobile'  => 'logo_title',
			'tablet'  => 'logo_title',
			'desktop' => 'logo_title',
		),
		'layout'  => array(
			'mobile'  => '',
			'tablet'  => '',
			'desktop' => 'standard',
		),
	)
);

// Footer line: "© 2026 Site Name" (drops the theme advert; the name updates when the site title changes).
set_theme_mod( 'footer_html_content', '{copyright} {year} {site-title}' );

/*
 * Task 8: article page. Our child theme adds its own author card and "Related stories"
 * (same story cards as the home page, same list as the app), so Kadence's versions are off.
 * Previous/Next links are off too: for news, related stories are the better next click.
 */
set_theme_mod( 'post_author_box', false );
set_theme_mod( 'post_related', false );
set_theme_mod( 'post_navigation', false );

// Headline first, picture under it. Kadence's default puts the picture behind the title
// as a full-width banner, which fills a whole phone screen before the reader sees the news.
set_theme_mod( 'post_feature_position', 'below' );

// Byline: writer's photo next to the name, and an "Updated" date when a story was changed
// after publishing (readers must be able to see that a correction was made).
$portal_meta = (array) \Kadence\kadence()->option( 'post_title_element_meta' );
set_theme_mod(
	'post_title_element_meta',
	array_merge(
		$portal_meta,
		array(
			'authorImage'          => true,
			'dateUpdated'          => true,
			'dateUpdatedDifferent' => true,
		)
	)
);

WP_CLI::success( 'Brand colours, background, header title, footer line and article page settings saved.' );
