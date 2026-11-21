<?php
/**
 * Task 18A: Hindi + English with Polylang. Safe to run again.
 *
 *   wp eval-file /scripts/setup-languages.php                 (languages, sections, pages, menus)
 *   wp eval-file /scripts/setup-languages.php hindi-default   (also makes Hindi the main language)
 *
 * On the LIVE site run it first WITHOUT "hindi-default", add the Hindi versions of the published
 * stories, and only then run it with "hindi-default" (otherwise the Hindi home page is empty).
 *
 * What it sets up:
 *   - languages Hindi (hi, hi_IN) and English (en, en_US); English at /en/, Hindi without prefix
 *     once it is the main language; no switching by browser language (most phones in India are set
 *     to English, which would send almost everyone away from Hindi);
 *   - everything that existed before is marked English (a Devanagari title is marked Hindi);
 *   - Hindi sections (समाचार, क्षेत्र, …) and Hindi pages (समुदाय, खबर भेजें, …) linked to the English ones;
 *   - Hindi menus, and an English | हिन्दी switch in both main menus.
 *
 * @package Portal
 */

if ( ! function_exists( 'PLL' ) || ! PLL() || ! isset( PLL()->model->languages ) ) {
	WP_CLI::error( 'Polylang is not active.' );
}
$portal_model = PLL()->model;
$portal_make_hindi_default = in_array( 'hindi-default', (array) ( $args ?? array() ), true );

/* ---------------------------------------------------------------- 1. Languages */

$portal_existing = wp_list_pluck( $portal_model->languages->get_list(), 'slug' );
$portal_wanted   = array(
	'en' => array( 'name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'rtl' => false, 'term_group' => 1, 'flag' => 'gb' ),
	'hi' => array( 'name' => 'हिन्दी', 'slug' => 'hi', 'locale' => 'hi_IN', 'rtl' => false, 'term_group' => 0, 'flag' => 'in' ),
);
foreach ( $portal_wanted as $portal_slug => $portal_lang_args ) {
	if ( in_array( $portal_slug, $portal_existing, true ) ) {
		continue;
	}
	$portal_result = $portal_model->languages->add( $portal_lang_args );
	if ( is_wp_error( $portal_result ) ) {
		WP_CLI::error( 'Could not add ' . $portal_slug . ': ' . $portal_result->get_error_message() );
	}
	WP_CLI::log( 'Added language: ' . $portal_lang_args['name'] );
	$portal_model->clean_languages_cache();
	if ( 'en' === $portal_slug ) {
		// Everything that exists now (stories, pages, sections, tags) is English.
		$portal_model->set_language_in_mass( 'en' );
		WP_CLI::log( 'Existing content marked English.' );
	}
}
$portal_model->clean_languages_cache();

// Any story or page with a Hindi (Devanagari) title is Hindi.
foreach ( get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'numberposts' => -1, 'lang' => '' ) ) as $portal_post ) {
	if ( preg_match( '/[\x{0900}-\x{097F}]/u', $portal_post->post_title ) && 'hi' !== pll_get_post_language( $portal_post->ID ) ) {
		pll_set_post_language( $portal_post->ID, 'hi' );
		WP_CLI::log( 'Marked Hindi: ' . $portal_post->post_title );
	}
}

/* ---------------------------------------------------------------- 2. Settings */

$portal_settings = array(
	'force_lang'    => 1,      // language in the address (/en/…).
	'hide_default'  => true,   // no prefix for the main language.
	'rewrite'       => true,   // /en/ rather than /language/en/.
	'browser'       => false,  // never switch by browser language.
	'redirect_lang' => false,
	'media_support' => false,  // one photo serves both versions.
	// Kept the same in both versions of a story: sections (translated), editorial fields (see
	// portal-core includes/languages.php), picture, sticky, comment settings.
	'sync'          => array( 'taxonomies', 'post_meta', '_thumbnail_id', 'sticky_posts', 'comment_status', 'ping_status' ),
);
foreach ( $portal_settings as $portal_key => $portal_value ) {
	$portal_set = PLL()->options->set( $portal_key, $portal_value );
	if ( is_wp_error( $portal_set ) && $portal_set->has_errors() ) {
		WP_CLI::warning( "Setting $portal_key: " . $portal_set->get_error_message() );
	}
}
PLL()->options->save();
WP_CLI::log( 'Polylang settings saved.' );

