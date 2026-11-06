<?php
/**
 * One story card. Used by the home page and the article page (related posts).
 *
 * Arguments ($args, passed by get_template_part):
 *   post    WP_Post  required
 *   variant string   lead | compact | headline | blog | tile   (default compact)
 *   heading string   h2 | h3 | h4                       (default h3)
 *   eager   bool     load the image immediately (only for the first big image on the page)
 *   kicker  bool     show the red category label (default true; lead and headline cards only)
 *   words   int      summary length for lead cards (default 32)
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

$portal_post = $args['post'] ?? null;
if ( ! $portal_post instanceof WP_Post ) {
	return;
}

$portal_variant = in_array( $args['variant'] ?? '', array( 'lead', 'compact', 'headline', 'blog', 'tile' ), true ) ? $args['variant'] : 'compact';
$portal_heading = in_array( $args['heading'] ?? '', array( 'h2', 'h3', 'h4' ), true ) ? $args['heading'] : 'h3';
$portal_link    = get_permalink( $portal_post );
$portal_sizes   = array(
	'lead'    => array( 'large', '(min-width: 1024px) 760px, 100vw' ),
	'compact' => array( 'thumbnail', '96px' ),
	'blog'    => array( 'medium_large', '(min-width: 1024px) 300px, (min-width: 768px) 50vw, 100vw' ),
	'tile'    => array( 'medium_large', '(min-width: 768px) 25vw, 50vw' ),
);
$portal_image   = isset( $portal_sizes[ $portal_variant ] ) && has_post_thumbnail( $portal_post );
$portal_cats    = get_the_category( $portal_post->ID );
$portal_cat     = $portal_cats[0] ?? null;

$portal_classes = array( 'portal-card', 'portal-card--' . $portal_variant );
if ( $portal_image ) {
	$portal_classes[] = 'has-media';
}
?>
<article class="<?php echo esc_attr( implode( ' ', $portal_classes ) ); ?>">
	<?php if ( $portal_image ) : ?>
		<a class="portal-card__media" href="<?php echo esc_url( $portal_link ); ?>" tabindex="-1" aria-hidden="true">
			<?php
			$portal_attrs = array(
				'sizes'    => $portal_sizes[ $portal_variant ][1],
				'alt'      => '',
				'decoding' => 'async',
				// WordPress only adds lazy loading by itself inside the main post loop; these
				// cards are outside it, so say it explicitly: load when near the screen.
				'loading'  => 'lazy',
			);
			if ( ! empty( $args['eager'] ) ) {
				// The top story image is the biggest thing on screen: load it first (helps PageSpeed).
				$portal_attrs['loading']       = 'eager';
				$portal_attrs['fetchpriority'] = 'high';
			}
			echo get_the_post_thumbnail( $portal_post, $portal_sizes[ $portal_variant ][0], $portal_attrs );
			?>
		</a>
	<?php endif; ?>

	<div class="portal-card__body">
		<?php if ( $portal_cat && ( $args['kicker'] ?? true ) && in_array( $portal_variant, array( 'lead', 'headline', 'tile' ), true ) ) : ?>
			<a class="portal-card__kicker" href="<?php echo esc_url( get_category_link( $portal_cat ) ); ?>"><?php echo esc_html( $portal_cat->name ); ?></a>
		<?php endif; ?>

		<<?php echo esc_html( $portal_heading ); ?> class="portal-card__title">
			<a href="<?php echo esc_url( $portal_link ); ?>"><?php echo esc_html( get_the_title( $portal_post ) ); ?></a>
		</<?php echo esc_html( $portal_heading ); ?>>

		<?php portal_child_badges( $portal_post ); ?>

		<?php if ( 'lead' === $portal_variant ) : ?>
			<p class="portal-card__excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt( $portal_post ), absint( $args['words'] ?? 32 ) ) ); ?></p>
		<?php endif; ?>

		<p class="portal-card__meta">
			<?php if ( 'blog' === $portal_variant ) : ?>
				<?php echo get_avatar( $portal_post->post_author, 28, '', '' ); ?>
				<span class="portal-card__author"><?php echo esc_html( get_the_author_meta( 'display_name', $portal_post->post_author ) ); ?></span>
				<span aria-hidden="true">&middot;</span>
			<?php endif; ?>
			<time datetime="<?php echo esc_attr( get_the_date( 'c', $portal_post ) ); ?>"><?php echo esc_html( portal_child_time_label( $portal_post ) ); ?></time>
		</p>
	</div>
</article>
