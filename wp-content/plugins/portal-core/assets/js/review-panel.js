/**
 * "Review" panel in the block editor sidebar.
 * Editors: Approve & publish / Request changes / Reject (a reason is required for the last two).
 * Writers: the editors' latest feedback and the review history of their story.
 */
( function ( wp ) {
	const { registerPlugin } = wp.plugins;
	const { PluginDocumentSettingPanel } = wp.editor || wp.editPost;
	const { createElement: el, useState, useEffect, useRef, Fragment } = wp.element;
	const PANEL = 'portal-review/portal-review'; // "<plugin name>/<panel name>"
	const { Button, TextareaControl, Notice, Spinner } = wp.components;
	const { useSelect, dispatch } = wp.data;
	const { __ } = wp.i18n;
	const canReview = !! ( window.portalReview && window.portalReview.canReview );

	function ReviewPanel() {
		const { postId, status, postType } = useSelect( ( select ) => {
			const editor = select( 'core/editor' );
			return {
				postId: editor.getCurrentPostId(),
				status: editor.getEditedPostAttribute( 'status' ),
				postType: editor.getCurrentPostType(),
			};
		}, [] );
		const [ history, setHistory ] = useState( null );
		const [ mode, setMode ] = useState( '' ); // '' | 'changes' | 'reject'
		const [ note, setNote ] = useState( '' );
		const [ busy, setBusy ] = useState( false );
		const [ error, setError ] = useState( '' );

		useEffect( () => {
			if ( postId && 'post' === postType ) {
				wp.apiFetch( { path: '/portal/v1/review/' + postId } )
					.then( ( r ) => setHistory( r.history ) )
					.catch( () => setHistory( [] ) );
			}
		}, [ postId, status ] );

		const waiting = [ 'pending', 'portal_changes' ].includes( status );
		const last = history && history.length ? history[ history.length - 1 ] : null;

		// WordPress keeps sidebar panels closed by default. Open this one once per visit when
		// there is something to do or read, so nobody has to hunt for the buttons.
		const opened = useRef( false );
		useEffect( () => {
			const needsAttention = waiting || 'portal_rejected' === status || ( last && 'approve' !== last.decision );
			if ( needsAttention && ! opened.current && ! wp.data.select( 'core/editor' ).isEditorPanelOpened( PANEL ) ) {
				dispatch( 'core/editor' ).toggleEditorPanelOpened( PANEL );
			}
			if ( needsAttention ) {
				opened.current = true;
			}
		}, [ waiting, status, last ] );

		if ( 'post' !== postType ) {
			return null;
		}
		if ( ! waiting && ! last && 'portal_rejected' !== status ) {
			return null; // nothing to review and no history: keep the sidebar clean
		}

		const decide = async ( decision ) => {
			setBusy( true );
			setError( '' );
			try {
				// Save the editor's own corrections first, then decide.
				await dispatch( 'core/editor' ).savePost();
				await wp.apiFetch( {
					path: '/portal/v1/review/' + postId,
					method: 'POST',
					data: { decision, note },
				} );
				window.location.reload();
			} catch ( e ) {
				setError( e.message || __( 'Something went wrong.', 'portal' ) );
				setBusy( false );
			}
		};

		const statusLabel = {
			pending: __( 'Waiting for review', 'portal' ),
			portal_changes: __( 'Changes requested', 'portal' ),
			portal_rejected: __( 'Rejected', 'portal' ),
			publish: __( 'Published', 'portal' ),
		}[ status ] || status;

		return el(
			PluginDocumentSettingPanel,
			{ name: 'portal-review', title: __( 'Review', 'portal' ), className: 'portal-review-panel', initialOpen: true },
			el( 'p', null, el( 'strong', null, statusLabel ) ),

			// The writer's view (and editors') of the latest feedback.
			last && 'approve' !== last.decision && el(
				Notice,
				{ status: 'reject' === last.decision ? 'error' : 'warning', isDismissible: false },
				el( 'strong', null, last.label + ' — ' + last.by ),
				el( 'p', { style: { whiteSpace: 'pre-wrap', margin: '4px 0 0' } }, last.note )
			),
			! canReview && 'portal_changes' === status && el( 'p', null,
				__( 'Make the changes, then press "Submit for review" (or Save) to send it back to the editors.', 'portal' ) ),

			canReview && waiting && el(
				Fragment,
				null,
				error && el( Notice, { status: 'error', isDismissible: false }, error ),
				! mode && el(
					'div',
					{ style: { display: 'grid', gap: '8px' } },
					el( Button, { variant: 'primary', isBusy: busy, disabled: busy, onClick: () => decide( 'approve' ) }, __( 'Approve & publish', 'portal' ) ),
					el( Button, { variant: 'secondary', disabled: busy, onClick: () => setMode( 'changes' ) }, __( 'Request changes', 'portal' ) ),
					el( Button, { variant: 'tertiary', isDestructive: true, disabled: busy, onClick: () => setMode( 'reject' ) }, __( 'Reject', 'portal' ) )
				),
				mode && el(
					'div',
					null,
					el( TextareaControl, {
						label: 'changes' === mode ? __( 'What should the writer change?', 'portal' ) : __( 'Why is it rejected? (sent to the writer)', 'portal' ),
						value: note,
						onChange: setNote,
						rows: 5,
					} ),
					el(
						'div',
						{ style: { display: 'flex', gap: '8px' } },
						el( Button, {
							variant: 'primary',
							isDestructive: 'reject' === mode,
							isBusy: busy,
							disabled: busy || note.trim().length < 10,
							onClick: () => decide( mode ),
						}, 'changes' === mode ? __( 'Send to writer', 'portal' ) : __( 'Reject story', 'portal' ) ),
						el( Button, { variant: 'tertiary', disabled: busy, onClick: () => { setMode( '' ); setNote( '' ); } }, __( 'Cancel', 'portal' ) )
					)
				)
			),

			history === null && el( Spinner ),
			history && history.length > 0 && el(
				'details',
				{ style: { marginTop: '12px' } },
				el( 'summary', null, __( 'Review history', 'portal' ) + ' (' + history.length + ')' ),
				el( 'ul', { style: { margin: '8px 0 0', paddingLeft: '16px' } },
					history.slice().reverse().map( ( h, i ) => el( 'li', { key: i, style: { marginBottom: '6px' } },
						el( 'strong', null, h.label ), ' — ' + h.by + ', ' + new Date( h.at ).toLocaleString(),
						h.note ? el( 'div', { style: { whiteSpace: 'pre-wrap' } }, h.note ) : null
					) )
				)
			)
		);
	}

	registerPlugin( 'portal-review', { render: ReviewPanel } );
}( window.wp ) );
