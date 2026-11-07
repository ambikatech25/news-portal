<?php
/**
 * Task 16: the Grievance form (Fluent Forms) on the Grievance Officer page. Safe to run again
 * (rebuilds the form's fields, keeps the same form and entries).
 *
 *   docker compose run --rm -T wpcli eval-file /scripts/setup-moderation.php
 *
 * Fields are built from Fluent Forms' own default field definitions, so the form behaves exactly
 * like one made in its form builder. It can be edited later in wp-admin → Fluent Forms.
 *
 * @package Portal
 */

global $wpdb;

if ( ! defined( 'FLUENTFORM_DIR_PATH' ) ) {
	WP_CLI::error( 'Fluent Forms must be active.' );
}

$portal_defaults_file = FLUENTFORM_DIR_PATH . 'app/Services/FormBuilder/DefaultElements.php';
$portal_returned      = include $portal_defaults_file;
$portal_defaults      = is_array( $portal_returned ) ? $portal_returned : ( $fluentformDefaultElements ?? array() ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
$portal_all           = array_merge( $portal_defaults['general'] ?? array(), $portal_defaults['advanced'] ?? array() );
foreach ( array( 'input_name', 'input_email', 'select', 'input_url', 'textarea', 'terms_and_condition' ) as $portal_need ) {
	if ( empty( $portal_all[ $portal_need ] ) ) {
		WP_CLI::error( "Fluent Forms default field '$portal_need' not found." );
	}
}

/**
 * A field from Fluent Forms' defaults, with our changes.
 *
 * @param array  $all     Defaults.
 * @param string $element Element key.
 * @param int    $index   Position.
 * @param array  $changes [ 'attributes' => [...], 'settings' => [...] ] merged in.
 * @return array
 */
function portal_ff_field( $all, $element, $index, $changes ) {
	$field              = $all[ $element ];
	$field['index']     = $index;
	$field['uniqElKey'] = 'el_portal_' . $element . '_' . $index;
	foreach ( $changes as $part => $values ) {
		$field[ $part ] = array_replace_recursive( (array) ( $field[ $part ] ?? array() ), $values );
	}
	unset( $field['editor_options']['template_preview'] );
	return $field;
}

$portal_required = static fn( $yes ) => array( 'validation_rules' => array( 'required' => array( 'value' => $yes, 'message' => 'This field is required' ) ) );

$portal_name                                                       = portal_ff_field( $portal_all, 'input_name', 0, array() );
$portal_name['fields']['first_name']['settings']['label']          = 'First name';
$portal_name['fields']['first_name']['attributes']['placeholder']  = '';
$portal_name['fields']['first_name']['settings']['validation_rules']['required']['value'] = true;
$portal_name['fields']['last_name']['settings']['label']           = 'Last name';
$portal_name['fields']['last_name']['attributes']['placeholder']   = '';
$portal_name['fields']['middle_name']['settings']['visible']       = false;

$portal_types = array(
	'About me: false or damaging (defamation)',
	'My privacy: personal information or photo',
	'Identifies a victim, a child or a witness',
	'Copyright: my work used without permission',
	'False or misleading news',
	'Obscene, violent or harmful content',
	'Something else',
);

$portal_fields = array(
	$portal_name,
	portal_ff_field(
		$portal_all,
		'input_email',
		1,
		array(
			'attributes' => array( 'name' => 'email', 'placeholder' => '' ),
			'settings'   => array( 'label' => 'Email (we reply here)' ) + $portal_required( true ),
		)
	),
	portal_ff_field(
		$portal_all,
		'select',
		2,
		array(
			'attributes' => array( 'name' => 'complaint_type', 'value' => '' ),
			'settings'   => array(
				'label'            => 'What is your complaint about?',
				'placeholder'      => '- Choose -',
				'advanced_options' => array_map( static fn( $t ) => array( 'label' => $t, 'value' => $t, 'calc_value' => '' ), $portal_types ),
			) + $portal_required( true ),
		)
	),
	portal_ff_field(
		$portal_all,
		'input_url',
		3,
		array(
			'attributes' => array( 'name' => 'content_url', 'placeholder' => 'https://citycharcha.com/...' ),
			'settings'   => array( 'label' => 'Link to the story or comment (if any)' ) + $portal_required( false ),
		)
	),
	portal_ff_field(
		$portal_all,
		'textarea',
		4,
		array(
			'attributes' => array( 'name' => 'details', 'placeholder' => '', 'rows' => 6 ),
			'settings'   => array( 'label' => 'Describe your complaint' ) + $portal_required( true ),
		)
	),
	portal_ff_field(
		$portal_all,
		'terms_and_condition',
		5,
		array(
			'attributes' => array( 'name' => 'declaration' ),
			'settings'   => array(
				'tnc_html'     => 'I confirm that the information I have given is true to the best of my knowledge.',
				'has_checkbox' => true,
			) + $portal_required( true ),
		)
	),
);

$portal_form_json = wp_json_encode(
	array(
		'fields'       => $portal_fields,
		'submitButton' => array(
			'uniqElKey'      => 'el_portal_submit',
			'element'        => 'button',
			'attributes'     => array( 'type' => 'submit', 'class' => '' ),
			'settings'       => array(
				'align'           => 'left',
				'button_style'    => 'default',
				'container_class' => '',
				'help_message'    => '',
				'background_color' => '#C8102E',
				'button_size'     => 'md',
				'color'           => '#ffffff',
				'button_ui'       => array( 'type' => 'default', 'text' => 'Send complaint', 'img_url' => '' ),
			),
			'editor_options' => array( 'title' => 'Submit Button' ),
		),
	)
);

$portal_table   = $wpdb->prefix . 'fluentform_forms';
$portal_form_id = (int) get_option( 'portal_grievance_form_id' );
if ( $portal_form_id && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$portal_table} WHERE id = %d", $portal_form_id ) ) ) { // phpcs:ignore WordPress.DB
	$portal_form_id = 0;
}
$portal_row = array(
	'title'       => 'Grievance',
	'status'      => 'published',
	'type'        => 'form',
	'form_fields' => $portal_form_json,
	'has_payment' => 0,
	'updated_at'  => current_time( 'mysql' ),
);
if ( $portal_form_id ) {
	$wpdb->update( $portal_table, $portal_row, array( 'id' => $portal_form_id ) ); // phpcs:ignore WordPress.DB
} else {
	$wpdb->insert( $portal_table, $portal_row + array( 'created_by' => 1, 'created_at' => current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB
	$portal_form_id = (int) $wpdb->insert_id;
	update_option( 'portal_grievance_form_id', $portal_form_id );
}

// Settings: same as Fluent Forms' defaults (copied from its demo form), with our thank-you message.
$portal_meta  = $wpdb->prefix . 'fluentform_form_meta';
$portal_demo  = $wpdb->get_var( "SELECT value FROM {$portal_meta} WHERE form_id = 1 AND meta_key = 'formSettings'" ); // phpcs:ignore WordPress.DB
$portal_set   = $portal_demo ? json_decode( $portal_demo, true ) : array();
$portal_set['confirmation']['redirectTo']           = 'samePage';
$portal_set['confirmation']['samePageFormBehavior'] = 'hide_form';
$portal_set['confirmation']['messageToShow']        = '<p><strong>Thank you. Your complaint has been received.</strong></p><p>Your reference number is <strong>GRV-{submission.id}</strong>. We have e-mailed you an acknowledgement and will reply within 15 days.</p>';
$wpdb->delete( $portal_meta, array( 'form_id' => $portal_form_id, 'meta_key' => 'formSettings' ) ); // phpcs:ignore WordPress.DB
$wpdb->insert( $portal_meta, array( 'form_id' => $portal_form_id, 'meta_key' => 'formSettings', 'value' => wp_json_encode( $portal_set ) ) ); // phpcs:ignore WordPress.DB

// Put the form on the Grievance Officer page (below the text the owner/lawyer writes).
$portal_page = get_page_by_path( 'grievance-officer' );
if ( $portal_page && ! has_shortcode( $portal_page->post_content, 'fluentform' ) ) {
	wp_update_post(
		array(
			'ID'           => $portal_page->ID,
			'post_content' => $portal_page->post_content . "\n\n<!-- wp:heading -->\n<h2>Send a complaint</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>You will get a reference number and an e-mail at once. We reply within 15 days.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[fluentform id=\"{$portal_form_id}\"]\n<!-- /wp:shortcode -->",
		)
	);
}

WP_CLI::success( sprintf( 'Grievance form #%d on the Grievance Officer page.', $portal_form_id ) );
