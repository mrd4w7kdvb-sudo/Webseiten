<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/admin.php';
require_admin();

$pdo = db();
$id = (int)get('id');
$l = $id ? listing_by_id($id) : null;
if ($id && !$l) {
    flash('error', 'Unterkunft nicht gefunden.');
    redirect(url('admin/unterkuenfte.php'));
}
$self = fn(array $extra = []) => url('admin/unterkunft.php', ['id' => $id ?: null] + $extra);

$text = ['title', 'type', 'address', 'summary', 'description', 'host_name', 'host_email', 'host_phone', 'ical_import_url'];
$ints = ['place_id', 'max_guests', 'bedrooms', 'single_beds', 'bathrooms', 'size_m2', 'min_nights', 'base_guests', 'wifi_mbit'];
$decs = ['price_night', 'cleaning_fee', 'weekly_discount', 'month_price', 'extra_guest_fee'];
$flags = ['mode_urlaub', 'mode_business', 'mode_langzeit', 'active', 'featured'];
$coords = ['lat', 'lng'];

$data = $l ?? ['title' => '', 'type' => 'Ferienwohnung', 'address' => '', 'summary' => '', 'description' => '', 'host_name' => '', 'host_email' => '', 'host_phone' => '',
    'ical_import_url' => '', 'place_id' => 0, 'max_guests' => 2, 'bedrooms' => 1, 'single_beds' => 0, 'bathrooms' => 1, 'size_m2' => 0, 'min_nights' => 2, 'base_guests' => 2,
    'wifi_mbit' => 0, 'price_night' => 0, 'cleaning_fee' => 0, 'weekly_discount' => 0, 'month_price' => 0, 'extra_guest_fee' => 0, 'mode_urlaub' => 1, 'mode_business' => 0,
    'mode_langzeit' => 0, 'active' => 0, 'featured' => 0, 'lat' => null, 'lng' => null, 'amenities' => '[]', 'slug' => ''];
$errors = [];

