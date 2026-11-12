<?php
/**
 * Article page: adds our parts to Kadence's single-post template through its hooks,
 * instead of copying the whole template (so Kadence updates keep working).
 *
 * Order on the page:
 *   header (Kadence: category, headline, "By <writer> [Follow]", date)   + kadence_author_meta_output
 *   -> Verified box, Premium label, read time, Reading mode   kadence_single_after_entry_header
 *   -> article text and tags (Kadence)
 *   -> series box (5), writer card (8), Like / Save / Comments, share, Report (10),
 *      comments panel (12)                                     kadence_single_after_inner_content
 *   -> related posts                                           kadence_single_after_content
 *
 * Comments sit right under the Comments button and open in place when it is pressed (article.js),
 * like on video sites, instead of at the very bottom where readers missed them. Kadence prints
 * comments last and has no hook after them, so we print them ourselves and hand Kadence's own
 * later call an empty template (portal_child_comments_once).
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether we are on a normal article page (not a page, preview of another type, etc.).
 *
 * @return bool
 */
function portal_child_is_article() {
	return is_singular( 'post' );
}

/**
 * Verified box, Premium label and read time, right under the headline.
 */
function portal_child_article_flags() {
	if ( portal_child_is_article() ) {
		get_template_part( 'template-parts/portal/article-flags', null, array( 'post' => get_post() ) );
	}
}
add_action( 'kadence_single_after_entry_header', 'portal_child_article_flags' );

/**
 * Share buttons and the Report button, after the article text.
 */
function portal_child_article_actions() {
	if ( portal_child_is_article() ) {
		get_template_part( 'template-parts/portal/article-actions', null, array( 'post' => get_post() ) );
	}
}
add_action( 'kadence_single_after_inner_content', 'portal_child_article_actions', 10 );

/**
 * Writer card, right after the story (above Like / Save / Comments).
 */
function portal_child_article_author() {
	if ( portal_child_is_article() ) {
		get_template_part( 'template-parts/portal/author-card', null, array( 'post' => get_post() ) );
	}
}
add_action( 'kadence_single_after_inner_content', 'portal_child_article_author', 8 );

/**
 * Comments panel, right under the Comments button. Open without JavaScript; article.js folds it
 * and the button opens it in place (it stays open when a link points to a comment).
 */
function portal_child_article_comments() {
	if ( ! portal_child_is_article() ) {
		return;
	}
	// Same condition Kadence uses (comments allowed on this post, open or already there).
	$show = function_exists( '\Kadence\kadence' ) ? \Kadence\kadence()->show_comments() : ( comments_open() || get_comments_number() );
	if ( ! $show ) {
		return;
	}
	echo '<div class="portal-comments-panel" id="portal-comments-panel" data-portal-comments>';
	comments_template();
	echo '</div>';
}
add_action( 'kadence_single_after_inner_content', 'portal_child_article_comments', 12 );

/**
 * Related posts, after the article box.
 */
function portal_child_article_after() {
	if ( portal_child_is_article() && function_exists( 'portal_get_related_posts' ) ) {
		get_template_part( 'template-parts/portal/related', null, array( 'posts' => portal_get_related_posts( get_post(), 4 ) ) );
	}
}
add_action( 'kadence_single_after_content', 'portal_child_article_after' );

/**
 * "Follow" right after the writer's name under the headline, so readers can follow at once.
 * Writers do not see it on their own stories (portal_child_toggle leaves it out).
 *
 * @param string $output Kadence's "By <writer>" markup.
 * @return string
 */
function portal_child_byline_follow( $output ) {
	if ( ! portal_child_is_article() || ! function_exists( 'portal_child_toggle' ) || ! in_the_loop() ) {
		return $output;
	}
	$button = portal_child_toggle( 'follow-author', (int) get_the_author_meta( 'ID' ) );
	$end    = strrpos( $output, '</span>' );
	if ( ! $button || false === $end ) {
		return $output;
	}
	// Inside Kadence's "posted-by" box, so its separator dot comes after the button.
	return substr_replace( $output, '<span class="portal-byline-follow">' . $button . '</span>', $end, 0 );
}
add_filter( 'kadence_author_meta_output', 'portal_child_byline_follow' );

/**
 * Print the comments only once per page: the second call (Kadence's own, at the bottom)
 * gets an empty template.
 *
 * @param string $template Comments template path.
 * @return string
 */
