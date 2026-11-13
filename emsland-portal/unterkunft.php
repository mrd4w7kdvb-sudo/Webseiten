<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$l = listing_by_slug(get('u'));
if (!$l || !$l['active']) {
    not_found();
}
$id = (int)$l['id'];

$purposes = ['Urlaub / privat', 'Praxis- oder Klinikvertretung', 'Bau- oder Projektleitung', 'Montage / Handwerk', 'Sonstiges beruflich'];

$in = [
    'von' => get('von'), 'bis' => get('bis'), 'gaeste' => get('gaeste') ?: (string)min(2, (int)$l['max_guests']),
    'reise' => get('modus') === 'business' ? 'beruflich' : 'privat',
    'purpose' => get('modus') === 'business' ? $purposes[1] : $purposes[0],
    'name' => '', 'email' => '', 'phone' => '', 'company' => '', 'vat_id' => '', 'cost_center' => '',
    'billing_address' => '', 'message' => '', 'weekdays_only' => '', 'agb' => '',
];
$errors = [];

if (is_post()) {
    check_csrf();
    foreach (array_keys($in) as $k) {
        $in[$k] = post($k);
    }
    if (looks_like_spam()) {
        $errors[] = 'Ihre Anfrage konnte nicht gesendet werden. Bitte versuchen Sie es erneut.';
    }
    $guests = (int)$in['gaeste'];
    $quote = calc_price($l, $in['von'], $in['bis'], $guests);
    if (isset($quote['error'])) {
        $errors[] = $quote['error'];
    } elseif ($in['von'] < date('Y-m-d')) {
        $errors[] = 'Die Anreise darf nicht in der Vergangenheit liegen.';
    } elseif (!is_available($id, $in['von'], $in['bis'])) {
        $errors[] = 'Im gewählten Zeitraum ist die Unterkunft leider schon belegt. Bitte wählen Sie andere Daten.';
    }
    if (mb_strlen($in['name']) < 2) $errors[] = 'Bitte Ihren Namen angeben.';
    if (!valid_email($in['email'])) $errors[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
    if ($in['reise'] !== 'beruflich' || !in_array($in['purpose'], $purposes, true)) {
        $in['purpose'] = $in['reise'] === 'beruflich' ? $purposes[4] : $purposes[0];
    }
    if ($in['agb'] !== '1') $errors[] = 'Bitte bestätigen Sie die Buchungsbedingungen und den Datenschutzhinweis.';
    foreach (['name' => 120, 'email' => 190, 'phone' => 60, 'company' => 160, 'vat_id' => 40, 'cost_center' => 80, 'billing_address' => 600, 'message' => 3000] as $k => $max) {
        if (mb_strlen($in[$k]) > $max) $errors[] = 'Eine Eingabe ist zu lang.';
    }

    if (!$errors) {
        $business = $in['reise'] === 'beruflich';
        $mode = $quote['tariff'] === 'langzeit' ? 'langzeit' : ($business ? 'business' : 'urlaub');
        $ref = random_ref();
        db()->prepare('INSERT INTO bookings (ref, listing_id, status, mode, date_from, date_to, guests, weekdays_only, name, email, phone, company, vat_id,
            cost_center, billing_address, purpose, message, price_total, price_detail) VALUES (?, ?, \'angefragt\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$ref, $id, $mode, $in['von'], $in['bis'], $guests, $business && $in['weekdays_only'] === '1' ? 1 : 0,
                $in['name'], $in['email'], $in['phone'], $business ? $in['company'] : '', $business ? $in['vat_id'] : '',
                $business ? $in['cost_center'] : '', $business ? $in['billing_address'] : '', $in['purpose'], $in['message'],
                $quote['total'], json_encode($quote['lines'], JSON_UNESCAPED_UNICODE)]);

        $summary = "Unterkunft: {$l['title']}\nZeitraum: " . date_de($in['von']) . ' – ' . date_de($in['bis']) . " ({$quote['nights']} Nächte)\n"
            . "Personen: $guests\nPreis laut Anfrage: " . money($quote['total']) . "\nAnfrage-Nr.: $ref";
        admin_notify("Neue Buchungsanfrage $ref", "Neue Anfrage von {$in['name']} ({$in['email']})\n\n$summary\nZweck: {$in['purpose']}"
            . ($in['company'] ? "\nFirma: {$in['company']}" : '') . ($in['message'] ? "\n\nNachricht:\n{$in['message']}" : ''));
        send_mail($in['email'], 'Ihre Anfrage bei ' . setting('site_name') . " ($ref)",
            "Guten Tag {$in['name']},\n\nvielen Dank für Ihre Anfrage. Wir prüfen die Verfügbarkeit und melden uns in der Regel innerhalb von 24 Stunden.\n\n$summary\n\nDies ist noch keine Buchungsbestätigung.\n\nViele Grüße\n" . setting('site_name') . "\n" . setting('contact_phone'));

        $_SESSION['last_ref'] = $ref;
        redirect(url('danke.php', ['ref' => $ref]));
    }
}

