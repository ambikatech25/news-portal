<?php
/**
 * Task 11: the "Write a story" form and pages. Safe to run again (rebuilds the form's
 * fields and settings, keeps the same form and pages).
 *
 *   docker compose run --rm -T wpcli eval-file /scripts/setup-writing.php
 *
 * The form is a WP User Frontend form (editable later in wp-admin → User Frontend → Post
 * Forms). The rules that matter are enforced by portal-core whatever the form says:
 * Contributors always go to Pending review, editor-only categories are always blocked.
 *
 * @package Portal
 */

if ( ! function_exists( 'wpuf_insert_form_field' ) || ! function_exists( 'portal_restricted_category_ids' ) ) {
	WP_CLI::error( 'WP User Frontend and portal-core must be active.' );
}

/* ---------- The form */

$portal_form_id = (int) get_option( 'portal_post_form_id' );
if ( ! $portal_form_id || 'wpuf_forms' !== get_post_type( $portal_form_id ) ) {
	$portal_form_id = (int) wp_insert_post(
		array(
			'post_title'     => 'Write a story',
			'post_type'      => 'wpuf_forms',
			'post_status'    => 'publish',
			'comment_status' => 'closed',
		)
	);
	update_option( 'portal_post_form_id', $portal_form_id );
}

// Start clean: remove the old fields, then add ours in order.
foreach ( get_posts( array( 'post_type' => 'wpuf_input', 'post_parent' => $portal_form_id, 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ) ) as $portal_old ) {
	wp_delete_post( $portal_old, true );
}

// Writers may choose any category except the editor-only ones (News, Crime, Case Files).
// Note: the default category is Community Blogs (Task 4 renamed "Uncategorized"), so a
// story whose section is refused falls back into Community Blogs.
$portal_hidden_cats = portal_restricted_category_ids();

$portal_common = array(
	'is_new'          => false,
	'width'           => 'large',
	'css'             => '',
	'default'         => '',
	'wpuf_cond'       => null,
	'wpuf_visibility' => array(
		'selected' => 'everyone',
		'choices'  => array(),
	),
);

$portal_fields = array(
	array(
		'input_type'  => 'text',
		'template'    => 'post_title',
		'name'        => 'post_title',
		'label'       => 'Headline',
		'required'    => 'yes',
		'is_meta'     => 'no',
		'placeholder' => 'A clear, factual headline',
		'help'        => '',
		'size'        => '40',
	),
	array(
		'input_type'   => 'textarea',
		'template'     => 'post_content',
		'name'         => 'post_content',
		'label'        => 'Story',
		'required'     => 'yes',
		'is_meta'      => 'no',
		'placeholder'  => '',
		'help'         => 'Who, what, where, when. Do not name victims of crime, minors or witnesses.',
		'rows'         => '12',
		'cols'         => '25',
		'rich'         => 'teeny',
		'insert_image' => 'yes',
	),
	array(
		'input_type'   => 'image_upload',
		'template'     => 'featured_image',
		'name'         => 'featured_image',
		'label'        => 'Main picture',
		'required'     => 'no',
		'is_meta'      => 'no',
		'help'         => 'JPG or PNG, up to 2 MB. Only photos you took or may use.',
		'button_label' => 'Choose picture',
		'max_size'     => '2048',
		'count'        => '1',
	),
	array(
		'input_type'   => 'taxonomy',
		'template'     => 'taxonomy',
		'name'         => 'category',
		'label'        => 'Section',
		'required'     => 'yes',
		'is_meta'      => 'no',
		'help'         => '',
		'type'         => 'select',
		'first'        => '- Choose a section -',
		'show_inline'  => 'inline',
		'orderby'      => 'name',
		'order'        => 'ASC',
		'exclude_type' => 'exclude',
		'exclude'      => array_map( 'strval', $portal_hidden_cats ),
	),
	array(
		'input_type'  => 'text',
		'template'    => 'post_tags',
		'name'        => 'tags',
		'label'       => 'Tags',
		'required'    => 'no',
		'is_meta'     => 'no',
		'placeholder' => 'e.g. Raipur, trucking, steel',
		'help'        => 'Separate tags with commas.',
		'size'        => '40',
	),
	// Honest disclosure for editors (stored as post meta portal_ai_assisted = "yes").
	array(
		'input_type'       => 'checkbox',
		'template'         => 'checkbox_field',
		'name'             => 'portal_ai_assisted',
		'label'            => 'AI tools',
		'required'         => 'no',
		'is_meta'          => 'yes',
		'help'             => 'Tick this if ChatGPT or another AI tool helped write the story. It is only shown to editors. You are still responsible for every fact.',
		'options'          => array( 'yes' => 'AI tools helped write this story' ),
		'selected'         => array(),
		'inline'           => 'no',
		'show_in_post'     => 'no',
		'hide_field_label' => 'no',
	),
);

