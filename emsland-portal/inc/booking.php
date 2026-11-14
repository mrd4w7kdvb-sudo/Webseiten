<?php
declare(strict_types=1);

/*
 * Gemeinsame Buchungs- und Rechnungslogik (Admin, Gastgeber, Firmenkonto).
 */

const PAYMENT_STATUS = ['offen' => 'Offen', 'bezahlt' => 'Bezahlt'];

function booking_status_badge(string $status): string
{
    $cls = ['angefragt' => 'badge-warn', 'bestaetigt' => 'badge-ok', 'abgelehnt' => 'badge-bad', 'storniert' => 'badge-muted'][$status] ?? 'badge-muted';
    return '<span class="badge ' . $cls . '">' . e(BOOKING_STATUS[$status] ?? $status) . '</span>';
}

function status_badge(string $status): string
{
    return booking_status_badge($status);
}

function booking_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT b.*, l.title, l.slug, l.host_user_id, c.name AS company_name, c.status AS company_status,
            u.name AS booker_name, u.email AS booker_email
        FROM bookings b JOIN listings l ON l.id = b.listing_id
        LEFT JOIN companies c ON c.id = b.company_id
        LEFT JOIN users u ON u.id = b.booked_by
        WHERE b.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function booking_nights(array $b): int
{
    $d1 = valid_date($b['date_from']);
    $d2 = valid_date($b['date_to']);
    return ($d1 && $d2) ? (int)$d1->diff($d2)->days : 0;
}

/** Firma bucht auf Rechnung (Sammelrechnung), wenn das Firmenkonto freigeschaltet ist. */
function booking_on_account(array $b): bool
{
    return !empty($b['company_id']) && ($b['company_status'] ?? '') === 'freigeschaltet';
}

/** Informiert Gast (und ggf. buchende Person der Firma) über Bestätigung, Ablehnung oder Stornierung. */
function booking_status_mail(array $b, string $status): bool
{
    $texts = [
        'bestaetigt' => 'wir freuen uns, Ihre Buchung zu bestätigen.',
        'abgelehnt'  => 'leider können wir Ihre Anfrage für diesen Zeitraum nicht bestätigen. Gern suchen wir eine Alternative für Sie.',
        'storniert'  => 'Ihre Buchung wurde storniert.',
    ];
    if (!isset($texts[$status])) {
        return false;
    }
    $body = "Guten Tag {$b['name']},\n\n" . $texts[$status] . "\n\nUnterkunft: {$b['title']}\nZeitraum: " . date_de($b['date_from'], true) . ' – ' . date_de($b['date_to'], true)
        . "\nPersonen: " . (int)$b['guests'] . "\nPreis: " . money((float)$b['price_total']) . "\nBuchungs-Nr.: {$b['ref']}\n";
    if ($status === 'bestaetigt') {
        $body .= booking_on_account($b)
            ? "\nDie Abrechnung erfolgt über das Firmenkonto von {$b['company_name']} (Sammelrechnung).\n"
            : "\n" . setting('payment_info') . "\n";
        $body .= "\nDie genaue Adresse und Hinweise zur Anreise senden wir Ihnen vor der Anreise.\n";
    }
    $body .= "\nViele Grüße\n" . setting('site_name') . "\n" . setting('contact_phone');
    $subject = ['bestaetigt' => 'Buchungsbestätigung', 'abgelehnt' => 'Ihre Anfrage', 'storniert' => 'Stornierung'][$status] . ' ' . $b['ref'];
    $ok = valid_email($b['email']) ? send_mail($b['email'], $subject, $body) : false;
    if (!empty($b['booker_email']) && mb_strtolower($b['booker_email']) !== mb_strtolower($b['email'])) {
        $ok = send_mail($b['booker_email'], $subject . ' (für ' . $b['name'] . ')', $body) || $ok;
    }
    return $ok;
}

/**
 * Status einer Buchung ändern (prüft beim Bestätigen die Verfügbarkeit).
 * Gibt eine Fehlermeldung zurück oder null bei Erfolg.
 */
function booking_change_status(int $id, string $status, bool $notify): ?string
{
    $b = booking_by_id($id);
    if (!$b) {
        return 'Buchung nicht gefunden.';
    }
    if (!isset(BOOKING_STATUS[$status])) {
        return 'Unbekannter Status.';
    }
    if ($b['status'] === $status) {
        return null;
    }
    if ($status === 'bestaetigt' && !is_available((int)$b['listing_id'], $b['date_from'], $b['date_to'], (int)$b['id'])) {
        return 'Buchung ' . $b['ref'] . ' (' . date_de($b['date_from']) . '): Der Zeitraum ist bereits belegt.';
    }
    db()->prepare("UPDATE bookings SET status = ?, updated_at = datetime('now','localtime') WHERE id = ?")->execute([$status, $id]);
    if ($notify) {
        booking_status_mail($b, $status);
    }
    return null;
}

