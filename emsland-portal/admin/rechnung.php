<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/admin.php';
require_admin();

$st = db()->prepare('SELECT b.*, l.title, l.address, p.name AS place_name FROM bookings b JOIN listings l ON l.id = b.listing_id LEFT JOIN places p ON p.id = l.place_id WHERE b.id = ?');
$st->execute([(int)get('id')]);
$b = $st->fetch();
if (!$b || !$b['invoice_no']) {
    flash('error', 'Rechnung nicht gefunden.');
    redirect(url('admin/buchungen.php'));
}
$lines = json_decode($b['price_detail'], true) ?: [['Unterkunft', (float)$b['price_total']]];
$total = (float)$b['price_total'];
$small = setting('small_business') === '1';
$rate = (float)str_replace(',', '.', setting('vat_rate', '7'));
$net = $small ? $total : round($total / (1 + $rate / 100), 2);
$vat = round($total - $net, 2);
$nights = (int)valid_date($b['date_from'])->diff(valid_date($b['date_to']))->days;
$recipient = $b['company'] ? $b['company'] . "\n" . ($b['billing_address'] ?: $b['name']) : $b['name'] . ($b['billing_address'] ? "\n" . $b['billing_address'] : '');
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Rechnung <?= e($b['invoice_no']) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body>
<div class="no-print">
  <button class="button" onclick="window.print()">Drucken / als PDF speichern</button>
  <a class="button button-ghost" href="<?= e(url('admin/buchung.php', ['id' => $b['id']])) ?>">Zurück zur Buchung</a>
  <p class="hint">Vorlage – bitte Pflichtangaben (Steuernummer, Anschrift) in den Einstellungen prüfen und steuerlich beraten lassen.</p>
</div>
<div class="invoice">
  <div class="head">
    <div>
      <p class="muted small" style="margin-bottom:4px"><?= e(setting('company_name')) ?> · <?= e(str_replace("\n", ' · ', setting('company_address'))) ?></p>
      <p><?= nl2br(e($recipient)) ?></p>
    </div>
    <div style="text-align:right">
      <p class="brand" style="font-size:1.4rem"><?= e(setting('site_name')) ?></p>
      <p class="small"><?= nl2br(e(setting('company_name') . "\n" . setting('company_address'))) ?><br><?= e(setting('contact_phone')) ?><br><?= e(setting('contact_email')) ?></p>
    </div>
  </div>
  <h1>Rechnung</h1>
  <dl class="kv" style="margin-bottom:12px">
    <dt>Rechnungsnummer</dt><dd><?= e($b['invoice_no']) ?></dd>
    <dt>Rechnungsdatum</dt><dd><?= date_de($b['invoice_date']) ?></dd>
    <dt>Leistungszeitraum</dt><dd><?= date_de($b['date_from']) ?> – <?= date_de($b['date_to']) ?> (<?= $nights ?> Nächte)</dd>
    <dt>Buchungs-Nr.</dt><dd><?= e($b['ref']) ?></dd>
    <?php if ($b['vat_id']): ?><dt>USt-IdNr. Kunde</dt><dd><?= e($b['vat_id']) ?></dd><?php endif; ?>
    <?php if ($b['cost_center']): ?><dt>Kostenstelle / Bestell-Nr.</dt><dd><?= e($b['cost_center']) ?></dd><?php endif; ?>
    <?php if ($b['company']): ?><dt>Gast</dt><dd><?= e($b['name']) ?></dd><?php endif; ?>
  </dl>
  <table>
    <tr><th>Leistung</th><th class="num">Betrag</th></tr>
    <tr><td colspan="2"><strong>Beherbergung: <?= e($b['title']) ?>, <?= e($b['place_name'] ?? '') ?></strong>, <?= (int)$b['guests'] ?> Person(en)</td></tr>
    <?php foreach ($lines as [$t, $v]): ?><tr><td><?= e($t) ?></td><td class="num"><?= money((float)$v) ?></td></tr><?php endforeach; ?>
    <tr><td><strong>Gesamtbetrag</strong></td><td class="num"><strong><?= money($total) ?></strong></td></tr>
    <?php if (!$small): ?>
      <tr><td>darin enthalten: Nettobetrag</td><td class="num"><?= money($net) ?></td></tr>
      <tr><td>darin enthalten: <?= e(rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',')) ?> % Umsatzsteuer</td><td class="num"><?= money($vat) ?></td></tr>
    <?php endif; ?>
  </table>
  <?php if ($small): ?><p class="small">Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.</p><?php endif; ?>
  <p><?= nl2br(e(setting('payment_info'))) ?></p>
  <p class="small muted"><?= e(setting('company_name')) ?><?= setting('tax_id') ? ' · Steuernummer/USt-IdNr.: ' . e(setting('tax_id')) : '' ?></p>
</div>
</body>
</html>
