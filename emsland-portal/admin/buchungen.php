<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/admin.php';
require_admin();

$status = get('status');
$listing = (int)get('unterkunft');
$q = get('q');
$zeit = get('zeit', 'aktuell');

$sql = 'SELECT b.*, l.title FROM bookings b JOIN listings l ON l.id = b.listing_id WHERE 1=1';
$args = [];
if (isset(BOOKING_STATUS[$status])) { $sql .= ' AND b.status = ?'; $args[] = $status; }
if ($listing) { $sql .= ' AND b.listing_id = ?'; $args[] = $listing; }
if ($q !== '') {
    $sql .= ' AND (b.name LIKE ? OR b.email LIKE ? OR b.company LIKE ? OR b.ref LIKE ?)';
    array_push($args, "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($zeit === 'aktuell') { $sql .= ' AND b.date_to >= ?'; $args[] = date('Y-m-d'); }
if ($zeit === 'vergangen') { $sql .= ' AND b.date_to < ?'; $args[] = date('Y-m-d'); }
$sql .= $zeit === 'vergangen' ? ' ORDER BY b.date_from DESC' : ' ORDER BY (b.status = \'angefragt\') DESC, b.date_from ASC';
$st = db()->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

if (get('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="buchungen-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Nr.', 'Status', 'Unterkunft', 'Anreise', 'Abreise', 'Personen', 'Name', 'E-Mail', 'Telefon', 'Firma', 'USt-IdNr.', 'Kostenstelle', 'Anlass', 'Preis', 'Rechnung', 'Eingang'], ';');
    foreach ($rows as $b) {
        fputcsv($out, [$b['ref'], BOOKING_STATUS[$b['status']] ?? $b['status'], $b['title'], $b['date_from'], $b['date_to'], $b['guests'], $b['name'], $b['email'], $b['phone'],
            $b['company'], $b['vat_id'], $b['cost_center'], $b['purpose'], number_format((float)$b['price_total'], 2, ',', ''), $b['invoice_no'], $b['created_at']], ';');
    }
    exit;
}

$listings = db()->query('SELECT id, title FROM listings ORDER BY title')->fetchAll();
admin_header('Buchungen', 'buchungen');
?>
<div class="admin-top"><h1>Buchungen</h1>
  <div class="actions">
    <a class="button button-ghost button-small" href="<?= e(url('admin/buchungen.php', ['status' => $status, 'unterkunft' => $listing ?: null, 'q' => $q, 'zeit' => $zeit, 'export' => 'csv'])) ?>">Als CSV exportieren</a>
    <a class="button button-small" href="<?= e(url('admin/buchung.php', ['neu' => 1])) ?>">+ Buchung eintragen</a>
  </div>
</div>

<div class="tabs">
  <a href="<?= e(url('admin/buchungen.php', ['zeit' => $zeit, 'unterkunft' => $listing ?: null, 'q' => $q])) ?>" class="<?= $status === '' ? 'active' : '' ?>">Alle</a>
  <?php foreach (BOOKING_STATUS as $k => $label): ?>
    <a href="<?= e(url('admin/buchungen.php', ['status' => $k, 'zeit' => $zeit, 'unterkunft' => $listing ?: null, 'q' => $q])) ?>" class="<?= $status === $k ? 'active' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<form class="panel" method="get" action="<?= e(url('admin/buchungen.php')) ?>">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <div class="field-row" style="align-items:end">
    <div class="field" style="margin:0"><label for="q">Suche</label><input type="text" id="q" name="q" value="<?= e($q) ?>" placeholder="Name, E-Mail, Firma, Nr."></div>
    <div class="field" style="margin:0"><label for="u">Unterkunft</label>
      <select id="u" name="unterkunft"><option value="">Alle</option>
        <?php foreach ($listings as $l): ?><option value="<?= (int)$l['id'] ?>" <?= (int)$l['id'] === $listing ? 'selected' : '' ?>><?= e($l['title']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field" style="margin:0"><label for="zeit">Zeitraum</label>
      <select id="zeit" name="zeit">
        <option value="aktuell" <?= $zeit === 'aktuell' ? 'selected' : '' ?>>Aktuell &amp; künftig</option>
        <option value="vergangen" <?= $zeit === 'vergangen' ? 'selected' : '' ?>>Vergangen</option>
        <option value="alle" <?= $zeit === 'alle' ? 'selected' : '' ?>>Alle</option>
      </select></div>
    <div><button class="button" type="submit">Filtern</button></div>
  </div>
</form>

<div class="panel">
  <?php if (!$rows): ?><p class="muted">Keine Buchungen gefunden.</p><?php else: ?>
  <div class="table-wrap"><table class="data">
    <tr><th>Nr.</th><th>Gast</th><th>Unterkunft</th><th>Zeitraum</th><th>Pers.</th><th>Art</th><th class="num">Preis</th><th>Status</th></tr>
    <?php foreach ($rows as $b): $n = (int)(new DateTime($b['date_from']))->diff(new DateTime($b['date_to']))->days; ?>
      <tr>
        <td><a href="<?= e(url('admin/buchung.php', ['id' => $b['id']])) ?>"><?= e($b['ref']) ?></a></td>
        <td><a href="<?= e(url('admin/buchung.php', ['id' => $b['id']])) ?>"><?= e($b['name']) ?></a><?= $b['company'] ? '<br><span class="muted small">' . e($b['company']) . '</span>' : '' ?></td>
        <td><?= e($b['title']) ?></td>
        <td><?= date_de($b['date_from']) ?> – <?= date_de($b['date_to']) ?><br><span class="muted small"><?= $n ?> Nächte<?= $b['weekdays_only'] ? ', nur Mo–Fr' : '' ?></span></td>
        <td><?= (int)$b['guests'] ?></td>
        <td><?= e(ucfirst($b['mode'])) ?></td>
        <td class="num"><?= money((float)$b['price_total']) ?></td>
        <td><?= status_badge($b['status']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
<?php admin_footer();