/* ---------------------------------------------------------------- 3. Hindi sections */

$portal_sections = array(
	'news'            => array( 'समाचार', 'samachar', 'छत्तीसगढ़ और मध्य भारत की ताज़ा खबरें.' ),
	'region'          => array( 'क्षेत्र', 'kshetra', 'ज़िलों और शहरों की खबरें.' ),
	'industry'        => array( 'उद्योग', 'udyog', 'कोयला, स्टील और उद्योग जगत की खबरें.' ),
	'logistics'       => array( 'लॉजिस्टिक्स', 'logistics-hindi', 'रेल, सड़क, माल ढुलाई और परिवहन की खबरें.' ),
	'crime'           => array( 'अपराध', 'apradh', 'पुलिस और अदालत से पुष्ट अपराध की खबरें.' ),
	'community-blogs' => array( 'सामुदायिक ब्लॉग', 'samudayik-blog', 'हमारे पाठकों और लेखकों की कहानियाँ और अनुभव.' ),
	'case-files'      => array( 'केस फ़ाइल्स', 'case-files-hindi', 'गहन पड़ताल.' ),
);
foreach ( $portal_sections as $portal_en_slug => list( $portal_name, $portal_hi_slug, $portal_desc ) ) {
	$portal_en = get_terms( array( 'taxonomy' => 'category', 'slug' => $portal_en_slug, 'hide_empty' => false, 'lang' => '' ) );
	if ( ! $portal_en || is_wp_error( $portal_en ) ) {
		WP_CLI::warning( "No English section '$portal_en_slug'." );
		continue;
	}
	$portal_en_id = (int) $portal_en[0]->term_id;
	$portal_hi_id = (int) pll_get_term( $portal_en_id, 'hi' );
	if ( $portal_hi_id ) {
		// Already there (Polylang also creates a Hindi default section by itself): name it properly.
		wp_update_term( $portal_hi_id, 'category', array( 'name' => $portal_name, 'slug' => $portal_hi_slug, 'description' => $portal_desc ) );
	} else {
		$portal_new = wp_insert_term( $portal_name, 'category', array( 'slug' => $portal_hi_slug, 'description' => $portal_desc ) );
		if ( is_wp_error( $portal_new ) ) {
			WP_CLI::warning( "$portal_en_slug: " . $portal_new->get_error_message() );
			continue;
		}
		$portal_hi_id = (int) $portal_new['term_id'];
		pll_set_term_language( $portal_hi_id, 'hi' );
	}
	pll_save_term_translations( array( 'en' => $portal_en_id, 'hi' => $portal_hi_id ) );
	WP_CLI::log( "Section: $portal_en_slug = $portal_name (/$portal_hi_slug/)" );
}

/* ---------------------------------------------------------------- 4. Hindi pages */

