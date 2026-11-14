<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/admin.php';
$admin = require_admin();

$pdo = db();
$today = date('Y-m-d');
$in30 = date('Y-m-d', strtotime('+30 days'));
$open = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'angefragt'")->fetchColumn();
$active = (int)$pdo->query('SELECT COUNT(*) FROM listings WHERE active = 1')->fetchColumn();
$inq = (int)$pdo->query("SELECT COUNT(*) FROM inquiries WHERE status = 'offen'")->fetchColumn();

// Auslastung der nächsten 30 Tage (bestätigte Buchungen + Sperren)
$booked = 0;
foreach ($pdo->query('SELECT id FROM listings WHERE active = 1')->fetchAll() as $l) {
    $booked += count(occupied_nights((int)$l['id'], $today, $in30));
}
$occ = $active ? round($booked / ($active * 30) * 100) : 0;

$st = $pdo->prepare("SELECT COALESCE(SUM(price_total), 0) FROM bookings WHERE status = 'bestaetigt' AND substr(date_from, 1, 4) = ?");
$st->execute([date('Y')]);
$revenue = (float)$st->fetchColumn();

$arrivals = $pdo->prepare("SELECT b.*, l.title FROM bookings b JOIN listings l ON l.id = b.listing_id
    WHERE b.status = 'bestaetigt' AND b.date_from BETWEEN ? AND ? ORDER BY b.date_from LIMIT 10");
$arrivals->execute([$today, date('Y-m-d', strtotime('+14 days'))]);
$arrivals = $arrivals->fetchAll();

$latest = $pdo->query("SELECT b.*, l.title FROM bookings b JOIN listings l ON l.id = b.listing_id ORDER BY b.created_at DESC, b.id DESC LIMIT 8")->fetchAll();

admin_header('Übersicht', 'dashboard');
?>
<div class="admin-top"><h1>Hallo <?= e($admin['name']) ?></h1><a class="button" href="<?= e(url('admin/buchung.php', ['neu' => 1])) ?>">+ Buchung eintragen</a></div>

<?php if (setting('contact_email') === 'info@example.de'): ?>
  <div class="alert alert-info">Bitte hinterlegen Sie unter <a href="<?= e(url('admin/einstellungen.php')) ?>">Einstellungen</a> Ihre Kontaktdaten und unter <a href="<?= e(url('admin/seiten.php')) ?>">Seiten &amp; Texte</a> Impressum und Datenschutzerklärung.</div>
<?php endif; ?>

<?php $newFirms = (int)$pdo->query("SELECT COUNT(*) FROM companies WHERE status = 'beantragt'")->fetchColumn();
$overdue = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS s FROM invoices WHERE status = 'offen' AND due_date < date('now','localtime')")->fetch(); ?>
<?php if ($newFirms): ?><div class="alert alert-info"><?= $newFirms ?> neue(s) Firmenkonto/-konten warten auf Freischaltung. <a href="<?= e(url('admin/firmen.php', ['status' => 'beantragt'])) ?>">Jetzt prüfen</a></div><?php endif; ?>
<?php if ((int)$overdue['n']): ?><div class="alert alert-error"><?= (int)$overdue['n'] ?> Sammelrechnung(en) überfällig (<?= money((float)$overdue['s']) ?>). <a href="<?= e(url('admin/sammelrechnungen.php', ['status' => 'offen'])) ?>">Ansehen</a></div><?php endif; ?>
<div class="stats">
  <a class="stat" href="<?= e(url('admin/buchungen.php', ['status' => 'angefragt'])) ?>" style="text-decoration:none"><strong><?= $open ?></strong><span>offene Buchungsanfragen</span></a>
  <div class="stat"><strong><?= $occ ?> %</strong><span>Auslastung nächste 30 Tage</span></div>
  <div class="stat"><strong><?= money($revenue, false) ?></strong><span>bestätigter Umsatz <?= date('Y') ?></span></div>
  <a class="stat" href="<?= e(url('admin/anfragen.php')) ?>" style="text-decoration:none"><strong><?= $inq ?></strong><span>offene Firmen-/Gastgeberanfragen</span></a>
</div>

<div class="panel">
  <h2>Anreisen in den nächsten 14 Tagen</h2>
  <?php if (!$arrivals): ?><p class="muted">Keine bestätigten Anreisen.</p><?php else: ?>
  <div class="table-wrap"><table class="data">
    <tr><th>Anreise</th><th>Abreise</th><th>Unterkunft</th><th>Gast</th><th>Pers.</th></tr>
    <?php foreach ($arrivals as $b): ?>
      <tr><td><?= date_de($b['date_from'], true) ?></td><td><?= date_de($b['date_to'], true) ?></td><td><?= e($b['title']) ?></td>
        <td><a href="<?= e(url('admin/buchung.php', ['id' => $b['id']])) ?>"><?= e($b['name']) ?></a><?= $b['company'] ? '<br><span class="muted small">' . e($b['company']) . '</span>' : '' ?></td><td><?= (int)$b['guests'] ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Neueste Buchungen und Anfragen</h2>
  <?php if (!$latest): ?><p class="muted">Noch keine Anfragen eingegangen.</p><?php else: ?>
  <div class="table-wrap"><table class="data">
    <tr><th>Eingang</th><th>Gast</th><th>Unterkunft</th><th>Zeitraum</th><th>Art</th><th class="num">Preis</th><th>Status</th></tr>
    <?php foreach ($latest as $b): ?>
      <tr>
        <td><?= date_de(substr($b['created_at'], 0, 10)) ?></td>
        <td><a href="<?= e(url('admin/buchung.php', ['id' => $b['id']])) ?>"><?= e($b['name']) ?></a></td>
        <td><?= e($b['title']) ?></td>
        <td><?= date_de($b['date_from']) ?> – <?= date_de($b['date_to']) ?></td>
        <td><?= e(ucfirst($b['mode'])) ?></td>
        <td class="num"><?= money((float)$b['price_total']) ?></td>
        <td><?= status_badge($b['status']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
<?php admin_footer();
