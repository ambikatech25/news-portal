<?php
/**
 * Under the headline: Verified box (FIR number, police station), Premium label, read time.
 *
 * The Verified box appears ONLY when an editor switched Verified on. An FIR number typed
 * in without Verified is never shown: the badge is the newsroom's promise that the
 * record was checked, and the FIR is part of that promise.
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

$portal_verified = (bool) get_post_meta( $portal_post->ID, 'portal_verified', true );
$portal_premium  = (bool) get_post_meta( $portal_post->ID, 'portal_is_premium', true );
$portal_fir      = trim( (string) get_post_meta( $portal_post->ID, 'portal_fir_number', true ) );
$portal_station  = trim( (string) get_post_meta( $portal_post->ID, 'portal_police_station', true ) );
$portal_minutes  = function_exists( 'portal_read_minutes' ) ? portal_read_minutes( $portal_post ) : 0;
$portal_versions = function_exists( 'portal_story_versions' ) ? portal_story_versions( $portal_post ) : array();
?>
<div class="portal-article-flags">
	<?php // Task 18A: हिन्दी | English, only when the story is published in both. ?>
	<?php if ( count( $portal_versions ) > 1 ) : ?>
		<nav class="portal-story-lang" aria-label="<?php esc_attr_e( 'Read this story in', 'portal' ); ?>">
			<?php foreach ( $portal_versions as $portal_i => $portal_version ) : ?>
				<?php if ( $portal_i ) : ?>
					<span class="portal-story-lang__sep" aria-hidden="true">|</span>
				<?php endif; ?>
				<?php if ( $portal_version['current'] ) : ?>
					<span class="portal-story-lang__item is-current" lang="<?php echo esc_attr( $portal_version['lang'] ); ?>" aria-current="page"><?php echo esc_html( $portal_version['name'] ); ?></span>
				<?php else : ?>
					<a class="portal-story-lang__item" href="<?php echo esc_url( $portal_version['url'] ); ?>" lang="<?php echo esc_attr( $portal_version['lang'] ); ?>" hreflang="<?php echo esc_attr( $portal_version['lang'] ); ?>"><?php echo esc_html( $portal_version['name'] ); ?></a>
				<?php endif; ?>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>
	<p class="portal-article-flags__line">
		<?php if ( $portal_premium ) : ?>
			<span class="portal-badge portal-badge--premium"><?php esc_html_e( 'Premium', 'portal' ); ?></span>
		<?php endif; ?>
		<?php if ( $portal_minutes ) : ?>
			<span class="portal-read-time">
				<?php
				/* translators: %s: number of minutes. */
				echo esc_html( sprintf( _n( '%s min read', '%s min read', $portal_minutes, 'portal' ), number_format_i18n( $portal_minutes ) ) );
				?>
			</span>
		<?php endif; ?>
		<?php // Text size and Light / Sepia / Dark, always visible (inc/reading.php). ?>
		<?php portal_child_reading_controls(); ?>
	</p>

	<?php if ( $portal_verified ) : ?>
		<aside class="portal-verified" aria-label="<?php esc_attr_e( 'Verification', 'portal' ); ?>">
			<span class="portal-verified__icon" aria-hidden="true">&#10003;</span>
			<div class="portal-verified__text">
				<strong class="portal-verified__title"><?php esc_html_e( 'Verified by our news desk', 'portal' ); ?></strong>
				<?php if ( $portal_fir || $portal_station ) : ?>
					<dl class="portal-verified__facts">
						<?php if ( $portal_fir ) : ?>
							<div>
								<dt><?php esc_html_e( 'FIR No.', 'portal' ); ?></dt>
								<dd><?php echo esc_html( $portal_fir ); ?></dd>
							</div>
						<?php endif; ?>
						<?php if ( $portal_station ) : ?>
							<div>
								<dt><?php esc_html_e( 'Police station', 'portal' ); ?></dt>
								<dd><?php echo esc_html( $portal_station ); ?></dd>
							</div>
						<?php endif; ?>
					</dl>
				<?php endif; ?>
			</div>
		</aside>
	<?php endif; ?>
</div>
