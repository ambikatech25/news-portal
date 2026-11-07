<?php
/**
 * Story import: fill the "Write a story" form from a Word file or pasted text (e.g. from ChatGPT).
 *
 *   Template:  /wp-admin/admin-post.php?action=portal_story_template   (a .docx, made on the fly)
 *   Import:    POST /wp-json/portal/v1/story-import   (writers only)
 *              multipart field "file" (.docx, .txt, .md, max 2 MB)  or  field "text" (pasted)
 *              -> { title, content (HTML), category (ID or null), tags, warnings[] }
 *
 * The document is laid out with labels on their own line (or "Label: value"):
 *
 *   HEADLINE   Truck drivers' rest stop opens on NH-130
 *   SECTION    Logistics
 *   TAGS       Raipur, trucking
 *   STORY      (everything after this label, with paragraphs, sub-headings, bold, lists, links)
 *
 * Nothing is saved: the file is read in memory and thrown away; the writer still checks the
 * form and presses Submit. Pictures are never imported (the writer adds them with the form's
 * picture buttons, so only photos they chose are uploaded). PDF is not accepted: PDFs store
 * text as positioned pieces, so headings, lists and paragraphs come out scrambled.
 *
 * @package Portal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Largest file accepted (bytes) and largest unpacked Word text (protects against "zip bombs").
 */
const PORTAL_IMPORT_MAX_BYTES     = 2097152;
const PORTAL_IMPORT_MAX_XML_BYTES = 10485760;

/**
 * Labels recognised in documents (lower case, without the colon) => field.
 *
 * @return array<string,string>
 */
function portal_import_labels() {
	return (array) apply_filters(
		'portal_import_labels',
		array(
			'headline' => 'title',
			'title'    => 'title',
			'शीर्षक'   => 'title',
			'section'  => 'section',
			'category' => 'section',
			'श्रेणी'    => 'section',
			'tags'     => 'tags',
			'टैग'      => 'tags',
			'story'    => 'story',
			'body'     => 'story',
			'article'  => 'story',
			'कहानी'    => 'story',
			'खबर'      => 'story',
		)
	);
}

/**
 * Sections a writer may choose (Task 11 rule): every category except the editor-only ones.
 *
 * @return WP_Term[]
 */
function portal_import_allowed_sections() {
	return get_terms(
		array(
			'taxonomy'   => 'category',
			'hide_empty' => false,
			'exclude'    => portal_restricted_category_ids(),
			'orderby'    => 'name',
		)
	);
}

/* ------------------------------------------------------------------ Reading a .docx */

/**
 * Turn a .docx file into a list of blocks: [ 'type' => p|h2|h3|li, 'html' => ..., 'text' => ... ].
 *
 * @param string $path Temporary file path.
 * @return array{blocks:array,images:int}|WP_Error
 */
