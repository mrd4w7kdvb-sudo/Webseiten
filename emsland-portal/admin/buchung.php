<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/admin.php';
require_admin();

$pdo = db();
$id = (int)get('id');
$isNew = get('neu') === '1' || $id === 0;
$b = null;
if (!$isNew) {
    $b = booking_by_id($id);
    if (!$b) {
        flash('error', 'Buchung nicht gefunden.');
        redirect(url('admin/buchungen.php'));
    }
}
$listings = $pdo->query('SELECT * FROM listings ORDER BY title')->fetchAll();
$companies = $pdo->query('SELECT id, name, status, vat_id, billing_address FROM companies ORDER BY name')->fetchAll();
$inCollective = [];
if ($b) {
    $st = $pdo->prepare('SELECT i.id, i.invoice_no, it.period_from, it.period_to, it.amount FROM invoice_items it JOIN invoices i ON i.id = it.invoice_id WHERE it.booking_id = ? ORDER BY it.period_from');
    $st->execute([$b['id']]);
    $inCollective = $st->fetchAll();
}
$fields = ['listing_id', 'status', 'mode', 'date_from', 'date_to', 'guests', 'name', 'email', 'phone', 'company', 'vat_id', 'cost_center', 'billing_address', 'purpose', 'message', 'admin_note', 'price_total'];
$data = $b ?? ['listing_id' => (int)get('unterkunft') ?: ($listings[0]['id'] ?? 0), 'status' => 'bestaetigt', 'mode' => 'urlaub', 'date_from' => '', 'date_to' => '', 'guests' => 2,
    'name' => '', 'email' => '', 'phone' => '', 'company' => '', 'vat_id' => '', 'cost_center' => '', 'billing_address' => '', 'purpose' => '', 'message' => '', 'admin_note' => '', 'price_total' => '', 'weekdays_only' => 0, 'company_id' => null];
$errors = [];