$portal_p = static fn( $text ) => "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->";
$portal_lawyer = $portal_p( 'इस पृष्ठ का हिन्दी पाठ तैयार किया जा रहा है। तब तक कृपया <a href="%s">अंग्रेज़ी संस्करण</a> देखें।' );
$portal_pages  = array(
	'community'            => array( 'समुदाय', 'samuday', $portal_p( 'छत्तीसगढ़ और मध्य भारत के लोगों की कहानियाँ, अनुभव और सीरीज़: ड्राइवर, दुकानदार, छात्र, कामगार और पड़ोसी। हर कहानी प्रकाशन से पहले हमारे संपादक पढ़ते हैं।' ) ),
	'send-news'            => array( 'खबर भेजें', 'khabar-bhejen', $portal_p( 'आपके इलाके में कुछ ऐसा हुआ है जो लोगों को जानना चाहिए: दुर्घटना, बंद सड़क, पानी या बिजली की समस्या, ठगी, या कोई स्थानीय उपलब्धि? हमें बताइए। प्रकाशन से पहले हमारे संपादक हर सूचना की जाँच करते हैं।' ) . "\n\n<!-- wp:shortcode -->\n[portal_tip_form]\n<!-- /wp:shortcode -->" ),
	'write'                => array( 'लिखें', 'likhen', null ),
	'edit-story'           => array( 'कहानी संपादित करें', 'kahani-sampadit-karen', null ),
	'account'              => array( 'मेरा खाता', 'mera-khata', null ),
	'about'                => array( 'हमारे बारे में', 'hamare-bare-mein', 'lawyer' ),
	'grievance-officer'    => array( 'शिकायत अधिकारी', 'shikayat-adhikari', null ),
	'editorial-policy'     => array( 'संपादकीय एवं सुधार नीति', 'sampadkiya-evam-sudhar-niti', 'lawyer' ),
	'community-guidelines' => array( 'सामुदायिक दिशानिर्देश', 'samudayik-dishanirdesh', 'lawyer' ),
	'terms-of-use'         => array( 'उपयोग की शर्तें', 'upyog-ki-sharten', 'lawyer' ),
	'privacy-policy'       => array( 'गोपनीयता नीति', 'gopniyata-niti', 'lawyer' ),
);
foreach ( $portal_pages as $portal_en_slug => list( $portal_title, $portal_hi_slug, $portal_content ) ) {
	$portal_en = get_page_by_path( $portal_en_slug );
	if ( ! $portal_en ) {
		WP_CLI::warning( "No English page '$portal_en_slug'." );
		continue;
	}
	if ( pll_get_post( $portal_en->ID, 'hi' ) ) {
		continue; // already translated; editors may have changed it, keep their text.
	}
	if ( null === $portal_content ) {
		$portal_content = $portal_en->post_content; // functional page (forms, account): same blocks and shortcodes.
	} elseif ( 'lawyer' === $portal_content ) {
		$portal_content = sprintf( $portal_lawyer, esc_url( get_permalink( $portal_en ) ) );
	}
	$portal_hi = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $portal_title,
			'post_name'    => $portal_hi_slug,
			'post_content' => $portal_content,
			'post_author'  => (int) $portal_en->post_author,
		),
		true
	);
	if ( is_wp_error( $portal_hi ) ) {
		WP_CLI::warning( "$portal_en_slug: " . $portal_hi->get_error_message() );
		continue;
	}
	pll_set_post_language( $portal_hi, 'hi' );
	pll_save_post_translations( array( 'en' => $portal_en->ID, 'hi' => $portal_hi ) );
	WP_CLI::log( "Page: /$portal_en_slug/ = $portal_title (/$portal_hi_slug/)" );
}

/* ---------------------------------------------------------------- 5. Menus with the language switch */

$portal_switch_item = static function ( $menu_id ) {
	foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $item ) {
		if ( '#pll_switcher' === $item->url ) {
			return;
		}
	}
	$item_id = wp_update_nav_menu_item(
		$menu_id,
		0,
		array(
			'menu-item-title'  => 'Languages',
			'menu-item-url'    => '#pll_switcher',
			'menu-item-type'   => 'custom',
			'menu-item-status' => 'publish',
		)
	);
	if ( ! is_wp_error( $item_id ) ) {
		// Show only the OTHER language: "English" on Hindi pages, "हिन्दी" on English pages.
		update_post_meta( $item_id, '_pll_menu_item', array( 'hide_if_no_translation' => 0, 'hide_current' => 1, 'force_home' => 0, 'show_flags' => 0, 'show_names' => 1, 'dropdown' => 0 ) );
	}
};
$portal_find_or_make_menu = static function ( $name ) {
	$menu = wp_get_nav_menu_object( $name );
	return $menu ? (int) $menu->term_id : (int) wp_create_nav_menu( $name );
};

$portal_en_main   = wp_get_nav_menu_object( 'main-menu' );
$portal_en_footer = wp_get_nav_menu_object( 'footer-menu' );
if ( $portal_en_main ) {
	$portal_switch_item( $portal_en_main->term_id );
}