function portal_import_read_docx( $path ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'portal_import_server', __( 'This server cannot read Word files.', 'portal' ), array( 'status' => 500 ) );
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $path ) ) {
		return new WP_Error( 'portal_import_type', __( 'This is not a Word (.docx) file. In Word or Google Docs choose File → Save as / Download → .docx.', 'portal' ), array( 'status' => 400 ) );
	}
	$stat = $zip->statName( 'word/document.xml' );
	if ( ! $stat || $stat['size'] > PORTAL_IMPORT_MAX_XML_BYTES ) {
		$zip->close();
		return new WP_Error( 'portal_import_type', __( 'This Word file could not be read. Please save it again as .docx and retry.', 'portal' ), array( 'status' => 400 ) );
	}
	$xml  = $zip->getFromName( 'word/document.xml' );
	$rels = (string) $zip->getFromName( 'word/_rels/document.xml.rels' );
	$zip->close();

	// Link targets (hyperlinks point to an ID listed in the relationships file).
	$links = array();
	if ( $rels && preg_match_all( '/<Relationship\b[^>]*\bId="([^"]+)"[^>]*\bTarget="([^"]+)"[^>]*TargetMode="External"/i', $rels, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $rel ) {
			$links[ $rel[1] ] = html_entity_decode( $rel[2], ENT_QUOTES | ENT_XML1 );
		}
	}

	$dom = new DOMDocument();
	// LIBXML_NONET: never fetch anything from the internet while reading the file.
	if ( ! $dom->loadXML( $xml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR ) ) {
		return new WP_Error( 'portal_import_type', __( 'This Word file could not be read. Please save it again as .docx and retry.', 'portal' ), array( 'status' => 400 ) );
	}
	$xp = new DOMXPath( $dom );
	$xp->registerNamespace( 'w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main' );
	$xp->registerNamespace( 'r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );

	$images = $xp->query( '//w:drawing | //w:pict' )->length;
	$blocks = array();

	foreach ( $xp->query( '//w:body//w:p' ) as $p ) {
		$style = (string) $xp->evaluate( 'string(w:pPr/w:pStyle/@w:val)', $p );
		$list  = $xp->query( 'w:pPr/w:numPr', $p )->length > 0 || false !== stripos( $style, 'List' );
		$type  = 'p';
		if ( preg_match( '/^(Heading|Title|Subtitle)\s*([1-9]?)$/i', $style, $hm ) ) {
			$type = ( '' === $hm[2] || (int) $hm[2] <= 2 ) ? 'h2' : 'h3';
		} elseif ( $list ) {
			$type = 'li';
		}

		$html = '';
		$text = '';
		foreach ( $xp->query( 'w:r | w:hyperlink', $p ) as $node ) {
			if ( 'hyperlink' === $node->localName ) {
				$inner = '';
				foreach ( $xp->query( 'w:r', $node ) as $run ) {
					list( $h, $t ) = portal_import_run( $xp, $run );
					$inner        .= $h;
					$text         .= $t;
				}
				$rid  = $node->getAttributeNS( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id' );
				$href = $links[ $rid ] ?? '';
				$html .= ( $href && preg_match( '#^https?://#i', $href ) ) ? '<a href="' . esc_url( $href ) . '">' . $inner . '</a>' : $inner;
			} else {
				list( $h, $t ) = portal_import_run( $xp, $node );
				$html         .= $h;
				$text         .= $t;
			}
		}
		$blocks[] = array(
			'type' => $type,
			'html' => trim( $html ),
			'text' => trim( $text ),
		);
	}

	return array(
		'blocks' => $blocks,
		'images' => $images,
	);
}

/**
 * One run of text in Word (same formatting): returns [ html, plain text ].
 *
 * @param DOMXPath $xp  XPath.
 * @param DOMNode  $run w:r element.
 * @return array{0:string,1:string}
 */
function portal_import_run( DOMXPath $xp, DOMNode $run ) {
	$text = '';
	foreach ( $xp->query( 'w:t | w:tab | w:br', $run ) as $piece ) {
		$text .= 't' === $piece->localName ? $piece->textContent : ( 'tab' === $piece->localName ? ' ' : "\n" );
	}
	if ( '' === $text ) {
		return array( '', '' );
	}
	$html = nl2br( esc_html( $text ), false );
	$on   = static function ( $tag ) use ( $xp, $run ) {
		$node = $xp->query( 'w:rPr/w:' . $tag, $run )->item( 0 );
		return $node && ! in_array( $node->getAttribute( 'w:val' ), array( '0', 'false', 'none' ), true );
	};
	if ( $on( 'i' ) ) {
		$html = '<em>' . $html . '</em>';
	}
	if ( $on( 'b' ) ) {
		$html = '<strong>' . $html . '</strong>';
	}
	return array( $html, $text );
}

/* ------------------------------------------------------------------ Reading plain text */

/**
 * Turn pasted text (ChatGPT style: "# Heading", "**bold**", "- item") into blocks.
 *
 * @param string $text Text.
 * @return array{blocks:array,images:int}
 */
function portal_import_read_text( $text ) {
	$text   = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
	$images = preg_match_all( '/!\[[^\]]*\]\([^)]*\)/', $text );
	$text   = preg_replace( '/!\[[^\]]*\]\([^)]*\)/', '', $text );
	$blocks = array();
	$para   = array();

	$flush = static function () use ( &$para, &$blocks ) {
		if ( $para ) {
			$plain    = trim( implode( ' ', $para ) );
			$blocks[] = array(
				'type' => 'p',
				'html' => portal_import_inline_markdown( $plain ),
				'text' => portal_import_strip_markdown( $plain ),
			);
			$para     = array();
		}
	};

	foreach ( explode( "\n", $text ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			$flush();
			continue;
		}
		if ( preg_match( '/^(#{1,6})\s+(.+)$/', $line, $m ) ) {
			$flush();
			$blocks[] = array(
				'type' => strlen( $m[1] ) <= 2 ? 'h2' : 'h3',
				'html' => portal_import_inline_markdown( $m[2] ),
				'text' => portal_import_strip_markdown( $m[2] ),
			);
		} elseif ( preg_match( '/^(?:[-*•]|\d+[.)])\s+(.+)$/u', $line, $m ) ) {
			$flush();
			$blocks[] = array(
				'type' => 'li',
				'html' => portal_import_inline_markdown( $m[1] ),
				'text' => portal_import_strip_markdown( $m[1] ),
			);
		} elseif ( portal_import_match_label( $line ) ) {
			// A label line ("HEADLINE", "**Headline:** ...") is always its own block.
			$flush();
			$blocks[] = array(
				'type' => 'p',
				'html' => portal_import_inline_markdown( $line ),
				'text' => portal_import_strip_markdown( $line ),
			);
		} else {
			$para[] = $line;
		}
	}
	$flush();

	return array(
		'blocks' => $blocks,
		'images' => (int) $images,
	);
}

/**
 * **bold**, *italic*, [text](https://link) to HTML (text is escaped first).
 *
 * @param string $text Text.
 * @return string
 */
function portal_import_inline_markdown( $text ) {
	$html = esc_html( $text );
	$html = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
	$html = preg_replace( '/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/', '<em>$1</em>', $html );
	return preg_replace_callback(
		'/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/',
		static fn( $m ) => '<a href="' . esc_url( html_entity_decode( $m[2] ) ) . '">' . $m[1] . '</a>',
		$html
	);
}

/**
 * Plain text without markdown marks.
 *
 * @param string $text Text.
 * @return string
 */
function portal_import_strip_markdown( $text ) {
	$text = preg_replace( '/\[([^\]]+)\]\([^)]*\)/', '$1', $text );
	return trim( str_replace( array( '**', '__' ), '', $text ) );
}

/**
 * If a line is a label ("HEADLINE", "Story:", "**Tags:** a, b"), return [ field, rest of line ].
 *
 * @param string $line Plain text line.
 * @return array{0:string,1:string}|null
 */
function portal_import_match_label( $line ) {
	$clean = trim( str_replace( array( '**', '__' ), '', $line ) );
	if ( ! preg_match( '/^([^:]{2,20}?)\s*(?::\s*(.*))?$/us', $clean, $m ) ) {
		return null;
	}
	$labels = portal_import_labels();
	$key    = mb_strtolower( trim( $m[1] ) );
	return isset( $labels[ $key ] ) ? array( $labels[ $key ], trim( $m[2] ?? '' ) ) : null;
}

/* ------------------------------------------------------------------ Blocks -> form fields */

/**
 * Sort blocks into headline, section, tags and story.
 *
 * @param array $blocks Blocks from a reader.
 * @param int   $images Pictures found in the document.
 * @return array{title:string,content:string,category:int|null,tags:string,warnings:string[]}
 */
function portal_import_to_fields( array $blocks, $images ) {
	$field    = null;
	$found    = array(
		'title'   => '',
		'section' => '',
		'tags'    => '',
	);
	$story    = array();
	$warnings = array();

	// With labels, anything before the first label (a document title, the template's own
	// instructions) is ignored. Without any labels, the first line becomes the headline.
	$has_labels = false;
	foreach ( $blocks as $block ) {
		if ( '' !== $block['text'] && portal_import_match_label( $block['text'] ) ) {
			$has_labels = true;
			break;
		}
	}

	foreach ( $blocks as $block ) {
		$plain = $block['text'];
		// Empty lines, and the template's example text left in [square brackets], are skipped.
		if ( '' === $plain || preg_match( '/^\[[^\]]*\]$/su', $plain ) ) {
			continue;
		}
		if ( $has_labels && null === $field && ! portal_import_match_label( $plain ) ) {
			continue;
		}
		// A label: "HEADLINE" alone, or "Headline: text" (bold marks ignored).
		$label = portal_import_match_label( $plain );
		if ( $label ) {
			list( $field, $rest ) = $label;
			if ( '' !== $rest && 'story' === $field ) {
				$story[] = array(
					'type' => 'p',
					'html' => esc_html( $rest ),
				);
			} elseif ( '' !== $rest ) {
				$found[ $field ] = $rest;
			}
			continue;
		}
		if ( 'story' === $field || null === $field ) {
			if ( null === $field && '' === $found['title'] && in_array( $block['type'], array( 'h2', 'p' ), true ) && ! $story ) {
				// No labels at all: treat the first line as the headline.
				$found['title'] = $plain;
				$field          = 'story';
				continue;
			}
			$story[] = $block;
		} elseif ( '' === $found[ $field ] ) {
			$found[ $field ] = $plain;
		} else {
			$found[ $field ] .= ' ' . $plain;
		}
	}

	// Story HTML: group list items into lists.
	$html    = '';
	$in_list = false;
	foreach ( $story as $block ) {
		if ( 'li' === $block['type'] ) {
			$html   .= ( $in_list ? '' : '<ul>' ) . '<li>' . $block['html'] . '</li>';
			$in_list = true;
			continue;
		}
		if ( $in_list ) {
			$html   .= '</ul>';
			$in_list = false;
		}
		$tag   = in_array( $block['type'], array( 'h2', 'h3' ), true ) ? $block['type'] : 'p';
		$html .= '<' . $tag . '>' . $block['html'] . '</' . $tag . '>';
	}
	if ( $in_list ) {
		$html .= '</ul>';
	}
	$html = wp_kses(
		$html,
		array(
			'p'      => array(),
			'h2'     => array(),
			'h3'     => array(),
			'ul'     => array(),
			'li'     => array(),
			'strong' => array(),
			'em'     => array(),
			'br'     => array(),
			'a'      => array( 'href' => true ),
		)
	);

	// Section: match the name (or slug) among the sections writers may use.
	$category = null;
	$wanted   = mb_strtolower( trim( $found['section'], " .\t" ) );
	if ( '' !== $wanted ) {
		foreach ( portal_import_allowed_sections() as $term ) {
			if ( mb_strtolower( $term->name ) === $wanted || $term->slug === sanitize_title( $wanted ) ) {
				$category = (int) $term->term_id;
				break;
			}
		}
		if ( null === $category ) {
			$restricted = false;
			foreach ( portal_restricted_category_ids() as $id ) {
				$term = get_term( $id );
				if ( $term && ( mb_strtolower( $term->name ) === $wanted || $term->slug === sanitize_title( $wanted ) ) ) {
					$restricted = true;
				}
			}
			$warnings[] = $restricted
				/* translators: %s: section name. */
				? sprintf( __( 'Only editors can publish in "%s". Please choose another section.', 'portal' ), $found['section'] )
				/* translators: %s: section name. */
				: sprintf( __( 'Section "%s" was not recognised. Please choose one from the list.', 'portal' ), $found['section'] );
		}
	}

	if ( '' === trim( $found['title'] ) ) {
		$warnings[] = __( 'No headline was found. Please type one.', 'portal' );
	}
	if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
		$warnings[] = __( 'No story text was found. Check that the text comes after the STORY label.', 'portal' );
	}
	if ( $images > 0 ) {
		$warnings[] = sprintf(
			/* translators: %d: number of pictures. */
			_n( 'Your document has %d picture. Pictures are not copied: add them with "Insert Photo" or "Main picture".', 'Your document has %d pictures. Pictures are not copied: add them with "Insert Photo" or "Main picture".', $images, 'portal' ),
			$images
		);
	}

	$tags = implode( ', ', array_filter( array_map( 'trim', preg_split( '/[,;#\n]+/', $found['tags'] ) ) ) );

	return array(
		'title'    => sanitize_text_field( mb_substr( $found['title'], 0, 200 ) ),
		'content'  => $html,
		'category' => $category,
		'tags'     => sanitize_text_field( $tags ),
		'warnings' => $warnings,
	);
}