$images = listing_images($id);
$amenities = listing_amenities($l);

// Kalender: zwei Monate ab ?m=YYYY-MM
$m = get('m');
$start = preg_match('/^\d{4}-\d{2}$/', $m) ? new DateTimeImmutable($m . '-01') : new DateTimeImmutable(date('Y-m-01'));
if ($start < new DateTimeImmutable(date('Y-m-01'))) {
    $start = new DateTimeImmutable(date('Y-m-01'));
}
$next = $start->modify('+1 month');
$occupied = occupied_nights($id, $start->format('Y-m-d'), $start->modify('+2 months')->format('Y-m-d'));
$prevLink = $start > new DateTimeImmutable(date('Y-m-01')) ? url('unterkunft.php', ['u' => $l['slug'], 'm' => $start->modify('-1 month')->format('Y-m')]) . '#kalender' : null;
$nextLink = url('unterkunft.php', ['u' => $l['slug'], 'm' => $next->format('Y-m')]) . '#kalender';

$quoteNow = ($in['von'] && $in['bis']) ? calc_price($l, $in['von'], $in['bis'], (int)$in['gaeste']) : null;

page_header($l['title'] . ' in ' . ($l['place_name'] ?? 'Emsland'), $l['summary']);
?>
<section class="section" style="padding-top:32px">
  <div class="wrap">
    <p class="small"><a href="<?= e(url('suche.php', ['modus' => get('modus') ?: null])) ?>">← Zur Übersicht</a></p>
    <div class="badges">
      <?php foreach (listing_modes($l) as $mode): ?><span class="badge <?= $mode === 'Business' ? 'badge-business' : ($mode === 'Wohnen auf Zeit' ? 'badge-langzeit' : '') ?>"><?= e($mode) ?></span><?php endforeach; ?>
    </div>
    <h1><?= e($l['title']) ?></h1>
    <p class="facts-row">
      <span><strong><?= e($l['type']) ?></strong> in <?= e($l['place_name'] ?? 'Emsland') ?></span>
      <span><strong><?= (int)$l['max_guests'] ?></strong> Personen</span>
      <span><strong><?= (int)$l['bedrooms'] ?></strong> Schlafzimmer</span>
      <?php if ((int)$l['single_beds']): ?><span><strong><?= (int)$l['single_beds'] ?></strong> Einzelbetten</span><?php endif; ?>
      <span><strong><?= (int)$l['bathrooms'] ?></strong> Bad</span>
      <?php if ((int)$l['size_m2']): ?><span><strong><?= (int)$l['size_m2'] ?></strong> m²</span><?php endif; ?>
      <?php if ((int)$l['wifi_mbit']): ?><span>WLAN <strong><?= (int)$l['wifi_mbit'] ?> Mbit/s</strong></span><?php endif; ?>
    </p>

    <?php if ($images): ?>
      <div class="gallery <?= [1 => 'single', 2 => 'double'][count($images)] ?? '' ?>">
        <?php foreach (array_slice($images, 0, 3) as $i => $img): ?>
          <img src="<?= e(image_url($img['file'])) ?>" alt="<?= e($img['alt'] ?: $l['title']) ?>" <?= $i ? 'loading="lazy"' : '' ?>>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="detail-layout">
      <div>
        <p class="lead"><?= e($l['summary']) ?></p>
        <?= paragraphs($l['description']) ?>

        <h2>Ausstattung</h2>
        <ul class="amenity-list">
          <?php foreach ($amenities as $a): if (!isset(AMENITIES[$a])) continue; ?>
            <li><?= e(AMENITIES[$a][0]) ?></li>
          <?php endforeach; ?>
        </ul>

        <h2>Preise</h2>
        <table class="price-table">
          <tr><th>Leistung</th><th>Preis</th></tr>
          <tr><td>Pro Nacht (bis <?= (int)$l['base_guests'] ?> Personen)</td><td><?= money((float)$l['price_night']) ?></td></tr>
          <?php if ((float)$l['extra_guest_fee'] > 0): ?><tr><td>Jede weitere Person pro Nacht</td><td><?= money((float)$l['extra_guest_fee']) ?></td></tr><?php endif; ?>
          <?php if ((float)$l['weekly_discount'] > 0): ?><tr><td>Rabatt ab 7 Nächten</td><td>−<?= e(rtrim(rtrim(number_format((float)$l['weekly_discount'], 1, ',', ''), '0'), ',')) ?> %</td></tr><?php endif; ?>
          <?php if ($l['mode_langzeit'] && (float)$l['month_price'] > 0): ?><tr><td>Monatspreis ab 28 Nächten (inkl. Nebenkosten)</td><td><?= money((float)$l['month_price']) ?></td></tr><?php endif; ?>
          <?php if ((float)$l['cleaning_fee'] > 0): ?><tr><td>Endreinigung (einmalig)</td><td><?= money((float)$l['cleaning_fee']) ?></td></tr><?php endif; ?>
          <tr><td>Mindestaufenthalt</td><td><?= (int)$l['min_nights'] ?> <?= (int)$l['min_nights'] === 1 ? 'Nacht' : 'Nächte' ?></td></tr>
        </table>
        <p class="hint">Alle Preise sind Endpreise inklusive gesetzlicher Mehrwertsteuer, Bettwäsche und Handtücher.</p>

        <h2 id="kalender">Verfügbarkeit</h2>
        <div class="cal-nav">
          <?php if ($prevLink): ?><a href="<?= e($prevLink) ?>">← Früher</a><?php else: ?><span></span><?php endif; ?>
          <a href="<?= e($nextLink) ?>">Später →</a>
        </div>
        <div class="calendars">
          <?= render_month((int)$start->format('Y'), (int)$start->format('n'), $occupied) ?>
          <?= render_month((int)$next->format('Y'), (int)$next->format('n'), $occupied) ?>
        </div>
        <p class="cal-legend"><span><i style="background:#e5f0e6"></i>frei</span><span><i style="background:#f2d9d3"></i>belegt</span><span>Am Abreisetag ist eine neue Anreise möglich.</span></p>

        <h2>Lage</h2>
        <p><?= e($l['place_name'] ?? 'Emsland') ?>. Die genaue Adresse erhalten Sie mit der Buchungsbestätigung.</p>
        <?php if ($l['lat'] !== null): ?>
          <p class="map-link"><a href="https://www.openstreetmap.org/?mlat=<?= e((string)round((float)$l['lat'], 2)) ?>&amp;mlon=<?= e((string)round((float)$l['lng'], 2)) ?>#map=12/<?= e((string)round((float)$l['lat'], 2)) ?>/<?= e((string)round((float)$l['lng'], 2)) ?>" rel="noopener" target="_blank">Ungefähre Lage auf OpenStreetMap ansehen</a> (öffnet externe Seite)</p>
        <?php endif; ?>
      </div>

      <aside class="card booking-box" id="anfrage">
        <h2>Unverbindlich anfragen</h2>
        <?php if ($errors): ?>
          <div class="alert alert-error" style="width:auto;margin:0 0 12px"><?php foreach (array_unique($errors) as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('unterkunft.php', ['u' => $l['slug']])) ?>#anfrage" data-booking data-mode="<?= $in['reise'] === 'beruflich' ? 'business' : 'urlaub' ?>"
              data-quote-url="<?= e(url('preis.php')) ?>" data-listing="<?= e($l['slug']) ?>">
          <?= csrf_field() ?><?= spam_fields() ?>
          <div class="field-row">
            <div class="field"><label for="b-von">Anreise</label><input required type="date" id="b-von" name="von" min="<?= date('Y-m-d') ?>" value="<?= e($in['von']) ?>"></div>
            <div class="field"><label for="b-bis">Abreise</label><input required type="date" id="b-bis" name="bis" min="<?= date('Y-m-d') ?>" value="<?= e($in['bis']) ?>"></div>
          </div>
          <div class="field"><label for="b-gaeste">Personen</label><input required type="number" id="b-gaeste" name="gaeste" min="1" max="<?= (int)$l['max_guests'] ?>" value="<?= e($in['gaeste']) ?>"></div>

          <div class="quote" data-quote aria-live="polite">
            <?php if ($quoteNow && !isset($quoteNow['error'])): ?>
              <table><?php foreach ($quoteNow['lines'] as [$t, $v]): ?><tr><td><?= e($t) ?></td><td><?= money($v) ?></td></tr><?php endforeach; ?>
              <tr class="total"><td>Gesamtpreis</td><td><?= money($quoteNow['total']) ?></td></tr></table>
            <?php elseif ($quoteNow): ?>
              <span class="error"><?= e($quoteNow['error']) ?></span>
            <?php else: ?>
              Wählen Sie Ihre Daten, um den Endpreis zu sehen.
            <?php endif; ?>
          </div>

          <fieldset>
            <legend>Ich reise …</legend>
            <label class="check"><input type="radio" name="reise" value="privat" <?= $in['reise'] !== 'beruflich' ? 'checked' : '' ?> data-mode-switch="urlaub"> privat</label>
            <label class="check"><input type="radio" name="reise" value="beruflich" <?= $in['reise'] === 'beruflich' ? 'checked' : '' ?> data-mode-switch="business"> beruflich (Rechnung auf Firma möglich)</label>
          </fieldset>

          <div class="business-only">
            <div class="field">
              <label for="b-purpose">Anlass</label>
              <select id="b-purpose" name="purpose">
                <?php foreach ($purposes as $p): ?><option <?= $p === $in['purpose'] ? 'selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?>
              </select>
            </div>
            <label class="check"><input type="checkbox" name="weekdays_only" value="1" <?= $in['weekdays_only'] === '1' ? 'checked' : '' ?>> Ich pendle und bin nur Mo–Fr vor Ort (Wochenenden können ggf. anders vergeben werden)</label>
            <div class="field"><label for="b-company">Firma / Klinik / Praxis</label><input type="text" id="b-company" name="company" value="<?= e($in['company']) ?>" autocomplete="organization"></div>
            <div class="field-row">
              <div class="field"><label for="b-vat">USt-IdNr.</label><input type="text" id="b-vat" name="vat_id" value="<?= e($in['vat_id']) ?>"></div>
              <div class="field"><label for="b-cc">Kostenstelle / Bestell-Nr.</label><input type="text" id="b-cc" name="cost_center" value="<?= e($in['cost_center']) ?>"></div>
            </div>
            <div class="field"><label for="b-billing">Rechnungsadresse</label><textarea id="b-billing" name="billing_address" rows="3" style="min-height:80px"><?= e($in['billing_address']) ?></textarea></div>
          </div>

          <div class="field"><label for="b-name">Name</label><input required type="text" id="b-name" name="name" value="<?= e($in['name']) ?>" autocomplete="name"></div>
          <div class="field"><label for="b-email">E-Mail</label><input required type="email" id="b-email" name="email" value="<?= e($in['email']) ?>" autocomplete="email"></div>
          <div class="field"><label for="b-phone">Telefon (optional)</label><input type="tel" id="b-phone" name="phone" value="<?= e($in['phone']) ?>" autocomplete="tel"></div>
          <div class="field"><label for="b-msg">Nachricht (optional)</label><textarea id="b-msg" name="message" rows="3"><?= e($in['message']) ?></textarea></div>
          <label class="check"><input required type="checkbox" name="agb" value="1" <?= $in['agb'] === '1' ? 'checked' : '' ?>>
            <span>Ich habe die <a href="<?= e(url('seite.php', ['s' => 'agb'])) ?>" target="_blank">Buchungsbedingungen</a> und den <a href="<?= e(url('seite.php', ['s' => 'datenschutz'])) ?>" target="_blank">Datenschutzhinweis</a> gelesen.</span></label>
          <button class="button button-accent button-block" type="submit" style="margin-top:10px">Anfrage senden</button>
          <p class="hint">Kostenlos und unverbindlich. Die Buchung kommt erst mit unserer Bestätigung zustande.</p>
        </form>
      </aside>
    </div>
  </div>
</section>
<?php page_footer();
