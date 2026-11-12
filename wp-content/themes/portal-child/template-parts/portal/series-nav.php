<?php
/**
 * After a story that is part of a series: where you are, Previous part, Next part, and a
 * link to all parts. Arguments: the array from portal_series_position().
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

$portal_series = $args['series'] ?? null;
if ( ! $portal_series instanceof WP_Term ) {
	return;
}
$portal_prev = $args['prev'];
$portal_next = $args['next'];
?>
<nav class="portal-series-nav" aria-label="<?php esc_attr_e( 'Series', 'portal' ); ?>">
	<p class="portal-series-nav__where">
		<?php
		printf(
			/* translators: 1: part number, 2: number of parts, 3: series name (link). */
			esc_html__( 'Part %1$s of %2$s in %3$s', 'portal' ),
			esc_html( number_format_i18n( $args['number'] ) ),
			esc_html( number_format_i18n( $args['total'] ) ),
			'<a href="' . esc_url( get_term_link( $portal_series ) ) . '">' . esc_html( $portal_series->name ) . '</a>'
		);
		?>
	</p>
	<div class="portal-series-nav__links">
		<?php if ( $portal_prev ) : ?>
			<a class="portal-series-nav__link portal-series-nav__link--prev" href="<?php echo esc_url( get_permalink( $portal_prev ) ); ?>" rel="prev">
				<span class="portal-series-nav__dir"><?php esc_html_e( '← Previous part', 'portal' ); ?></span>
				<span class="portal-series-nav__title"><?php echo esc_html( get_the_title( $portal_prev ) ); ?></span>
			</a>
		<?php endif; ?>
		<?php if ( $portal_next ) : ?>
			<a class="portal-series-nav__link portal-series-nav__link--next" href="<?php echo esc_url( get_permalink( $portal_next ) ); ?>" rel="next">
				<span class="portal-series-nav__dir"><?php esc_html_e( 'Next part →', 'portal' ); ?></span>
				<span class="portal-series-nav__title"><?php echo esc_html( get_the_title( $portal_next ) ); ?></span>
			</a>
		<?php else : ?>
			<p class="portal-series-nav__end">
				<?php esc_html_e( 'You have reached the latest part.', 'portal' ); ?>
				<?php
				$portal_owner = function_exists( 'portal_series_owner' ) ? portal_series_owner( $portal_series ) : 0;
				if ( $portal_owner && function_exists( 'portal_child_toggle' ) && get_current_user_id() !== $portal_owner ) {
					esc_html_e( 'Follow the writer to hear about the next one.', 'portal' );
					echo ' ' . portal_child_toggle( 'follow-author', $portal_owner ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
				}
				?>
			</p>
		<?php endif; ?>
	</div>
</nav>
