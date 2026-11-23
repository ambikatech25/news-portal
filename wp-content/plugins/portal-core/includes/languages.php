<?php
/**
 * Hindi + English (Task 18A), with Polylang (free).
 *
 * Hindi is the main language (site root), English is at /en/. News, Region, Industry, Logistics
 * and Crime appear in both languages; Community Blogs in the writer's language.
 *
 * This file:
 *   - helpers that find a page / section in the reader's language (portal_page_url( 'write' ),
 *     portal_category( 'news' )) and fall back to the English original when Polylang is off;
 *   - short English-letter web addresses for Hindi titles ("रायपुर के पुराने बाज़ार" ->
 *     raipur-ke-purane-bazar) instead of long %e0%a4… addresses;
 *   - which story fields are shared between the two language versions (Verified, FIR, Breaking…);
 *   - the language choice on the Write a story form.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------ Basics */

/**
 * Whether Polylang is running with languages set up.
 *
 * @return bool
 */
function portal_has_languages() {
	return function_exists( 'pll_languages_list' ) && function_exists( 'pll_get_post' ) && pll_languages_list();
}

/**
 * The reader's language: 'hi' or 'en' ('' when Polylang is off or no language applies, e.g. WP-CLI).
 *
 * @return string
 */
function portal_lang() {
	if ( ! portal_has_languages() ) {
		return '';
	}
	$lang = pll_current_language();
	return $lang ? (string) $lang : '';
}

/**
 * Language of a post ('' when unknown).
 *
 * @param int|WP_Post $post Post.
 * @return string
 */
function portal_post_lang( $post ) {
	if ( ! portal_has_languages() || ! function_exists( 'pll_get_post_language' ) ) {
		return '';
	}
	$post = get_post( $post );
	return $post ? (string) pll_get_post_language( $post->ID ) : '';
}

/* ------------------------------------------------------------------ Pages and sections in the reader's language */

/**
 * A page by its English address (e.g. 'write'), in the given or current language when a
 * translation exists, otherwise the English page.
 *
 * @param string $slug English page slug.
 * @param string $lang Language ('' = current).
 * @return WP_Post|null
 */
function portal_page( $slug, $lang = '' ) {
	$page = get_page_by_path( $slug );
	if ( ! $page ) {
		return null;
	}
	$lang = $lang ? $lang : portal_lang();
	if ( $lang && portal_has_languages() ) {
		$translated = pll_get_post( $page->ID, $lang );
		if ( $translated && 'publish' === get_post_status( $translated ) ) {
			return get_post( $translated );
		}
	}
	return $page;
}

/**
 * Address of a page by its English slug, in the reader's language.
 *
 * @param string $slug     English page slug.
 * @param string $fallback Address when the page does not exist.
 * @return string
 */
function portal_page_url( $slug, $fallback = '' ) {
	$page = portal_page( $slug );
	return $page ? (string) get_permalink( $page ) : (string) $fallback;
}

/**
 * The English original of a category, found by slug in any language (Polylang may otherwise hide
 * it while a Hindi page is shown).
 *
 * @param string $slug English category slug.
 * @return WP_Term|null
 */
function portal_category_source( $slug ) {
	$args = array(
		'taxonomy'   => 'category',
		'slug'       => $slug,
		'hide_empty' => false,
		'number'     => 1,
	);
	if ( portal_has_languages() ) {
		$args['lang'] = ''; // every language.
	}
	$terms = get_terms( $args );
	return ( $terms && ! is_wp_error( $terms ) ) ? $terms[0] : null;
}

/**
 * A category by its English slug, in the given or current language (translation when it exists).
 *
 * @param string $slug English category slug ('news', 'community-blogs', …).
 * @param string $lang Language ('' = current).
 * @return WP_Term|null
 */
function portal_category( $slug, $lang = '' ) {
	$term = portal_category_source( $slug );
	if ( ! $term ) {
		return null;
	}
	$lang = $lang ? $lang : portal_lang();
	if ( $lang && portal_has_languages() && function_exists( 'pll_get_term' ) ) {
		$translated = pll_get_term( $term->term_id, $lang );
		if ( $translated ) {
			$t = get_term( $translated, 'category' );
			if ( $t instanceof WP_Term ) {
				return $t;
			}
		}
	}
	return $term;
}

