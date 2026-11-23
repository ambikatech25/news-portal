/**
 * Reading comfort: progress bar, text size (A− A+) and colours (Light / Sepia / Dark).
 * The reader's choices stay in their own browser, on this device (see inc/reading.php).
 */
( function () {
	'use strict';

	var root = document.documentElement;
	var content = document.querySelector( '.single-entry .entry-content' );
	var bar = document.querySelector( '[data-portal-progress]' );
	var SIZES = [ 0.9, 1, 1.1, 1.2, 1.3 ];

	if ( ! content ) {
		return;
	}

	/* ------------------------------------------------------------ saved choices */

	function load() {
		try {
			return JSON.parse( localStorage.getItem( 'portalReader' ) || '{}' ) || {};
		} catch ( e ) {
			return {};
		}
	}

	function save( key, value ) {
		var prefs = load();
		prefs[ key ] = value;
		try {
			localStorage.setItem( 'portalReader', JSON.stringify( prefs ) );
		} catch ( e ) {}
	}

	/* ------------------------------------------------------------ progress */

	var ticking = false;

	function progress() {
		var rect = content.getBoundingClientRect();
		var total = rect.height - window.innerHeight * 0.6;
		var done = total > 0 ? ( window.innerHeight * 0.4 - rect.top ) / total : ( rect.top < window.innerHeight ? 1 : 0 );
		return Math.min( 1, Math.max( 0, done ) );
	}

	function update() {
		ticking = false;
		if ( bar ) {
			bar.style.transform = 'scaleX(' + progress() + ')';
		}
	}

	function onScroll() {
		if ( ! ticking ) {
			ticking = true;
			window.requestAnimationFrame( update );
		}
	}

	window.addEventListener( 'scroll', onScroll, { passive: true } );
	window.addEventListener( 'resize', onScroll, { passive: true } );

	/* ------------------------------------------------------------ size */

	function currentSize() {
		var size = parseFloat( getComputedStyle( root ).getPropertyValue( '--portal-read-scale' ) ) || 1;
		return SIZES.indexOf( size ) < 0 ? 1 : size;
	}

	function showSize() {
		var index = SIZES.indexOf( currentSize() );
		document.querySelectorAll( '[data-portal-size]' ).forEach( function ( btn ) {
			var step = parseInt( btn.getAttribute( 'data-portal-size' ), 10 );
			btn.disabled = ( step < 0 && index === 0 ) || ( step > 0 && index === SIZES.length - 1 );
		} );
	}

	// The paragraph at the top of the screen stays in place when the text grows or shrinks.
	function anchor() {
		var items = content.children;
		for ( var i = 0; i < items.length; i++ ) {
			if ( items[ i ].getBoundingClientRect().bottom > 0 ) {
				return items[ i ];
			}
		}
		return null;
	}

	document.querySelectorAll( '[data-portal-size]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var index = SIZES.indexOf( currentSize() );
			index = Math.min( SIZES.length - 1, Math.max( 0, index + parseInt( btn.getAttribute( 'data-portal-size' ), 10 ) ) );
			var keep = content.getBoundingClientRect().top < 0 ? anchor() : null;
			var before = keep ? keep.getBoundingClientRect().top : 0;
			root.style.setProperty( '--portal-read-scale', SIZES[ index ] );
			save( 'size', SIZES[ index ] );
			if ( keep ) {
				window.scrollBy( 0, keep.getBoundingClientRect().top - before );
			}
			showSize();
			update();
		} );
	} );

	/* ------------------------------------------------------------ colours */

	function showTheme() {
		var theme = root.getAttribute( 'data-portal-theme' ) || 'light';
		document.querySelectorAll( '[data-portal-theme-pick]' ).forEach( function ( btn ) {
			btn.setAttribute( 'aria-pressed', btn.getAttribute( 'data-portal-theme-pick' ) === theme ? 'true' : 'false' );
		} );
	}

	document.querySelectorAll( '[data-portal-theme-pick]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var theme = btn.getAttribute( 'data-portal-theme-pick' );
			if ( 'light' === theme ) {
				root.removeAttribute( 'data-portal-theme' );
			} else {
				root.setAttribute( 'data-portal-theme', theme );
			}
			save( 'theme', theme );
			showTheme();
		} );
	} );

	showSize();
	showTheme();
	update();
}() );