/** Alle Buchungen einer Serie. */
function series_bookings(string $seriesRef): array
{
    $st = db()->prepare('SELECT * FROM bookings WHERE series_ref = ? ORDER BY date_from');
    $st->execute([$seriesRef]);
    return $st->fetchAll();
}

/** Ganze Serie bestätigen/ablehnen; eine E-Mail mit allen Terminen. Gibt Fehlermeldungen zurück. */
function series_change_status(string $seriesRef, string $status, bool $notify): array
{
    $errors = [];
    $changed = [];
    foreach (series_bookings($seriesRef) as $b) {
        if ($b['status'] === $status || in_array($b['status'], ['storniert', 'abgelehnt'], true) && $status === 'bestaetigt') {
            continue;
        }
        $err = booking_change_status((int)$b['id'], $status, false);
        if ($err) {
            $errors[] = $err;
        } else {
            $changed[] = $b;
        }
    }
    if ($notify && $changed) {
        $first = booking_by_id((int)$changed[0]['id']);
        $dates = implode("\n", array_map(fn($b) => '  ' . date_de($b['date_from'], true) . ' – ' . date_de($b['date_to'], true), $changed));
        $first['date_to'] = end($changed)['date_to'];
        $first['price_total'] = array_sum(array_column($changed, 'price_total'));
        $first['ref'] = $seriesRef;
        $mail = $first;
        $mail['title'] = $first['title'] . "\nTermine (" . count($changed) . "):\n" . $dates;
        booking_status_mail($mail, $status);
    }
    return $errors;
}

/* ---------- Rechnungen ---------- */

function next_invoice_no(): string
{
    $year = date('Y');
    $key = 'invoice_counter_' . $year;
    $n = (int)setting($key, '0') + 1;
    set_setting($key, (string)$n);
    return sprintf('%s-%04d', $year, $n);
}

function assign_booking_invoice(int $bookingId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT invoice_no FROM bookings WHERE id = ?');
    $st->execute([$bookingId]);
    if (!$st->fetchColumn()) {
        $pdo->prepare('UPDATE bookings SET invoice_no = ?, invoice_date = ? WHERE id = ?')->execute([next_invoice_no(), date('Y-m-d'), $bookingId]);
    }
    $pdo->commit();
}

/**
 * Positionen für eine Sammelrechnung: bestätigte Buchungen der Firma, die in den Zeitraum fallen,
 * keine Einzelrechnung haben und für diese Nächte noch nicht abgerechnet wurden.
 * Langzeitaufenthalte werden anteilig nach Nächten im Zeitraum berechnet; die letzte Teilrechnung gleicht Rundungen aus.
 */