if (is_post()) {
    check_csrf();
    $action = post('action');

    if ($action === 'delete' && $b) {
        $pdo->prepare('DELETE FROM bookings WHERE id = ?')->execute([$b['id']]);
        flash('ok', 'Buchung ' . $b['ref'] . ' wurde gelöscht.');
        redirect(url('admin/buchungen.php'));
    }

    if ($action === 'invoice' && $b) {
        if ($inCollective) {
            flash('error', 'Diese Buchung ist bereits (teilweise) in einer Sammelrechnung enthalten.');
            redirect(url('admin/buchung.php', ['id' => $b['id']]));
        }
        assign_booking_invoice((int)$b['id']);
        redirect(url('admin/rechnung.php', ['id' => $b['id']]));
    }

    if ($action === 'payment' && $b) {
        $paid = $b['payment_status'] !== 'bezahlt';
        $pdo->prepare('UPDATE bookings SET payment_status = ?, paid_at = ? WHERE id = ?')->execute([$paid ? 'bezahlt' : 'offen', $paid ? date('Y-m-d') : null, $b['id']]);
        flash('ok', $paid ? 'Zahlung als eingegangen markiert.' : 'Zahlung wieder auf offen gesetzt.');
        redirect(url('admin/buchung.php', ['id' => $b['id']]));
    }

    if ($action === 'series' && $b && $b['series_ref'] && isset(BOOKING_STATUS[post('status')])) {
        $errs = series_change_status($b['series_ref'], post('status'), post('notify') === '1');
        foreach ($errs as $err) flash('error', $err);
        flash('ok', 'Serie ' . $b['series_ref'] . ': Status auf „' . BOOKING_STATUS[post('status')] . '“ gesetzt' . ($errs ? ' (außer den genannten Terminen).' : '.'));
        redirect(url('admin/buchung.php', ['id' => $b['id']]));
    }

    // Speichern
    foreach ($fields as $f) {
        $data[$f] = post($f);
    }
    $data['weekdays_only'] = post('weekdays_only') === '1' ? 1 : 0;
    $data['company_id'] = null;
    foreach ($companies as $c) {
        if ((int)$c['id'] === (int)post('company_id')) {
            $data['company_id'] = (int)$c['id'];
            if ($data['company'] === '') $data['company'] = $c['name'];
            if ($data['vat_id'] === '') $data['vat_id'] = $c['vat_id'];
            if ($data['billing_address'] === '') $data['billing_address'] = $c['billing_address'];
        }
    }
    $listing = null;
    foreach ($listings as $l) {
        if ((int)$l['id'] === (int)$data['listing_id']) $listing = $l;
    }
    if (!$listing) $errors[] = 'Bitte eine Unterkunft wählen.';
    $d1 = valid_date($data['date_from']);
    $d2 = valid_date($data['date_to']);
    if (!$d1 || !$d2 || $d2 <= $d1) $errors[] = 'Bitte gültige An- und Abreisedaten angeben (Abreise nach Anreise).';
    if ($data['name'] === '') $errors[] = 'Bitte einen Namen angeben.';
    if ($data['email'] !== '' && !valid_email($data['email'])) $errors[] = 'Die E-Mail-Adresse ist ungültig.';
    if (!isset(BOOKING_STATUS[$data['status']])) $data['status'] = 'angefragt';
    if (!in_array($data['mode'], ['urlaub', 'business', 'langzeit'], true)) $data['mode'] = 'urlaub';
    if (!$errors && $data['status'] === 'bestaetigt' && !is_available((int)$data['listing_id'], $data['date_from'], $data['date_to'], $b ? (int)$b['id'] : null)) {
        $errors[] = 'Der Zeitraum überschneidet sich mit einer anderen bestätigten Buchung oder einer Sperrzeit.';
    }

    $priceDetail = $b['price_detail'] ?? '[]';
    if (!$errors) {
        $priceInput = $data['price_total'] === '' ? '' : (string)parse_decimal($data['price_total']);
        if ($data['price_total'] === '' || !is_numeric($priceInput)) {
            $q = calc_price($listing, $data['date_from'], $data['date_to'], max(1, (int)$data['guests']));
            if (isset($q['error'])) {
                // Admin darf Regeln (z. B. Mindestaufenthalt) übergehen: einfacher Nachtpreis
                $nights = (int)$d1->diff($d2)->days;
                $q = ['total' => $nights * (float)$listing['price_night'] + (float)$listing['cleaning_fee'],
                      'lines' => [["$nights Nächte × " . money((float)$listing['price_night']), $nights * (float)$listing['price_night']], ['Endreinigung', (float)$listing['cleaning_fee']]]];
            }
            $price = $q['total'];
            $priceDetail = json_encode($q['lines'], JSON_UNESCAPED_UNICODE);
        } else {
            $price = round((float)$priceInput, 2);
            if ($b && abs($price - (float)$b['price_total']) > 0.001) {
                $priceDetail = json_encode([['Vereinbarter Preis', $price]], JSON_UNESCAPED_UNICODE);
            }
        }

        $values = [(int)$data['listing_id'], $data['status'], $data['mode'], $data['date_from'], $data['date_to'], max(1, (int)$data['guests']), $data['weekdays_only'],
            $data['name'], $data['email'], $data['phone'], $data['company'], $data['vat_id'], $data['cost_center'], $data['billing_address'],
            $data['purpose'], $data['message'], $data['admin_note'], $price, $priceDetail, $data['company_id']];
        if ($b) {
            $pdo->prepare("UPDATE bookings SET listing_id=?, status=?, mode=?, date_from=?, date_to=?, guests=?, weekdays_only=?, name=?, email=?, phone=?, company=?, vat_id=?,
                cost_center=?, billing_address=?, purpose=?, message=?, admin_note=?, price_total=?, price_detail=?, company_id=?, updated_at=datetime('now','localtime') WHERE id=?")
                ->execute([...$values, $b['id']]);
            $bookingId = (int)$b['id'];
            $ref = $b['ref'];
        } else {
            $ref = random_ref();
            $pdo->prepare("INSERT INTO bookings (listing_id, status, mode, date_from, date_to, guests, weekdays_only, name, email, phone, company, vat_id,
                cost_center, billing_address, purpose, message, admin_note, price_total, price_detail, company_id, ref, source) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'admin')")
                ->execute([...$values, $ref]);
            $bookingId = (int)$pdo->lastInsertId();
        }

        // Gast informieren
        $statusChanged = !$b || $b['status'] !== $data['status'];
        if (post('notify') === '1' && $statusChanged && in_array($data['status'], ['bestaetigt', 'abgelehnt', 'storniert'], true)) {
            $fresh = booking_by_id($bookingId);
            $sent = $fresh && booking_status_mail($fresh, $data['status']);
            flash($sent ? 'ok' : 'error', $sent ? 'E-Mail an den Gast wurde versendet.' : 'E-Mail konnte nicht versendet werden. Bitte E-Mail-Adresse und Absender in den Einstellungen prüfen.');
        }
        flash('ok', $b ? 'Buchung gespeichert.' : 'Buchung angelegt.');
        redirect(url('admin/buchung.php', ['id' => $bookingId]));
    }
}