// Hindi main menu: होम, the Hindi sections, समुदाय, then the switch.
$portal_hi_main = $portal_find_or_make_menu( 'Main Menu Hindi' );
if ( ! wp_get_nav_menu_items( $portal_hi_main ) ) {
	wp_update_nav_menu_item( $portal_hi_main, 0, array( 'menu-item-title' => 'होम', 'menu-item-url' => home_url( '/' ), 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) );
	foreach ( array( 'news', 'region', 'industry', 'logistics', 'crime' ) as $portal_slug ) {
		$portal_term = portal_category( $portal_slug, 'hi' );
		if ( $portal_term && 'hi' === pll_get_term_language( $portal_term->term_id ) ) {
			wp_update_nav_menu_item( $portal_hi_main, 0, array( 'menu-item-object-id' => $portal_term->term_id, 'menu-item-object' => 'category', 'menu-item-type' => 'taxonomy', 'menu-item-status' => 'publish' ) );
		}
	}
	$portal_hi_community = portal_page( 'community', 'hi' );
	if ( $portal_hi_community && 'hi' === pll_get_post_language( $portal_hi_community->ID ) ) {
		wp_update_nav_menu_item( $portal_hi_main, 0, array( 'menu-item-object-id' => $portal_hi_community->ID, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-title' => 'समुदाय', 'menu-item-status' => 'publish' ) );
	}
}
$portal_switch_item( $portal_hi_main );

// Hindi footer: the Hindi legal pages.
$portal_hi_footer = $portal_find_or_make_menu( 'Footer Menu Hindi' );
if ( ! wp_get_nav_menu_items( $portal_hi_footer ) ) {
	foreach ( array( 'about', 'editorial-policy', 'community-guidelines', 'terms-of-use', 'privacy-policy', 'grievance-officer' ) as $portal_slug ) {
		$portal_page_hi = portal_page( $portal_slug, 'hi' );
		if ( $portal_page_hi && 'hi' === pll_get_post_language( $portal_page_hi->ID ) ) {
			wp_update_nav_menu_item( $portal_hi_footer, 0, array( 'menu-item-object-id' => $portal_page_hi->ID, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
		}
	}
}

// A Hindi story must sit in the Hindi sections (its sections were set before the Hindi ones existed).
foreach ( get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1, 'lang' => 'hi' ) ) as $portal_post ) {
	$portal_cats  = wp_get_post_categories( $portal_post->ID );
	$portal_fixed = array();
	foreach ( $portal_cats as $portal_cat ) {
		$portal_t       = pll_get_term( $portal_cat, 'hi' );
		$portal_fixed[] = $portal_t ? (int) $portal_t : (int) $portal_cat;
	}
	if ( $portal_fixed && $portal_fixed !== array_map( 'intval', $portal_cats ) ) {
		wp_set_post_categories( $portal_post->ID, $portal_fixed );
		WP_CLI::log( 'Hindi sections for: ' . $portal_post->post_title );
	}
}

// Which menu shows where, per language.
$portal_theme = get_stylesheet();
$portal_nav   = (array) PLL()->options['nav_menus'];
foreach ( array( 'primary' => array( $portal_en_main, $portal_hi_main ), 'mobile' => array( $portal_en_main, $portal_hi_main ), 'footer' => array( $portal_en_footer, $portal_hi_footer ) ) as $portal_location => list( $portal_en_menu, $portal_hi_menu ) ) {
	$portal_nav[ $portal_theme ][ $portal_location ] = array(
		'en' => $portal_en_menu ? (int) $portal_en_menu->term_id : 0,
		'hi' => (int) $portal_hi_menu,
	);
}
PLL()->options->set( 'nav_menus', $portal_nav );
PLL()->options->save();
WP_CLI::log( 'Menus: Hindi and English menus assigned, language switch added.' );

/* ---------------------------------------------------------------- 6. Main language */

if ( $portal_make_hindi_default && 'hi' !== pll_default_language() ) {
	$portal_result = $portal_model->languages->update_default( 'hi' );
	if ( is_wp_error( $portal_result ) && $portal_result->has_errors() ) {
		WP_CLI::warning( 'Could not make Hindi the main language: ' . $portal_result->get_error_message() );
	} else {
		WP_CLI::log( 'Hindi is now the main language (/), English at /en/.' );
	}
}
// The theme menu locations (used by WordPress itself) point at the main language's menus.
$portal_locations = (array) get_theme_mod( 'nav_menu_locations', array() );
$portal_main_lang = pll_default_language();
foreach ( $portal_nav[ $portal_theme ] as $portal_location => $portal_by_lang ) {
	if ( ! empty( $portal_by_lang[ $portal_main_lang ] ) ) {
		$portal_locations[ $portal_location ] = (int) $portal_by_lang[ $portal_main_lang ];
	}
}
set_theme_mod( 'nav_menu_locations', $portal_locations );

update_option( 'portal_flush_rewrites', 1 );
flush_rewrite_rules( false );
WP_CLI::success( 'Languages ready. Main language: ' . pll_default_language() . '. Languages: ' . implode( ', ', pll_languages_list() ) . '.' );