/* ------------------------------------------------------------------ API */

/**
 * REST: POST /portal/v1/story-import.
 */
function portal_register_import_routes() {
	register_rest_route(
		'portal/v1',
		'/story-import',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => static function () {
				if ( ! is_user_logged_in() ) {
					return new WP_Error( 'rest_not_logged_in', __( 'Please sign in first.', 'portal' ), array( 'status' => 401 ) );
				}
				return current_user_can( 'edit_posts' ) ? true : new WP_Error( 'portal_import_forbidden', __( 'Only writers can import stories.', 'portal' ), array( 'status' => 403 ) );
			},
			'callback'            => 'portal_story_import_request',
		)
	);
}
add_action( 'rest_api_init', 'portal_register_import_routes' );

/**
 * Handle an import: a file, or pasted text.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function portal_story_import_request( WP_REST_Request $request ) {
	$files = $request->get_file_params();
	$file  = $files['file'] ?? null;

	if ( $file && empty( $file['error'] ) && ! empty( $file['tmp_name'] ) ) {
		if ( (int) $file['size'] > PORTAL_IMPORT_MAX_BYTES ) {
			return new WP_Error( 'portal_import_size', __( 'The file is too large. The limit is 2 MB.', 'portal' ), array( 'status' => 400 ) );
		}
		$ext = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		if ( 'docx' === $ext ) {
			$read = portal_import_read_docx( $file['tmp_name'] );
		} elseif ( in_array( $ext, array( 'txt', 'md' ), true ) ) {
			$raw = (string) file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local temp file.
			if ( ! wp_check_invalid_utf8( $raw ) && '' !== $raw ) {
				return new WP_Error( 'portal_import_type', __( 'This text file is not in UTF-8. Save it as UTF-8 and retry.', 'portal' ), array( 'status' => 400 ) );
			}
			$read = portal_import_read_text( $raw );
		} elseif ( 'pdf' === $ext ) {
			return new WP_Error( 'portal_import_pdf', __( 'PDF files cannot be read reliably. Open the PDF in Word or Google Docs and save it as .docx, then upload that.', 'portal' ), array( 'status' => 400 ) );
		} else {
			return new WP_Error( 'portal_import_type', __( 'Please upload a Word file (.docx) or a text file (.txt).', 'portal' ), array( 'status' => 400 ) );
		}
	} elseif ( $file && in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
		return new WP_Error( 'portal_import_size', __( 'The file is too large. The limit is 2 MB.', 'portal' ), array( 'status' => 400 ) );
	} elseif ( '' !== trim( (string) $request['text'] ) ) {
		$text = (string) $request['text'];
		if ( strlen( $text ) > PORTAL_IMPORT_MAX_BYTES ) {
			return new WP_Error( 'portal_import_size', __( 'The text is too long.', 'portal' ), array( 'status' => 400 ) );
		}
		$read = portal_import_read_text( $text );
	} else {
		return new WP_Error( 'portal_import_empty', __( 'Choose a file or paste your text first.', 'portal' ), array( 'status' => 400 ) );
	}

	if ( is_wp_error( $read ) ) {
		return $read;
	}
	return rest_ensure_response( portal_import_to_fields( $read['blocks'], $read['images'] ) );
}

/* ------------------------------------------------------------------ The Word template */