$lines = json_decode((string)($b['price_detail'] ?? '[]'), true) ?: [];
$nights = ($b && valid_date($b['date_from']) && valid_date($b['date_to'])) ? (int)valid_date($b['date_from'])->diff(valid_date($b['date_to']))->days : 0;

admin_header($b ? 'Buchung ' . $b['ref'] : 'Neue Buchung', 'buchungen');
?>
<div class="admin-top">
  <h1><?= $b ? 'Buchung ' . e($b['ref']) . ' ' . status_badge($b['status']) : 'Buchung eintragen' ?></h1>
  <?php if ($b): ?>
  <div class="actions">
    <?php if (!$inCollective && ($b['invoice_no'] || !booking_on_account($b))): ?>
    <form method="post" class="inline-form" action="<?= e(url('admin/buchung.php', ['id' => $b['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="invoice">
      <button class="button button-ghost button-small" type="submit"><?= $b['invoice_no'] ? 'Rechnung ' . e($b['invoice_no']) . ' öffnen' : 'Einzelrechnung erstellen' ?></button></form>
    <?php endif; ?>
    <?php if ($b['email']): ?><a class="button button-ghost button-small" href="mailto:<?= e($b['email']) ?>?subject=<?= rawurlencode('Ihre Buchung ' . $b['ref']) ?>">E-Mail schreiben</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

<?php if ($b): ?>
<div class="panel">
  <h2>Überblick</h2>
  <dl class="kv">
    <dt>Unterkunft</dt><dd><a href="<?= e(url('unterkunft.php', ['u' => $b['slug']])) ?>" target="_blank"><?= e($b['title']) ?></a></dd>
    <dt>Zeitraum</dt><dd><?= date_de($b['date_from'], true) ?> – <?= date_de($b['date_to'], true) ?> (<?= $nights ?> Nächte)<?= $b['weekdays_only'] ? ' · <strong>Wochenpendler, nur Mo–Fr</strong>' : '' ?></dd>
    <dt>Eingegangen</dt><dd><?= e($b['created_at']) ?> (<?= $b['source'] === 'admin' ? 'manuell eingetragen' : 'über die Webseite' ?>)</dd>
    <?php if ($b['purpose']): ?><dt>Anlass</dt><dd><?= e($b['purpose']) ?></dd><?php endif; ?>
    <?php if ($b['company_id']): ?><dt>Firmenkonto</dt><dd><a href="<?= e(url('admin/firmen.php', ['id' => $b['company_id']])) ?>"><?= e($b['company_name']) ?></a> · <?= booking_on_account($b) ? 'Abrechnung per Sammelrechnung' : 'Firmenkonto nicht freigeschaltet' ?></dd><?php endif; ?>
    <?php if ($b['booker_name']): ?><dt>Gebucht von</dt><dd><?= e($b['booker_name']) ?> (<?= e($b['booker_email']) ?>)</dd><?php endif; ?>
    <?php if ($b['cost_center']): ?><dt>Kostenstelle</dt><dd><?= e($b['cost_center']) ?></dd><?php endif; ?>
    <?php if ($b['admin_note']): ?><dt>Interne Notiz</dt><dd><?= nl2br(e($b['admin_note'])) ?></dd><?php endif; ?>
    <dt>Abrechnung</dt><dd>
      <?php if ($inCollective): ?>
        <?php foreach ($inCollective as $ic): ?><a href="<?= e(url('admin/sammelrechnung.php', ['id' => $ic['id']])) ?>" target="_blank">Sammelrechnung <?= e($ic['invoice_no']) ?></a> (<?= date_de($ic['period_from']) ?> – <?= date_de($ic['period_to']) ?>, <?= money((float)$ic['amount']) ?>)<br><?php endforeach; ?>
      <?php elseif ($b['invoice_no']): ?>
        Einzelrechnung <?= e($b['invoice_no']) ?> · Zahlung: <?= $b['payment_status'] === 'bezahlt' ? '<span class="badge badge-ok">bezahlt ' . date_de($b['paid_at']) . '</span>' : '<span class="badge badge-warn">offen</span>' ?>
        <form method="post" action="<?= e(url('admin/buchung.php', ['id' => $b['id']])) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="payment"><button class="button button-ghost button-small" type="submit"><?= $b['payment_status'] === 'bezahlt' ? 'Auf offen setzen' : 'Zahlung eingegangen' ?></button></form>
      <?php else: ?>
        <?= booking_on_account($b) ? 'Wird mit der nächsten Sammelrechnung abgerechnet.' : 'Noch keine Rechnung.' ?>
      <?php endif; ?>
    </dd>
    <?php if ($b['message']): ?><dt>Nachricht des Gastes</dt><dd><?= nl2br(e($b['message'])) ?></dd><?php endif; ?>
    <dt>Preis</dt><dd>
      <?php if ($lines): ?><table class="data" style="max-width:520px"><?php foreach ($lines as [$t, $v]): ?><tr><td><?= e($t) ?></td><td class="num"><?= money((float)$v) ?></td></tr><?php endforeach; ?>
        <tr><td><strong>Gesamt</strong></td><td class="num"><strong><?= money((float)$b['price_total']) ?></strong></td></tr></table>
      <?php else: ?><?= money((float)$b['price_total']) ?><?php endif; ?>
    </dd>
  </dl>
  <?php if ($b['status'] === 'angefragt'): ?>
    <div class="actions" style="margin-top:16px">
      <form method="post" action="<?= e(url('admin/buchung.php', ['id' => $b['id']])) ?>" class="inline-form">
        <?= csrf_field() ?>
        <?php foreach ($fields as $f): if ($f === 'status') continue; ?><input type="hidden" name="<?= e($f) ?>" value="<?= e($f === 'price_total' ? number_format((float)$b[$f], 2, ',', '') : (string)$b[$f]) ?>"><?php endforeach; ?>
        <input type="hidden" name="weekdays_only" value="<?= (int)$b['weekdays_only'] ?>"><input type="hidden" name="company_id" value="<?= (int)$b['company_id'] ?>"><input type="hidden" name="notify" value="1">
        <button class="button" name="status" value="bestaetigt" type="submit">Bestätigen &amp; Gast informieren</button>
        <button class="button button-danger" name="status" value="abgelehnt" type="submit">Ablehnen &amp; Gast informieren</button>
      </form>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($b && $b['series_ref']): $series = series_bookings($b['series_ref']); ?>
