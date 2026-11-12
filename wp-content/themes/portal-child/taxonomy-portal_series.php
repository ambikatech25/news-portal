<?php
/**
 * Series page: /series/<name>/
 *
 * Cover, name, writer, description, "Start reading", then the contents list (Part 1, 2, 3...)
 * with read time. Only published parts are listed, numbered in reading order. Which series
 * have a page at all is decided in portal-core (includes/series.php).
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

$portal_series = get_queried_object();
if ( ! $portal_series instanceof WP_Term || ! function_exists( 'portal_series_item' ) ) {
	require get_template_directory() . '/archive.php'; // portal-core switched off.
	return;
}
$portal_item  = portal_series_item( $portal_series );
$portal_parts = $portal_item['parts'];
$portal_owner = portal_series_owner( $portal_series );

get_header();
?>
<div id="primary" class="content-area portal-home-area">
	<div class="site-container">
		<main id="main" class="site-main portal-series-page">

			<header class="portal-series-page__header">
				<?php if ( $portal_item['cover'] ) : ?>
					<img class="portal-series-page__cover" src="<?php echo esc_url( $portal_item['cover'] ); ?>" alt="" width="768" height="432" loading="eager" fetchpriority="high">
				<?php endif; ?>
				<div class="portal-series-page__info">
					<p class="portal-series-page__kicker"><?php esc_html_e( 'Series', 'portal' ); ?></p>
					<h1 class="portal-series-page__name"><?php echo esc_html( $portal_series->name ); ?></h1>
					<?php if ( $portal_owner ) : ?>
						<p class="portal-series-page__by">
							<?php echo get_avatar( $portal_owner, 32 ); ?>
							<?php
							$portal_by = '<a href="' . esc_url( get_author_posts_url( $portal_owner ) ) . '">' . esc_html( get_the_author_meta( 'display_name', $portal_owner ) ) . '</a>';
							/* translators: %s: writer name (link). */
							printf( esc_html__( 'by %s', 'portal' ), $portal_by ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
							?>
							<span aria-hidden="true">·</span>
							<?php
							/* translators: %s: number of parts. */
							echo esc_html( sprintf( _n( '%s part', '%s parts', count( $portal_parts ), 'portal' ), number_format_i18n( count( $portal_parts ) ) ) );
							?>
						</p>
					<?php endif; ?>
					<?php if ( '' !== $portal_series->description ) : ?>
						<div class="portal-series-page__description"><?php echo wp_kses_post( wpautop( esc_html( $portal_series->description ) ) ); ?></div>
					<?php endif; ?>
					<?php if ( $portal_parts ) : ?>
						<p><a class="portal-button" href="<?php echo esc_url( $portal_parts[0]['link'] ); ?>"><?php esc_html_e( 'Start reading: Part 1', 'portal' ); ?></a></p>
					<?php else : ?>
						<p class="portal-notice"><?php esc_html_e( 'No part is published yet, so only you and the editors can see this page.', 'portal' ); ?></p>
					<?php endif; ?>
				</div>
			</header>

			<?php if ( $portal_parts ) : ?>
				<section class="portal-series-page__contents" aria-labelledby="portal-series-contents">
					<h2 id="portal-series-contents"><?php esc_html_e( 'Contents', 'portal' ); ?></h2>
					<ol class="portal-series-contents">
						<?php foreach ( $portal_parts as $portal_part ) : ?>
							<?php $portal_minutes = function_exists( 'portal_read_minutes' ) ? portal_read_minutes( get_post( $portal_part['id'] ) ) : 0; ?>
							<li class="portal-series-contents__item">
								<span class="portal-series-contents__number">
									<?php
									/* translators: %s: part number. */
									echo esc_html( sprintf( __( 'Part %s', 'portal' ), number_format_i18n( $portal_part['part'] ) ) );
									?>
								</span>
								<a class="portal-series-contents__title" href="<?php echo esc_url( $portal_part['link'] ); ?>"><?php echo esc_html( $portal_part['title'] ); ?></a>
								<span class="portal-series-contents__meta">
									<?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $portal_part['date_gmt'] ) ) ); ?>
									<?php if ( $portal_minutes ) : ?>
										<span aria-hidden="true">·</span>
										<?php
										/* translators: %s: number of minutes. */
										echo esc_html( sprintf( _n( '%s min read', '%s min read', $portal_minutes, 'portal' ), number_format_i18n( $portal_minutes ) ) );
										?>
									<?php endif; ?>
								</span>
							</li>
						<?php endforeach; ?>
					</ol>
				</section>
			<?php endif; ?>

		</main>
	</div>
</div>
<?php
get_footer();