/**
 * Build the template .docx (bytes). Sections listed are the ones writers may use today.
 *
 * @return string|WP_Error
 */
function portal_story_template_docx() {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'portal_import_server', 'ZipArchive missing' );
	}
	$sections = implode( ' / ', wp_list_pluck( portal_import_allowed_sections(), 'name' ) );
	$x        = static fn( $s ) => htmlspecialchars( $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	$para     = static function ( $text, $style = '', $bold = false, $grey = false ) use ( $x ) {
		$ppr = $style ? '<w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>' : '';
		$rpr = ( $bold || $grey ) ? '<w:rPr>' . ( $bold ? '<w:b/>' : '' ) . ( $grey ? '<w:color w:val="6B7280"/><w:i/>' : '' ) . '</w:rPr>' : '';
		return '<w:p>' . $ppr . '<w:r>' . $rpr . '<w:t xml:space="preserve">' . $x( $text ) . '</w:t></w:r></w:p>';
	};

	$body  = $para( get_bloginfo( 'name' ) . ' – story template', 'Title' );
	$body .= $para( 'Keep the four labels (HEADLINE, SECTION, TAGS, STORY). Replace each [grey text in brackets] with your own, then upload this file on the Write page. Text left in [brackets] is ignored. Pictures are not copied: add them on the Write page.', '', false, true );
	$body .= $para( 'HEADLINE', '', true );
	$body .= $para( '[Write a clear, factual headline here]', '', false, true );
	$body .= $para( 'SECTION', '', true );
	$body .= $para( '[Choose one: ' . $sections . ']', '', false, true );
	$body .= $para( 'TAGS', '', true );
	$body .= $para( '[Raipur, trucking, steel - separate with commas]', '', false, true );
	$body .= $para( 'STORY', '', true );
	$body .= $para( '[Start your story here: who, what, where, when. Use Heading 2 for sub-headings and normal bullets for lists.]', '', false, true );
	$body .= $para( '[Do not name victims of crime, minors or witnesses.]', '', false, true );

	$files = array(
		'[Content_Types].xml'          => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>',
		'_rels/.rels'                  => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
		'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
		'word/styles.xml'              => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:pPr><w:spacing w:after="160"/></w:pPr><w:rPr><w:sz w:val="24"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:rPr><w:b/><w:sz w:val="36"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:rPr><w:b/><w:sz w:val="28"/></w:rPr></w:style></w:styles>',
		'word/document.xml'            => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>' . $body . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr></w:body></w:document>',
	);

	$tmp = wp_tempnam( 'portal-template.docx' );
	$zip = new ZipArchive();
	$zip->open( $tmp, ZipArchive::OVERWRITE );
	foreach ( $files as $name => $content ) {
		$zip->addFromString( $name, $content );
	}
	$zip->close();
	$bytes = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local temp file.
	wp_delete_file( $tmp );
	return $bytes;
}