<div class="panel">
  <h2>Serie <?= e($b['series_ref']) ?> · <?= count($series) ?> Termine · <?= money(array_sum(array_column($series, 'price_total'))) ?></h2>
  <div class="table-wrap"><table class="data">
    <tr><th>Nr.</th><th>Zeitraum</th><th class="num">Preis</th><th>Status</th></tr>
    <?php foreach ($series as $sb): ?><tr<?= (int)$sb['id'] === (int)$b['id'] ? ' style="font-weight:600"' : '' ?>><td><a href="<?= e(url('admin/buchung.php', ['id' => $sb['id']])) ?>"><?= e($sb['ref']) ?></a></td>
      <td><?= date_de($sb['date_from'], true) ?> – <?= date_de($sb['date_to'], true) ?></td><td class="num"><?= money((float)$sb['price_total']) ?></td><td><?= status_badge($sb['status']) ?></td></tr><?php endforeach; ?>
  </table></div>
  <form method="post" action="<?= e(url('admin/buchung.php', ['id' => $b['id']])) ?>" class="actions" style="margin-top:12px">
    <?= csrf_field() ?><input type="hidden" name="action" value="series">
    <label class="check" style="width:100%"><input type="checkbox" name="notify" value="1" checked> Gast (und buchende Person) mit einer E-Mail über alle Termine informieren</label>
    <button class="button" name="status" value="bestaetigt" type="submit">Ganze Serie bestätigen</button>
    <button class="button button-danger" name="status" value="abgelehnt" type="submit">Ganze Serie ablehnen</button>
    <button class="button button-ghost" name="status" value="storniert" type="submit">Ganze Serie stornieren</button>
  </form>
</div>
<?php endif; ?>

