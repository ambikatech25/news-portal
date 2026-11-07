<?php
/**
 * Author card under the article: photo, name, role, short bio, link to their posts.
 * Shows the follower count; the Follow button comes in Task 12.
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
// The rule lives in portal-core so the app labels writers the same way.
$portal_desk      = function_exists( 'portal_is_news_desk' ) && portal_is_news_desk( $portal_author );
$portal_followers = function_exists( 'portal_get_follower_count' ) ? portal_get_follower_count( $portal_author ) : 0;
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
		<p class="portal-author-card__stats">
			<?php
			/* translators: %s: number of stories. */
			echo esc_html( sprintf( _n( '%s story', '%s stories', $portal_count, 'portal' ), number_format_i18n( $portal_count ) ) );
			?>
			<span aria-hidden="true">&middot;</span>
			<span class="portal-author-card__followers">
				<?php
				// The number is in its own span so the Follow button (engage.js) can update it;
				// "Followers: N" reads right for any number, also after it changes live.
				printf(
					/* translators: %s: number of followers. */
					esc_html__( 'Followers: %s', 'portal' ),
					'<span data-portal-count="follow-author-' . esc_attr( (string) $portal_author ) . '">' . esc_html( number_format_i18n( $portal_followers ) ) . '</span>'
				);
				?>
			</span>
		</p>
		<p class="portal-author-card__actions">
			<?php
			if ( function_exists( 'portal_child_toggle' ) ) {
				echo portal_child_toggle( 'follow-author', $portal_author ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
			}
			?>
			<a class="portal-author-card__more" href="<?php echo esc_url( $portal_url ); ?>">
				<?php esc_html_e( 'View profile', 'portal' ); ?>
				<span aria-hidden="true">&rarr;</span>
			</a>
		</p>
	</div>
</section>