/**
 * IDs of a category in every language (English original + translations).
 *
 * @param string $slug English category slug.
 * @return int[]
 */
function portal_category_ids_all( $slug ) {
	$term = portal_category_source( $slug );
	if ( ! $term ) {
		return array();
	}
	$ids = array( (int) $term->term_id );
	if ( portal_has_languages() && function_exists( 'pll_get_term_translations' ) ) {
		$ids = array_merge( $ids, array_map( 'intval', array_values( (array) pll_get_term_translations( $term->term_id ) ) ) );
	}
	return array_values( array_unique( $ids ) );
}

/**
 * The published language versions of a story, for the हिन्दी | English switch under the headline.
 * Hindi first. Each item: id, lang, name (in its own language), url, current (bool).
 * Fewer than two items = no other version to switch to.
 *
 * @param WP_Post|int $post Story.
 * @return array<int, array{id: int, lang: string, name: string, url: string, current: bool}>
 */
function portal_story_versions( $post ) {
	$post = get_post( $post );
	if ( ! $post || ! portal_has_languages() || ! function_exists( 'pll_get_post_translations' ) ) {
		return array();
	}
	$versions     = array();
	$translations = (array) pll_get_post_translations( $post->ID );
	foreach ( array( 'hi', 'en' ) as $lang ) {
		$id       = (int) ( $translations[ $lang ] ?? 0 );
		$language = PLL()->model->get_language( $lang );
		if ( ! $id || ! $language || ! portal_is_public_post( get_post( $id ) ) ) {
			continue;
		}
		$versions[] = array(
			'id'      => $id,
			'lang'    => $lang,
			'name'    => $language->name,
			'url'     => get_permalink( $id ),
			'current' => $id === (int) $post->ID,
		);
	}
	return $versions;
}

/* ------------------------------------------------------------------ Short English-letter addresses for Hindi titles */

/**
 * Write Hindi (Devanagari) text in English letters, the way people type Hindi on phones:
 * "रायपुर के पुराने बाज़ार" -> "raypur ke purane bazar". Not a perfect transliteration, but short,
 * readable and safe for web addresses. Other characters are kept as they are.
 *
 * @param string $text Text.
 * @return string
 */
