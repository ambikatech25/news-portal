<?php
/**
 * Engagement buttons (looks only): Like, Save, Follow; the Saved and Following tabs in
 * My Account; Report button on comments. Rules and storage live in portal-core
 * (includes/engagement.php); the buttons talk to its portal/v1 API (assets/js/engage.js).
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Small inline icons.
 *
 * @param string $name heart | bookmark | follow | comment.
 * @return string SVG (trusted).
 */
function portal_child_engage_icon( $name ) {
	$paths = array(
		'heart'    => '<path d="M12 20.5s-7.5-4.6-9.3-9.4C1.4 7.6 3.6 4 7.2 4c2 0 3.4 1.1 4.8 2.8C13.4 5.1 14.8 4 16.8 4c3.6 0 5.8 3.6 4.5 7.1-1.8 4.8-9.3 9.4-9.3 9.4Z"/>',
		'bookmark' => '<path d="M6 3.5h12a.5.5 0 0 1 .5.5v16.6l-6.5-4.4-6.5 4.4V4a.5.5 0 0 1 .5-.5Z"/>',
		'follow'   => '<path d="M12 5v14M5 12h14"/>',
		'comment'  => '<path d="M4 5.5h16a.5.5 0 0 1 .5.5v10a.5.5 0 0 1-.5.5H9l-4.5 3.5V16.5H4a.5.5 0 0 1-.5-.5V6a.5.5 0 0 1 .5-.5Z"/>',
	);
	return '<svg class="portal-toggle__icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . ( $paths[ $name ] ?? '' ) . '</svg>';
}

/**
 * A Like / Save / Follow button. Logged-in members get a real toggle; visitors get a link
 * to sign in (and come back to this page).
 *
 * @param string   $kind  like | bookmark | follow-author | follow-tag.
 * @param int      $id    Post, user or term ID.
 * @param int|null $count Number to show (likes or followers), or null for none.
 * @return string HTML.
 */
function portal_child_toggle( $kind, $id, $count = null ) {
	if ( ! function_exists( 'portal_has_mark' ) ) {
		return '';
	}
	$labels = array(
		'like'          => array( __( 'Like', 'portal' ), __( 'Liked', 'portal' ), 'heart' ),
		'bookmark'      => array( __( 'Save', 'portal' ), __( 'Saved', 'portal' ), 'bookmark' ),
		'follow-author' => array( __( 'Follow', 'portal' ), __( 'Following', 'portal' ), 'follow' ),
		'follow-tag'    => array( __( 'Follow', 'portal' ), __( 'Following', 'portal' ), 'follow' ),
	);
	if ( ! isset( $labels[ $kind ] ) ) {
		return '';
	}
	list( $off, $on_label, $icon ) = $labels[ $kind ];
	$user                          = get_current_user_id();

	// Writers cannot follow themselves: no button on their own profile or card.
	if ( 'follow-author' === $kind && $user === (int) $id ) {
		return '';
	}

	$on = false;
	if ( $user ) {
		$on = match ( $kind ) {
			'like', 'bookmark' => portal_has_mark( $user, $kind, $id ),
			'follow-author'    => portal_is_following( $user, 'author', $id ),
			'follow-tag'       => portal_is_following( $user, 'tag', $id ),
		};
	}

	$inner = portal_child_engage_icon( $icon )
		. '<span class="portal-toggle__label" data-on="' . esc_attr( $on_label ) . '" data-off="' . esc_attr( $off ) . '">' . esc_html( $on ? $on_label : $off ) . '</span>'
		. ( null !== $count ? '<span class="portal-toggle__count">' . esc_html( number_format_i18n( (int) $count ) ) . '</span>' : '' );
	$class = 'portal-toggle portal-toggle--' . $kind;

	if ( ! $user ) {
		// Visitor: the button is a sign-in link that brings them back here.
		$here = ( is_singular() || is_author() || is_tag() ) ? home_url( add_query_arg( array() ) ) : home_url( '/' );
		return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( wp_login_url( $here ) ) . '" rel="nofollow">' . $inner . '</a>';
	}
	return '<button type="button" class="' . esc_attr( $class ) . '" data-portal-toggle="' . esc_attr( $kind ) . '" data-id="' . esc_attr( (string) (int) $id ) . '" aria-pressed="' . ( $on ? 'true' : 'false' ) . '">' . $inner . '</button>';
}

/**
 * Script for the buttons, on pages that have them.
 */
