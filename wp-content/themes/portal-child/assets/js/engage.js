/**
 * Like / Save / Follow buttons. Each button says what it is (data-portal-toggle) and which
 * post, writer or tag (data-id); pressing it switches it on or off through portal/v1.
 * Only loaded for logged-in members (visitors get sign-in links instead).
 */
( function () {
	'use strict';

	var cfg = window.portalEngage || {};
	if ( ! cfg.root ) {
		return;
	}

	var routes = {
		'like': 'likes/',
		'bookmark': 'bookmarks/',
		'follow-author': 'follows/author/',
		'follow-tag': 'follows/tag/'
	};

	function setState( kind, id, on, count ) {
		// Update every button for the same thing (e.g. Follow on the card and in a list).
		document.querySelectorAll( '[data-portal-toggle="' + kind + '"][data-id="' + id + '"]' ).forEach( function ( btn ) {
			btn.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			var label = btn.querySelector( '.portal-toggle__label' );
			if ( label ) {
				label.textContent = on ? label.dataset.on : label.dataset.off;
			}
			var num = btn.querySelector( '.portal-toggle__count' );
			if ( num && typeof count === 'number' ) {
				num.textContent = count.toLocaleString();
			}
		} );
		if ( typeof count === 'number' ) {
			document.querySelectorAll( '[data-portal-count="' + kind + '-' + id + '"]' ).forEach( function ( el ) {
				el.textContent = count.toLocaleString();
			} );
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( '[data-portal-toggle]' );
		if ( ! btn || btn.disabled ) {
			return;
		}
		var kind = btn.dataset.portalToggle;
		var id = btn.dataset.id;
		var on = btn.getAttribute( 'aria-pressed' ) === 'true';
		if ( ! routes[ kind ] ) {
			return;
		}

		btn.disabled = true;
		setState( kind, id, ! on ); // show the change at once; undone below if it fails

		fetch( cfg.root + routes[ kind ] + id, {
			method: on ? 'DELETE' : 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce }
		} )
			.then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( 'HTTP ' + res.status );
				}
				return res.json();
			} )
			.then( function ( data ) {
				var now = 'liked' in data ? data.liked : ( 'bookmarked' in data ? data.bookmarked : data.following );
				var count = 'likes' in data ? data.likes : data.followers;
				setState( kind, id, now, count );
			} )
			.catch( function () {
				setState( kind, id, on );
				window.alert( cfg.error );
			} )
			.finally( function () {
				btn.disabled = false;
			} );
	} );
}() );
