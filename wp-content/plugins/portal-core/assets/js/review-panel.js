/**
 * "Review" panel in the block editor sidebar.
 * Editors: Approve & publish / Request changes / Reject (a reason is required for the last two),
 *          and for Sensitive stories: Give second approval (Task 14).
 * Writers: the editors' latest feedback and the review history of their story.
 */
( function ( wp ) {
	const { registerPlugin } = wp.plugins;
	const { PluginDocumentSettingPanel } = wp.editor || wp.editPost;
	const { createElement: el, useState, useEffect, useRef, Fragment } = wp.element;
	const { Button, TextareaControl, Notice, Spinner } = wp.components;
	const { useSelect, dispatch } = wp.data;
	const { __ } = wp.i18n;
	const cfg = window.portalReview || {};
	const canReview = !! cfg.canReview;
	const PANEL = 'portal-review/portal-review'; // "<plugin name>/<panel name>"

	function ReviewPanel() {
		const { postId, status, postType, savedSensitive, saving } = useSelect( ( select ) => {
			const editor = select( 'core/editor' );
			const saved = editor.getCurrentPostAttribute( 'meta' ) || {};
			return {
				postId: editor.getCurrentPostId(),
				// The SAVED status, not the edited one: after a refused Publish the unsaved status
				// stays "publish", which would wrongly hide this panel right when it is needed.
				status: editor.getCurrentPostAttribute( 'status' ),
				postType: editor.getCurrentPostType(),
				savedSensitive: !! saved.portal_sensitive,
				saving: editor.isSavingPost(),
			};
		}, [] );
		const [ data, setData ] = useState( null );
		const [ mode, setMode ] = useState( '' ); // '' | 'changes' | 'reject'
		const [ note, setNote ] = useState( '' );
		const [ busy, setBusy ] = useState( false );
		const [ error, setError ] = useState( '' );

		const load = () => {
			if ( postId && 'post' === postType ) {
				wp.apiFetch( { path: '/portal/v1/review/' + postId } )
					.then( setData )
					.catch( () => setData( { history: [], sensitive: null } ) );
			}
		};
		// Reload after every save (status or Sensitive may have changed).
		useEffect( () => {
			if ( ! saving ) {
				load();
			}
		}, [ postId, status, savedSensitive, saving ] );

		const history = data ? data.history || [] : null;
		const sens = data ? data.sensitive : null;
		const waiting = [ 'pending', 'portal_changes' ].includes( status );
		const last = history && history.length ? history[ history.length - 1 ] : null;
		const showSens = !! ( sens && sens.is && 'publish' !== status );

		// WordPress keeps sidebar panels closed by default. Open this one once per visit when
		// there is something to do or read, so nobody has to hunt for the buttons.
		const opened = useRef( false );
		useEffect( () => {
			const needsAttention = waiting || showSens || 'portal_rejected' === status || ( last && 'approve' !== last.decision );
			if ( needsAttention && ! opened.current && ! wp.data.select( 'core/editor' ).isEditorPanelOpened( PANEL ) ) {
				dispatch( 'core/editor' ).toggleEditorPanelOpened( PANEL );
			}
			if ( needsAttention ) {
				opened.current = true;
			}
		}, [ waiting, status, last, showSens ] );

		if ( 'post' !== postType ) {
			return null;
		}
		if ( ! waiting && ! last && ! showSens && 'portal_rejected' !== status ) {
			return null; // nothing to review and no history: keep the sidebar clean
		}

		const call = async ( request, reload ) => {
			setBusy( true );
			setError( '' );
			try {
				// After a refused Publish the editor still holds an unsaved "publish" status; saving
				// that again would fail and stop this action. Put the status back to the saved one.
				if ( wp.data.select( 'core/editor' ).getEditedPostAttribute( 'status' ) !== status ) {
					dispatch( 'core/editor' ).editPost( { status } );
				}
				await dispatch( 'core/editor' ).savePost(); // keep the editor's own corrections
				await wp.apiFetch( request );
				if ( reload ) {
					window.location.reload();
				} else {
					load();
					setBusy( false );
				}
			} catch ( e ) {
				setError( e.message || __( 'Something went wrong.', 'portal' ) );
				setBusy( false );
			}
		};
		const decide = ( decision ) => call( { path: '/portal/v1/review/' + postId, method: 'POST', data: { decision, note } }, true );
		const second = ( method ) => call( { path: '/portal/v1/review/' + postId + '/second-approval', method }, false );

		const statusLabel = {
			pending: __( 'Waiting for review', 'portal' ),
			portal_changes: __( 'Changes requested', 'portal' ),
			portal_rejected: __( 'Rejected', 'portal' ),
			publish: __( 'Published', 'portal' ),
			draft: __( 'Draft', 'portal' ),
		}[ status ] || status;
		const mayPublish = ! sens || sens.can_publish;

		return el(
			PluginDocumentSettingPanel,
			{ name: 'portal-review', title: __( 'Review', 'portal' ), className: 'portal-review-panel' },
			el( 'p', null, el( 'strong', null, statusLabel ) ),

			// Sensitive: two editors needed.
			// A plain box, not a WordPress <Notice>: Notice crashed when it contained buttons.
			showSens && el(
				'div',
				{
					className: 'portal-sensitive-box',
					role: 'status',
					style: { margin: '0 0 12px', padding: '8px 12px', borderLeft: '4px solid #dba617', background: '#fcf9e8' },
				},
				el( 'strong', null, __( 'Sensitive story: two editors needed', 'portal' ) ),
				el( 'p', { style: { margin: '4px 0' } },
					sens.approver
						? __( 'Second approval by', 'portal' ) + ' ' + sens.approver.name + ', ' + new Date( sens.approver.at ).toLocaleString()
						: __( 'No second approval yet.', 'portal' ) ),
				canReview && sens.can_approve && el( Button, { variant: 'secondary', isBusy: busy, disabled: busy, onClick: () => second( 'POST' ) }, __( 'Give second approval', 'portal' ) ),
				// Number(): wp_localize_script sends numbers as text ("279").
				canReview && sens.approver && sens.approver.id === Number( cfg.userId ) && el( Button, { variant: 'link', disabled: busy, onClick: () => second( 'DELETE' ) }, __( 'Withdraw my approval', 'portal' ) )
			),

			// Latest feedback (writer's view too).
			last && 'approve' !== last.decision && el(
				Notice,
				{ status: 'reject' === last.decision ? 'error' : 'warning', isDismissible: false },
				el( 'strong', null, last.label + ' — ' + last.by ),
				el( 'p', { style: { whiteSpace: 'pre-wrap', margin: '4px 0 0' } }, last.note )
			),
			! canReview && 'portal_changes' === status && el( 'p', null,
				__( 'Make the changes, then press "Submit for review" (or Save) to send it back to the editors.', 'portal' ) ),

			error && el( Notice, { status: 'error', isDismissible: false }, error ),

			canReview && waiting && el(
				Fragment,
				null,
				! mode && el(
					'div',
					{ style: { display: 'grid', gap: '8px' } },
					el( Button, { variant: 'primary', isBusy: busy, disabled: busy || ! mayPublish, onClick: () => decide( 'approve' ) }, __( 'Approve & publish', 'portal' ) ),
					! mayPublish && el( 'p', { className: 'portal-review-blocked', style: { margin: 0, fontSize: '12px' } }, sens.message ),
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
