<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

const SESSION_IDLE_SECONDS = 7200;

function current_admin(): ?array
{
    $id = (int)($_SESSION['admin_id'] ?? 0);
    if (!$id) {
        return null;
    }
    if (time() - (int)($_SESSION['admin_seen'] ?? 0) > SESSION_IDLE_SECONDS) {
        unset($_SESSION['admin_id']);
        return null;
    }
    $st = db()->prepare('SELECT id, name, email FROM admins WHERE id = ?');
    $st->execute([$id]);
    $a = $st->fetch() ?: null;
    if ($a) {
        $_SESSION['admin_seen'] = time();
    }
    return $a;
}

function require_admin(): array
{
    $a = current_admin();
    if (!$a) {
        redirect(url('admin/login.php'));
    }
    return $a;
}

function admin_count(): int
{
    return (int)db()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
}

function login_blocked(string $ip): bool
{
    $since = time() - 900;
    db()->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([time() - 86400]);
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > ?');
    $st->execute([$ip, $since]);
    return (int)$st->fetchColumn() >= 5;
}

function login_failed(string $ip): void
{
    db()->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)')->execute([$ip, time()]);
}

function admin_login(array $admin): void
{
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$admin['id'];
    $_SESSION['admin_seen'] = time();
    unset($_SESSION['csrf']);
    db()->prepare("UPDATE admins SET last_login = datetime('now','localtime') WHERE id = ?")->execute([$admin['id']]);
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

/** iCal-Kalender einer fremden Plattform importieren (ersetzt bisherige importierte Sperren). */
function ical_import(array $l): int
{
    $src = trim($l['ical_import_url']);
    if (!preg_match('#^https?://#i', $src)) {
        throw new RuntimeException('Bitte eine gültige iCal-Adresse (https://…) eintragen.');
    }
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'follow_location' => 1, 'max_redirects' => 3, 'user_agent' => 'Kalender-Sync']]);
    $data = @file_get_contents($src, false, $ctx, 0, 2 * 1024 * 1024);
    if ($data === false || !str_contains($data, 'BEGIN:VCALENDAR')) {
        throw new RuntimeException('Der Kalender konnte nicht geladen werden.');
    }
    $data = preg_replace("/\r?\n[ \t]/", '', $data); // gefaltete Zeilen
    preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $data, $m);
    $events = [];
    foreach ($m[1] as $ev) {
        if (!preg_match('/^DTSTART[^:]*:(\d{8})/m', $ev, $s)) continue;
        $e = preg_match('/^DTEND[^:]*:(\d{8})/m', $ev, $x) ? $x[1] : null;
        $from = DateTimeImmutable::createFromFormat('!Ymd', $s[1]);
        $to = $e ? DateTimeImmutable::createFromFormat('!Ymd', $e) : $from->modify('+1 day');
        if (!$from || !$to || $to <= $from) continue;
        $summary = preg_match('/^SUMMARY:(.*)$/m', $ev, $sm) ? trim($sm[1]) : 'Import';
        $events[] = [$from->format('Y-m-d'), $to->format('Y-m-d'), mb_substr($summary, 0, 80)];
    }
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM blocks WHERE listing_id = ? AND source = 'ical'")->execute([$l['id']]);
    $ins = $pdo->prepare("INSERT INTO blocks (listing_id, date_from, date_to, reason, source) VALUES (?, ?, ?, ?, 'ical')");
    foreach ($events as $ev) {
        $ins->execute([$l['id'], $ev[0], $ev[1], 'iCal: ' . $ev[2]]);
    }
    $pdo->prepare("UPDATE listings SET ical_synced_at = datetime('now','localtime') WHERE id = ?")->execute([$l['id']]);
    $pdo->commit();
    return count($events);
}

function status_badge(string $status): string
{
    $cls = ['angefragt' => 'badge-warn', 'bestaetigt' => 'badge-ok', 'abgelehnt' => 'badge-bad', 'storniert' => 'badge-muted'][$status] ?? 'badge-muted';
    return '<span class="badge ' . $cls . '">' . e(BOOKING_STATUS[$status] ?? $status) . '</span>';
}

function admin_header(string $title, string $active = ''): void
{
    $admin = current_admin();
    $open = (int)db()->query("SELECT COUNT(*) FROM bookings WHERE status = 'angefragt'")->fetchColumn();
    $inq = (int)db()->query("SELECT COUNT(*) FROM inquiries WHERE status = 'offen'")->fetchColumn();
    $nav = [
        'dashboard'   => ['admin/index.php', 'Übersicht', 0],
        'buchungen'   => ['admin/buchungen.php', 'Buchungen', $open],
        'unterkuenfte'=> ['admin/unterkuenfte.php', 'Unterkünfte', 0],
        'anfragen'    => ['admin/anfragen.php', 'Firmen & Gastgeber', $inq],
        'orte'        => ['admin/orte.php', 'Orte', 0],
        'seiten'      => ['admin/seiten.php', 'Seiten & Texte', 0],
        'einstellungen' => ['admin/einstellungen.php', 'Einstellungen', 0],
    ];
    ?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> – Admin – <?= e(setting('site_name')) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body class="admin-body">
<div class="admin-shell">
  <nav class="admin-nav" aria-label="Admin-Navigation">
    <a class="brand" href="<?= e(url('admin/index.php')) ?>"><span class="brand-mark" aria-hidden="true"></span><?= e(setting('site_name')) ?></a>
    <ul>
      <?php foreach ($nav as $key => [$href, $label, $count]): ?>
        <li><a href="<?= e(url($href)) ?>" class="<?= $key === $active ? 'active' : '' ?>"><?= e($label) ?><?php if ($count): ?><span class="count"><?= $count ?></span><?php endif; ?></a></li>
      <?php endforeach; ?>
      <li class="sep"></li>
      <li><a href="<?= e(url()) ?>" target="_blank">Webseite ansehen ↗</a></li>
      <li>
        <form method="post" action="<?= e(url('admin/logout.php')) ?>"><?= csrf_field() ?><button class="linklike" type="submit">Abmelden<?= $admin ? ' (' . e($admin['name']) . ')' : '' ?></button></form>
      </li>
    </ul>
  </nav>
  <main class="admin-main">
    <?= flashes() ?>
<?php
}

function admin_footer(): void
{
    ?>
  </main>
</div>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
<?php
}