function portal_hindi_to_latin( $text ) {
	static $consonants = array(
		'क' => 'k', 'ख' => 'kh', 'ग' => 'g', 'घ' => 'gh', 'ङ' => 'n',
		'च' => 'ch', 'छ' => 'chh', 'ज' => 'j', 'झ' => 'jh', 'ञ' => 'n',
		'ट' => 't', 'ठ' => 'th', 'ड' => 'd', 'ढ' => 'dh', 'ण' => 'n',
		'त' => 't', 'थ' => 'th', 'द' => 'd', 'ध' => 'dh', 'न' => 'n',
		'प' => 'p', 'फ' => 'ph', 'ब' => 'b', 'भ' => 'bh', 'म' => 'm',
		'य' => 'y', 'र' => 'r', 'ल' => 'l', 'ळ' => 'l', 'व' => 'v',
		'श' => 'sh', 'ष' => 'sh', 'स' => 's', 'ह' => 'h',
		'क़' => 'q', 'ख़' => 'kh', 'ग़' => 'g', 'ज़' => 'z', 'ड़' => 'd', 'ढ़' => 'rh', 'फ़' => 'f', 'य़' => 'y',
	);
	static $vowels = array(
		'अ' => 'a', 'आ' => 'aa', 'इ' => 'i', 'ई' => 'i', 'उ' => 'u', 'ऊ' => 'u', 'ऋ' => 'ri',
		'ए' => 'e', 'ऐ' => 'ai', 'ओ' => 'o', 'औ' => 'au', 'ऑ' => 'o',
	);
	static $signs = array(
		'ा' => 'a', 'ि' => 'i', 'ी' => 'i', 'ु' => 'u', 'ू' => 'u', 'ृ' => 'ri',
		'े' => 'e', 'ै' => 'ai', 'ो' => 'o', 'ौ' => 'au', 'ॉ' => 'o', 'ॅ' => 'e',
	);
	static $marks = array( 'ं' => 'n', 'ँ' => 'n', 'ः' => 'h', '़' => '', '।' => ' ', '॥' => ' ' );
	static $digits = array( '०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9' );

	if ( ! preg_match( '/[\x{0900}-\x{097F}]/u', $text ) ) {
		return $text;
	}
	// Combine consonant + nukta into one letter first (ज + ़ = ज़).
	$text  = preg_replace( '/([कखगजडढफय])\x{093C}/u', '$1़', $text );
	$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
	$out          = '';
	$count        = count( $chars );
	$vowel_before = false; // was the sound just before this letter a vowel?
	$half         = '';    // the half letter just before this one (क् in क्य), if any.
	for ( $i = 0; $i < $count; $i++ ) {
		$c    = $chars[ $i ];
		$pair = $c . ( $chars[ $i + 1 ] ?? '' );
		if ( isset( $consonants[ $pair ] ) ) { // letter with nukta.
			$c = $pair;
			++$i;
		}
		if ( isset( $consonants[ $c ] ) ) {
			$out .= $consonants[ $c ];
			$next = $chars[ $i + 1 ] ?? '';
			$after_half = $half;
			$half       = '';
			if ( '्' === $next ) {          // half letter: no vowel.
				++$i;
				$vowel_before = false;
				$half         = $c;
				continue;
			}
			if ( isset( $signs[ $next ] ) ) { // vowel sign follows.
				$vowel_before = false;          // the sign itself sets it below.
				continue;
			}
			// Hindi drops the short "a" at the end of a word (बाज़ार -> bazar, not bazara) and in
			// the middle between a vowel and a voiced letter (रायपुर -> raypur, सरकार -> sarkar),
			// but keeps it before a word-final letter (खबर -> khabar), and at the end after a
			// joined र or य (महाराष्ट्र -> maharashtra, मध्य -> madhya; not after र्: दुर्ग -> durg).
			$at_word_end = ( '' === $next ) || ! preg_match( '/[\x{0900}-\x{097F}]/u', $next ) || isset( $marks[ $next ] ) && ' ' === $marks[ $next ];
			$drop        = $at_word_end && ! ( $after_half && 'र' !== $after_half && in_array( $c, array( 'र', 'य' ), true ) );
			$drop        = $drop || in_array( $next, array( 'अ', 'आ' ), true ); // joined words: पिकअप -> pikap (but नई -> nai).
			if ( ! $drop && $vowel_before && isset( $consonants[ $next ] ) ) {
				$skip  = isset( $consonants[ $next . ( $chars[ $i + 2 ] ?? '' ) ] ) ? 3 : 2; // next letter may carry a nukta.
				$after = $chars[ $i + $skip ] ?? '';
				$drop  = isset( $signs[ $after ] ) || isset( $consonants[ $after ] );
			}
			if ( ! $drop ) {
				$out .= 'a';
			}
			$vowel_before = ! $drop;
			continue;
		}
		if ( 'ं' !== $c && 'ँ' !== $c ) { // a nasal dot belongs to the vowel before it.
			$vowel_before = isset( $vowels[ $c ] ) || isset( $signs[ $c ] );
		}
		$half         = '';
		if ( isset( $vowels[ $c ] ) ) {
			$out .= $vowels[ $c ];
		} elseif ( isset( $signs[ $c ] ) ) {
			$out .= $signs[ $c ];
		} elseif ( isset( $marks[ $c ] ) ) {
			$out .= $marks[ $c ];
		} elseif ( isset( $digits[ $c ] ) ) {
			$out .= $digits[ $c ];
		} elseif ( '्' === $c ) {
			continue;
		} else {
			$out .= $c;
		}
	}
	return $out;
}

/**
 * Web addresses (slugs) for posts, pages, sections and profiles: Hindi titles become short
 * English-letter addresses, at most 10 words, instead of long %e0%a4… codes.
 *
 * @param string $title     Title being turned into a slug.
 * @param string $raw_title Original title.
 * @param string $context   'save' when a slug is being created.
 * @return string
 */
function portal_hindi_slug( $title, $raw_title = '', $context = 'display' ) {
	if ( 'save' !== $context ) {
		return $title;
	}
	$title = str_replace( '₹', ' rs', (string) $title ); // ₹88 -> rs88 (not %e2%82%b9), in both languages.
	if ( ! preg_match( '/[\x{0900}-\x{097F}]/u', $title ) ) {
		return $title;
	}
	$latin = portal_hindi_to_latin( $title );
	$words = preg_split( '/[\s\-:,.;!?\'"“”‘’()\[\]]+/u', trim( $latin ), -1, PREG_SPLIT_NO_EMPTY );
	return implode( ' ', array_slice( $words, 0, 10 ) );
}
add_filter( 'sanitize_title', 'portal_hindi_slug', 9, 3 );

