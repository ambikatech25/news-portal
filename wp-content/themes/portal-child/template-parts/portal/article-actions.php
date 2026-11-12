<?php
/**
 * After the article text: share buttons and the Report button.
 *
 * Arguments: post (WP_Post).
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

$portal_post = $args['post'] ?? null;
if ( ! $portal_post instanceof WP_Post ) {
	return;
}
$portal_title = html_entity_decode( get_the_title( $portal_post ), ENT_QUOTES, 'UTF-8' );
?>
<div class="portal-article-actions">
	<?php if ( function_exists( 'portal_child_toggle' ) ) : ?>
		<div class="portal-engage" role="group" aria-label="<?php esc_attr_e( 'Like, save or comment on this story', 'portal' ); ?>">
			<?php
			echo portal_child_toggle( 'like', $portal_post->ID, function_exists( 'portal_get_likes' ) ? portal_get_likes( $portal_post->ID ) : 0 ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
			echo portal_child_toggle( 'bookmark', $portal_post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
			// Jump to the comments (they come right after the writer's card).
			if ( comments_open( $portal_post ) || get_comments_number( $portal_post ) ) {
				printf(
					'<a class="portal-toggle portal-toggle--comments" href="#comments">%1$s<span class="portal-toggle__label">%2$s</span><span class="portal-toggle__count">%3$s</span></a>',
					portal_child_engage_icon( 'comment' ), // phpcs:ignore WordPress.Security.EscapeOutput -- trusted SVG.
					esc_html__( 'Comments', 'portal' ),
					esc_html( number_format_i18n( (int) get_comments_number( $portal_post ) ) )
				);
			}
			?>
		</div>
	<?php endif; ?>
	<div class="portal-share" role="group" aria-label="<?php esc_attr_e( 'Share this story', 'portal' ); ?>">
		<span class="portal-share__label"><?php esc_html_e( 'Share', 'portal' ); ?></span>

		<?php // Phone share sheet (all installed apps). Shown by article.js only where the browser supports it. ?>
		<button type="button" class="portal-share__btn portal-share__btn--native" data-portal-share hidden
			data-title="<?php echo esc_attr( $portal_title ); ?>" data-url="<?php echo esc_url( get_permalink( $portal_post ) ); ?>">
			<?php echo portal_child_share_icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput -- trusted SVG. ?>
			<span><?php esc_html_e( 'Share', 'portal' ); ?></span>
		</button>

		<?php foreach ( portal_child_share_links( $portal_post ) as $portal_key => $portal_link ) : ?>
			<a class="portal-share__btn portal-share__btn--<?php echo esc_attr( $portal_key ); ?>"
				href="<?php echo esc_url( $portal_link[1] ); ?>" target="_blank" rel="noopener noreferrer nofollow">
				<?php echo portal_child_share_icon( $portal_key ); // phpcs:ignore WordPress.Security.EscapeOutput -- trusted SVG. ?>
				<span class="screen-reader-text">
					<?php
					/* translators: %s: app name, e.g. WhatsApp. */
					echo esc_html( sprintf( __( 'Share on %s', 'portal' ), $portal_link[0] ) );
					?>
				</span>
			</a>
		<?php endforeach; ?>

		<button type="button" class="portal-share__btn portal-share__btn--copy" data-portal-copy hidden
			data-url="<?php echo esc_url( get_permalink( $portal_post ) ); ?>">
			<?php echo portal_child_share_icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput -- trusted SVG. ?>
			<span class="screen-reader-text"><?php esc_html_e( 'Copy link', 'portal' ); ?></span>
		</button>
		<span class="portal-share__status" role="status" aria-live="polite"></span>
	</div>

	<button type="button" class="portal-report-btn" data-portal-report="post" data-id="<?php echo esc_attr( (string) $portal_post->ID ); ?>" hidden>
		<span aria-hidden="true">&#9873;</span>
		<?php esc_html_e( 'Report', 'portal' ); ?>
	</button>
</div>
