<?php
/**
 * Reading comfort (Task 17B, simplified after launch), on every story:
 *   - comfortable text: 18-19 px, tall lines for Hindi, Devanagari fonts already on the device
 *     (style.css, no font download);
 *   - reading progress bar at the top of the screen;
 *   - always-visible controls under the headline: "5 min read · A− A+ · Light / Sepia / Dark"
 *     (template-parts/portal/article-flags.php). The whole story page follows the chosen colours.
 *
 * The page HTML is the same for every reader (so LiteSpeed can cache it): each reader's choices
 * live in their own browser (localStorage), and are remembered on that device.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Before the page is drawn: apply the reader's saved size and colours, so the page never
 * appears in the default style first and then jumps (no layout shift, no flash of white).
 */
function portal_child_reading_head() {
	if ( ! portal_child_is_article() ) {
		return;
	}
	// Plain, tiny and wrapped in try: private windows can block storage.
	echo "<script>(function(){try{var d=document.documentElement,p=JSON.parse(localStorage.getItem('portalReader')||'{}');if(/^(sepia|dark)$/.test(p.theme||'')){d.setAttribute('data-portal-theme',p.theme);}if(p.size>=0.85&&p.size<=1.4){d.style.setProperty('--portal-read-scale',p.size);}}catch(e){}})();</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed script, no data.
}
add_action( 'wp_head', 'portal_child_reading_head', 1 );

/**
 * Progress bar, at the start of the page.
 */
function portal_child_reading_bar() {
	if ( portal_child_is_article() ) {
		echo '<div class="portal-progress" aria-hidden="true"><div class="portal-progress__bar" data-portal-progress></div></div>';
	}
}
add_action( 'wp_body_open', 'portal_child_reading_bar' );

/**
 * Text size and colour buttons, for the line under the headline.
 */
function portal_child_reading_controls() {
	?>
	<span class="portal-read-controls">
		<span class="portal-read-controls__group" role="group" aria-label="<?php esc_attr_e( 'Text size', 'portal' ); ?>">
			<button type="button" class="portal-read-controls__btn" data-portal-size="-1" aria-label="<?php esc_attr_e( 'Smaller text', 'portal' ); ?>">A&minus;</button>
			<button type="button" class="portal-read-controls__btn portal-read-controls__big" data-portal-size="1" aria-label="<?php esc_attr_e( 'Larger text', 'portal' ); ?>">A+</button>
		</span>
		<span class="portal-read-controls__group" role="group" aria-label="<?php esc_attr_e( 'Colours', 'portal' ); ?>">
			<?php
			$portal_themes = array(
				'light' => __( 'Light', 'portal' ),
				'sepia' => __( 'Sepia', 'portal' ),
				'dark'  => __( 'Dark', 'portal' ),
			);
			foreach ( $portal_themes as $portal_key => $portal_label ) :
				?>
				<button type="button" class="portal-read-controls__btn portal-read-controls__theme portal-read-controls__theme--<?php echo esc_attr( $portal_key ); ?>" data-portal-theme-pick="<?php echo esc_attr( $portal_key ); ?>" aria-pressed="<?php echo 'light' === $portal_key ? 'true' : 'false'; ?>">
					<span class="portal-read-controls__word"><?php echo esc_html( $portal_label ); ?></span>
				</button>
			<?php endforeach; ?>
		</span>
	</span>
	<?php
}

/**
 * The reading script (small, loaded after the page, only on stories).
 */
function portal_child_reading_assets() {
	if ( ! portal_child_is_article() ) {
		return;
	}
	$path = '/assets/js/reading.js';
	wp_enqueue_script(
		'portal-reading',
		get_stylesheet_directory_uri() . $path,
		array(),
		(string) filemtime( get_stylesheet_directory() . $path ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}
add_action( 'wp_enqueue_scripts', 'portal_child_reading_assets' );
