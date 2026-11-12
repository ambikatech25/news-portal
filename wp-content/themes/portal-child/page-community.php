<?php
/**
 * Community page: /community/ (Task 17C)
 *
 * Intro and one button (Write a story / Become a writer / Sign up), Editors' picks, Trending
 * writers, New series, Newest community stories. What goes in each part is decided in
 * portal-core (includes/community.php); this file only draws it.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'portal_get_editors_picks' ) ) {
	require get_template_directory() . '/page.php'; // portal-core switched off.
	return;
}

$portal_picks   = portal_get_editors_picks( 4 );
$portal_writers = portal_get_trending_writers( 6 );
$portal_series  = portal_get_new_series( 4 );
$portal_latest  = portal_get_community_latest( 12, wp_list_pluck( $portal_picks, 'ID' ) );
$portal_write   = get_page_by_path( 'write' );
$portal_all     = get_category_link( portal_community_category_id() );

// One button, depending on who is looking. Visitors get the cached page (Sign up).
if ( current_user_can( 'edit_posts' ) ) {
	$portal_cta = array( __( 'Write a story', 'portal' ), $portal_write ? get_permalink( $portal_write ) : admin_url( 'post-new.php' ) );
} elseif ( is_user_logged_in() ) {
	$portal_cta = array( __( 'Become a writer', 'portal' ), $portal_write ? get_permalink( $portal_write ) : home_url( '/' ) );
} else {
	$portal_cta = array( __( 'Sign up to write', 'portal' ), wp_registration_url() );
}

get_header();
?>
<div id="primary" class="content-area portal-home-area">
	<div class="site-container">
		<main id="main" class="site-main portal-community">

			<header class="portal-community__header">
				<h1 class="portal-community__title"><?php the_title(); ?></h1>
				<div class="portal-community__intro">
					<?php
					while ( have_posts() ) {
						the_post();
						the_content(); // the page's own text, written in wp-admin → Pages → Community.
					}
					?>
				</div>
				<p><a class="portal-button" href="<?php echo esc_url( $portal_cta[1] ); ?>"><?php echo esc_html( $portal_cta[0] ); ?></a></p>
			</header>

			<?php if ( $portal_picks ) : ?>
				<section class="portal-community__section portal-blogs" aria-labelledby="portal-picks-title">
					<div class="portal-section-title"><h2 id="portal-picks-title"><?php esc_html_e( "Editors' picks", 'portal' ); ?></h2></div>
					<div class="portal-blogs__grid">
						<?php foreach ( $portal_picks as $portal_i => $portal_post ) : ?>
							<?php get_template_part( 'template-parts/portal/card', null, array( 'post' => $portal_post, 'variant' => 'blog', 'eager' => 0 === $portal_i ) ); ?>
						<?php endforeach; ?>
					</div>
				</section>
			<?php elseif ( current_user_can( 'edit_others_posts' ) ) : ?>
				<p class="portal-notice"><?php esc_html_e( 'Editors: no picks yet. In wp-admin → Posts, hover over a published community story and click "Pick for Community" (or switch on "Editor\'s pick" in the Editorial panel). Only editors see this note.', 'portal' ); ?></p>
			<?php endif; ?>

			<?php if ( $portal_writers ) : ?>
				<section class="portal-community__section" aria-labelledby="portal-writers-title">
					<div class="portal-section-title"><h2 id="portal-writers-title"><?php esc_html_e( 'Trending writers', 'portal' ); ?></h2></div>
					<ul class="portal-writers">
						<?php foreach ( $portal_writers as $portal_row ) : ?>
							<?php
							$portal_profile = portal_get_profile( $portal_row['id'] );
							if ( ! $portal_profile ) {
								continue;
							}
							?>
							<li class="portal-writers__item">
								<a class="portal-writers__link" href="<?php echo esc_url( $portal_profile['link'] ); ?>">
									<?php echo get_avatar( $portal_row['id'], 64, '', '', array( 'loading' => 'lazy' ) ); ?>
									<span class="portal-writers__name"><?php echo esc_html( $portal_profile['name'] ); ?></span>
								</a>
								<span class="portal-writers__meta">
									<?php
									/* translators: %s: number of stories. */
									$portal_stories = sprintf( _n( '%s story', '%s stories', $portal_profile['posts'], 'portal' ), number_format_i18n( $portal_profile['posts'] ) );
									/* translators: %s: number of followers. */
									$portal_fans = sprintf( _n( '%s follower', '%s followers', $portal_profile['followers'], 'portal' ), number_format_i18n( $portal_profile['followers'] ) );
									echo esc_html( $portal_stories . ' · ' . $portal_fans );
									?>
								</span>
								<?php echo function_exists( 'portal_child_toggle' ) ? portal_child_toggle( 'follow-author', $portal_row['id'] ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<?php if ( $portal_series ) : ?>
				<section class="portal-community__section" aria-labelledby="portal-series-title">
					<div class="portal-section-title"><h2 id="portal-series-title"><?php esc_html_e( 'New series', 'portal' ); ?></h2></div>
					<ul class="portal-series-cards">
						<?php foreach ( $portal_series as $portal_term ) : ?>
							<?php
							$portal_image = portal_series_card_image( $portal_term );
							$portal_owner = portal_series_owner( $portal_term );
							?>
							<li class="portal-series-card">
								<a class="portal-series-card__link" href="<?php echo esc_url( get_term_link( $portal_term ) ); ?>">
									<span class="portal-series-card__media">
										<?php if ( $portal_image ) : ?>
											<img src="<?php echo esc_url( $portal_image ); ?>" alt="" width="384" height="216" loading="lazy" decoding="async">
										<?php endif; ?>
									</span>
									<span class="portal-series-card__kicker"><?php esc_html_e( 'Series', 'portal' ); ?></span>
									<span class="portal-series-card__name"><?php echo esc_html( $portal_term->name ); ?></span>
								</a>
								<span class="portal-series-card__meta">
									<?php
									$portal_parts = sprintf( /* translators: %s: number of parts. */ _n( '%s part', '%s parts', (int) $portal_term->count, 'portal' ), number_format_i18n( (int) $portal_term->count ) );
									echo esc_html( $portal_owner ? get_the_author_meta( 'display_name', $portal_owner ) . ' · ' . $portal_parts : $portal_parts );
									?>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<section class="portal-community__section" aria-labelledby="portal-latest-title">
				<div class="portal-section-title">
					<h2 id="portal-latest-title"><?php esc_html_e( 'Newest community stories', 'portal' ); ?></h2>
					<?php if ( is_string( $portal_all ) ) : ?>
						<a class="portal-section-title__more" href="<?php echo esc_url( $portal_all ); ?>"><?php esc_html_e( 'All', 'portal' ); ?><span class="screen-reader-text"> <?php esc_html_e( 'community stories', 'portal' ); ?></span> &rarr;</a>
					<?php endif; ?>
				</div>
				<?php if ( $portal_latest ) : ?>
					<ul class="portal-plain-list portal-community__latest">
						<?php foreach ( $portal_latest as $portal_post ) : ?>
							<li><?php get_template_part( 'template-parts/portal/card', null, array( 'post' => $portal_post, 'variant' => 'compact', 'heading' => 'h3' ) ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p><?php esc_html_e( 'No community stories yet. Be the first to write one!', 'portal' ); ?></p>
				<?php endif; ?>
			</section>

		</main>
	</div>
</div>
<?php
get_footer();
