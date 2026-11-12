<?php
/**
 * Plugin Name: Haus Rosenstock – Unsichtbar für Suchmaschinen
 * Description: Hält die gesamte Webseite aus Google, Bing & Co. heraus: noindex für jede Seite und jede Datei, keine Sitemap, keine Feeds, keine Autoren-Archive. Erreichbar bleibt sie für alle, die die Adresse kennen.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* 1. Robots-Meta-Tag in jeder HTML-Seite: nicht indexieren, nicht folgen, nichts zwischenspeichern. */
add_filter( 'wp_robots', function () {
	return array(
		'noindex'      => true,
		'nofollow'     => true,
		'noarchive'    => true,
		'nosnippet'    => true,
		'noimageindex' => true,
	);
}, 999 );

/* 2. Dasselbe als HTTP-Header – greift auch bei Bildern, PDFs usw., wenn der .htaccess-Teil der Anleitung genutzt wird. */
add_action( 'send_headers', function () {
	header( 'X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex', true );
} );

/* 3. WordPress-Einstellung "Suchmaschinen davon abhalten" dauerhaft einschalten. */
add_filter( 'pre_option_blog_public', function () {
	return '0';
} );

/*
 * 4. robots.txt: Crawling NICHT sperren. Nur wenn Suchmaschinen eine Seite abrufen
 *    dürfen, sehen sie das "noindex" und nehmen sie sicher nicht auf. Ein reines
 *    "Disallow" kann dazu führen, dass die Adresse trotzdem ohne Inhalt gelistet wird.
 */
add_filter( 'robots_txt', function () {
	return "User-agent: *\nDisallow:\n";
}, 999 );

/* 5. Keine Sitemap, die die Seiten auflistet. */
add_filter( 'wp_sitemaps_enabled', '__return_false' );

/* 6. Keine Feeds (würden Inhalte an Feed-Dienste weitergeben). */
foreach ( array( 'do_feed', 'do_feed_rdf', 'do_feed_rss', 'do_feed_rss2', 'do_feed_atom', 'do_feed_rss2_comments', 'do_feed_atom_comments' ) as $hr_feed ) {
	add_action( $hr_feed, function () {
		wp_die( 'Nicht verfügbar.', '', array( 'response' => 404 ) );
	}, 1 );
}
remove_action( 'wp_head', 'feed_links', 2 );
remove_action( 'wp_head', 'feed_links_extra', 3 );

/* 7. Keine Benachrichtigung von Ping-Diensten beim Veröffentlichen. */
add_filter( 'pre_option_ping_sites', '__return_empty_string' );
add_filter( 'pre_option_default_pingback_flag', '__return_zero' );
add_filter( 'xmlrpc_enabled', '__return_false' );

/* 8. Autoren-Archive und ?author=1 abschalten (verraten sonst Benutzernamen). */
add_action( 'template_redirect', function () {
	if ( is_author() || isset( $_GET['author'] ) ) {
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
} );

/* 9. Benutzerliste über die REST-API nur für angemeldete Redakteure. */
add_filter( 'rest_endpoints', function ( $endpoints ) {
	if ( ! current_user_can( 'list_users' ) ) {
		unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
	}
	return $endpoints;
} );

/* 10. Hinweis im Dashboard, damit niemand den Schutz versehentlich vergisst. */
add_action( 'admin_notices', function () {
	$screen = get_current_screen();
	if ( $screen && in_array( $screen->id, array( 'dashboard', 'options-reading' ), true ) ) {
		echo '<div class="notice notice-info"><p><strong>Haus Rosenstock:</strong> Die Webseite ist für Suchmaschinen unsichtbar. Erreichbar ist sie nur über ihre Adresse. Verlinke sie nicht öffentlich (z.&nbsp;B. auf Social Media oder anderen Webseiten).</p></div>';
	}
} );
