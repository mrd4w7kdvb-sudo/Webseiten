<?php
declare(strict_types=1);

/* ---------- Ausgabe & URLs ---------- */

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Text mit Absätzen (Leerzeile) und Zeilenumbrüchen sicher ausgeben. */
function paragraphs(?string $text): string
{
    $parts = preg_split("/\R{2,}/", trim((string)$text)) ?: [];
    $out = '';
    foreach ($parts as $p) {
        if ($p !== '') {
            $out .= '<p>' . nl2br(e($p)) . "</p>\n";
        }
    }
    return $out;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

/** Basis-Pfad der Anwendung (funktioniert auch in einem Unterordner). */
function base_path(): string
{
    static $base = null;
    if ($base === null) {
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        if (in_array(basename($dir), ['admin', 'konto'], true)) {
            $dir = dirname($dir);
        }
        $base = rtrim($dir, '/');
    }
    return $base;
}

function url(string $path = '', array $query = []): string
{
    $u = base_path() . '/' . ltrim($path, '/');
    $query = array_filter($query, fn($v) => $v !== null && $v !== '' && $v !== []);
    if ($query) {
        $u .= '?' . http_build_query($query);
    }
    return $u;
}

function absolute_url(string $path = '', array $query = []): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (is_https() ? 'https://' : 'http://') . $host . url($path, $query);
}

function asset(string $path): string
{
    $file = APP_ROOT . '/assets/' . $path;
    $v = is_file($file) ? (string)filemtime($file) : '1';
    return url('assets/' . $path) . '?v=' . $v;
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function not_found(): never
{
    http_response_code(404);
    page_header('Nicht gefunden');
    echo '<section class="section"><div class="wrap narrow"><h1>Seite nicht gefunden</h1><p>Die gesuchte Seite gibt es nicht (mehr).</p><p><a class="button" href="' . e(url()) . '">Zur Startseite</a></p></div></section>';
    page_footer();
    exit;
}

/* ---------- Einstellungen ---------- */

function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null || $key === '__reset') {
        $cache = [];
        foreach (db()->query('SELECT key, value FROM settings') as $row) {
            $cache[$row['key']] = $row['value'];
        }
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings(key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
    setting('__reset');
}

/* ---------- Sicherheit ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function check_csrf(): void
{
    $sent = (string)($_POST['csrf'] ?? '');
    if (!hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Sitzung abgelaufen. Bitte die Seite neu laden und erneut absenden.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function get(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Einfacher Spam-Schutz für öffentliche Formulare: Honigtopf-Feld + Mindestzeit. */
function spam_fields(): string
{
    return '<div class="hp" aria-hidden="true"><label>Bitte leer lassen <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
        . '<input type="hidden" name="ts" value="' . time() . '">';
}

function looks_like_spam(): bool
{
    if (post('website') !== '') {
        return true;
    }
    $ts = (int)post('ts');
    return $ts > 0 && (time() - $ts) < 3;
}

/* ---------- Flash-Meldungen ---------- */

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function flashes(): string
{
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        $out .= '<div class="alert alert-' . e($type) . '" role="status">' . e($msg) . '</div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

/* ---------- Formatierung ---------- */

function money(float $v, bool $cents = true): string
{
    return number_format($v, $cents ? 2 : 0, ',', '.') . ' €';
}

function date_de(?string $ymd, bool $weekday = false): string
{
    if (!$ymd) {
        return '';
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10));
    if (!$d) {
        return e($ymd);
    }
    $days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    return ($weekday ? $days[(int)$d->format('w')] . ', ' : '') . $d->format('d.m.Y');
}

function valid_date(string $s): ?DateTimeImmutable
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    return ($d && $d->format('Y-m-d') === $s) ? $d : null;
}

function valid_email(string $s): bool
{
    return (bool)filter_var($s, FILTER_VALIDATE_EMAIL);
}

function slugify(string $s): string
{
    $s = mb_strtolower($s);
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-') ?: 'unterkunft';
}

function random_ref(int $len = 8): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

/* ---------- Fachliche Stammdaten ---------- */

