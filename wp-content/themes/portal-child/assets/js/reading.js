/**
 * Reading comfort (Task 17B): progress bar, "minutes left", reading mode, text size, colours.
 * The reader's choices stay in their own browser (see inc/reading.php).
 */
( function () {
	'use strict';

	var root = document.documentElement;
	var text = window.portalReading || {};
	var content = document.querySelector( '.single-entry .entry-content' );
	var bar = document.querySelector( '[data-portal-progress]' );
	var toolbar = document.querySelector( '.portal-reading-bar' );
	var leftEl = document.querySelector( '[data-portal-left]' );
	var toggle = document.querySelector( '[data-portal-reading-toggle]' );
	var minutes = toolbar ? parseInt( toolbar.getAttribute( 'data-portal-minutes' ), 10 ) || 0 : 0;
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
	var lastLeft = '';

	function progress() {
		var rect = content.getBoundingClientRect();
		var total = rect.height - window.innerHeight * 0.6;
		var done = total > 0 ? ( window.innerHeight * 0.4 - rect.top ) / total : ( rect.top < window.innerHeight ? 1 : 0 );
		return Math.min( 1, Math.max( 0, done ) );
	}

	function update() {
		ticking = false;
		var p = progress();
		if ( bar ) {
			bar.style.transform = 'scaleX(' + p + ')';
		}
		if ( leftEl && minutes ) {
			var label = p >= 0.98 ? text.done : ( text.left || '%s' ).replace( '%s', String( Math.max( 1, Math.ceil( minutes * ( 1 - p ) ) ) ) );
			if ( label !== lastLeft ) {
				leftEl.textContent = label;
				lastLeft = label;
			}
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

	/* ------------------------------------------------------------ reading mode */

	function isOn() {
		return root.classList.contains( 'portal-reading' );
	}

	function showState() {
		var on = isOn();
		if ( toggle ) {
			toggle.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			toggle.querySelector( '.portal-reading-toggle__label' ).textContent = on ? text.off : text.on;
		}
		var theme = root.getAttribute( 'data-portal-theme' ) || 'light';
		document.querySelectorAll( '[data-portal-theme-pick]' ).forEach( function ( btn ) {
			btn.setAttribute( 'aria-pressed', btn.getAttribute( 'data-portal-theme-pick' ) === theme ? 'true' : 'false' );
		} );
	}

	// The first paragraph (or heading, image...) still visible at the top of the screen.
	function anchor() {
		var items = content.children;
		for ( var i = 0; i < items.length; i++ ) {
			if ( items[ i ].getBoundingClientRect().bottom > 0 ) {
				return items[ i ];
			}
		}
		return null;
	}

	function setMode( on ) {
		if ( on === isOn() ) {
			return;
		}
		// Keep the reader's place: hiding or showing the header moves the text.
		var keep = anchor();
		var before = keep ? keep.getBoundingClientRect().top : 0;
		root.classList.toggle( 'portal-reading', on );
		try {
			if ( on ) {
				sessionStorage.setItem( 'portalReading', '1' );
			} else {
				sessionStorage.removeItem( 'portalReading' );
			}
		} catch ( e ) {}
		if ( keep && window.scrollY > 0 ) {
			window.scrollBy( 0, keep.getBoundingClientRect().top - before );
		}
		showState();
		update();
	}

	if ( toggle ) {
		toggle.hidden = false;
		toggle.addEventListener( 'click', function () {
			setMode( ! isOn() );
		} );
	}

	document.querySelectorAll( '[data-portal-reading-exit]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			setMode( false );
			if ( toggle ) {
				toggle.focus();
			}
		} );
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && isOn() && ! document.querySelector( 'dialog[open]' ) ) {
			setMode( false );
		}
	} );

	/* ------------------------------------------------------------ size and colours */

	document.querySelectorAll( '[data-portal-size]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var current = parseFloat( getComputedStyle( root ).getPropertyValue( '--portal-read-scale' ) ) || 1;
			var index = SIZES.indexOf( current );
			if ( index < 0 ) {
				index = 1;
			}
			index = Math.min( SIZES.length - 1, Math.max( 0, index + parseInt( btn.getAttribute( 'data-portal-size' ), 10 ) ) );
			root.style.setProperty( '--portal-read-scale', SIZES[ index ] );
			save( 'size', SIZES[ index ] );
			update();
		} );
	} );

	document.querySelectorAll( '[data-portal-theme-pick]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var theme = btn.getAttribute( 'data-portal-theme-pick' );
			if ( 'light' === theme ) {
				root.removeAttribute( 'data-portal-theme' );
			} else {
				root.setAttribute( 'data-portal-theme', theme );
			}
			save( 'theme', theme );
			showState();
		} );
	} );

	showState();
	update();
}() );