if (is_post()) {
    check_csrf();
    $action = post('action');
    try {
        switch ($action) {
            case 'save':
                foreach ($text as $f) $data[$f] = post($f);
                foreach ($ints as $f) $data[$f] = max(0, (int)post($f));
                foreach ($decs as $f) $data[$f] = max(0, parse_decimal(post($f)));
                foreach ($flags as $f) $data[$f] = post($f) === '1' ? 1 : 0;
                foreach ($coords as $f) $data[$f] = post($f) === '' ? null : parse_decimal(post($f));
                $data['amenities'] = json_encode(array_values(array_intersect(array_keys(AMENITIES), (array)($_POST['amenities'] ?? []))));
                if (!in_array($data['type'], LISTING_TYPES, true)) $data['type'] = LISTING_TYPES[0];
                if ($data['title'] === '') $errors[] = 'Bitte einen Titel angeben.';
                if ($data['price_night'] <= 0) $errors[] = 'Bitte einen Preis pro Nacht angeben.';
                if ($data['max_guests'] < 1) $errors[] = 'Maximale Personenzahl muss mindestens 1 sein.';
                if ($data['min_nights'] < 1) $data['min_nights'] = 1;
                if ($data['base_guests'] < 1) $data['base_guests'] = 1;
                if ($data['weekly_discount'] > 90) $errors[] = 'Der Rabatt ist zu hoch.';
                if (!$data['mode_urlaub'] && !$data['mode_business'] && !$data['mode_langzeit']) $errors[] = 'Bitte mindestens ein Vermietungsmodell wählen.';
                if ($data['host_email'] !== '' && !valid_email($data['host_email'])) $errors[] = 'Die E-Mail des Gastgebers ist ungültig.';
                if ($data['ical_import_url'] !== '' && !preg_match('#^https?://#i', $data['ical_import_url'])) $errors[] = 'Die iCal-Adresse muss mit https:// beginnen.';
                if ($data['place_id'] && !place_by_id((int)$data['place_id'])) $data['place_id'] = 0;
                if ($errors) break;

                // Ohne eigene Koordinaten: Koordinaten des Ortes verwenden
                if ($data['lat'] === null && $data['place_id']) {
                    $p = place_by_id((int)$data['place_id']);
                    $data['lat'] = (float)$p['lat'];
                    $data['lng'] = (float)$p['lng'];
                }
                $cols = array_merge($text, $ints, $decs, $flags, $coords, ['amenities']);
                $vals = array_map(fn($c) => $c === 'place_id' && !$data[$c] ? null : $data[$c], $cols);
                if ($l) {
                    $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
                    $pdo->prepare("UPDATE listings SET $set, updated_at = datetime('now','localtime') WHERE id = ?")->execute([...$vals, $l['id']]);
                    flash('ok', 'Unterkunft gespeichert.');
                } else {
                    $slug = slugify($data['title'] . ' ' . (place_by_id((int)$data['place_id'])['name'] ?? ''));
                    $base = $slug;
                    for ($i = 2; listing_by_slug($slug); $i++) $slug = "$base-$i";
                    $cols2 = [...$cols, 'slug', 'ical_token'];
                    $pdo->prepare('INSERT INTO listings (' . implode(', ', $cols2) . ') VALUES (' . implode(', ', array_fill(0, count($cols2), '?')) . ')')
                        ->execute([...$vals, $slug, bin2hex(random_bytes(16))]);
                    $id = (int)$pdo->lastInsertId();
                    flash('ok', 'Unterkunft angelegt. Fügen Sie jetzt Fotos hinzu und schalten Sie sie online.');
                }
                redirect(url('admin/unterkunft.php', ['id' => $id]));

            case 'upload':
                if (!$l) break;
                $files = $_FILES['images'] ?? null;
                $count = 0;
                if ($files && is_array($files['name'])) {
                    $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort), -1) FROM listing_images WHERE listing_id = ' . (int)$l['id'])->fetchColumn();
                    foreach ($files['name'] as $i => $name) {
                        if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                        $stored = store_upload(['name' => $name, 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]]);
                        $pdo->prepare('INSERT INTO listing_images (listing_id, file, alt, sort) VALUES (?, ?, ?, ?)')->execute([$l['id'], $stored, post('alt') ?: $l['title'], ++$sort]);
                        $count++;
                    }
                }
                flash($count ? 'ok' : 'error', $count ? "$count Foto(s) hochgeladen." : 'Bitte mindestens ein Foto auswählen.');
                redirect($self() . '#fotos');

            case 'image_delete':
            case 'image_cover':
                if (!$l) break;
                $st = $pdo->prepare('SELECT * FROM listing_images WHERE id = ? AND listing_id = ?');
                $st->execute([(int)post('image_id'), $l['id']]);
                $img = $st->fetch();
                if ($img && $action === 'image_delete') {
                    $pdo->prepare('DELETE FROM listing_images WHERE id = ?')->execute([$img['id']]);
                    if (!str_starts_with($img['file'], 'demo:')) @unlink(UPLOAD_DIR . '/' . basename($img['file']));
                    flash('ok', 'Foto gelöscht.');
                } elseif ($img) {
                    $pdo->prepare('UPDATE listing_images SET sort = sort + 1 WHERE listing_id = ?')->execute([$l['id']]);
                    $pdo->prepare('UPDATE listing_images SET sort = 0 WHERE id = ?')->execute([$img['id']]);
                    flash('ok', 'Titelbild geändert.');
                }
                redirect($self() . '#fotos');

            case 'block_add':
                if (!$l) break;
                $from = post('block_from');
                $to = post('block_to');
                if (!valid_date($from) || !valid_date($to) || $to <= $from) {
                    flash('error', 'Bitte gültigen Zeitraum für die Sperrzeit angeben (Ende nach Beginn).');
                } else {
                    $pdo->prepare("INSERT INTO blocks (listing_id, date_from, date_to, reason, source) VALUES (?, ?, ?, ?, 'manuell')")
                        ->execute([$l['id'], $from, $to, mb_substr(post('block_reason'), 0, 120)]);
                    flash('ok', 'Sperrzeit eingetragen.');
                }
                redirect($self() . '#kalender');

            case 'block_delete':
                if (!$l) break;
                $pdo->prepare('DELETE FROM blocks WHERE id = ? AND listing_id = ?')->execute([(int)post('block_id'), $l['id']]);
                flash('ok', 'Sperrzeit entfernt.');
                redirect($self() . '#kalender');

            case 'ical_sync':
                if (!$l) break;
                $n = ical_import($l);
                flash('ok', "Kalender synchronisiert: $n Belegungen übernommen.");
                redirect($self() . '#kalender');

            case 'ical_token':
                if (!$l) break;
                $pdo->prepare('UPDATE listings SET ical_token = ? WHERE id = ?')->execute([bin2hex(random_bytes(16)), $l['id']]);
                flash('ok', 'Neue Export-Adresse erzeugt. Bitte in den anderen Portalen aktualisieren.');
                redirect($self() . '#kalender');

            case 'delete':
                if (!$l) break;
                $cnt = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE listing_id = ?');
                $cnt->execute([$l['id']]);
                if ((int)$cnt->fetchColumn() > 0) {
                    flash('error', 'Diese Unterkunft hat Buchungen und kann nicht gelöscht werden. Schalten Sie sie stattdessen offline.');
                    redirect($self());
                }
                foreach (listing_images((int)$l['id']) as $img) {
                    if (!str_starts_with($img['file'], 'demo:')) @unlink(UPLOAD_DIR . '/' . basename($img['file']));
                }
                $pdo->prepare('DELETE FROM listings WHERE id = ?')->execute([$l['id']]);
                flash('ok', 'Unterkunft gelöscht.');
                redirect(url('admin/unterkuenfte.php'));
        }
    } catch (Throwable $ex) {
        flash('error', $ex instanceof RuntimeException ? $ex->getMessage() : 'Es ist ein Fehler aufgetreten.');
        redirect($self());
    }
}