function portal_child_comments_once( $template ) {
	static $shown = false;
	if ( ! portal_child_is_article() ) {
		return $template;
	}
	if ( $shown ) {
		return get_stylesheet_directory() . '/template-parts/portal/comments-shown.php';
	}
	$shown = true;
	return $template;
}
add_filter( 'comments_template', 'portal_child_comments_once' );

/**
 * Share targets for a post: key => [label, url]. Plain links, no third-party scripts,
 * so sharing adds no tracking and no page weight. WhatsApp first: most shared in India.
 *
 * @param WP_Post $post Post.
 * @return array<string,array{0:string,1:string}>
 */
function portal_child_share_links( WP_Post $post ) {
	$url   = rawurlencode( get_permalink( $post ) );
	$title = rawurlencode( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) );
	return array(
		'whatsapp' => array( 'WhatsApp', "https://wa.me/?text={$title}%20{$url}" ),
		'facebook' => array( 'Facebook', "https://www.facebook.com/sharer/sharer.php?u={$url}" ),
		'x'        => array( 'X', "https://twitter.com/intent/tweet?text={$title}&url={$url}" ),
		'telegram' => array( 'Telegram', "https://t.me/share/url?url={$url}&text={$title}" ),
		'linkedin' => array( 'LinkedIn', "https://www.linkedin.com/sharing/share-offsite/?url={$url}" ),
	);
}

/**
 * Small icon for a share button. Kadence ships the app logos. Three are drawn here:
 * Kadence has no X logo, no share icon, and its icon named "link" is a Visa card.
 *
 * @param string $key Share key.
 * @return string SVG markup (trusted).
 */
function portal_child_share_icon( $key ) {
	$own = array(
		'x'     => '<path fill="currentColor" d="M18.9 2H22l-6.8 7.8L23 22h-6.2l-4.8-6.3L6.4 22H3.3l7.3-8.4L1 2h6.3l4.4 5.8L18.9 2Zm-1.1 18.1h1.7L6.3 3.8H4.5l13.3 16.3Z"/>',
		'copy'  => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M10 14a4.5 4.5 0 0 0 6.4 0l3.3-3.3a4.5 4.5 0 0 0-6.4-6.4l-1 1M14 10a4.5 4.5 0 0 0-6.4 0l-3.3 3.3a4.5 4.5 0 0 0 6.4 6.4l1-1"/>',
		'share' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 15V3M7.5 7.5 12 3l4.5 4.5M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/>',
	);
	if ( isset( $own[ $key ] ) ) {
		return '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">' . $own[ $key ] . '</svg>';
	}
	if ( function_exists( '\Kadence\kadence' ) ) {
		return (string) \Kadence\kadence()->get_icon( $key, '', false );
	}
	return '';
}

/**
 * The script for "Copy link", the phone Share sheet and the Report form, plus the
 * Report form itself (printed once, in the footer). Only on article pages.
 */
function portal_child_article_assets() {
	if ( ! portal_child_is_article() || ! function_exists( 'portal_report_reasons' ) ) {
		return;
	}
	$path = '/assets/js/article.js';
	wp_enqueue_script(
		'portal-article',
		get_stylesheet_directory_uri() . $path,
		array(),
		(string) filemtime( get_stylesheet_directory() . $path ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
	wp_localize_script(
		'portal-article',
		'portalArticle',
		array(
			'reportUrl' => rest_url( 'portal/v1/reports' ),
			// Logged-in readers send their login with the report; visitors report anonymously.
			// Cached pages are only served to visitors, so a nonce never leaks between people.
			'nonce'     => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'i18n'      => array(
				'copied'  => __( 'Link copied', 'portal' ),
				'sending' => __( 'Sending…', 'portal' ),
				'error'   => __( 'Could not send the report. Please try again.', 'portal' ),
				'choose'  => __( 'Please choose a reason.', 'portal' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'portal_child_article_assets' );

/**
 * Print the Report form once, at the end of the page.
 */
function portal_child_report_dialog() {
	if ( portal_child_is_article() && function_exists( 'portal_report_reasons' ) ) {
		get_template_part( 'template-parts/portal/report-dialog', null, array( 'reasons' => portal_report_reasons() ) );
	}
}
add_action( 'wp_footer', 'portal_child_report_dialog' );