foreach ( $portal_fields as $portal_order => $portal_field ) {
	wpuf_insert_form_field( $portal_form_id, array_merge( $portal_common, $portal_field ), false, $portal_order );
}

/* ---------- Form settings */

update_post_meta(
	$portal_form_id,
	'wpuf_form_settings',
	array(
		'post_type'        => 'post',
		// "publish" here: Authors (trusted writers) publish directly; portal-core turns a
		// Contributor's "publish" into Pending review.
		'post_status'      => 'publish',
		'post_format'      => '0',
		'default_cat'      => '-1',
		'post_permission'  => 'role_base',
		'role_base'        => 'true',
		'roles'            => array( 'administrator', 'editor', 'author', 'contributor' ),
		'guest_post'       => 'false',
		'message_restrict' => 'Please {login} to write a story.',
		'redirect_to'      => 'same',
		'message'          => 'Thank you! Your story has been saved. You can find it under My Account → Posts.',
		'comment_status'   => 'open',
		'submit_text'      => 'Submit story',
		'draft_post'       => 'true',
		'edit_post_status' => 'publish',
		'edit_redirect_to' => 'same',
		'update_message'   => 'Your changes have been saved.',
		'update_text'      => 'Save changes',
		'subscription'     => '- Select -',
		'form_template'    => '',
		'notification'     => array(
			// Off since Task 13: PublishPress e-mails editors when any story moves to review
			// (from this form or the block editor), so this would be a duplicate.
			'new'          => 'off',
			'new_to'       => get_option( 'admin_email' ),
			'new_subject'  => 'New story from {author}: {post_title}',
			'new_body'     => "A writer submitted a story.\r\n\r\nTitle: {post_title}\r\nWriter: {author}\r\nReview it here: {editlink}",
			'edit'         => 'off',
			'edit_to'      => get_option( 'admin_email' ),
			'edit_subject' => '',
			'edit_body'    => '',
		),
	)
);
update_post_meta( $portal_form_id, 'wpuf_form_version', defined( 'WPUF_VERSION' ) ? WPUF_VERSION : '' );

/* ---------- Pages */

/**
 * Create a page if missing; return its ID.
 *
 * @param string $slug    Slug.
 * @param string $title   Title.
 * @param string $content Content.
 * @return int
 */
function portal_setup_page( $slug, $title, $content ) {
	$page = get_page_by_path( $slug );
	if ( $page ) {
		return $page->ID;
	}
	return (int) wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $content,
		)
	);
}

$portal_write_page = portal_setup_page( 'write', 'Write a story', "<!-- wp:shortcode -->\n[portal_write]\n<!-- /wp:shortcode -->" );
$portal_edit_page  = portal_setup_page( 'edit-story', 'Edit story', "<!-- wp:shortcode -->\n[wpuf_edit]\n<!-- /wp:shortcode -->" );

// WP User Frontend: this form for "Submit Post" in My Account, and the edit page.
$portal_posting                      = (array) get_option( 'wpuf_frontend_posting', array() );
$portal_posting['default_post_form'] = (string) $portal_form_id;
$portal_posting['edit_page_id']      = (string) $portal_edit_page;
update_option( 'wpuf_frontend_posting', $portal_posting );

WP_CLI::success( sprintf( 'Story form #%d, Write page #%d, Edit page #%d.', $portal_form_id, $portal_write_page, $portal_edit_page ) );