/**
 * Download the template (anyone may download it; it contains nothing private).
 */
function portal_story_template_download() {
	$bytes = portal_story_template_docx();
	if ( is_wp_error( $bytes ) ) {
		wp_die( esc_html( $bytes->get_error_message() ), 500 );
	}
	nocache_headers();
	header( 'Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document' );
	header( 'Content-Disposition: attachment; filename="story-template.docx"' );
	header( 'Content-Length: ' . strlen( $bytes ) );
	echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput -- binary file.
	exit;
}
add_action( 'admin_post_portal_story_template', 'portal_story_template_download' );
add_action( 'admin_post_nopriv_portal_story_template', 'portal_story_template_download' );

/* ------------------------------------------------------------------ The import box on /write/ */

/**
 * The ChatGPT prompt shown on the Write page (writers copy it).
 *
 * @return string
 */
function portal_import_chatgpt_prompt() {
	$sections = implode( ', ', wp_list_pluck( portal_import_allowed_sections(), 'name' ) );
	return sprintf(
		"Write a news story for a website in Chhattisgarh, India. Reply in exactly this format and nothing else:\n\nHEADLINE: (one factual headline)\nSECTION: (one of: %s)\nTAGS: (3 to 5 tags, separated by commas)\nSTORY:\n(the story in short paragraphs, with ## for sub-headings)\n\nTopic: ",
		$sections
	);
}

