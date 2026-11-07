/**
 * "Fill the form from a document" on the Write page.
 * Sends the file (or pasted text) to /portal/v1/story-import and fills the WP User Frontend
 * story form: headline, story (TinyMCE editor), section, tags. Nothing is submitted:
 * the writer checks everything, adds pictures and presses Submit.
 */
( function () {
	'use strict';

	var cfg = window.portalImport || {};
	var t = cfg.i18n || {};
	var box = document.querySelector( '.portal-import' );
	if ( ! box || ! cfg.url ) {
		return;
	}
	var statusEl = box.querySelector( '.portal-import__status' );

	function form() {
		return document.querySelector( 'form.wpuf-form-add' );
	}

	function field( name ) {
		var f = form();
		return f ? f.querySelector( '[name="' + name + '"]' ) : null;
	}

	function editor() {
		var area = field( 'post_content' );
		return area && window.tinymce ? window.tinymce.get( area.id ) : null;
	}

	function hasTyping() {
		var title = field( 'post_title' );
		var ed = editor();
		var area = field( 'post_content' );
		var body = ed ? ed.getContent( { format: 'text' } ) : ( area ? area.value : '' );
		return ( title && title.value.trim() !== '' ) || body.trim() !== '';
	}

	function show( message, warnings, isError ) {
		statusEl.textContent = '';
		var p = document.createElement( 'p' );
		p.className = isError ? 'is-error' : 'is-ok';
		p.textContent = message;
		statusEl.appendChild( p );
		if ( warnings && warnings.length ) {
			var ul = document.createElement( 'ul' );
			warnings.forEach( function ( w ) {
				var li = document.createElement( 'li' );
				li.textContent = w;
				ul.appendChild( li );
			} );
			statusEl.appendChild( ul );
		}
	}

	function fill( data ) {
		var title = field( 'post_title' );
		var area = field( 'post_content' );
		var cat = field( 'category' );
		var tags = field( 'tags' );
		var ed = editor();

		if ( title && data.title ) {
			title.value = data.title;
		}
		if ( data.content ) {
			if ( ed ) {
				ed.setContent( data.content ); // HTML was cleaned on the server
				ed.save();
			} else if ( area ) {
				area.value = data.content;
			}
		}
		if ( cat && data.category && cat.querySelector( 'option[value="' + data.category + '"]' ) ) {
			cat.value = String( data.category );
			cat.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
		if ( tags && data.tags ) {
			tags.value = data.tags;
		}
		show( t.done, data.warnings, false );
		if ( title ) {
			title.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}
	}

	function send( body ) {
		if ( hasTyping() && ! window.confirm( t.replace ) ) {
			return;
		}
		show( t.reading, [], false );
		fetch( cfg.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce },
			body: body
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
				fill( r.data );
			} )
			.catch( function ( err ) {
				show( err.message || t.error, [], true );
			} );
	}

	var fileInput = box.querySelector( '[data-portal-import-file]' );
	fileInput.addEventListener( 'change', function () {
		if ( ! fileInput.files.length ) {
			return;
		}
		var data = new FormData();
		data.append( 'file', fileInput.files[ 0 ] );
		send( data );
		fileInput.value = ''; // the same file can be chosen again after editing it
	} );

	box.querySelector( '[data-portal-import-paste]' ).addEventListener( 'click', function () {
		var text = box.querySelector( '[data-portal-import-text]' ).value;
		var data = new FormData();
		data.append( 'text', text );
		send( data );
	} );

	var promptBtn = box.querySelector( '[data-portal-import-prompt]' );
	promptBtn.addEventListener( 'click', function () {
		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( promptBtn.dataset.prompt ).then( function () {
				show( t.copied, [], false );
			} );
		} else {
			box.querySelector( '[data-portal-import-text]' ).value = promptBtn.dataset.prompt;
		}
	} );

	// Expose for testing in the browser (fill from a Blob without the file picker).
	window.portalImportSend = send;
}() );