const AMENITIES = [
    'wlan'          => ['WLAN', 'basis'],
    'kueche'        => ['Voll ausgestattete Küche', 'basis'],
    'spuelmaschine' => ['Spülmaschine', 'basis'],
    'waschmaschine' => ['Waschmaschine', 'business'],
    'trockner'      => ['Trockner', 'basis'],
    'parkplatz'     => ['Parkplatz', 'basis'],
    'schreibtisch'  => ['Schreibtisch / Arbeitsplatz', 'business'],
    'transporter'   => ['Stellplatz Transporter/Anhänger', 'business'],
    'einzelbetten'  => ['Einzelbetten (Monteure)', 'business'],
    'selfcheckin'   => ['Self-Check-in (Schlüsselbox)', 'business'],
    'verdunkelung'  => ['Verdunkelung (Schichtdienst)', 'business'],
    'hund'          => ['Hund erlaubt', 'urlaub'],
    'garten'        => ['Garten', 'urlaub'],
    'terrasse'      => ['Terrasse / Balkon', 'basis'],
    'grill'         => ['Grill', 'basis'],
    'sauna'         => ['Sauna', 'urlaub'],
    'kamin'         => ['Kamin / Kaminofen', 'urlaub'],
    'fahrradgarage' => ['Fahrradgarage', 'urlaub'],
    'eladestation'  => ['E-Ladestation', 'basis'],
    'kinderbett'    => ['Kinderbett', 'urlaub'],
    'barrierefrei'  => ['Barrierearm', 'urlaub'],
];

const LISTING_TYPES = ['Ferienwohnung', 'Ferienhaus', 'Apartment', 'Monteurwohnung', 'Landhaus', 'Tiny House', 'Besonderes'];

const BOOKING_STATUS = [
    'angefragt'  => 'Angefragt',
    'bestaetigt' => 'Bestätigt',
    'abgelehnt'  => 'Abgelehnt',
    'storniert'  => 'Storniert',
];

function places(): array
{
    static $p = null;
    if ($p === null) {
        $p = db()->query('SELECT * FROM places ORDER BY name')->fetchAll();
    }
    return $p;
}

function place_by_id(int $id): ?array
{
    foreach (places() as $p) {
        if ((int)$p['id'] === $id) {
            return $p;
        }
    }
    return null;
}

/** Entfernung per Luftlinie in km. */
function distance_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function listing_amenities(array $l): array
{
    $a = json_decode($l['amenities'] ?? '[]', true);
    return is_array($a) ? $a : [];
}

function listing_images(int $listingId): array
{
    $st = db()->prepare('SELECT * FROM listing_images WHERE listing_id = ? ORDER BY sort, id');
    $st->execute([$listingId]);
    return $st->fetchAll();
}

function image_url(?string $file): string
{
    if (!$file) {
        return asset('img/hero.jpg');
    }
    if (str_starts_with($file, 'demo:')) {
        return asset('img/' . basename(substr($file, 5)));
    }
    return url('uploads/' . rawurlencode(basename($file)));
}

function listing_cover(int $listingId): array
{
    $imgs = listing_images($listingId);
    return $imgs[0] ?? ['file' => null, 'alt' => ''];
}

