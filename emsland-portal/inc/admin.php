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

function admin_login(array $admin): void
{
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$admin['id'];
    $_SESSION['admin_seen'] = time();
    unset($_SESSION['csrf']);
    db()->prepare("UPDATE admins SET last_login = datetime('now','localtime') WHERE id = ?")->execute([$admin['id']]);
}

function admin_header(string $title, string $active = ''): void
{
    $admin = current_admin();
    $open = (int)db()->query("SELECT COUNT(*) FROM bookings WHERE status = 'angefragt'")->fetchColumn();
    $inq = (int)db()->query("SELECT COUNT(*) FROM inquiries WHERE status = 'offen'")->fetchColumn();
    $firms = (int)db()->query("SELECT COUNT(*) FROM companies WHERE status = 'beantragt'")->fetchColumn();
    $overdue = (int)db()->query("SELECT COUNT(*) FROM invoices WHERE status = 'offen' AND due_date < date('now','localtime')")->fetchColumn();
    $nav = [
        'dashboard'   => ['admin/index.php', 'Übersicht', 0],
        'buchungen'   => ['admin/buchungen.php', 'Buchungen', $open],
        'sammelrechnungen' => ['admin/sammelrechnungen.php', 'Sammelrechnungen', $overdue],
        'unterkuenfte'=> ['admin/unterkuenfte.php', 'Unterkünfte', 0],
        'firmen'      => ['admin/firmen.php', 'Firmenkonten', $firms],
        'gastgeber'   => ['admin/gastgeber-konten.php', 'Gastgeber-Zugänge', 0],
        'anfragen'    => ['admin/anfragen.php', 'Kontaktanfragen', $inq],
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
