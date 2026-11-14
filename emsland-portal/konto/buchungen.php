<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
$u = require_user();

[$where, $args] = booking_scope($u);
$status = get('status');
$zeit = get('zeit', 'aktuell');
$cc = get('kostenstelle');
$sql = "SELECT b.*, l.title, us.name AS booker FROM bookings b JOIN listings l ON l.id = b.listing_id LEFT JOIN users us ON us.id = b.booked_by WHERE $where";
if (isset(BOOKING_STATUS[$status])) { $sql .= ' AND b.status = ?'; $args[] = $status; }
if ($zeit === 'aktuell') { $sql .= ' AND b.date_to >= ?'; $args[] = date('Y-m-d'); }
if ($zeit === 'vergangen') { $sql .= ' AND b.date_to < ?'; $args[] = date('Y-m-d'); }
if ($cc !== '' && $u['role'] === 'firma') { $sql .= ' AND b.cost_center = ?'; $args[] = $cc; }
$sql .= $zeit === 'vergangen' ? ' ORDER BY b.date_from DESC' : ' ORDER BY b.date_from';
$st = db()->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

konto_header('Buchungen', 'buchungen');
?>
<section class="account-main"><div class="wrap">
  <div class="admin-top"><h1>Buchungen</h1>
    <?php if ($u['role'] === 'firma'): ?><a class="button" href="<?= e(url('suche.php', ['modus' => 'business'])) ?>">+ Neue Buchung</a><?php endif; ?></div>
  <form class="panel" method="get" action="<?= e(url('konto/buchungen.php')) ?>">
    <div class="field-row" style="align-items:end">
      <div class="field" style="margin:0"><label for="status">Status</label><select id="status" name="status"><option value="">Alle</option>
        <?php foreach (BOOKING_STATUS as $k => $l): ?><option value="<?= $k ?>" <?= $k === $status ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="margin:0"><label for="zeit">Zeitraum</label><select id="zeit" name="zeit">
        <?php foreach (['aktuell' => 'Aktuell & künftig', 'vergangen' => 'Vergangen', 'alle' => 'Alle'] as $k => $l): ?><option value="<?= $k ?>" <?= $k === $zeit ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <?php if ($u['role'] === 'firma'): ?>
      <div class="field" style="margin:0"><label for="kostenstelle">Kostenstelle</label><select id="kostenstelle" name="kostenstelle"><option value="">Alle</option>
        <?php foreach (company_cost_centers((int)$u['company_id']) as $c): ?><option <?= $c['name'] === $cc ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <div><button class="button" type="submit">Filtern</button></div>
    </div>
  </form>
  <div class="panel">
    <?php if (!$rows): ?><p class="muted">Keine Buchungen gefunden.</p><?php else: ?>
    <div class="table-wrap"><table class="data">
      <tr><th>Nr.</th><th>Gast</th><th>Unterkunft</th><th>Zeitraum</th><?= $u['role'] === 'firma' ? '<th>Kostenstelle</th><th>Gebucht von</th>' : '' ?><th class="num">Preis</th><th>Status</th></tr>
      <?php foreach ($rows as $b): ?>
        <tr>
          <td><a href="<?= e(url('konto/buchung.php', ['id' => $b['id']])) ?>"><?= e($b['ref']) ?></a></td>
          <td><?= e($b['name']) ?></td>
          <td><?= e($b['title']) ?></td>
          <td><?= date_de($b['date_from']) ?> – <?= date_de($b['date_to']) ?><?= $b['series_ref'] ? '<br><span class="badge badge-business">Serie ' . e($b['series_ref']) . '</span>' : '' ?></td>
          <?php if ($u['role'] === 'firma'): ?><td><?= e($b['cost_center']) ?></td><td><?= e($b['booker'] ?? '') ?></td><?php endif; ?>
          <td class="num"><?= money((float)$b['price_total']) ?></td>
          <td><?= booking_status_badge($b['status']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </div>
</div></section>
<?php page_footer();