<form class="panel" method="post" action="<?= e(url('admin/buchung.php', $b ? ['id' => $b['id']] : ['neu' => 1])) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <h2><?= $b ? 'Bearbeiten' : 'Daten' ?></h2>
  <div class="form-grid">
    <div class="field"><label for="listing_id">Unterkunft</label>
      <select id="listing_id" name="listing_id"><?php foreach ($listings as $l): ?><option value="<?= (int)$l['id'] ?>" <?= (int)$l['id'] === (int)$data['listing_id'] ? 'selected' : '' ?>><?= e($l['title']) ?><?= $l['active'] ? '' : ' (inaktiv)' ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="status">Status</label>
      <select id="status" name="status"><?php foreach (BOOKING_STATUS as $k => $label): ?><option value="<?= $k ?>" <?= $k === $data['status'] ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="date_from">Anreise</label><input required type="date" id="date_from" name="date_from" value="<?= e($data['date_from']) ?>"></div>
    <div class="field"><label for="date_to">Abreise</label><input required type="date" id="date_to" name="date_to" value="<?= e($data['date_to']) ?>"></div>
    <div class="field"><label for="guests">Personen</label><input type="number" min="1" id="guests" name="guests" value="<?= e((string)$data['guests']) ?>"></div>
    <div class="field"><label for="mode">Art</label>
      <select id="mode" name="mode"><?php foreach (['urlaub' => 'Urlaub', 'business' => 'Business', 'langzeit' => 'Wohnen auf Zeit'] as $k => $label): ?><option value="<?= $k ?>" <?= $k === $data['mode'] ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
    <div class="field full"><label class="check"><input type="checkbox" name="weekdays_only" value="1" <?= !empty($data['weekdays_only']) ? 'checked' : '' ?>> Wochenpendler (nur Mo–Fr vor Ort)</label></div>
    <div class="field"><label for="name">Name</label><input required type="text" id="name" name="name" value="<?= e($data['name']) ?>"></div>
    <div class="field"><label for="email">E-Mail</label><input type="email" id="email" name="email" value="<?= e($data['email']) ?>"></div>
    <div class="field"><label for="phone">Telefon</label><input type="tel" id="phone" name="phone" value="<?= e($data['phone']) ?>"></div>
    <div class="field"><label for="purpose">Anlass</label><input type="text" id="purpose" name="purpose" value="<?= e($data['purpose']) ?>"></div>
    <div class="field"><label for="company_id">Firmenkonto</label>
      <select id="company_id" name="company_id"><option value="">– kein Firmenkonto –</option>
        <?php foreach ($companies as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === (int)($data['company_id'] ?? 0) ? 'selected' : '' ?>><?= e($c['name']) ?><?= $c['status'] !== 'freigeschaltet' ? ' (' . e(COMPANY_STATUS[$c['status']] ?? '') . ')' : '' ?></option><?php endforeach; ?>
      </select><p class="hint">Mit freigeschaltetem Firmenkonto wird die Buchung per Sammelrechnung abgerechnet.</p></div>
    <div class="field"><label for="company">Firma (Name auf Rechnung)</label><input type="text" id="company" name="company" value="<?= e($data['company']) ?>"></div>
    <div class="field"><label for="vat_id">USt-IdNr.</label><input type="text" id="vat_id" name="vat_id" value="<?= e($data['vat_id']) ?>"></div>
    <div class="field"><label for="cost_center">Kostenstelle / Bestell-Nr.</label><input type="text" id="cost_center" name="cost_center" value="<?= e($data['cost_center']) ?>"></div>
    <div class="field"><label for="price_total">Gesamtpreis (€)</label><input type="text" inputmode="decimal" id="price_total" name="price_total" value="<?= $data['price_total'] === '' ? '' : e(number_format((float)$data['price_total'], 2, ',', '')) ?>"><p class="hint">Leer lassen = automatisch berechnen.</p></div>
    <div class="field full"><label for="billing_address">Rechnungsadresse</label><textarea id="billing_address" name="billing_address" rows="3"><?= e($data['billing_address']) ?></textarea></div>
    <div class="field full"><label for="message">Nachricht des Gastes</label><textarea id="message" name="message" rows="3"><?= e($data['message']) ?></textarea></div>
    <div class="field full"><label for="admin_note">Interne Notiz (nur für Sie sichtbar)</label><textarea id="admin_note" name="admin_note" rows="3"><?= e($data['admin_note']) ?></textarea></div>
    <div class="field full"><label class="check"><input type="checkbox" name="notify" value="1"> Gast per E-Mail informieren, wenn sich der Status auf Bestätigt, Abgelehnt oder Storniert ändert</label></div>
  </div>
  <button class="button" type="submit"><?= $b ? 'Speichern' : 'Buchung anlegen' ?></button>
</form>

<?php if ($b): ?>
<form method="post" action="<?= e(url('admin/buchung.php', ['id' => $b['id']])) ?>" data-confirm="Buchung <?= e($b['ref']) ?> endgültig löschen?">
  <?= csrf_field() ?><input type="hidden" name="action" value="delete">
  <button class="button button-danger button-small" type="submit">Buchung löschen</button>
</form>
<?php endif; ?>
<?php admin_footer();