function collective_candidates(int $companyId, string $from, string $to): array
{
    $st = db()->prepare("SELECT b.*, l.title FROM bookings b JOIN listings l ON l.id = b.listing_id
        WHERE b.company_id = ? AND b.status = 'bestaetigt' AND b.invoice_no IS NULL AND b.date_from < ? AND b.date_to > ?
        ORDER BY b.date_from");
    $st->execute([$companyId, $to, $from]);
    $items = [];
    foreach ($st->fetchAll() as $b) {
        $total = booking_nights($b);
        if ($total <= 0) continue;
        $pFrom = max($b['date_from'], $from);
        $pTo = min($b['date_to'], $to);
        $nights = (int)valid_date($pFrom)->diff(valid_date($pTo))->days;
        if ($nights <= 0) continue;

        $billed = db()->prepare('SELECT COALESCE(SUM(nights), 0) AS n, COALESCE(SUM(amount), 0) AS a FROM invoice_items WHERE booking_id = ?');
        $billed->execute([$b['id']]);
        $prev = $billed->fetch();
        $overlap = db()->prepare('SELECT COUNT(*) FROM invoice_items WHERE booking_id = ? AND period_from < ? AND period_to > ?');
        $overlap->execute([$b['id'], $pTo, $pFrom]);
        if ((int)$overlap->fetchColumn() > 0) continue; // schon abgerechnet

        $isLast = ((int)$prev['n'] + $nights) >= $total;
        $amount = $isLast ? round((float)$b['price_total'] - (float)$prev['a'], 2) : round((float)$b['price_total'] * $nights / $total, 2);
        $desc = $b['title'] . ', Gast: ' . $b['name'] . ' (Buchung ' . $b['ref'] . ')'
            . ($nights < $total ? sprintf(' – anteilig %d von %d Nächten', $nights, $total) : '')
            . ($b['cost_center'] ? ' · Kostenstelle ' . $b['cost_center'] : '');
        $items[] = ['booking_id' => (int)$b['id'], 'description' => $desc, 'period_from' => $pFrom, 'period_to' => $pTo, 'nights' => $nights, 'amount' => $amount];
    }
    return $items;
}

function create_collective_invoice(int $companyId, string $from, string $to): ?int
{
    $items = collective_candidates($companyId, $from, $to);
    if (!$items) {
        return null;
    }
    $st = db()->prepare('SELECT payment_days FROM companies WHERE id = ?');
    $st->execute([$companyId]);
    $days = max(0, (int)$st->fetchColumn());
    $pdo = db();
    $pdo->beginTransaction();
    $no = next_invoice_no();
    $total = round(array_sum(array_column($items, 'amount')), 2);
    $pdo->prepare('INSERT INTO invoices (invoice_no, company_id, period_from, period_to, invoice_date, due_date, total) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$no, $companyId, $from, $to, date('Y-m-d'), date('Y-m-d', strtotime("+$days days")), $total]);
    $invId = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO invoice_items (invoice_id, booking_id, description, period_from, period_to, nights, amount) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($items as $it) {
        $ins->execute([$invId, $it['booking_id'], $it['description'], $it['period_from'], $it['period_to'], $it['nights'], $it['amount']]);
    }
    $pdo->commit();
    return $invId;
}

function invoice_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT i.*, c.name AS company_name, c.vat_id, c.billing_address, c.billing_email FROM invoices i JOIN companies c ON c.id = i.company_id WHERE i.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Steuer-Aufschlüsselung (Preise sind Bruttopreise). */
function vat_split(float $total): array
{
    $small = setting('small_business') === '1';
    $rate = (float)str_replace(',', '.', setting('vat_rate', '7'));
    $net = $small ? $total : round($total / (1 + $rate / 100), 2);
    return ['small' => $small, 'rate' => $rate, 'net' => $net, 'vat' => round($total - $net, 2)];
}

/**
 * Gibt eine druckbare Rechnungsseite aus.
 * $doc = [no, date, due?, recipient, meta => [label => value], rows => [[text, amount]], heading?, total]
 */
function render_invoice_page(array $doc, string $backUrl, string $backLabel): void
{
    $tax = vat_split((float)$doc['total']);
    $rateText = rtrim(rtrim(number_format($tax['rate'], 2, ',', ''), '0'), ',');
    ?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($doc['title'] ?? 'Rechnung') ?> <?= e($doc['no']) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body>
<div class="no-print">
  <button class="button" onclick="window.print()">Drucken / als PDF speichern</button>
  <a class="button button-ghost" href="<?= e($backUrl) ?>"><?= e($backLabel) ?></a>
</div>
<div class="invoice">
  <div class="head">
    <div>
      <p class="muted small" style="margin-bottom:4px"><?= e(setting('company_name')) ?> · <?= e(str_replace("\n", ' · ', setting('company_address'))) ?></p>
      <p><?= nl2br(e($doc['recipient'])) ?></p>
    </div>
    <div style="text-align:right">
      <p class="brand" style="font-size:1.4rem"><?= e(setting('site_name')) ?></p>
      <p class="small"><?= nl2br(e(setting('company_name') . "\n" . setting('company_address'))) ?><br><?= e(setting('contact_phone')) ?><br><?= e(setting('contact_email')) ?></p>
    </div>
  </div>
  <h1><?= e($doc['title'] ?? 'Rechnung') ?></h1>
  <dl class="kv" style="margin-bottom:12px">
    <dt>Rechnungsnummer</dt><dd><?= e($doc['no']) ?></dd>
    <dt>Rechnungsdatum</dt><dd><?= date_de($doc['date']) ?></dd>
    <?php foreach ($doc['meta'] as $k => $v): if ($v === '' || $v === null) continue; ?><dt><?= e($k) ?></dt><dd><?= e((string)$v) ?></dd><?php endforeach; ?>
  </dl>
  <table>
    <tr><th>Leistung</th><th class="num">Betrag</th></tr>
    <?php if (!empty($doc['heading'])): ?><tr><td colspan="2"><strong><?= e($doc['heading']) ?></strong></td></tr><?php endif; ?>
    <?php foreach ($doc['rows'] as [$t, $v]): ?><tr><td><?= nl2br(e($t)) ?></td><td class="num"><?= money((float)$v) ?></td></tr><?php endforeach; ?>
    <tr><td><strong>Gesamtbetrag</strong></td><td class="num"><strong><?= money((float)$doc['total']) ?></strong></td></tr>
    <?php if (!$tax['small']): ?>
      <tr><td>darin enthalten: Nettobetrag</td><td class="num"><?= money($tax['net']) ?></td></tr>
      <tr><td>darin enthalten: <?= e($rateText) ?> % Umsatzsteuer</td><td class="num"><?= money($tax['vat']) ?></td></tr>
    <?php endif; ?>
  </table>
  <?php if ($tax['small']): ?><p class="small">Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.</p><?php endif; ?>
  <?php if (!empty($doc['due'])): ?><p>Bitte überweisen Sie den Gesamtbetrag bis zum <strong><?= date_de($doc['due']) ?></strong> unter Angabe der Rechnungsnummer.</p><?php endif; ?>
  <?php $pay = setting('payment_info');
  if (!empty($doc['due'])) { // feste Frist: allgemeine Fristangaben aus dem Zahlungshinweis weglassen
      $pay = implode("\n", array_filter(preg_split('/\R/', $pay), fn($line) => !preg_match('/innerhalb von \d+ Tag/i', $line)));
  } ?>
  <p><?= nl2br(e($pay)) ?></p>
  <p class="small muted"><?= e(setting('company_name')) ?><?= setting('tax_id') ? ' · Steuernummer/USt-IdNr.: ' . e(setting('tax_id')) : '' ?></p>
</div>
</body>
</html>
<?php
}

function render_booking_invoice(array $b, string $backUrl, string $backLabel): void
{
    $lines = json_decode($b['price_detail'], true) ?: [['Unterkunft', (float)$b['price_total']]];
    $st = db()->prepare('SELECT p.name FROM listings l LEFT JOIN places p ON p.id = l.place_id WHERE l.id = ?');
    $st->execute([$b['listing_id']]);
    $place = (string)$st->fetchColumn();
    $company = $b['company'] ?: ($b['company_name'] ?? '');
    $recipient = $company ? $company . "\n" . ($b['billing_address'] ?: $b['name']) : $b['name'] . ($b['billing_address'] ? "\n" . $b['billing_address'] : '');
    render_invoice_page([
        'no' => $b['invoice_no'], 'date' => $b['invoice_date'], 'recipient' => $recipient, 'total' => (float)$b['price_total'],
        'meta' => [
            'Leistungszeitraum' => date_de($b['date_from']) . ' – ' . date_de($b['date_to']) . ' (' . booking_nights($b) . (booking_nights($b) === 1 ? ' Nacht)' : ' Nächte)'),
            'Buchungs-Nr.' => $b['ref'],
            'USt-IdNr. Kunde' => $b['vat_id'],
            'Kostenstelle / Bestell-Nr.' => $b['cost_center'],
            'Gast' => $company ? $b['name'] : '',
        ],
        'heading' => 'Beherbergung: ' . $b['title'] . ($place ? ', ' . $place : '') . ', ' . (int)$b['guests'] . ' Person(en)',
        'rows' => array_map(fn($l) => [$l[0], (float)$l[1]], $lines),
    ], $backUrl, $backLabel);
}

function render_collective_invoice(array $inv, string $backUrl, string $backLabel): void
{
    $st = db()->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY period_from, id');
    $st->execute([$inv['id']]);
    $rows = [];
    foreach ($st->fetchAll() as $it) {
        $rows[] = [$it['description'] . "\n" . date_de($it['period_from']) . ' – ' . date_de($it['period_to']) . ' (' . (int)$it['nights'] . ((int)$it['nights'] === 1 ? ' Nacht)' : ' Nächte)'), (float)$it['amount']];
    }
    render_invoice_page([
        'title' => 'Sammelrechnung', 'no' => $inv['invoice_no'], 'date' => $inv['invoice_date'], 'due' => $inv['due_date'],
        'recipient' => $inv['company_name'] . ($inv['billing_address'] ? "\n" . $inv['billing_address'] : ''),
        'total' => (float)$inv['total'],
        'meta' => [
            'Leistungszeitraum' => date_de($inv['period_from']) . ' – ' . date_de(date('Y-m-d', strtotime($inv['period_to'] . ' -1 day'))),
            'USt-IdNr. Kunde' => $inv['vat_id'],
        ],
        'heading' => 'Beherbergungsleistungen',
        'rows' => $rows,
    ], $backUrl, $backLabel);
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

/** E-Mail an den Gastgeber der Unterkunft, falls ein Gastgeber-Zugang zugeordnet ist. */
function notify_host(array $listing, string $subject, string $body): void
{
    if (empty($listing['host_user_id'])) {
        return;
    }
    $st = db()->prepare('SELECT email, name FROM users WHERE id = ? AND active = 1');
    $st->execute([$listing['host_user_id']]);
    if ($h = $st->fetch()) {
        send_mail($h['email'], '[' . setting('site_name') . '] ' . $subject, "Guten Tag {$h['name']},\n\n$body");
    }
}
