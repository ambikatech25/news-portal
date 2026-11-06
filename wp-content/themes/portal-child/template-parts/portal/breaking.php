<?php
/**
 * Red "Breaking" strip under the header. Only printed when there is breaking news.
 *
 * Arguments: posts (WP_Post[]).
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $args['posts'] ) ) {
	return;
}
?>
<div class="portal-breaking" role="region" aria-label="<?php esc_attr_e( 'Breaking news', 'portal' ); ?>">
	<div class="site-container portal-breaking__inner">
		<span class="portal-breaking__label"><?php esc_html_e( 'Breaking', 'portal' ); ?></span>
		<ul class="portal-breaking__list">
			<?php foreach ( $args['posts'] as $portal_post ) : ?>
				<li>
					<a href="<?php echo esc_url( get_permalink( $portal_post ) ); ?>"><?php echo esc_html( get_the_title( $portal_post ) ); ?></a>
					<time datetime="<?php echo esc_attr( get_the_date( 'c', $portal_post ) ); ?>"><?php echo esc_html( portal_child_time_label( $portal_post ) ); ?></time>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</div>
