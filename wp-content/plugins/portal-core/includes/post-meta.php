<?php
/**
 * Custom post fields (post meta) and the editor sidebar panel.
 *
 * All fields are registered with show_in_rest so the website, the block editor and the
 * future mobile app read and write them the same way: in the "meta" object of
 * /wp-json/wp/v2/posts.
 *
 * Only users with PORTAL_CAP_EDITORIAL_FIELDS (Editors, Admins) can change them.
 * Internal fields (Sensitive, second approver) are hidden from public API responses
 * and only appear with ?context=edit for users who can edit the post.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions: meta key => settings.
 *
 * @return array<string, array>
 */
function portal_post_meta_fields() {
	return array(
		'portal_verified'        => array(
			'type'        => 'boolean',
			'description' => __( 'Checked by the editorial desk. Shows the Verified badge.', 'portal' ),
			'default'     => false,
			'public'      => true,
		),
		'portal_fir_number'      => array(
			'type'        => 'string',
			'description' => __( 'FIR number shown with the Verified badge.', 'portal' ),
			'default'     => '',
			'public'      => true,
		),
		'portal_police_station'  => array(
			'type'        => 'string',
			'description' => __( 'Police station shown with the Verified badge.', 'portal' ),
			'default'     => '',
			'public'      => true,
		),
		'portal_sensitive'       => array(
			'type'        => 'boolean',
			'description' => __( 'Crime, minors or sexual offence: needs a second approval before publishing.', 'portal' ),
			'default'     => false,
			'public'      => false,
		),
		'portal_second_approver' => array(
			'type'        => 'integer',
			'description' => __( 'User ID of the second Editor or Admin who approved a sensitive post.', 'portal' ),
			'default'     => 0,
			'public'      => false,
		),
		'portal_is_premium'      => array(
			'type'        => 'boolean',
			'description' => __( 'Premium post: non-members see a preview and a subscribe box.', 'portal' ),
			'default'     => false,
			'public'      => true,
		),
		'portal_breaking'        => array(
			'type'        => 'boolean',
			'description' => __( 'Breaking news: shown in the red Breaking strip for 24 hours after publishing.', 'portal' ),
			'default'     => false,
			'public'      => true,
		),
	);
}

/**
 * Only Editors/Admins who can edit this post may change the fields.
 *
 * @param bool   $allowed  Unused.
 * @param string $meta_key Unused.
 * @param int    $post_id  Post ID.
 * @param int    $user_id  User ID.
 * @return bool
 */
function portal_can_edit_editorial_meta( $allowed, $meta_key, $post_id, $user_id ) {
	return user_can( $user_id, 'edit_post', $post_id ) && user_can( $user_id, PORTAL_CAP_EDITORIAL_FIELDS );
}

/**
 * Sanitize a field value to its declared type.
 *
 * @param mixed  $value    Raw value.
 * @param string $meta_key Meta key.
 * @return bool|int|string
 */
function portal_sanitize_editorial_meta( $value, $meta_key ) {
	$fields = portal_post_meta_fields();
	switch ( $fields[ $meta_key ]['type'] ?? 'string' ) {
		case 'boolean':
			return rest_sanitize_boolean( $value );
		case 'integer':
			return absint( $value );
		default:
			return sanitize_text_field( (string) $value );
	}
}

/**
 * Register the fields.
 */
function portal_register_post_meta() {
	foreach ( portal_post_meta_fields() as $key => $field ) {
		register_post_meta(
			'post',
			$key,
			array(
				'type'              => $field['type'],
				'description'       => $field['description'],
				'single'            => true,
				'default'           => $field['default'],
				'sanitize_callback' => 'portal_sanitize_editorial_meta',
				'auth_callback'     => 'portal_can_edit_editorial_meta',
				'show_in_rest'      => array(
					'schema' => array(
						// 'view' = public responses; 'edit' = ?context=edit (people who can edit the post).
						'context' => $field['public'] ? array( 'view', 'edit' ) : array( 'edit' ),
					),
				),
			)
		);
	}
}
add_action( 'init', 'portal_register_post_meta' );

/**
 * Load the "Editorial" panel in the block editor for people who may change the fields.
 */
function portal_enqueue_editorial_panel() {
	$screen = get_current_screen();
	if ( ! $screen || 'post' !== $screen->post_type || ! current_user_can( PORTAL_CAP_EDITORIAL_FIELDS ) ) {
		return;
	}

	$file = 'assets/js/editorial-panel.js';
	wp_enqueue_script(
		'portal-editorial-panel',
		PORTAL_CORE_URL . $file,
		array( 'wp-components', 'wp-core-data', 'wp-data', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins' ),
		(string) filemtime( PORTAL_CORE_DIR . $file ),
		true
	);
	wp_set_script_translations( 'portal-editorial-panel', 'portal', PORTAL_CORE_DIR . 'languages' );
}
add_action( 'enqueue_block_editor_assets', 'portal_enqueue_editorial_panel' );