/**
 * Hindi tags get English-letter addresses, so "दुर्ग" and the English tag "Durg" share the
 * address durg. WordPress finds existing tags by address first, which would put the English tag
 * on a Hindi story. Look only among tags of the reader's language; a new Hindi tag then gets its
 * own address (durg-hi) from Polylang.
 *
 * @param array      $args     Query used by term_exists().
 * @param int|string $term     Term ID or name being looked up.
 * @param string     $taxonomy Taxonomy.
 * @return array
 */
function portal_term_exists_in_language( $args, $term, $taxonomy ) {
	$lang = portal_lang();
	// Only lookups by name (a tag typed by a writer); a lookup by ID (delete, update) must find any language.
	if ( $lang && ! is_int( $term ) && ! ctype_digit( (string) $term ) && function_exists( 'pll_is_translated_taxonomy' ) && pll_is_translated_taxonomy( (string) $taxonomy ) ) {
		$args['lang'] = $lang;
	}
	return $args;
}
add_filter( 'term_exists_default_query_args', 'portal_term_exists_in_language', 10, 3 );

/**
 * A tag created outside wp-admin (Write page, API) is in the reader's language. Polylang only
 * knows this on its own admin screens; telling it lets it give "दुर्ग" its own address (durg-hi)
 * instead of WordPress handing back the English "Durg".
 *
 * @param PLL_Language|null $lang     Language found so far.
 * @param string            $taxonomy Taxonomy.
 * @return PLL_Language|null
 */
function portal_new_term_language( $lang, $taxonomy ) {
	if ( $lang || ! portal_lang() || ! pll_is_translated_taxonomy( $taxonomy ) ) {
		return $lang;
	}
	$found = PLL()->model->get_language( portal_lang() );
	return $found ? $found : null;
}
add_filter( 'pll_inserted_term_language', 'portal_new_term_language', 20, 2 );

/* ------------------------------------------------------------------ Shared fields between language versions */

/**
 * When an editor adds the other-language version of a story, and whenever either version is
 * saved, these editorial fields stay the same in both: Verified, FIR number, police station,
 * Sensitive (and its second approval), Premium, Breaking. Likes, views, series and picks belong
 * to each version separately.
 *
 * @param string[] $keys Meta keys Polylang would copy.
 * @return string[]
 */
function portal_shared_translation_fields( $keys ) {
	$shared = array( 'portal_verified', 'portal_fir_number', 'portal_police_station', 'portal_sensitive', 'portal_second_approver', 'portal_is_premium', 'portal_breaking' );
	return array_values( array_intersect( (array) $keys, $shared ) );
}
add_filter( 'pll_copy_post_metas', 'portal_shared_translation_fields' );

/**
 * Writers fill in one bio. Polylang shows a separate per-language bio on /en/; when that is
 * empty, show the writer's main bio instead of nothing.
 *
 * @param mixed  $value    Value from earlier filters (Polylang's per-language bio), or null.
 * @param int    $user_id  User ID.
 * @param string $meta_key Meta key.
 * @return mixed Null lets WordPress read the main bio.
 */
function portal_bio_fallback( $value, $user_id, $meta_key ) {
	if ( 'description' !== $meta_key || null === $value ) {
		return $value;
	}
	$empty = is_array( $value ) ? ( '' === implode( '', array_map( 'strval', $value ) ) ) : ( '' === (string) $value );
	return $empty ? null : $value;
}
add_filter( 'get_user_metadata', 'portal_bio_fallback', 20, 3 );

/* ------------------------------------------------------------------ Language of new stories sent through the API */

/**
 * A new story sent through the API (app, block editor, scripts) without a language takes the
 * language of its sections: English sections -> English story. Without this Polylang files it
 * under the main language (Hindi) and quietly swaps its sections for the Hindi ones.
 *
 * @param mixed           $response Response so far.
 * @param array           $handler  Route handler.
 * @param WP_REST_Request $request  Request.
 * @return mixed
 */
