/**
 * Article page: phone Share sheet, "Copy link", and the Report form.
 * Plain JavaScript, no libraries. Settings come from portalArticle (inc/article.php).
 */
( function () {
	'use strict';

	var cfg = window.portalArticle || {};
	var t = cfg.i18n || {};

	function say( el, text ) {
		if ( el ) {
			el.textContent = text;
		}
	}

	/* ---------- Share: the phone's own share sheet, where available ---------- */
	document.querySelectorAll( '[data-portal-share]' ).forEach( function ( btn ) {
		if ( ! navigator.share ) {
			return; // stays hidden; the WhatsApp/Facebook/... links still work
		}
		btn.hidden = false;
		// Lets the CSS show a shorter row on phones (the share sheet already lists every app).
		btn.parentNode.classList.add( 'portal-share--native' );
		btn.addEventListener( 'click', function () {
			navigator.share( { title: btn.dataset.title, url: btn.dataset.url } ).catch( function () {} );
		} );
	} );

	/* ---------- Copy link ---------- */
	document.querySelectorAll( '[data-portal-copy]' ).forEach( function ( btn ) {
		if ( ! navigator.clipboard ) {
			return;
		}
		btn.hidden = false;
		btn.addEventListener( 'click', function () {
			var status = btn.parentNode.querySelector( '.portal-share__status' );
			navigator.clipboard.writeText( btn.dataset.url ).then( function () {
				say( status, t.copied );
				setTimeout( function () { say( status, '' ); }, 2500 );
			} );
		} );
	} );

	/* ---------- Report ---------- */
	var dialog = document.getElementById( 'portal-report-dialog' );
	if ( ! dialog || typeof dialog.showModal !== 'function' || ! cfg.reportUrl ) {
		return; // very old browser: Report buttons stay hidden
	}
	var form = dialog.querySelector( 'form' );
	var status = dialog.querySelector( '.portal-report__status' );
	var submit = dialog.querySelector( '.portal-report__submit' );
	var lastButton = null;

	document.querySelectorAll( '[data-portal-report]' ).forEach( function ( btn ) {
		btn.hidden = false;
		btn.addEventListener( 'click', function () {
			lastButton = btn;
			form.reset();
			form.hidden = false;
			submit.disabled = false;
			form.elements.object_type.value = btn.dataset.portalReport;
			form.elements.object_id.value = btn.dataset.id;
			say( status, '' );
			dialog.showModal();
		} );
	} );

	dialog.querySelector( '.portal-report__cancel' ).addEventListener( 'click', function () {
		dialog.close();
	} );

	// Give focus back to the button that opened the form (keyboard and screen-reader users).
	dialog.addEventListener( 'close', function () {
		if ( lastButton ) {
			lastButton.focus();
		}
	} );

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		var reason = form.querySelector( 'input[name="reason"]:checked' );
		if ( ! reason ) {
			say( status, t.choose );
			return;
		}

		var headers = { 'Content-Type': 'application/json' };
		if ( cfg.nonce ) {
			headers[ 'X-WP-Nonce' ] = cfg.nonce;
		}
		submit.disabled = true;
		say( status, t.sending );

		fetch( cfg.reportUrl, {
			method: 'POST',
			headers: headers,
			credentials: 'same-origin',
			body: JSON.stringify( {
				object_type: form.elements.object_type.value,
				object_id: parseInt( form.elements.object_id.value, 10 ),
				reason: reason.value,
				details: form.elements.details.value
			} )
		} )
			.then( function ( res ) {
				return res.json().then( function ( data ) {
					return { ok: res.ok, data: data };
				} );
			} )
			.then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( ( r.data && r.data.message ) || t.error );
				}
				say( status, r.data.message );
				if ( lastButton ) {
					lastButton.disabled = true; // one report per item per visit
				}
				setTimeout( function () { dialog.close(); }, 1800 );
			} )
			.catch( function ( err ) {
				submit.disabled = false;
				say( status, err.message || t.error );
			} );
	} );
}() );
