<?php
/**
 * Build the Hindi interface files from scripts/hindi-translations.php (Task 18A). Developer tool,
 * run locally after changing translations or interface texts, then commit the results:
 *
 *   docker compose run --rm -T --user root wpcli i18n make-pot /var/www/html/wp-content/plugins/portal-core /var/www/html/wp-content/plugins/portal-core/languages/portal.pot --domain=portal --skip-js --allow-root
 *   docker compose run --rm -T --user root wpcli i18n make-pot /var/www/html/wp-content/themes/portal-child /var/www/html/wp-content/themes/portal-child/languages/portal.pot --domain=portal --skip-js --allow-root
 *   docker compose run --rm -T --user root wpcli eval-file /scripts/build-hindi-translations.php --allow-root
 *
 * Writes portal-core/languages/portal-hi_IN.po/.mo and portal-child/languages/hi_IN.po/.mo.
 *
 * @package Portal
 */

require_once ABSPATH . WPINC . '/pomo/po.php';
require_once ABSPATH . WPINC . '/pomo/mo.php';

$portal_hi = include '/scripts/hindi-translations.php';
if ( ! is_array( $portal_hi ) ) {
	WP_CLI::error( 'Could not read /scripts/hindi-translations.php' );
}

$portal_targets = array(
	'plugin' => array( WP_CONTENT_DIR . '/plugins/portal-core/languages/portal.pot', WP_CONTENT_DIR . '/plugins/portal-core/languages/portal-hi_IN' ),
	'theme'  => array( WP_CONTENT_DIR . '/themes/portal-child/languages/portal.pot', WP_CONTENT_DIR . '/themes/portal-child/languages/hi_IN' ),
);
$portal_used = array();
foreach ( $portal_targets as $portal_name => list( $portal_pot, $portal_out ) ) {
	$portal_po = new PO();
	if ( ! $portal_po->import_from_file( $portal_pot ) ) {
		WP_CLI::error( "Cannot read $portal_pot" );
	}
	$portal_done = 0;
	$portal_left = 0;
	foreach ( $portal_po->entries as $portal_key => $portal_entry ) {
		if ( isset( $portal_hi[ $portal_entry->singular ] ) ) {
			$portal_value = $portal_hi[ $portal_entry->singular ];
			if ( is_array( $portal_value ) ) {
				$portal_entry->translations = array_values( $portal_value );
			} else {
				$portal_entry->translations = $portal_entry->is_plural ? array( $portal_value, $portal_value ) : array( $portal_value );
			}
			$portal_used[ $portal_entry->singular ] = true;
			++$portal_done;
		} else {
			++$portal_left;
		}
		$portal_po->entries[ $portal_key ] = $portal_entry;
	}
	$portal_headers = array(
		'Language'                  => 'hi_IN',
		'Plural-Forms'              => 'nplurals=2; plural=(n != 1);',
		'Content-Type'              => 'text/plain; charset=UTF-8',
		'Content-Transfer-Encoding' => '8bit',
		'MIME-Version'              => '1.0',
		'PO-Revision-Date'          => gmdate( 'Y-m-d H:i+0000' ),
		'Project-Id-Version'        => 'CityCharcha ' . $portal_name,
	);
	$portal_po->set_headers( $portal_headers );
	$portal_po->export_to_file( $portal_out . '.po' );
	$portal_mo = new MO();
	$portal_mo->set_headers( $portal_headers );
	$portal_mo->entries = $portal_po->entries;
	$portal_mo->export_to_file( $portal_out . '.mo' );
	WP_CLI::log( sprintf( '%s: %d texts in Hindi, %d left in English (editor/admin screens) -> %s.mo', $portal_name, $portal_done, $portal_left, basename( $portal_out ) ) );
}

$portal_unused = array_diff( array_keys( $portal_hi ), array_keys( $portal_used ) );
if ( $portal_unused ) {
	WP_CLI::warning( count( $portal_unused ) . ' translations match no text in the code (typo, or the text changed):' );
	foreach ( $portal_unused as $portal_text ) {
		WP_CLI::log( '  - ' . $portal_text );
	}
}
WP_CLI::success( 'Hindi interface files built.' );
