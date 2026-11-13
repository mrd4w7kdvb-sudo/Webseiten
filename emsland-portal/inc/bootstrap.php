<?php
declare(strict_types=1);

/*
 * EmsQuartier – gemeinsamer Start für alle Seiten.
 * Lädt Konfiguration, Datenbank, Session und Hilfsfunktionen.
 */

define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', APP_ROOT . '/data');
define('UPLOAD_DIR', APP_ROOT . '/uploads');

error_reporting(E_ALL);
ini_set('display_errors', getenv('EQ_DEBUG') ? '1' : '0');
date_default_timezone_set('Europe/Berlin');
mb_internal_encoding('UTF-8');

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/pricing.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name('eqsid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_path() . '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');
if (setting('noindex') === '1') {
    header('X-Robots-Tag: noindex, nofollow');
}
