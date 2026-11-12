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
?>
<div class="portal-article-flags">
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
		<?php // Task 17B. Hidden until reading.js runs, so it never shows as a dead button. ?>
		<button type="button" class="portal-reading-toggle" data-portal-reading-toggle aria-pressed="false" hidden>
			<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5.5c3-1 6-1 9 1 3-2 6-2 9-1v13c-3-1-6-1-9 1-3-2-6-2-9-1v-13ZM12 6.5v13"/></svg>
			<span class="portal-reading-toggle__label"><?php esc_html_e( 'Reading mode', 'portal' ); ?></span>
		</button>
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
