<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
$u = require_user('gastgeber');
$pdo = db();

$l = listing_by_id((int)get('id'));
if (!$l || (int)$l['host_user_id'] !== (int)$u['id']) {
    flash('error', 'Unterkunft nicht gefunden.');
    redirect(url('konto/unterkuenfte.php'));
}
$id = (int)$l['id'];
$self = url('konto/unterkunft.php', ['id' => $id]);

if (is_post()) {
    check_csrf();
    try {
        switch (post('action')) {
            case 'save':
                $price = parse_decimal(post('price_night'));
                $disc = parse_decimal(post('weekly_discount'));
                if ($price <= 0 || $disc > 90) {
                    flash('error', 'Bitte einen gültigen Nachtpreis und einen Rabatt bis 90 % angeben.');
                    break;
                }
                $amen = json_encode(array_values(array_intersect(array_keys(AMENITIES), (array)($_POST['amenities'] ?? []))));
                $pdo->prepare("UPDATE listings SET summary = ?, description = ?, price_night = ?, cleaning_fee = ?, min_nights = ?, weekly_discount = ?, month_price = ?,
                    base_guests = ?, extra_guest_fee = ?, wifi_mbit = ?, amenities = ?, ical_import_url = ?, updated_at = datetime('now','localtime') WHERE id = ?")
                    ->execute([mb_substr(post('summary'), 0, 240), mb_substr(post('description'), 0, 8000), $price, max(0, parse_decimal(post('cleaning_fee'))),
                        max(1, (int)post('min_nights')), max(0, $disc), max(0, parse_decimal(post('month_price'))), max(1, (int)post('base_guests')),
                        max(0, parse_decimal(post('extra_guest_fee'))), max(0, (int)post('wifi_mbit')), $amen,
                        preg_match('#^https?://#i', post('ical_import_url')) ? post('ical_import_url') : '', $id]);
                admin_notify('Gastgeber hat „' . $l['title'] . '“ geändert', "{$u['name']} hat Texte/Preise der Unterkunft „{$l['title']}“ geändert.");
                flash('ok', 'Änderungen gespeichert.');
                break;
            case 'block_add':
                $from = post('block_from');
                $to = post('block_to');
                if (!valid_date($from) || !valid_date($to) || $to <= $from) {
                    flash('error', 'Bitte gültigen Zeitraum angeben (Ende nach Beginn).');
                } else {
                    $pdo->prepare("INSERT INTO blocks (listing_id, date_from, date_to, reason, source) VALUES (?, ?, ?, ?, 'manuell')")
                        ->execute([$id, $from, $to, mb_substr(post('block_reason'), 0, 120) ?: 'Gastgeber']);
                    flash('ok', 'Zeitraum gesperrt.');
                }
                break;
            case 'block_delete':
                $pdo->prepare("DELETE FROM blocks WHERE id = ? AND listing_id = ? AND source = 'manuell'")->execute([(int)post('block_id'), $id]);
                flash('ok', 'Sperre entfernt.');
                break;
            case 'ical_sync':
                $n = ical_import($l);
                flash('ok', "Kalender synchronisiert: $n Belegungen übernommen.");
                break;
            case 'upload':
                $files = $_FILES['images'] ?? null;
                $count = 0;
                if ($files && is_array($files['name'])) {
                    $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort), -1) FROM listing_images WHERE listing_id = ' . $id)->fetchColumn();
                    foreach ($files['name'] as $i => $name) {
                        if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                        $stored = store_upload(['name' => $name, 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]]);
                        $pdo->prepare('INSERT INTO listing_images (listing_id, file, alt, sort) VALUES (?, ?, ?, ?)')->execute([$id, $stored, post('alt') ?: $l['title'], ++$sort]);
                        $count++;
                    }
                }
                flash($count ? 'ok' : 'error', $count ? "$count Foto(s) hochgeladen." : 'Bitte mindestens ein Foto auswählen.');
                break;
            case 'image_delete':
                $st = $pdo->prepare('SELECT * FROM listing_images WHERE id = ? AND listing_id = ?');
                $st->execute([(int)post('image_id'), $id]);
                if ($img = $st->fetch()) {
                    $pdo->prepare('DELETE FROM listing_images WHERE id = ?')->execute([$img['id']]);
                    if (!str_starts_with($img['file'], 'demo:')) @unlink(UPLOAD_DIR . '/' . basename($img['file']));
                    flash('ok', 'Foto gelöscht.');
                }
                break;
            case 'image_cover':
                $pdo->prepare('UPDATE listing_images SET sort = sort + 1 WHERE listing_id = ?')->execute([$id]);
                $pdo->prepare('UPDATE listing_images SET sort = 0 WHERE id = ? AND listing_id = ?')->execute([(int)post('image_id'), $id]);
                flash('ok', 'Titelbild geändert.');
                break;
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect($self);
}

