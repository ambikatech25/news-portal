<?php
/**
 * Author card under the article: photo, name, role, short bio, link to their posts.
 * (Follow button and follower count are added with author profiles, Tasks 9 and 12.)
 *
 * Arguments: post (WP_Post).
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

$portal_post = $args['post'] ?? null;
if ( ! $portal_post instanceof WP_Post || ! $portal_post->post_author ) {
	return;
}

$portal_author = (int) $portal_post->post_author;
$portal_name   = get_the_author_meta( 'display_name', $portal_author );
$portal_bio    = get_the_author_meta( 'description', $portal_author );
$portal_url    = get_author_posts_url( $portal_author );
$portal_count  = (int) count_user_posts( $portal_author, 'post', true );

// Readers should be able to tell newsroom reporting from community writing at a glance.
$portal_desk = defined( 'PORTAL_CAP_EDITORIAL_FIELDS' ) && user_can( $portal_author, PORTAL_CAP_EDITORIAL_FIELDS );
?>
<section class="portal-author-card" aria-label="<?php esc_attr_e( 'About the author', 'portal' ); ?>">
	<a class="portal-author-card__photo" href="<?php echo esc_url( $portal_url ); ?>" tabindex="-1" aria-hidden="true">
		<?php echo get_avatar( $portal_author, 72, '', '', array( 'loading' => 'lazy' ) ); ?>
	</a>
	<div class="portal-author-card__body">
		<p class="portal-author-card__role">
			<?php echo esc_html( $portal_desk ? __( 'News desk', 'portal' ) : __( 'Community writer', 'portal' ) ); ?>
		</p>
		<h2 class="portal-author-card__name">
			<a href="<?php echo esc_url( $portal_url ); ?>"><?php echo esc_html( $portal_name ); ?></a>
		</h2>
		<?php if ( $portal_bio ) : ?>
			<p class="portal-author-card__bio"><?php echo esc_html( wp_trim_words( $portal_bio, 40 ) ); ?></p>
		<?php endif; ?>
		<a class="portal-author-card__more" href="<?php echo esc_url( $portal_url ); ?>">
			<?php
			/* translators: %s: number of stories. */
			echo esc_html( sprintf( _n( '%s story', 'All %s stories', $portal_count, 'portal' ), number_format_i18n( $portal_count ) ) );
			?>
			<span aria-hidden="true">&rarr;</span>
		</a>
	</div>
</section>
