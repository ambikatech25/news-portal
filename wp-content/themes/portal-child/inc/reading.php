<?php
/**
 * Reading comfort (Task 17B), on every story:
 *   - comfortable text: 18-19 px, tall lines for Hindi, Devanagari fonts already on the device
 *     (style.css, no font download);
 *   - reading progress bar at the top of the screen;
 *   - reading mode: hides everything except the headline, the story and the series links,
 *     with a toolbar for text size, colours (light / sepia / dark) and "minutes left".
 *
 * The page HTML is the same for every reader (so LiteSpeed can cache it): each reader's choices
 * live in their own browser (localStorage for size and colours, sessionStorage for "reading
 * mode is on", so it stays on while moving through a series and is off on the next visit).
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Before the page is drawn: apply the reader's saved choices, so a reader who has reading mode
 * on never sees the full page first and then a jump (no layout shift).
 */
function portal_child_reading_head() {
	if ( ! portal_child_is_article() ) {
		return;
	}
	// Plain, tiny and wrapped in try: private windows can block storage.
	echo "<script>(function(){try{var d=document.documentElement,p=JSON.parse(localStorage.getItem('portalReader')||'{}');if(sessionStorage.getItem('portalReading')==='1'){d.classList.add('portal-reading');}if(/^(sepia|dark)$/.test(p.theme||'')){d.setAttribute('data-portal-theme',p.theme);}if(p.size>=0.85&&p.size<=1.4){d.style.setProperty('--portal-read-scale',p.size);}}catch(e){}})();</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed script, no data.
}
add_action( 'wp_head', 'portal_child_reading_head', 1 );

/**
 * Progress bar and reading-mode toolbar, at the start of the page.
 */
function portal_child_reading_bar() {
	if ( ! portal_child_is_article() ) {
		return;
	}
	$minutes = function_exists( 'portal_read_minutes' ) ? portal_read_minutes( get_post() ) : 0;
	?>
	<div class="portal-progress" aria-hidden="true"><div class="portal-progress__bar" data-portal-progress></div></div>
	<div class="portal-reading-bar" role="toolbar" aria-label="<?php esc_attr_e( 'Reading mode', 'portal' ); ?>" data-portal-minutes="<?php echo esc_attr( (string) $minutes ); ?>">
		<button type="button" class="portal-reading-bar__btn portal-reading-bar__exit" data-portal-reading-exit>
			<span aria-hidden="true">&#10005;</span> <span class="portal-reading-bar__word"><?php esc_html_e( 'Exit', 'portal' ); ?></span>
		</button>
		<span class="portal-reading-bar__left" data-portal-left aria-live="off"></span>
		<span class="portal-reading-bar__group" role="group" aria-label="<?php esc_attr_e( 'Text size', 'portal' ); ?>">
			<button type="button" class="portal-reading-bar__btn" data-portal-size="-1" aria-label="<?php esc_attr_e( 'Smaller text', 'portal' ); ?>">A&minus;</button>
			<button type="button" class="portal-reading-bar__btn portal-reading-bar__big" data-portal-size="1" aria-label="<?php esc_attr_e( 'Larger text', 'portal' ); ?>">A+</button>
		</span>
		<span class="portal-reading-bar__group" role="group" aria-label="<?php esc_attr_e( 'Colours', 'portal' ); ?>">
			<button type="button" class="portal-reading-bar__btn portal-reading-bar__theme portal-reading-bar__theme--light" data-portal-theme-pick="light" aria-pressed="false"><?php esc_html_e( 'Light', 'portal' ); ?></button>
			<button type="button" class="portal-reading-bar__btn portal-reading-bar__theme portal-reading-bar__theme--sepia" data-portal-theme-pick="sepia" aria-pressed="false"><?php esc_html_e( 'Sepia', 'portal' ); ?></button>
			<button type="button" class="portal-reading-bar__btn portal-reading-bar__theme portal-reading-bar__theme--dark" data-portal-theme-pick="dark" aria-pressed="false"><?php esc_html_e( 'Dark', 'portal' ); ?></button>
		</span>
	</div>
	<?php
}
add_action( 'wp_body_open', 'portal_child_reading_bar' );

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
	wp_localize_script(
		'portal-reading',
		'portalReading',
		array(
			'on'  => __( 'Reading mode', 'portal' ),
			'off' => __( 'Exit reading mode', 'portal' ),
			/* translators: %s: number of minutes. */
			'left' => __( '%s min left', 'portal' ),
			'done' => __( 'Finished', 'portal' ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'portal_child_reading_assets' );