function portal_child_engage_assets() {
	$account = function_exists( 'portal_account_url' ) ? portal_account_url() : '';
	$is_acct = $account && is_page() && get_permalink() === $account;
	if ( ! is_user_logged_in() || ! ( is_singular( 'post' ) || is_author() || is_tag() || $is_acct ) ) {
		return;
	}
	$path = '/assets/js/engage.js';
	wp_enqueue_script( 'portal-engage', get_stylesheet_directory_uri() . $path, array(), (string) filemtime( get_stylesheet_directory() . $path ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
	wp_localize_script(
		'portal-engage',
		'portalEngage',
		array(
			'root'  => esc_url_raw( rest_url( 'portal/v1/' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'error' => __( 'That did not work. Please try again.', 'portal' ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'portal_child_engage_assets' );

/**
 * Tag pages: tag name with a Follow button above the stories.
 */
function portal_child_tag_follow() {
	if ( ! is_tag() || ! function_exists( 'portal_count_followers' ) ) {
		return;
	}
	$term = get_queried_object();
	echo '<div class="portal-tag-follow"><span class="portal-tag-follow__text">'
		/* translators: %s: tag name. */
		. esc_html( sprintf( __( 'Get stories about %s in your Following list', 'portal' ), $term->name ) )
		. '</span>' . portal_child_toggle( 'follow-tag', $term->term_id, portal_count_followers( 'tag', $term->term_id ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
}
add_action( 'kadence_before_main_content', 'portal_child_tag_follow' );

/**
 * My Account → Saved.
 */
function portal_child_account_saved() {
	$posts = function_exists( 'portal_get_bookmarks' ) ? portal_get_bookmarks( get_current_user_id() ) : array();
	echo '<div class="portal-account-list"><h2>' . esc_html__( 'Saved stories', 'portal' ) . '</h2>';
	if ( ! $posts ) {
		echo '<p>' . esc_html__( 'Nothing saved yet. Press "Save" under any story to read it later.', 'portal' ) . '</p></div>';
		return;
	}
	echo '<ul class="portal-plain-list">';
	foreach ( $posts as $post ) {
		echo '<li class="portal-account-list__item">';
		get_template_part( 'template-parts/portal/card', null, array( 'post' => $post, 'variant' => 'compact', 'heading' => 'h3' ) );
		echo portal_child_toggle( 'bookmark', $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		echo '</li>';
	}
	echo '</ul></div>';
}
add_action( 'wpuf_account_content_portal-saved', 'portal_child_account_saved' );

/**
 * My Account → Following: who and what you follow, and their newest stories.
 */
function portal_child_account_following() {
	if ( ! function_exists( 'portal_get_follows' ) ) {
		return;
	}
	$user    = get_current_user_id();
	$follows = portal_get_follows( $user );
	echo '<div class="portal-account-list"><h2>' . esc_html__( 'Following', 'portal' ) . '</h2>';
	if ( ! $follows['author'] && ! $follows['tag'] ) {
		echo '<p>' . esc_html__( 'You are not following anyone yet. Press "Follow" on a writer\'s profile or a tag page.', 'portal' ) . '</p></div>';
		return;
	}
	echo '<ul class="portal-follow-chips">';
	foreach ( $follows['author'] as $author ) {
		if ( get_userdata( $author ) ) {
			echo '<li><a href="' . esc_url( get_author_posts_url( $author ) ) . '">' . get_avatar( $author, 24 ) . esc_html( get_the_author_meta( 'display_name', $author ) ) . '</a>' . portal_child_toggle( 'follow-author', $author ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped parts.
		}
	}
	foreach ( $follows['tag'] as $tag ) {
		$term = get_term( $tag, 'post_tag' );
		if ( $term instanceof WP_Term ) {
			echo '<li><a href="' . esc_url( get_term_link( $term ) ) . '">#' . esc_html( $term->name ) . '</a>' . portal_child_toggle( 'follow-tag', $term->term_id ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped parts.
		}
	}
	echo '</ul><h3>' . esc_html__( 'Newest stories', 'portal' ) . '</h3><ul class="portal-plain-list portal-following-feed">';
	foreach ( portal_get_following_feed( $user, 10 ) as $post ) {
		echo '<li>';
		get_template_part( 'template-parts/portal/card', null, array( 'post' => $post, 'variant' => 'compact', 'heading' => 'h4' ) );
		echo '</li>';
	}
	echo '</ul></div>';
}
add_action( 'wpuf_account_content_portal-following', 'portal_child_account_following' );

/**
 * Report button under every approved comment on article pages (CLAUDE.md rule 4).
 * Uses the same Report form and API as the article's Report button.
 *
 * @param string          $text    Comment text.
 * @param WP_Comment|null $comment Comment.
 * @return string
 */
function portal_child_comment_report_button( $text, $comment = null ) {
	if ( is_admin() || is_feed() || ! portal_child_is_article() || ! $comment instanceof WP_Comment || '1' !== (string) $comment->comment_approved ) {
		return $text;
	}
	return $text . '<p class="portal-comment-report"><button type="button" class="portal-report-btn portal-report-btn--comment" data-portal-report="comment" data-id="' . esc_attr( (string) $comment->comment_ID ) . '" hidden><span aria-hidden="true">&#9873;</span> ' . esc_html__( 'Report', 'portal' ) . '</button></p>';
}
add_filter( 'comment_text', 'portal_child_comment_report_button', 20, 2 );