function portal_story_lang_from_sections( $response, $handler, $request ) {
	if ( ! portal_has_languages() || 'POST' !== $request->get_method() || '/wp/v2/posts' !== untrailingslashit( $request->get_route() ) || $request->get_param( 'lang' ) ) {
		return $response;
	}
	$cats = array_filter( array_map( 'intval', (array) $request->get_param( 'categories' ) ) );
	foreach ( $cats as $cat ) {
		$lang = function_exists( 'pll_get_term_language' ) ? pll_get_term_language( $cat ) : '';
		if ( $lang ) {
			$request->set_param( 'lang', $lang );
			break;
		}
	}
	return $response;
}
add_filter( 'rest_request_before_callbacks', 'portal_story_lang_from_sections', 5, 3 );

/* ------------------------------------------------------------------ Language in the API lists (for the app) */

/**
 * ?lang=hi or ?lang=en on /wp/v2/posts, /wp/v2/categories and /wp/v2/tags lists only that
 * language (Polylang free does not do this; without ?lang every language is listed).
 *
 * @param array           $args    Query arguments.
 * @param WP_REST_Request $request Request.
 * @return array
 */
function portal_rest_list_language( $args, $request ) {
	$lang = sanitize_key( (string) $request->get_param( 'lang' ) );
	if ( $lang && portal_has_languages() && in_array( $lang, pll_languages_list(), true ) ) {
		$args['lang'] = $lang;
	}
	return $args;
}
add_filter( 'rest_post_query', 'portal_rest_list_language', 10, 2 );
add_filter( 'rest_category_query', 'portal_rest_list_language', 10, 2 );
add_filter( 'rest_post_tag_query', 'portal_rest_list_language', 10, 2 );

/**
 * Each story in the API says its language and its published versions:
 * "lang": "hi", "translations": { "hi": 12, "en": 15 }.
 */
