<?php
/**
 * Reader news tips (Task 17D), the look: My Account → My tips, the credit line at the end of a
 * story made from a tip, and the "Send news" link in the header. Rules, form and data live in
 * portal-core (includes/tips.php).
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * "Based on a reader report." right after the story text.
 */
function portal_child_tip_credit() {
	if ( ! portal_child_is_article() || ! function_exists( 'portal_tip_credit_line' ) ) {
		return;
	}
	$line = portal_tip_credit_line( get_post() );
	if ( $line ) {
		echo '<p class="portal-tip-credit">' . esc_html( $line ) . '</p>';
	}
}
add_action( 'kadence_single_after_inner_content', 'portal_child_tip_credit', 3 );

/**
 * My Account → My tips.
 */
function portal_child_account_tips() {
	if ( ! function_exists( 'portal_get_user_tips' ) ) {
		return;
	}
	$tips = portal_get_user_tips( get_current_user_id() );
	$sent = isset( $_GET['tip_sent'] ) ? absint( $_GET['tip_sent'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
	$page = get_page_by_path( 'send-news' );

	echo '<div class="portal-account-list portal-my-tips"><h2>' . esc_html__( 'My tips', 'portal' ) . '</h2>';
	if ( $sent && in_array( $sent, wp_list_pluck( $tips, 'id' ), true ) ) {
		/* translators: %s: reference, e.g. TIP-123. */
		echo '<p class="portal-notice" role="status">' . esc_html( sprintf( __( 'Thank you! Your tip %s has reached our editors. We have e-mailed you a receipt and will tell you when it is published or if we decide not to publish it.', 'portal' ), 'TIP-' . $sent ) ) . '</p>';
	}
	if ( $page ) {
		echo '<p><a class="portal-button" href="' . esc_url( get_permalink( $page ) ) . '">' . esc_html__( 'Send news', 'portal' ) . '</a></p>';
	}
	if ( ! $tips ) {
		echo '<p>' . esc_html__( 'You have not sent any news yet. Seen something our readers should know? Tell us: our editors check every tip.', 'portal' ) . '</p></div>';
		return;
	}
	echo '<ul class="portal-plain-list portal-my-tips__list">';
	foreach ( $tips as $tip ) {
		echo '<li class="portal-my-tips__item">';
		echo '<span class="portal-tip-state portal-tip-state--' . esc_attr( $tip['state'] ) . '">' . esc_html( $tip['state_label'] ) . '</span>';
		echo '<strong class="portal-my-tips__title">' . esc_html( $tip['title'] ) . '</strong>';
		echo '<span class="portal-my-tips__meta">' . esc_html( $tip['reference'] . ' · ' . wp_date( get_option( 'date_format' ), strtotime( $tip['date_gmt'] ) ) ) . '</span>';
		if ( $tip['story'] ) {
			echo '<a class="portal-my-tips__story" href="' . esc_url( $tip['story'] ) . '">' . esc_html__( 'Read the story →', 'portal' ) . '</a>';
		}
		if ( $tip['reason'] ) {
			echo '<span class="portal-my-tips__reason">' . esc_html( __( 'Reason:', 'portal' ) . ' ' . $tip['reason'] ) . '</span>';
		}
		echo '</li>';
	}
	echo '</ul></div>';
}
add_action( 'wpuf_account_content_portal-tips', 'portal_child_account_tips' );

/**
 * "Send news" in the main and phone menus, before "Write" (everyone; visitors are asked to
 * sign in on the page itself).
 *
 * @param string   $items Menu HTML.
 * @param stdClass $args  Menu arguments.
 * @return string
 */
function portal_child_send_news_link( $items, $args ) {
	if ( ! in_array( $args->theme_location ?? '', array( 'primary', 'mobile' ), true ) ) {
		return $items;
	}
	$page = get_page_by_path( 'send-news' );
	if ( ! $page ) {
		return $items;
	}
	$link = '<li class="menu-item portal-send-news-link"><a href="' . esc_url( get_permalink( $page ) ) . '">' . esc_html__( 'Send news', 'portal' ) . '</a></li>';
	// Put it before "Write" (added by inc/account.php at priority 10).
	$at = strpos( $items, '<li class="menu-item portal-write-link"' );
	return false === $at ? $items . $link : substr_replace( $items, $link, $at, 0 );
}
add_filter( 'wp_nav_menu_items', 'portal_child_send_news_link', 20, 2 );