$amen = listing_amenities($l);
$images = listing_images($id);
$st = $pdo->prepare('SELECT * FROM blocks WHERE listing_id = ? AND date_to >= ? ORDER BY date_from');
$st->execute([$id, date('Y-m-d')]);
$blocks = $st->fetchAll();
$m = new DateTimeImmutable(date('Y-m-01'));
$occupied = occupied_nights($id, $m->format('Y-m-d'), $m->modify('+3 months')->format('Y-m-d'));
$num = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', ''), '0'), ',');

konto_header($l['title'], 'unterkuenfte');
?>
<section class="account-main"><div class="wrap">
  <p class="small"><a href="<?= e(url('konto/unterkuenfte.php')) ?>">← Meine Unterkünfte</a></p>
  <div class="admin-top"><h1><?= e($l['title']) ?></h1>
    <div class="actions"><?= $l['active'] ? '<span class="badge badge-ok">online</span>' : '<span class="badge badge-muted">offline</span>' ?>
      <a class="button button-ghost button-small" href="<?= e(url('unterkunft.php', ['u' => $l['slug']])) ?>" target="_blank">Ansehen ↗</a></div></div>

  <div class="panel">
    <h2>Belegung</h2>
    <div class="calendars" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr))">
      <?php for ($i = 0; $i < 3; $i++): $mm = $m->modify("+$i month"); ?><?= render_month((int)$mm->format('Y'), (int)$mm->format('n'), $occupied) ?><?php endfor; ?>
    </div>
    <h3 style="margin-top:18px">Zeitraum sperren</h3>
    <?php if ($blocks): ?>
      <div class="table-wrap"><table class="data"><tr><th>Von</th><th>Bis</th><th>Grund</th><th></th></tr>
        <?php foreach ($blocks as $bl): ?><tr><td><?= date_de($bl['date_from']) ?></td><td><?= date_de($bl['date_to']) ?></td><td><?= e($bl['reason']) ?></td>
          <td><?php if ($bl['source'] === 'manuell'): ?><form method="post" action="<?= e($self) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="block_delete"><input type="hidden" name="block_id" value="<?= (int)$bl['id'] ?>"><button class="button button-ghost button-small" type="submit">Entfernen</button></form><?php else: ?><span class="muted small">iCal</span><?php endif; ?></td></tr><?php endforeach; ?>
      </table></div>
    <?php endif; ?>
    <form method="post" action="<?= e($self) ?>" style="margin-top:10px">
      <?= csrf_field() ?><input type="hidden" name="action" value="block_add">
      <div class="field-row" style="align-items:end">
        <div class="field"><label for="block_from">Von</label><input type="date" id="block_from" name="block_from" required></div>
        <div class="field"><label for="block_to">Bis (wieder frei ab)</label><input type="date" id="block_to" name="block_to" required></div>
        <div class="field"><label for="block_reason">Grund</label><input type="text" id="block_reason" name="block_reason" placeholder="z. B. Eigennutzung"></div>
        <div class="field"><button class="button" type="submit">Sperren</button></div>
      </div>
    </form>
    <p class="small">Kalender-Export für Airbnb, Booking &amp; Co.: <code class="copy"><?= e(absolute_url('ical.php', ['u' => $l['slug'], 't' => $l['ical_token']])) ?></code></p>
    <?php if ($l['ical_import_url']): ?>
      <form method="post" action="<?= e($self) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="ical_sync"><button class="button button-small" type="submit">iCal-Import synchronisieren</button></form>
      <span class="muted small">Zuletzt: <?= e($l['ical_synced_at'] ?? 'noch nie') ?></span>
    <?php endif; ?>
  </div>

  <form class="panel" method="post" action="<?= e($self) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <h2>Texte, Preise &amp; Ausstattung</h2>
    <div class="form-grid">
      <div class="field full"><label for="summary">Kurzbeschreibung</label><input type="text" id="summary" name="summary" value="<?= e($l['summary']) ?>" maxlength="240"></div>
      <div class="field full"><label for="description">Beschreibung</label><textarea id="description" name="description" rows="6"><?= e($l['description']) ?></textarea></div>
      <div class="field"><label for="price_night">Preis pro Nacht (€)</label><input required type="text" inputmode="decimal" id="price_night" name="price_night" value="<?= $num($l['price_night']) ?>"></div>
      <div class="field"><label for="cleaning_fee">Endreinigung (€)</label><input type="text" inputmode="decimal" id="cleaning_fee" name="cleaning_fee" value="<?= $num($l['cleaning_fee']) ?>"></div>
      <div class="field"><label for="base_guests">Im Preis enthaltene Personen</label><input type="number" min="1" id="base_guests" name="base_guests" value="<?= (int)$l['base_guests'] ?>"></div>
      <div class="field"><label for="extra_guest_fee">Weitere Person / Nacht (€)</label><input type="text" inputmode="decimal" id="extra_guest_fee" name="extra_guest_fee" value="<?= $num($l['extra_guest_fee']) ?>"></div>
      <div class="field"><label for="min_nights">Mindestaufenthalt (Nächte)</label><input type="number" min="1" id="min_nights" name="min_nights" value="<?= (int)$l['min_nights'] ?>"></div>
      <div class="field"><label for="weekly_discount">Rabatt ab 7 Nächten (%)</label><input type="text" inputmode="decimal" id="weekly_discount" name="weekly_discount" value="<?= $num($l['weekly_discount']) ?>"></div>
      <div class="field"><label for="month_price">Monatspreis ab 28 Nächten (€)</label><input type="text" inputmode="decimal" id="month_price" name="month_price" value="<?= $num($l['month_price']) ?>" <?= $l['mode_langzeit'] ? '' : 'disabled' ?>>
        <?php if (!$l['mode_langzeit']): ?><p class="hint">„Wohnen auf Zeit“ ist für diese Unterkunft nicht aktiv. Sprechen Sie uns an.</p><?php endif; ?></div>
      <div class="field"><label for="wifi_mbit">WLAN (Mbit/s)</label><input type="number" min="0" id="wifi_mbit" name="wifi_mbit" value="<?= (int)$l['wifi_mbit'] ?>"></div>
      <div class="field full"><label for="ical_import_url">iCal-Import-Adresse (von Airbnb, Booking …)</label><input type="url" id="ical_import_url" name="ical_import_url" value="<?= e($l['ical_import_url']) ?>" placeholder="https://…"></div>
    </div>
    <?php if (!$l['mode_langzeit']): ?><input type="hidden" name="month_price" value="<?= $num($l['month_price']) ?>"><?php endif; ?>
    <fieldset><legend>Ausstattung</legend><div class="amenity-grid">
      <?php foreach (AMENITIES as $k => [$label]): ?><label class="check"><input type="checkbox" name="amenities[]" value="<?= e($k) ?>" <?= in_array($k, $amen, true) ? 'checked' : '' ?>> <?= e($label) ?></label><?php endforeach; ?>
    </div></fieldset>
    <button class="button" type="submit">Speichern</button>
  </form>

  <div class="panel">
    <h2>Fotos</h2>
    <div class="thumbs">
      <?php foreach ($images as $i => $img): ?>
        <div class="thumb"><img src="<?= e(image_url($img['file'])) ?>" alt=""><?= $i === 0 ? '<span class="badge badge-ok">Titelbild</span>' : '' ?>
          <div class="actions" style="margin-top:6px">
            <?php if ($i > 0): ?><form method="post" action="<?= e($self) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="image_cover"><input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>"><button class="button button-ghost button-small" type="submit">Als Titelbild</button></form><?php endif; ?>
            <form method="post" action="<?= e($self) ?>" class="inline-form" data-confirm="Foto löschen?"><?= csrf_field() ?><input type="hidden" name="action" value="image_delete"><input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>"><button class="button button-danger button-small" type="submit">Löschen</button></form>
          </div></div>
      <?php endforeach; ?>
    </div>
    <form method="post" action="<?= e($self) ?>" enctype="multipart/form-data" style="margin-top:14px">
      <?= csrf_field() ?><input type="hidden" name="action" value="upload">
      <div class="form-grid">
        <div class="field"><label for="images">Fotos hochladen (JPG, PNG, WebP, max. 8 MB)</label><input type="file" id="images" name="images[]" accept="image/jpeg,image/png,image/webp" multiple></div>
        <div class="field"><label for="alt">Bildbeschreibung</label><input type="text" id="alt" name="alt"></div>
      </div>
      <button class="button" type="submit">Hochladen</button>
    </form>
  </div>
</div></section>
<?php page_footer();
