<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
$u = require_user();

[$where, $args] = booking_scope($u);
$q = fn(string $sql, array $a = []) => (function () use ($sql, $a) { $st = db()->prepare($sql); $st->execute($a); return $st; })();
$base = "FROM bookings b JOIN listings l ON l.id = b.listing_id WHERE $where";
$today = date('Y-m-d');
$open = (int)$q("SELECT COUNT(*) $base AND b.status = 'angefragt' AND b.date_to >= ?", [...$args, $today])->fetchColumn();
$current = $q("SELECT b.*, l.title $base AND b.status = 'bestaetigt' AND b.date_to >= ? ORDER BY b.date_from LIMIT 8", [...$args, $today])->fetchAll();
$requests = $q("SELECT b.*, l.title $base AND b.status = 'angefragt' AND b.date_to >= ? ORDER BY b.date_from LIMIT 8", [...$args, $today])->fetchAll();

konto_header('Mein Konto', 'index');
?>
<section class="account-main"><div class="wrap">
<?php if ($u['role'] === 'firma'): ?>
  <?php
  $unpaid = $q("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS s FROM invoices WHERE company_id = ? AND status = 'offen'", [$u['company_id']])->fetch();
  ?>
  <div class="admin-top"><h1>Willkommen, <?= e($u['name']) ?></h1><a class="button button-accent" href="<?= e(url('suche.php', ['modus' => 'business'])) ?>">Unterkunft suchen</a></div>
  <?php if ($u['company_status'] === 'beantragt'): ?>
    <div class="alert alert-info" style="width:auto">Ihr Firmenkonto wird gerade geprüft. Sie können schon Anfragen stellen. Nach der Freischaltung werden Buchungen über die monatliche Sammelrechnung abgerechnet.</div>
  <?php endif; ?>
  <div class="stats">
    <div class="stat"><strong><?= count($current) ?></strong><span>laufende und kommende Aufenthalte</span></div>
    <div class="stat"><strong><?= $open ?></strong><span>offene Anfragen</span></div>
    <a class="stat" href="<?= e(url('konto/rechnungen.php')) ?>" style="text-decoration:none"><strong><?= money((float)$unpaid['s'], false) ?></strong><span><?= (int)$unpaid['n'] ?> offene Rechnung(en)</span></a>
    <div class="stat"><strong><?= (float)$u['company_max_price'] > 0 ? money((float)$u['company_max_price'], false) : '–' ?></strong><span>Preisgrenze pro Nacht</span></div>
  </div>
<?php else: ?>
  <?php $listings = $q('SELECT COUNT(*) FROM listings WHERE host_user_id = ?', [$u['id']])->fetchColumn(); ?>
  <div class="admin-top"><h1>Willkommen, <?= e($u['name']) ?></h1><a class="button" href="<?= e(url('konto/unterkuenfte.php')) ?>">Meine Unterkünfte (<?= (int)$listings ?>)</a></div>
  <?php if (!(int)$listings): ?><div class="alert alert-info" style="width:auto">Ihrem Zugang ist noch keine Unterkunft zugeordnet. Wir melden uns, sobald Ihr Inserat angelegt ist.</div><?php endif; ?>
<?php endif; ?>

  <div class="panel">
    <h2>Offene Anfragen</h2>
    <?php if (!$requests): ?><p class="muted">Keine offenen Anfragen.</p><?php else: ?>
    <div class="table-wrap"><table class="data">
      <tr><th>Gast</th><th>Unterkunft</th><th>Zeitraum</th><th class="num">Preis</th><th></th></tr>
      <?php foreach ($requests as $b): ?>
        <tr><td><?= e($b['name']) ?></td><td><?= e($b['title']) ?></td><td><?= date_de($b['date_from']) ?> – <?= date_de($b['date_to']) ?><?= $b['series_ref'] ? ' <span class="badge badge-business">Serie</span>' : '' ?></td>
          <td class="num"><?= money((float)$b['price_total']) ?></td><td><a href="<?= e(url('konto/buchung.php', ['id' => $b['id']])) ?>"><?= $u['role'] === 'gastgeber' ? 'Prüfen' : 'Details' ?></a></td></tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>Laufende und kommende Aufenthalte</h2>
    <?php if (!$current): ?><p class="muted">Keine bestätigten Aufenthalte.</p><?php else: ?>
    <div class="table-wrap"><table class="data">
      <tr><th>Anreise</th><th>Abreise</th><th>Gast</th><th>Unterkunft</th><th></th></tr>
      <?php foreach ($current as $b): ?>
        <tr><td><?= date_de($b['date_from'], true) ?></td><td><?= date_de($b['date_to'], true) ?></td><td><?= e($b['name']) ?></td><td><?= e($b['title']) ?></td>
          <td><a href="<?= e(url('konto/buchung.php', ['id' => $b['id']])) ?>">Details</a></td></tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </div>
</div></section>
<?php page_footer();
