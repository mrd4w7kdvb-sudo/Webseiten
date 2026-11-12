<?php
/**
 * Haus Rosenstock – Theme-Funktionen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stylesheet im Frontend und im Editor laden. */
add_action( 'after_setup_theme', function () {
	add_theme_support( 'editor-styles' );
	add_editor_style( 'assets/css/site.css' );
} );

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style(
		'haus-rosenstock',
		get_theme_file_uri( 'assets/css/site.css' ),
		array(),
		wp_get_theme()->get( 'Version' )
	);
} );

/** Inhalt der Startseite aus dem Muster erzeugen. */
function haus_rosenstock_startseite_inhalt() {
	ob_start();
	include get_theme_file_path( 'patterns/startseite.php' );
	$html = ob_get_clean();
	// Muster-Kopfkommentar entfernen, nur Blöcke behalten.
	return trim( preg_replace( '#^\s*<\?php.*?\?>#s', '', $html ) );
}

/**
 * Beim Aktivieren: Seite "Startseite" anlegen (falls noch nicht vorhanden)
 * und als statische Startseite einstellen. Bestehende Inhalte bleiben unberührt.
 */
add_action( 'after_switch_theme', function () {
	$seite = get_page_by_path( 'startseite' );
	if ( ! $seite ) {
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Startseite',
				'post_name'    => 'startseite',
				'post_content' => wp_slash( haus_rosenstock_startseite_inhalt() ),
			)
		);
	} else {
		$id = $seite->ID;
	}
	if ( $id && ! is_wp_error( $id ) ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', (int) $id );
	}

	// Leere Rechtsseiten anlegen, auf die die Fußzeile verlinkt.
	$rechtsseiten = array(
		'impressum'   => 'Impressum',
		'datenschutz' => 'Datenschutzerklärung',
	);
	foreach ( $rechtsseiten as $slug => $titel ) {
		if ( ! get_page_by_path( $slug ) ) {
			wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $titel,
					'post_name'    => $slug,
					'post_content' => "<!-- wp:paragraph -->\n<p>Bitte hier den vollständigen Text einfügen.</p>\n<!-- /wp:paragraph -->",
				)
			);
		}
	}
} );
