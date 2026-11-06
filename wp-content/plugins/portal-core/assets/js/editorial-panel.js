/**
 * "Editorial" panel in the block editor sidebar (Post tab).
 *
 * Plain JavaScript using WordPress's built-in libraries - no build step needed.
 * Reads and writes the portal_* fields through the post's "meta" object, so values
 * are saved together with the post when the editor clicks Save/Update.
 */
( function ( wp ) {
	const { createElement: el, Fragment } = wp.element;
	const { __ } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const { ToggleControl, TextControl, Notice } = wp.components;
	const { useSelect } = wp.data;
	const { useEntityProp } = wp.coreData;
	// Moved from wp.editPost to wp.editor in WordPress 6.6; support both.
	const PluginDocumentSettingPanel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

	function EditorialPanel() {
		const postType = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostType(), [] );
		const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );

		if ( 'post' !== postType || ! meta ) {
			return null;
		}

		const set = ( key ) => ( value ) => setMeta( { ...meta, [ key ]: value } );

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'portal-editorial',
				title: __( 'Editorial', 'portal' ),
				className: 'portal-editorial-panel',
			},
			el( ToggleControl, {
				label: __( 'Breaking news', 'portal' ),
				help: __( 'Shows in the red Breaking strip at the top of every page for 24 hours after publishing.', 'portal' ),
				checked: !! meta.portal_breaking,
				onChange: set( 'portal_breaking' ),
				__nextHasNoMarginBottom: true,
			} ),
			el( ToggleControl, {
				label: __( 'Verified', 'portal' ),
				help: __( 'Shows the Verified badge on the article and in story cards.', 'portal' ),
				checked: !! meta.portal_verified,
				onChange: set( 'portal_verified' ),
				__nextHasNoMarginBottom: true,
			} ),
			meta.portal_verified &&
				el(
					Fragment,
					null,
					el( TextControl, {
						label: __( 'FIR number', 'portal' ),
						value: meta.portal_fir_number || '',
						onChange: set( 'portal_fir_number' ),
						__next40pxDefaultSize: true,
						__nextHasNoMarginBottom: true,
					} ),
					el( TextControl, {
						label: __( 'Police station', 'portal' ),
						value: meta.portal_police_station || '',
						onChange: set( 'portal_police_station' ),
						__next40pxDefaultSize: true,
						__nextHasNoMarginBottom: true,
					} )
				),
			el( ToggleControl, {
				label: __( 'Sensitive', 'portal' ),
				help: __( 'Crime involving minors or a sexual offence. Needs a second editor to approve before publishing.', 'portal' ),
				checked: !! meta.portal_sensitive,
				onChange: set( 'portal_sensitive' ),
				__nextHasNoMarginBottom: true,
			} ),
			meta.portal_sensitive &&
				el(
					Notice,
					{ status: 'warning', isDismissible: false },
					__( 'Do not name or show victims, minors or their families. Blur faces and number plates.', 'portal' )
				),
			el( ToggleControl, {
				label: __( 'Premium', 'portal' ),
				help: __( 'Non-members see a preview and a subscribe box. Never use for public-safety news or corrections.', 'portal' ),
				checked: !! meta.portal_is_premium,
				onChange: set( 'portal_is_premium' ),
				__nextHasNoMarginBottom: true,
			} )
		);
	}

	registerPlugin( 'portal-editorial-panel', { render: EditorialPanel } );
} )( window.wp );