function listing_by_slug(string $slug): ?array
{
    $st = db()->prepare('SELECT l.*, p.name AS place_name FROM listings l LEFT JOIN places p ON p.id = l.place_id WHERE l.slug = ?');
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

function listing_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT l.*, p.name AS place_name FROM listings l LEFT JOIN places p ON p.id = l.place_id WHERE l.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function listing_modes(array $l): array
{
    $m = [];
    if ($l['mode_urlaub']) $m[] = 'Urlaub';
    if ($l['mode_business']) $m[] = 'Business';
    if ($l['mode_langzeit']) $m[] = 'Wohnen auf Zeit';
    return $m;
}

/* ---------- E-Mail ---------- */

function send_mail(string $to, string $subject, string $body): bool
{
    if (!valid_email($to)) {
        return false;
    }
    $from = setting('contact_email');
    $headers = [
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    if (valid_email($from)) {
        $headers[] = 'From: ' . mb_encode_mimeheader(setting('site_name'), 'UTF-8') . ' <' . $from . '>';
    }
    if (getenv('EQ_NO_MAIL')) {
        return true;
    }
    return @mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $body, implode("\r\n", $headers));
}

function admin_notify(string $subject, string $body): void
{
    $to = setting('notify_email') ?: setting('contact_email');
    send_mail($to, '[' . setting('site_name') . '] ' . $subject, $body . "\n\nAdmin-Bereich: " . absolute_url('admin/'));
}

/* ---------- Seitenrahmen (öffentlich) ---------- */

function page_header(string $title, string $description = '', string $bodyClass = ''): void
{
    $site = setting('site_name', 'EmsQuartier');
    $full = $title ? $title . ' – ' . $site : $site . ' – ' . setting('site_claim');
    ?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($full) ?></title>
<?php if ($description): ?><meta name="description" content="<?= e($description) ?>">
<?php endif; ?>
<?php if (setting('noindex') === '1'): ?><meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%231f4e5a'/%3E%3Cpath d='M8 17l8-7 8 7v8H8z' fill='%23f3efe6'/%3E%3C/svg%3E">
</head>
<body class="<?= e($bodyClass) ?>">
<a class="skip" href="#main">Zum Inhalt springen</a>
<header class="site-header">
  <div class="wrap header-inner">
    <a class="brand" href="<?= e(url()) ?>"><span class="brand-mark" aria-hidden="true"></span><?= e($site) ?></a>
    <nav class="nav" aria-label="Hauptnavigation">
      <a href="<?= e(url('suche.php', ['modus' => 'urlaub'])) ?>">Urlaub</a>
      <a href="<?= e(url('suche.php', ['modus' => 'business'])) ?>">Wohnen auf Zeit</a>
      <a href="<?= e(url('firmen.php')) ?>">Für Firmen</a>
      <?php $acct = current_user(); ?>
      <?php if ($acct): ?>
        <a href="<?= e(url('konto/index.php')) ?>" class="nav-cta">Mein Konto</a>
      <?php else: ?>
        <a href="<?= e(url('konto/login.php')) ?>">Anmelden</a>
        <a href="<?= e(url('gastgeber.php')) ?>" class="nav-cta">Gastgeber werden</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main id="main">
<?php
    echo flashes();
}

function page_footer(): void
{
    $site = setting('site_name', 'EmsQuartier');
    ?>
</main>
<footer class="site-footer">
  <div class="wrap footer-grid">
    <div>
      <p class="brand brand-footer"><?= e($site) ?></p>
      <p><?= e(setting('site_claim')) ?></p>
    </div>
    <div>
      <h2 class="footer-h">Gäste</h2>
      <ul>
        <li><a href="<?= e(url('suche.php', ['modus' => 'urlaub'])) ?>">Urlaub im Emsland</a></li>
        <li><a href="<?= e(url('suche.php', ['modus' => 'business'])) ?>">Wohnen auf Zeit</a></li>
        <li><a href="<?= e(url('firmen.php')) ?>">Für Firmen und Kliniken</a></li>
      </ul>
    </div>
    <div>
      <h2 class="footer-h">Kontakt</h2>
      <ul>
        <?php if (setting('contact_phone')): ?><li><?= e(setting('contact_phone')) ?></li><?php endif; ?>
        <?php if (setting('contact_email')): ?><li><a href="mailto:<?= e(setting('contact_email')) ?>"><?= e(setting('contact_email')) ?></a></li><?php endif; ?>
        <li><a href="<?= e(url('gastgeber.php')) ?>">Gastgeber werden</a></li>
      </ul>
    </div>
    <div>
      <h2 class="footer-h">Rechtliches</h2>
      <ul>
        <li><a href="<?= e(url('seite.php', ['s' => 'impressum'])) ?>">Impressum</a></li>
        <li><a href="<?= e(url('seite.php', ['s' => 'datenschutz'])) ?>">Datenschutz</a></li>
        <li><a href="<?= e(url('seite.php', ['s' => 'agb'])) ?>">Buchungsbedingungen</a></li>
      </ul>
    </div>
  </div>
  <p class="wrap footer-note">© <?= date('Y') ?> <?= e($site) ?></p>
</footer>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
<?php
}

/** "1.234,50" oder "1234.50" → 1234.5 */
function parse_decimal(string $s): float
{
    $s = trim(str_replace([' ', '€'], '', $s));
    if (str_contains($s, ',')) {
        $s = str_replace(['.', ','], ['', '.'], $s);
    }
    return is_numeric($s) ? (float)$s : 0.0;
}

/** Bild-Upload prüfen und speichern. Gibt den Dateinamen zurück oder wirft eine Exception. */
function store_upload(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload fehlgeschlagen (Fehlercode ' . (int)($file['error'] ?? -1) . ').');
    }
    if ($file['size'] > 8 * 1024 * 1024) {
        throw new RuntimeException('Bild ist größer als 8 MB.');
    }
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) {
        throw new RuntimeException('Nur JPG-, PNG- oder WebP-Bilder sind erlaubt.');
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    $name = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $types[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $name)) {
        throw new RuntimeException('Bild konnte nicht gespeichert werden. Bitte Schreibrechte des Ordners „uploads“ prüfen.');
    }
    return $name;
}
