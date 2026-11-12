<?php
/**
 * Series (Task 17A), the look: "Part 2 of 3" under the headline, Previous / Next part after
 * the story, and the My Account → My series tab. Rules and data live in portal-core
 * (includes/series.php); the series page itself is taxonomy-portal_series.php.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * "Series name · Part 2 of 3" right under the headline.
 */
function portal_child_series_label() {
	if ( ! portal_child_is_article() || ! function_exists( 'portal_series_position' ) ) {
		return;
	}
	$at = portal_series_position( get_post() );
	if ( ! $at ) {
		return;
	}
	printf(
		'<p class="portal-series-label"><a href="%1$s"><span class="portal-series-label__kicker">%2$s</span> %3$s</a> <span class="portal-series-label__part">%4$s</span></p>',
		esc_url( get_term_link( $at['series'] ) ),
		esc_html__( 'Series', 'portal' ),
		esc_html( $at['series']->name ),
		/* translators: 1: part number, 2: number of parts. */
		esc_html( sprintf( __( 'Part %1$s of %2$s', 'portal' ), number_format_i18n( $at['number'] ), number_format_i18n( $at['total'] ) ) )
	);
}
add_action( 'kadence_single_after_entry_header', 'portal_child_series_label', 8 );

/**
 * Previous / Next part box after the story text (before the share buttons).
 */
function portal_child_series_nav() {
	if ( ! portal_child_is_article() || ! function_exists( 'portal_series_position' ) ) {
		return;
	}
	$at = portal_series_position( get_post() );
	if ( $at ) {
		get_template_part( 'template-parts/portal/series-nav', null, $at );
	}
}
add_action( 'kadence_single_after_inner_content', 'portal_child_series_nav', 5 );

/**
 * Words for the status of a writer's own story in My series.
 *
 * @param string $status Post status.
 * @return string
 */
function portal_child_series_status_label( $status ) {
	$labels = array(
		'publish'         => __( 'Published', 'portal' ),
		'future'          => __( 'Scheduled', 'portal' ),
		'pending'         => __( 'Waiting for review', 'portal' ),
		'draft'           => __( 'Draft', 'portal' ),
		'portal_changes'  => __( 'Changes requested', 'portal' ),
		'portal_rejected' => __( 'Not accepted', 'portal' ),
		'portal_takedown' => __( 'Taken down', 'portal' ),
		'private'         => __( 'Private', 'portal' ),
	);
	return $labels[ $status ] ?? $status;
}

/**
 * Opening tag and hidden fields of a My series form.
 *
 * @param string $action    portal_do value.
 * @param int    $series_id Series (0 for create).
 * @param bool   $files     Whether the form uploads a file.
 */
function portal_child_series_form_open( $action, $series_id = 0, $files = false ) {
	printf(
		'<form method="post" action="%1$s"%2$s>',
		esc_url( admin_url( 'admin-post.php' ) ),
		$files ? ' enctype="multipart/form-data"' : ''
	);
	echo '<input type="hidden" name="action" value="portal_series_action">';
	echo '<input type="hidden" name="portal_do" value="' . esc_attr( $action ) . '">';
	if ( $series_id ) {
		echo '<input type="hidden" name="series" value="' . esc_attr( (string) $series_id ) . '">';
	}
	wp_nonce_field( 'portal_series_' . $action );
}

/**
 * My Account → My series.
 */
