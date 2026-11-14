<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
$u = require_user('firma');
$cid = (int)$u['company_id'];

$st = db()->prepare('SELECT * FROM invoices WHERE company_id = ? ORDER BY invoice_date DESC, id DESC');
$st->execute([$cid]);
$collective = $st->fetchAll();
$st = db()->prepare('SELECT b.*, l.title FROM bookings b JOIN listings l ON l.id = b.listing_id WHERE b.company_id = ? AND b.invoice_no IS NOT NULL ORDER BY b.invoice_date DESC');
$st->execute([$cid]);
$single = $st->fetchAll();

konto_header('Rechnungen', 'rechnungen');
?>
<section class="account-main"><div class="wrap">
  <div class="admin-top"><h1>Rechnungen</h1></div>
  <?php if ($u['company_status'] !== 'freigeschaltet'): ?>
    <div class="alert alert-info" style="width:auto">Sammelrechnungen gibt es nach Freischaltung Ihres Firmenkontos.</div>
  <?php endif; ?>
  <div class="panel">
    <h2>Sammelrechnungen</h2>
    <p class="muted small">Einmal im Monat für alle bestätigten Aufenthalte. Langzeitaufenthalte werden anteilig nach Nächten im Monat abgerechnet.</p>
    <?php if (!$collective): ?><p class="muted">Noch keine Sammelrechnungen.</p><?php else: ?>
    <div class="table-wrap"><table class="data">
      <tr><th>Nr.</th><th>Datum</th><th>Zeitraum</th><th>Fällig</th><th class="num">Betrag</th><th>Status</th></tr>
      <?php foreach ($collective as $i): ?>
        <tr><td><a href="<?= e(url('konto/rechnung.php', ['id' => $i['id']])) ?>"><?= e($i['invoice_no']) ?></a></td><td><?= date_de($i['invoice_date']) ?></td>
          <td><?= date_de($i['period_from']) ?> – <?= date_de(date('Y-m-d', strtotime($i['period_to'] . ' -1 day'))) ?></td><td><?= date_de($i['due_date']) ?></td>
          <td class="num"><?= money((float)$i['total']) ?></td>
          <td><?= $i['status'] === 'bezahlt' ? '<span class="badge badge-ok">Bezahlt</span>' : ($i['due_date'] < date('Y-m-d') ? '<span class="badge badge-bad">Überfällig</span>' : '<span class="badge badge-warn">Offen</span>') ?></td></tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </div>
  <?php if ($single): ?>
  <div class="panel">
    <h2>Einzelrechnungen</h2>
    <div class="table-wrap"><table class="data">
      <tr><th>Nr.</th><th>Datum</th><th>Unterkunft / Gast</th><th class="num">Betrag</th><th>Status</th></tr>
      <?php foreach ($single as $b): ?>
        <tr><td><a href="<?= e(url('konto/rechnung.php', ['buchung' => $b['id']])) ?>"><?= e($b['invoice_no']) ?></a></td><td><?= date_de($b['invoice_date']) ?></td>
          <td><?= e($b['title']) ?> · <?= e($b['name']) ?></td><td class="num"><?= money((float)$b['price_total']) ?></td>
          <td><?= $b['payment_status'] === 'bezahlt' ? '<span class="badge badge-ok">Bezahlt</span>' : '<span class="badge badge-warn">Offen</span>' ?></td></tr>
      <?php endforeach; ?>
    </table></div>
  </div>
  <?php endif; ?>
</div></section>
<?php page_footer();
