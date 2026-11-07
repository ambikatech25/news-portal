<?php
/**
 * Writer profile page: /writer/<slug>/
 *
 * Photo, name, News desk / Community writer, bio, website, number of stories, followers,
 * joined date, then the writer's stories (12 per page). Which users have a page at all,
 * the follower count and the photo are decided in portal-core (includes/profiles.php).
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

$portal_author  = get_queried_object();
$portal_profile = ( $portal_author instanceof WP_User && function_exists( 'portal_get_profile' ) ) ? portal_get_profile( $portal_author->ID ) : null;

if ( ! $portal_profile ) {
	// portal-core switched off: fall back to Kadence's normal archive list.
	require get_template_directory() . '/archive.php';
	return;
}

get_header();
?>
<div id="primary" class="content-area portal-home-area">
	<div class="site-container">
		<main id="main" class="site-main portal-profile">

			<header class="portal-profile__header">
				<div class="portal-profile__photo">
					<?php echo get_avatar( $portal_author->ID, 120, '', '', array( 'loading' => 'eager' ) ); ?>
				</div>
				<div class="portal-profile__info">
					<p class="portal-profile__desk">
						<?php echo esc_html( 'news' === $portal_profile['desk'] ? __( 'News desk', 'portal' ) : __( 'Community writer', 'portal' ) ); ?>
					</p>
					<h1 class="portal-profile__name"><?php echo esc_html( $portal_profile['name'] ); ?></h1>

					<?php if ( $portal_profile['bio'] ) : ?>
						<p class="portal-profile__bio"><?php echo esc_html( $portal_profile['bio'] ); ?></p>
					<?php endif; ?>

					<ul class="portal-profile__stats">
						<li>
							<strong><?php echo esc_html( number_format_i18n( $portal_profile['posts'] ) ); ?></strong>
							<?php echo esc_html( _n( 'story', 'stories', $portal_profile['posts'], 'portal' ) ); ?>
						</li>
						<li class="portal-profile__followers">
							<strong><?php echo esc_html( number_format_i18n( $portal_profile['followers'] ) ); ?></strong>
							<?php echo esc_html( _n( 'follower', 'followers', $portal_profile['followers'], 'portal' ) ); ?>
						</li>
						<li>
							<?php
							/* translators: %s: month and year, e.g. "November 2026". */
							echo esc_html( sprintf( __( 'Joined %s', 'portal' ), date_i18n( 'F Y', strtotime( $portal_author->user_registered ) ) ) );
							?>
						</li>
					</ul>

					<?php if ( $portal_profile['website'] ) : ?>
						<?php // ugc + nofollow: writers' own links get no search-ranking boost from our site. ?>
						<a class="portal-profile__website" href="<?php echo esc_url( $portal_profile['website'] ); ?>" rel="nofollow ugc noopener" target="_blank">
							<?php echo esc_html( preg_replace( '#^https?://(www\.)?#', '', untrailingslashit( $portal_profile['website'] ) ) ); ?>
						</a>
					<?php endif; ?>
				</div>
			</header>

			<section class="portal-profile__posts" aria-labelledby="portal-profile-posts-title">
				<div class="portal-section-title">
					<h2 id="portal-profile-posts-title">
						<?php
						/* translators: %s: writer's name. */
						echo esc_html( sprintf( __( 'Stories by %s', 'portal' ), $portal_profile['name'] ) );
						?>
					</h2>
				</div>

				<?php if ( have_posts() ) : ?>
					<div class="portal-profile__grid">
						<?php
						while ( have_posts() ) {
							the_post();
							get_template_part(
								'template-parts/portal/card',
								null,
								array(
									'post'    => get_post(),
									'variant' => 'tile',
								)
							);
						}
						?>
					</div>
					<?php
					the_posts_pagination(
						array(
							'mid_size'           => 1,
							'prev_text'          => __( 'Newer', 'portal' ),
							'next_text'          => __( 'Older', 'portal' ),
							'screen_reader_text' => __( 'More stories', 'portal' ),
						)
					);
					?>
				<?php else : ?>
					<p class="portal-home__empty"><?php esc_html_e( 'No published stories yet.', 'portal' ); ?></p>
				<?php endif; ?>
			</section>

		</main>
	</div>
</div>
<?php
get_footer();