function portal_child_account_series() {
	if ( ! function_exists( 'portal_get_user_series' ) || ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	$messages = array(
		'created' => __( 'Series created. Now add stories to it: on the Write page, pick it in the "Series" box.', 'portal' ),
		'saved'   => __( 'Series saved.', 'portal' ),
		'cover'   => __( 'Cover photo saved.', 'portal' ),
		'uncover' => __( 'Cover photo removed.', 'portal' ),
		'deleted' => __( 'Series deleted. Its stories are still published.', 'portal' ),
	);
	$code     = isset( $_GET['portal_series_msg'] ) ? sanitize_key( wp_unslash( $_GET['portal_series_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
	$error    = 'error' === $code ? portal_series_take_error() : '';

	echo '<div class="portal-account-list portal-my-series"><h2>' . esc_html__( 'My series', 'portal' ) . '</h2>';
	if ( $error ) {
		echo '<p class="portal-notice portal-notice--error" role="alert">' . esc_html( $error ) . '</p>';
	} elseif ( isset( $messages[ $code ] ) ) {
		echo '<p class="portal-notice" role="status">' . esc_html( $messages[ $code ] ) . '</p>';
	}
	echo '<p>' . esc_html__( 'A series is a story told in parts (Part 1, 2, 3…). Readers see "Part 2 of 3" and a "Next part" button. Only published parts are shown, in the order you set here.', 'portal' ) . '</p>';

	foreach ( portal_get_user_series( get_current_user_id() ) as $series ) {
		$item = portal_series_item( $series, 'owner' );
		echo '<details class="portal-my-series__item"><summary>';
		echo '<strong>' . esc_html( $series->name ) . '</strong> <span class="portal-my-series__count">';
		/* translators: 1: published parts, 2: all parts. */
		echo esc_html( sprintf( __( '%1$s published of %2$s', 'portal' ), number_format_i18n( $item['parts_count'] ), number_format_i18n( count( $item['parts'] ) ) ) );
		echo '</span></summary>';

		// Name, description, order.
		portal_child_series_form_open( 'save', $series->term_id );
		echo '<p><label>' . esc_html__( 'Name', 'portal' ) . '<input type="text" name="name" required minlength="3" maxlength="80" value="' . esc_attr( $series->name ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Description (shown on the series page)', 'portal' ) . '<textarea name="description" rows="3" maxlength="600">' . esc_textarea( $series->description ) . '</textarea></label></p>';
		if ( $item['parts'] ) {
			echo '<fieldset><legend>' . esc_html__( 'Order of parts (change the numbers, then Save)', 'portal' ) . '</legend><ol class="portal-my-series__parts">';
			foreach ( $item['parts'] as $i => $part ) {
				printf(
					'<li><input type="number" min="1" max="200" name="part[%1$d]" value="%2$d" aria-label="%3$s"> <a href="%4$s">%5$s</a> <span class="portal-my-series__status">%6$s</span></li>',
					(int) $part['id'],
					(int) ( $i + 1 ),
					/* translators: %s: story title. */
					esc_attr( sprintf( __( 'Part number for %s', 'portal' ), $part['title'] ) ),
					esc_url( 'publish' === $part['status'] ? $part['link'] : get_edit_post_link( $part['id'] ) ),
					esc_html( $part['title'] ),
					esc_html( portal_child_series_status_label( $part['status'] ) )
				);
			}
			echo '</ol></fieldset>';
		} else {
			echo '<p class="portal-my-series__empty">' . esc_html__( 'No stories yet. On the Write page, pick this series in the "Series" box.', 'portal' ) . '</p>';
		}
		echo '<p><button type="submit" class="portal-button">' . esc_html__( 'Save', 'portal' ) . '</button>';
		if ( portal_series_is_public( $series ) ) {
			echo ' <a class="portal-button portal-button--ghost" href="' . esc_url( get_term_link( $series ) ) . '">' . esc_html__( 'View series page', 'portal' ) . '</a>';
		}
		echo '</p></form>';

		// Cover photo.
		echo '<div class="portal-my-series__cover">';
		if ( $item['cover'] ) {
			echo '<img src="' . esc_url( $item['cover'] ) . '" alt="" width="160" height="90" loading="lazy">';
		}
		portal_child_series_form_open( 'cover', $series->term_id, true );
		echo '<label>' . esc_html__( 'Cover photo (JPG, PNG or WebP, up to 2 MB; wide photos look best)', 'portal' ) . '<input type="file" name="cover" accept="image/jpeg,image/png,image/webp" required></label> ';
		echo '<button type="submit" class="portal-button portal-button--ghost">' . esc_html__( 'Upload cover', 'portal' ) . '</button></form>';
		if ( $item['cover'] ) {
			portal_child_series_form_open( 'uncover', $series->term_id );
			echo '<button type="submit" class="portal-link-button">' . esc_html__( 'Remove cover', 'portal' ) . '</button></form>';
		}
		echo '</div>';

		// Delete.
		portal_child_series_form_open( 'delete', $series->term_id );
		echo '<button type="submit" class="portal-link-button portal-link-button--danger" onclick="return confirm(this.dataset.confirm)" data-confirm="' . esc_attr__( 'Delete this series? The stories stay published, they just stop being a series.', 'portal' ) . '">' . esc_html__( 'Delete series', 'portal' ) . '</button></form>';
		echo '</details>';
	}

	// New series.
	echo '<h3>' . esc_html__( 'Start a new series', 'portal' ) . '</h3>';
	portal_child_series_form_open( 'create' );
	echo '<p><label>' . esc_html__( 'Name', 'portal' ) . '<input type="text" name="name" required minlength="3" maxlength="80"></label></p>';
	echo '<p><label>' . esc_html__( 'Description (optional)', 'portal' ) . '<textarea name="description" rows="3" maxlength="600"></textarea></label></p>';
	echo '<p><button type="submit" class="portal-button">' . esc_html__( 'Create series', 'portal' ) . '</button></p></form>';
	echo '</div>';
}
add_action( 'wpuf_account_content_portal-series', 'portal_child_account_series' );
