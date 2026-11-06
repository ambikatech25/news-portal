<?php
/**
 * "Related stories": up to 4 tiles. Which posts is decided by portal_get_related_posts()
 * in portal-core, so the app shows the same list (GET /portal/v1/related/<id>).
 *
 * Arguments: posts (WP_Post[]).
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

$portal_posts = $args['posts'] ?? array();
if ( ! $portal_posts ) {
	return;
}
?>
<section class="portal-related" aria-labelledby="portal-related-title">
	<div class="portal-section-title">
		<h2 id="portal-related-title"><?php esc_html_e( 'Related stories', 'portal' ); ?></h2>
	</div>
	<div class="portal-related__grid">
		<?php
		foreach ( $portal_posts as $portal_related ) {
			get_template_part(
				'template-parts/portal/card',
				null,
				array(
					'post'    => $portal_related,
					'variant' => 'tile',
				)
			);
		}
		?>
	</div>
</section>