function portal_register_language_fields() {
	if ( ! portal_has_languages() ) {
		return;
	}
	register_rest_field(
		'post',
		'lang',
		array(
			'get_callback' => static fn( $data ) => portal_post_lang( (int) $data['id'] ),
			'schema'       => array(
				'description' => __( 'Language of the story (hi or en).', 'portal' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit', 'embed' ),
				'readonly'    => true,
			),
		)
	);
	register_rest_field(
		'post',
		'translations',
		array(
			'get_callback' => static function ( $data ) {
				$ids = array();
				foreach ( portal_story_versions( (int) $data['id'] ) as $version ) {
					$ids[ $version['lang'] ] = $version['id'];
				}
				return (object) $ids;
			},
			'schema'       => array(
				'description' => __( 'Published versions of the story by language (language => post ID).', 'portal' ),
				'type'        => 'object',
				'context'     => array( 'view', 'edit', 'embed' ),
				'readonly'    => true,
			),
		)
	);
}
add_action( 'rest_api_init', 'portal_register_language_fields' );

/* ------------------------------------------------------------------ Words from Kadence and WP User Frontend */

/**
 * Kadence (our parent theme) and WP User Frontend (My Account) have no official Hindi, so the few
 * words readers see from them are translated here, on Hindi pages only.
 *
 * @param string $translation Translated text.
 * @param string $text        Original text.
 * @param string $domain      Text domain.
 * @return string
 */
function portal_hindi_for_parent_texts( $translation, $text, $domain ) {
	static $words = array(
		'kadence'          => array(
			'By'                      => 'लेखक',
			'Search'                  => 'खोजें',
			'Search for:'             => 'खोजें:',
			'Search Results for: %s'  => '"%s" के लिए खोज परिणाम',
			'Nothing Found'           => 'कुछ नहीं मिला',
			'Skip to content'         => 'सामग्री पर जाएँ',
			'Toggle Menu'             => 'मेनू',
			'Previous'                => 'पिछला',
			'Next'                    => 'अगला',
			'Posted in'               => 'सेक्शन',
			'Updated on'              => 'अपडेट',
			'Read More'               => 'आगे पढ़ें',
			'Scroll to top'           => 'ऊपर जाएँ',
		),
		'wp-user-frontend' => array(
			'Dashboard'        => 'डैशबोर्ड',
			'Posts'            => 'मेरी कहानियाँ',
			'Edit Profile'     => 'प्रोफ़ाइल संपादित करें',
			'Submit Post'      => 'कहानी लिखें',
			'Logout'           => 'लॉग आउट',
			'Log out'          => 'लॉग आउट',
			'Account'          => 'खाता',
			'My Account'       => 'मेरा खाता',
			'Update Profile'   => 'प्रोफ़ाइल अपडेट करें',
			'First Name'       => 'पहला नाम',
			'Last Name'        => 'उपनाम',
			'Display Name'     => 'दिखने वाला नाम',
			'Email'            => 'ई-मेल',
			'Password'         => 'पासवर्ड',
			'Confirm Password' => 'पासवर्ड दोबारा लिखें',
		),
	);
	if ( isset( $words[ $domain ][ $text ] ) && 'hi_IN' === determine_locale() ) {
		return $words[ $domain ][ $text ];
	}
	return $translation;
}
add_filter( 'gettext', 'portal_hindi_for_parent_texts', 10, 3 );

/* ------------------------------------------------------------------ Language choice on the Write a story form */

/**
 * "Language of your story" on the Write form (Hindi by default on the Hindi site).
 *
 * @param int $form_id Form ID.
 * @param int $post_id Story being edited (0 for new).
 */
function portal_language_form_field( $form_id, $post_id = 0 ) {
	if ( ! portal_has_languages() || (int) $form_id !== (int) get_option( 'portal_post_form_id' ) ) {
		return;
	}
	$current = $post_id ? portal_post_lang( $post_id ) : '';
	$current = $current ? $current : ( portal_lang() ? portal_lang() : pll_default_language() );
	?>
	<li class="wpuf-el portal-language-field">
		<div class="wpuf-label"><label><?php esc_html_e( 'Language of your story', 'portal' ); ?></label></div>
		<div class="wpuf-fields">
			<?php wp_nonce_field( 'portal_story_lang', 'portal_story_lang_nonce' ); ?>
			<?php foreach ( pll_languages_list( array( 'fields' => '' ) ) as $language ) : ?>
				<label class="portal-language-field__choice">
					<input type="radio" name="portal_story_lang" value="<?php echo esc_attr( $language->slug ); ?>" <?php checked( $current, $language->slug ); ?>>
					<?php echo esc_html( $language->name ); ?>
				</label>
			<?php endforeach; ?>
			<span class="wpuf-help"><?php esc_html_e( 'Write in the language you are most comfortable with. Our editors may add a version in the other language.', 'portal' ); ?></span>
		</div>
	</li>
	<?php
}
add_action( 'wpuf_add_post_form_top', static fn( $form_id ) => portal_language_form_field( $form_id ), 10, 1 );
add_action( 'wpuf_edit_post_form_top', static fn( $form_id, $post_id ) => portal_language_form_field( $form_id, (int) $post_id ), 10, 2 );

/**
 * Save the chosen language after the form saved the story.
 *
 * @param int $post_id Story.
 * @param int $form_id Form.
 */
function portal_language_form_save( $post_id, $form_id ) {
	if ( ! portal_has_languages() || (int) $form_id !== (int) get_option( 'portal_post_form_id' ) ) {
		return;
	}
	if ( ! isset( $_POST['portal_story_lang'], $_POST['portal_story_lang_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['portal_story_lang_nonce'] ) ), 'portal_story_lang' ) ) {
		return;
	}
	$lang = sanitize_key( wp_unslash( $_POST['portal_story_lang'] ) );
	if ( in_array( $lang, pll_languages_list(), true ) ) {
		pll_set_post_language( $post_id, $lang );
		// The section the writer chose must be the one in the story's language.
		$cats = wp_get_post_categories( $post_id );
		$fixed = array();
		foreach ( $cats as $cat ) {
			$t       = function_exists( 'pll_get_term' ) ? pll_get_term( $cat, $lang ) : 0;
			$fixed[] = $t ? (int) $t : (int) $cat;
		}
		if ( $fixed && $fixed !== array_map( 'intval', $cats ) ) {
			wp_set_post_categories( $post_id, $fixed );
		}
	}
}
add_action( 'wpuf_add_post_after_insert', 'portal_language_form_save', 5, 2 );
add_action( 'wpuf_edit_post_after_update', 'portal_language_form_save', 5, 2 );