/**
 * HTML of the import box (shown above the story form to writers).
 *
 * @return string
 */
function portal_import_box() {
	$path = 'assets/js/story-import.js';
	wp_enqueue_script( 'portal-story-import', PORTAL_CORE_URL . $path, array(), (string) filemtime( PORTAL_CORE_DIR . $path ), array( 'in_footer' => true ) );
	wp_localize_script(
		'portal-story-import',
		'portalImport',
		array(
			'url'   => rest_url( 'portal/v1/story-import' ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'i18n'  => array(
				'reading' => __( 'Reading…', 'portal' ),
				'done'    => __( 'Done! Check every field, add pictures, then press Submit story.', 'portal' ),
				'error'   => __( 'Something went wrong. Please try again.', 'portal' ),
				'copied'  => __( 'Prompt copied', 'portal' ),
				'replace' => __( 'This will replace what you have typed in the form. Continue?', 'portal' ),
			),
		)
	);
	ob_start();
	?>
	<details class="portal-import" open>
		<summary><?php esc_html_e( 'Already written it? Fill the form from a document', 'portal' ); ?></summary>
		<div class="portal-import__body">
			<ol class="portal-import__steps">
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin-post.php?action=portal_story_template' ) ); ?>"><?php esc_html_e( 'Download the Word template', 'portal' ); ?></a>
					<?php esc_html_e( 'and write your story in it (or in Google Docs, then File → Download → .docx).', 'portal' ); ?>
				</li>
				<li>
					<label class="portal-import__file">
						<span><?php esc_html_e( 'Upload it here (.docx or .txt, up to 2 MB):', 'portal' ); ?></span>
						<input type="file" accept=".docx,.txt,.md,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain" data-portal-import-file>
					</label>
				</li>
			</ol>
			<p class="portal-import__or"><?php esc_html_e( 'Or paste text from ChatGPT or anywhere else:', 'portal' ); ?></p>
			<textarea rows="5" data-portal-import-text placeholder="HEADLINE: …&#10;SECTION: …&#10;TAGS: …&#10;STORY:&#10;…"></textarea>
			<p class="portal-import__actions">
				<button type="button" class="portal-button" data-portal-import-paste><?php esc_html_e( 'Fill the form', 'portal' ); ?></button>
				<button type="button" class="portal-button portal-button--ghost" data-portal-import-prompt data-prompt="<?php echo esc_attr( portal_import_chatgpt_prompt() ); ?>"><?php esc_html_e( 'Copy a ChatGPT prompt', 'portal' ); ?></button>
			</p>
			<div class="portal-import__status" role="status" aria-live="polite"></div>
			<p class="portal-import__note"><?php esc_html_e( 'PDF? Open it in Word or Google Docs and save as .docx. Pictures in documents are not copied. You are responsible for what you submit, even if a tool helped write it.', 'portal' ); ?></p>
		</div>
	</details>
	<?php
	return (string) ob_get_clean();
}

/* ------------------------------------------------------------------ "AI tools helped" disclosure */

/**
 * Store the writer's "AI tools helped write this" answer (from the story form) and expose it
 * to the app. Editors see it while reviewing; it is not shown publicly unless the editorial
 * policy decides so.
 */
function portal_register_ai_meta() {
	register_post_meta(
		'post',
		'portal_ai_assisted',
		array(
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'description'       => 'Writer says AI tools helped write this ("yes" or empty).',
			'sanitize_callback' => static fn( $v ) => 'yes' === strtolower( trim( (string) $v ) ) ? 'yes' : '',
			'auth_callback'     => static fn( $allowed, $key, $post_id ) => current_user_can( 'edit_post', $post_id ),
			'show_in_rest'      => true,
		)
	);
}
add_action( 'init', 'portal_register_ai_meta' );
