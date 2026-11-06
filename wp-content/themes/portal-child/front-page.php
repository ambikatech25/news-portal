<?php
/**
 * News home page.
 *
 * The blocks below are in phone reading order (top story, latest, trending, sections,
 * community blogs). On wider screens style.css arranges them into columns.
 * All content comes from normal posts: editors control it by publishing, choosing
 * categories, "Stick to the top of the blog" (top story) and the Breaking switch.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

// /page/2/ and later keep Kadence's normal post list.
if ( is_home() && is_paged() ) {
	require get_home_template();
	return;
}

$portal_top     = portal_child_top_story();
$portal_exclude = $portal_top ? array( $portal_top->ID ) : array();
$portal_blog_id = portal_child_blog_category_id();

$portal_latest   = portal_child_get_posts(
	array(
		'posts_per_page'   => 8,
		'post__not_in'     => $portal_exclude,
		'category__not_in' => array( $portal_blog_id ),
	)
);
$portal_trending = function_exists( 'portal_get_trending_posts' ) ? portal_get_trending_posts( 5 ) : array();
$portal_blogs    = $portal_blog_id ? portal_child_get_posts(
	array(
		'posts_per_page' => 4,
		'cat'            => $portal_blog_id,
	)
) : array();

get_header();
?>
<div id="primary" class="content-area portal-home-area">
	<div class="content-container site-container">
		<div id="main" class="site-main portal-home">
			<h1 class="screen-reader-text"><?php bloginfo( 'name' ); ?></h1>

			<?php if ( ! $portal_top ) : ?>
				<p class="portal-home__empty"><?php esc_html_e( 'No stories have been published yet.', 'portal' ); ?></p>
			<?php else : ?>
				<section class="portal-home__top portal-top-story" aria-label="<?php esc_attr_e( 'Top story', 'portal' ); ?>">
					<?php
					get_template_part(
						'template-parts/portal/card',
						null,
						array(
							'post'    => $portal_top,
							'variant' => 'lead',
							'heading' => 'h2',
							'eager'   => true,
						)
					);
					?>
				</section>
			<?php endif; ?>

			<?php if ( $portal_latest ) : ?>
				<section class="portal-home__latest portal-latest" aria-labelledby="portal-latest-title">
					<h2 class="portal-section-title" id="portal-latest-title"><?php esc_html_e( 'Latest', 'portal' ); ?></h2>
					<ul class="portal-plain-list">
						<?php foreach ( $portal_latest as $portal_post ) : ?>
							<li><?php get_template_part( 'template-parts/portal/card', null, array( 'post' => $portal_post, 'variant' => 'headline' ) ); ?></li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<?php if ( $portal_trending ) : ?>
				<?php // The outer box is the column; only the inner box is sticky, so it cannot slide past the column's end. ?>
				<div class="portal-home__trending">
					<section class="portal-trending" aria-labelledby="portal-trending-title">
						<h2 class="portal-section-title" id="portal-trending-title"><?php esc_html_e( 'Trending', 'portal' ); ?></h2>
						<ol class="portal-trending__list">
							<?php foreach ( $portal_trending as $portal_post ) : ?>
								<li class="portal-trending__item">
									<a href="<?php echo esc_url( get_permalink( $portal_post ) ); ?>"><?php echo esc_html( get_the_title( $portal_post ) ); ?></a>
								</li>
							<?php endforeach; ?>
						</ol>
					</section>
				</div>
			<?php endif; ?>

			<div class="portal-home__sections">
				<?php
				foreach ( portal_child_home_sections() as $portal_slug ) :
					$portal_cat = get_category_by_slug( $portal_slug );
					if ( ! $portal_cat ) {
						continue;
					}
					$portal_posts = portal_child_get_posts(
						array(
							'posts_per_page' => 4,
							'cat'            => $portal_cat->term_id,
							'post__not_in'   => $portal_exclude,
						)
					);
					if ( ! $portal_posts ) {
						continue; // Hide empty sections.
					}
					?>
					<section class="portal-section portal-section--<?php echo esc_attr( $portal_slug ); ?>" aria-labelledby="portal-section-<?php echo esc_attr( $portal_slug ); ?>">
						<div class="portal-section-title">
							<h2 id="portal-section-<?php echo esc_attr( $portal_slug ); ?>">
								<a href="<?php echo esc_url( get_category_link( $portal_cat ) ); ?>"><?php echo esc_html( $portal_cat->name ); ?></a>
							</h2>
							<a class="portal-section-title__more" href="<?php echo esc_url( get_category_link( $portal_cat ) ); ?>">
								<?php esc_html_e( 'More', 'portal' ); ?><span class="screen-reader-text"> <?php echo esc_html( $portal_cat->name ); ?></span> &rarr;
							</a>
						</div>
						<?php
						foreach ( $portal_posts as $portal_i => $portal_post ) {
							get_template_part(
								'template-parts/portal/card',
								null,
								array(
									'post'    => $portal_post,
									'variant' => 0 === $portal_i ? 'lead' : 'compact',
									'kicker'  => false, // The section heading already names the category.
									'words'   => 18,
								)
							);
						}
						?>
					</section>
				<?php endforeach; ?>
			</div>

			<?php if ( $portal_blogs ) : ?>
				<section class="portal-home__blogs portal-blogs" aria-labelledby="portal-blogs-title">
					<div class="portal-section-title">
						<h2 id="portal-blogs-title">
							<a href="<?php echo esc_url( get_category_link( $portal_blog_id ) ); ?>"><?php esc_html_e( 'Community Blogs', 'portal' ); ?></a>
						</h2>
						<a class="portal-section-title__more" href="<?php echo esc_url( get_category_link( $portal_blog_id ) ); ?>">
							<?php esc_html_e( 'More', 'portal' ); ?><span class="screen-reader-text"> <?php esc_html_e( 'Community Blogs', 'portal' ); ?></span> &rarr;
						</a>
					</div>
					<div class="portal-blogs__grid">
						<?php foreach ( $portal_blogs as $portal_post ) : ?>
							<?php get_template_part( 'template-parts/portal/card', null, array( 'post' => $portal_post, 'variant' => 'blog' ) ); ?>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>
		</div>
	</div>
</div>
<?php
get_footer();