$amen = json_decode((string)$data['amenities'], true) ?: [];
$images = $l ? listing_images((int)$l['id']) : [];
$blocks = [];
if ($l) {
    $st = $pdo->prepare('SELECT * FROM blocks WHERE listing_id = ? AND date_to >= ? ORDER BY date_from');
    $st->execute([$l['id'], date('Y-m-d')]);
    $blocks = $st->fetchAll();
}
$num = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', ''), '0'), ',');

admin_header($l ? $l['title'] : 'Neue Unterkunft', 'unterkuenfte');
?>
<div class="admin-top">
  <h1><?= $l ? e($l['title']) : 'Neue Unterkunft' ?></h1>
  <?php if ($l): ?><div class="actions">
    <a class="button button-ghost button-small" href="<?= e(url('unterkunft.php', ['u' => $l['slug']])) ?>" target="_blank">Ansehen ↗</a>
    <a class="button button-small" href="<?= e(url('admin/buchung.php', ['neu' => 1, 'unterkunft' => $l['id']])) ?>">+ Buchung</a>
  </div><?php endif; ?>
</div>
<?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

<form method="post" action="<?= e($self()) ?>">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">

  <div class="panel">
    <h2>Grunddaten</h2>
    <div class="form-grid">
      <div class="field full"><label for="title">Titel</label><input required type="text" id="title" name="title" value="<?= e($data['title']) ?>" maxlength="120"></div>
      <div class="field"><label for="type">Art</label><select id="type" name="type"><?php foreach (LISTING_TYPES as $t): ?><option <?= $t === $data['type'] ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="place_id">Ort</label><select id="place_id" name="place_id"><option value="0">–</option><?php foreach (places() as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === (int)$data['place_id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
        <p class="hint">Fehlt ein Ort? <a href="<?= e(url('admin/orte.php')) ?>">Orte verwalten</a></p></div>
      <div class="field full"><label for="summary">Kurzbeschreibung (1–2 Sätze, erscheint in der Trefferliste)</label><input type="text" id="summary" name="summary" value="<?= e($data['summary']) ?>" maxlength="240"></div>
      <div class="field full"><label for="description">Beschreibung</label><textarea id="description" name="description" rows="7"><?= e($data['description']) ?></textarea><p class="hint">Leerzeile = neuer Absatz.</p></div>
      <div class="field full"><label for="address">Adresse (nur intern, wird Gästen nicht angezeigt)</label><input type="text" id="address" name="address" value="<?= e($data['address']) ?>"></div>
      <div class="field"><label for="lat">Breitengrad (optional)</label><input type="text" id="lat" name="lat" value="<?= $data['lat'] === null ? '' : e((string)$data['lat']) ?>" placeholder="z. B. 52.6920"></div>
      <div class="field"><label for="lng">Längengrad (optional)</label><input type="text" id="lng" name="lng" value="<?= $data['lng'] === null ? '' : e((string)$data['lng']) ?>" placeholder="z. B. 7.2910"><p class="hint">Leer = Ortsmitte. Für genaue Entfernungen zum Arbeitsort.</p></div>
    </div>
  </div>

  <div class="panel">
    <h2>Größe &amp; Ausstattung</h2>
    <div class="form-grid">
      <div class="field"><label for="max_guests">Max. Personen</label><input type="number" min="1" id="max_guests" name="max_guests" value="<?= (int)$data['max_guests'] ?>"></div>
      <div class="field"><label for="bedrooms">Schlafzimmer</label><input type="number" min="0" id="bedrooms" name="bedrooms" value="<?= (int)$data['bedrooms'] ?>"></div>
      <div class="field"><label for="single_beds">Einzelbetten</label><input type="number" min="0" id="single_beds" name="single_beds" value="<?= (int)$data['single_beds'] ?>"></div>
      <div class="field"><label for="bathrooms">Badezimmer</label><input type="number" min="0" id="bathrooms" name="bathrooms" value="<?= (int)$data['bathrooms'] ?>"></div>
      <div class="field"><label for="size_m2">Größe (m²)</label><input type="number" min="0" id="size_m2" name="size_m2" value="<?= (int)$data['size_m2'] ?>"></div>
      <div class="field"><label for="wifi_mbit">WLAN (Mbit/s, gemessen)</label><input type="number" min="0" id="wifi_mbit" name="wifi_mbit" value="<?= (int)$data['wifi_mbit'] ?>"></div>
    </div>
    <fieldset><legend>Ausstattung</legend>
      <div class="amenity-grid">
        <?php foreach (AMENITIES as $k => [$label]): ?>
          <label class="check"><input type="checkbox" name="amenities[]" value="<?= e($k) ?>" <?= in_array($k, $amen, true) ? 'checked' : '' ?>> <?= e($label) ?></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
  </div>

  <div class="panel">
    <h2>Vermietung &amp; Preise</h2>
    <fieldset><legend>Vermietungsmodelle</legend>
      <label class="check"><input type="checkbox" name="mode_urlaub" value="1" <?= $data['mode_urlaub'] ? 'checked' : '' ?>> <span><strong>Urlaub</strong> – erscheint in der Urlaubssuche</span></label>
      <label class="check"><input type="checkbox" name="mode_business" value="1" <?= $data['mode_business'] ? 'checked' : '' ?>> <span><strong>Business</strong> – erscheint in „Wohnen auf Zeit“, auch für kurze Arbeitsaufenthalte</span></label>
      <label class="check"><input type="checkbox" name="mode_langzeit" value="1" <?= $data['mode_langzeit'] ? 'checked' : '' ?>> <span><strong>Wohnen auf Zeit</strong> – Aufenthalte ab 28 Nächten zum Monatspreis</span></label>
    </fieldset>
    <div class="form-grid">
      <div class="field"><label for="price_night">Preis pro Nacht (€)</label><input required type="text" inputmode="decimal" id="price_night" name="price_night" value="<?= $num($data['price_night']) ?>"></div>
      <div class="field"><label for="cleaning_fee">Endreinigung (€)</label><input type="text" inputmode="decimal" id="cleaning_fee" name="cleaning_fee" value="<?= $num($data['cleaning_fee']) ?>"></div>
      <div class="field"><label for="base_guests">Im Preis enthaltene Personen</label><input type="number" min="1" id="base_guests" name="base_guests" value="<?= (int)$data['base_guests'] ?>"></div>
      <div class="field"><label for="extra_guest_fee">Jede weitere Person / Nacht (€)</label><input type="text" inputmode="decimal" id="extra_guest_fee" name="extra_guest_fee" value="<?= $num($data['extra_guest_fee']) ?>"></div>
      <div class="field"><label for="min_nights">Mindestaufenthalt (Nächte)</label><input type="number" min="1" id="min_nights" name="min_nights" value="<?= (int)$data['min_nights'] ?>"></div>
      <div class="field"><label for="weekly_discount">Rabatt ab 7 Nächten (%)</label><input type="text" inputmode="decimal" id="weekly_discount" name="weekly_discount" value="<?= $num($data['weekly_discount']) ?>"></div>
      <div class="field"><label for="month_price">Monatspreis ab 28 Nächten (€)</label><input type="text" inputmode="decimal" id="month_price" name="month_price" value="<?= $num($data['month_price']) ?>"><p class="hint">Inklusive Nebenkosten. Wird anteilig berechnet (1 Monat = 30 Nächte).</p></div>
    </div>
  </div>

  <div class="panel">
    <h2>Gastgeber &amp; Sichtbarkeit</h2>
    <div class="form-grid">
      <div class="field"><label for="host_name">Gastgeber/in</label><input type="text" id="host_name" name="host_name" value="<?= e($data['host_name']) ?>"></div>
      <div class="field"><label for="host_phone">Telefon</label><input type="tel" id="host_phone" name="host_phone" value="<?= e($data['host_phone']) ?>"></div>
      <div class="field"><label for="host_email">E-Mail</label><input type="email" id="host_email" name="host_email" value="<?= e($data['host_email']) ?>"></div>
      <div class="field"><label for="ical_import_url">iCal-Import (Kalender von Airbnb, Booking …)</label><input type="url" id="ical_import_url" name="ical_import_url" value="<?= e($data['ical_import_url']) ?>" placeholder="https://…"></div>
    </div>
    <label class="check"><input type="checkbox" name="active" value="1" <?= $data['active'] ? 'checked' : '' ?>> <span><strong>Online</strong> – auf der Webseite sichtbar und buchbar</span></label>
    <label class="check"><input type="checkbox" name="featured" value="1" <?= $data['featured'] ? 'checked' : '' ?>> Auf der Startseite hervorheben</label>
  </div>
  <p><button class="button" type="submit"><?= $l ? 'Änderungen speichern' : 'Unterkunft anlegen' ?></button></p>
</form>

<?php if ($l): ?>
<div class="panel" id="fotos">
  <h2>Fotos</h2>
  <?php if (!$images): ?><p class="muted">Noch keine Fotos.</p><?php endif; ?>
  <div class="thumbs">
    <?php foreach ($images as $i => $img): ?>
      <div class="thumb">
        <img src="<?= e(image_url($img['file'])) ?>" alt="">
        <?= $i === 0 ? '<span class="badge badge-ok">Titelbild</span>' : '' ?>
        <div class="actions" style="margin-top:6px">
          <?php if ($i > 0): ?>
          <form method="post" action="<?= e($self()) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="image_cover"><input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>"><button class="button button-ghost button-small" type="submit">Als Titelbild</button></form>
          <?php endif; ?>
          <form method="post" action="<?= e($self()) ?>" class="inline-form" data-confirm="Foto löschen?"><?= csrf_field() ?><input type="hidden" name="action" value="image_delete"><input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>"><button class="button button-danger button-small" type="submit">Löschen</button></form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <form method="post" action="<?= e($self()) ?>" enctype="multipart/form-data" style="margin-top:18px">
    <?= csrf_field() ?><input type="hidden" name="action" value="upload">
    <div class="form-grid">
      <div class="field"><label for="images">Fotos hochladen (JPG, PNG, WebP, max. 8 MB je Bild)</label><input type="file" id="images" name="images[]" accept="image/jpeg,image/png,image/webp" multiple></div>
      <div class="field"><label for="alt">Bildbeschreibung (für Barrierefreiheit)</label><input type="text" id="alt" name="alt" placeholder="z. B. Wohnzimmer mit Kaminofen"></div>
    </div>
    <button class="button" type="submit">Hochladen</button>
  </form>
</div>

<div class="panel" id="kalender">
  <h2>Kalender &amp; Sperrzeiten</h2>
  <p>Gesperrte Zeiträume (z. B. Eigennutzung, Renovierung) sind auf der Webseite als belegt markiert.</p>
  <?php if ($blocks): ?>
    <div class="table-wrap"><table class="data">
      <tr><th>Von</th><th>Bis</th><th>Grund</th><th>Quelle</th><th></th></tr>
      <?php foreach ($blocks as $bl): ?>
        <tr><td><?= date_de($bl['date_from']) ?></td><td><?= date_de($bl['date_to']) ?></td><td><?= e($bl['reason']) ?></td><td><?= e($bl['source']) ?></td>
          <td><?php if ($bl['source'] === 'manuell'): ?><form method="post" action="<?= e($self()) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="block_delete"><input type="hidden" name="block_id" value="<?= (int)$bl['id'] ?>"><button class="button button-ghost button-small" type="submit">Entfernen</button></form><?php endif; ?></td></tr>
      <?php endforeach; ?>
    </table></div>
  <?php endif; ?>
  <form method="post" action="<?= e($self()) ?>" style="margin-top:14px">
    <?= csrf_field() ?><input type="hidden" name="action" value="block_add">
    <div class="field-row" style="align-items:end">
      <div class="field"><label for="block_from">Von (erste gesperrte Nacht)</label><input type="date" id="block_from" name="block_from" required></div>
      <div class="field"><label for="block_to">Bis (Tag, an dem wieder frei)</label><input type="date" id="block_to" name="block_to" required></div>
      <div class="field"><label for="block_reason">Grund</label><input type="text" id="block_reason" name="block_reason" placeholder="z. B. Eigennutzung"></div>
      <div class="field"><button class="button" type="submit">Sperren</button></div>
    </div>
  </form>

  <h3 style="margin-top:20px">Kalender-Abgleich mit anderen Portalen</h3>
  <p><strong>Export</strong> – diese Adresse bei Airbnb, Booking &amp; Co. als „Kalender importieren“ eintragen:<br>
    <code class="copy"><?= e(absolute_url('ical.php', ['u' => $l['slug'], 't' => $l['ical_token']])) ?></code></p>
  <div class="actions">
    <?php if ($l['ical_import_url']): ?>
      <form method="post" action="<?= e($self()) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="ical_sync"><button class="button button-small" type="submit">Import jetzt synchronisieren</button></form>
      <span class="muted small">Zuletzt: <?= e($l['ical_synced_at'] ?? 'noch nie') ?></span>
    <?php else: ?>
      <span class="muted small">Für den Import oben unter „Gastgeber &amp; Sichtbarkeit“ eine iCal-Adresse eintragen.</span>
    <?php endif; ?>
    <form method="post" action="<?= e($self()) ?>" class="inline-form" data-confirm="Neue Export-Adresse erzeugen? Die alte funktioniert danach nicht mehr."><?= csrf_field() ?><input type="hidden" name="action" value="ical_token"><button class="button button-ghost button-small" type="submit">Neue Export-Adresse</button></form>
  </div>
</div>

<form method="post" action="<?= e($self()) ?>" data-confirm="Unterkunft „<?= e($l['title']) ?>“ endgültig löschen?">
  <?= csrf_field() ?><input type="hidden" name="action" value="delete">
  <button class="button button-danger button-small" type="submit">Unterkunft löschen</button>
</form>
<?php endif; ?>
<?php admin_footer();
