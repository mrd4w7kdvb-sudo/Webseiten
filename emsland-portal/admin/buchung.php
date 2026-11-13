<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/admin.php';
require_admin();

$pdo = db();
$id = (int)get('id');
$isNew = get('neu') === '1' || $id === 0;
$b = null;
if (!$isNew) {
    $st = $pdo->prepare('SELECT b.*, l.title, l.slug FROM bookings b JOIN listings l ON l.id = b.listing_id WHERE b.id = ?');
    $st->execute([$id]);
    $b = $st->fetch();
    if (!$b) {
        flash('error', 'Buchung nicht gefunden.');
        redirect(url('admin/buchungen.php'));
    }
}
$listings = $pdo->query('SELECT * FROM listings ORDER BY title')->fetchAll();
$fields = ['listing_id', 'status', 'mode', 'date_from', 'date_to', 'guests', 'name', 'email', 'phone', 'company', 'vat_id', 'cost_center', 'billing_address', 'purpose', 'message', 'admin_note', 'price_total'];
$data = $b ?? ['listing_id' => (int)get('unterkunft') ?: ($listings[0]['id'] ?? 0), 'status' => 'bestaetigt', 'mode' => 'urlaub', 'date_from' => '', 'date_to' => '', 'guests' => 2,
    'name' => '', 'email' => '', 'phone' => '', 'company' => '', 'vat_id' => '', 'cost_center' => '', 'billing_address' => '', 'purpose' => '', 'message' => '', 'admin_note' => '', 'price_total' => '', 'weekdays_only' => 0];
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
        if (!$b['invoice_no']) {
            $pdo->beginTransaction();
            $year = date('Y');
            $counterKey = 'invoice_counter_' . $year;
            $n = (int)setting($counterKey, '0') + 1;
            set_setting($counterKey, (string)$n);
            $no = sprintf('%s-%04d', $year, $n);
            $pdo->prepare('UPDATE bookings SET invoice_no = ?, invoice_date = ? WHERE id = ?')->execute([$no, date('Y-m-d'), $b['id']]);
            $pdo->commit();
        }
        redirect(url('admin/rechnung.php', ['id' => $b['id']]));
    }

    // Speichern
    foreach ($fields as $f) {
        $data[$f] = post($f);
    }
    $data['weekdays_only'] = post('weekdays_only') === '1' ? 1 : 0;
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
            $data['purpose'], $data['message'], $data['admin_note'], $price, $priceDetail];
        if ($b) {
            $pdo->prepare("UPDATE bookings SET listing_id=?, status=?, mode=?, date_from=?, date_to=?, guests=?, weekdays_only=?, name=?, email=?, phone=?, company=?, vat_id=?,
                cost_center=?, billing_address=?, purpose=?, message=?, admin_note=?, price_total=?, price_detail=?, updated_at=datetime('now','localtime') WHERE id=?")
                ->execute([...$values, $b['id']]);
            $bookingId = (int)$b['id'];
            $ref = $b['ref'];
        } else {
            $ref = random_ref();
            $pdo->prepare("INSERT INTO bookings (listing_id, status, mode, date_from, date_to, guests, weekdays_only, name, email, phone, company, vat_id,
                cost_center, billing_address, purpose, message, admin_note, price_total, price_detail, ref, source) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'admin')")
                ->execute([...$values, $ref]);
            $bookingId = (int)$pdo->lastInsertId();
        }

        // Gast informieren
        $statusChanged = !$b || $b['status'] !== $data['status'];
        if (post('notify') === '1' && $statusChanged && valid_email($data['email']) && in_array($data['status'], ['bestaetigt', 'abgelehnt', 'storniert'], true)) {
            $texts = [
                'bestaetigt' => "wir freuen uns, Ihre Buchung zu bestätigen.",
                'abgelehnt'  => "leider können wir Ihre Anfrage für diesen Zeitraum nicht bestätigen. Gern suchen wir eine Alternative für Sie.",
                'storniert'  => "Ihre Buchung wurde storniert.",
            ];
            $body = "Guten Tag {$data['name']},\n\n" . $texts[$data['status']] . "\n\nUnterkunft: {$listing['title']}\nZeitraum: " . date_de($data['date_from'], true) . ' – ' . date_de($data['date_to'], true)
                . "\nPersonen: " . (int)$data['guests'] . "\nPreis: " . money($price) . "\nBuchungs-Nr.: $ref\n";
            if ($data['status'] === 'bestaetigt') {
                $body .= "\n" . setting('payment_info') . "\n\nDie genaue Adresse und Hinweise zur Anreise senden wir Ihnen vor der Anreise.\n";
            }
            $body .= "\nViele Grüße\n" . setting('site_name') . "\n" . setting('contact_phone');
            $subject = ['bestaetigt' => 'Buchungsbestätigung', 'abgelehnt' => 'Ihre Anfrage', 'storniert' => 'Stornierung'][$data['status']] . " $ref";
            $sent = send_mail($data['email'], $subject, $body);
            flash($sent ? 'ok' : 'error', $sent ? 'E-Mail an ' . $data['email'] . ' wurde versendet.' : 'E-Mail an ' . $data['email'] . ' konnte nicht versendet werden. Bitte Absender-Adresse in den Einstellungen prüfen.');
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
    <form method="post" class="inline-form" action="<?= e(url('admin/buchung.php', ['id' => $b['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="invoice">
      <button class="button button-ghost button-small" type="submit"><?= $b['invoice_no'] ? 'Rechnung ' . e($b['invoice_no']) . ' öffnen' : 'Rechnung erstellen' ?></button></form>
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
        <input type="hidden" name="weekdays_only" value="<?= (int)$b['weekdays_only'] ?>"><input type="hidden" name="notify" value="1">
        <button class="button" name="status" value="bestaetigt" type="submit">Bestätigen &amp; Gast informieren</button>
        <button class="button button-danger" name="status" value="abgelehnt" type="submit">Ablehnen &amp; Gast informieren</button>
      </form>
    </div>
  <?php endif; ?>
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
    <div class="field"><label for="company">Firma</label><input type="text" id="company" name="company" value="<?= e($data['company']) ?>"></div>
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
