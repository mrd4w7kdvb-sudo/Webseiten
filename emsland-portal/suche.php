<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$modus = get('modus') === 'business' ? 'business' : 'urlaub';
$ort = (int)get('ort');
$arbeitsort = (int)get('arbeitsort');
$von = get('von');
$bis = get('bis');
$gaeste = max(1, min(20, (int)(get('gaeste') ?: '1')));
$typ = get('typ');
$sort = get('sort', $arbeitsort ? 'entfernung' : 'empfohlen');
$wifiMin = (int)get('wlan');
$filters = array_values(array_intersect(array_keys(AMENITIES), (array)($_GET['f'] ?? [])));
$hasDates = valid_date($von) && valid_date($bis) && $bis > $von;

$sql = 'SELECT l.*, p.name AS place_name, p.lat AS place_lat, p.lng AS place_lng FROM listings l LEFT JOIN places p ON p.id = l.place_id WHERE l.active = 1';
$args = [];
$sql .= $modus === 'business' ? ' AND (l.mode_business = 1 OR l.mode_langzeit = 1)' : ' AND l.mode_urlaub = 1';
if ($ort && $modus === 'urlaub') {
    $sql .= ' AND l.place_id = ?';
    $args[] = $ort;
}
if (in_array($typ, LISTING_TYPES, true)) {
    $sql .= ' AND l.type = ?';
    $args[] = $typ;
}
$sql .= ' AND l.max_guests >= ?';
$args[] = $gaeste;
if ($wifiMin > 0) {
    $sql .= ' AND l.wifi_mbit >= ?';
    $args[] = $wifiMin;
}
$st = db()->prepare($sql . ' ORDER BY l.featured DESC, l.id DESC');
$st->execute($args);

$work = $arbeitsort ? place_by_id($arbeitsort) : null;
$results = [];
foreach ($st->fetchAll() as $l) {
    $am = listing_amenities($l);
    if (array_diff($filters, $am)) {
        continue;
    }
    if ($hasDates) {
        if (!is_available((int)$l['id'], $von, $bis)) {
            continue;
        }
        $l['_quote'] = calc_price($l, $von, $bis, $gaeste);
    }
    if ($work) {
        $lat = $l['lat'] ?? $l['place_lat'];
        $lng = $l['lng'] ?? $l['place_lng'];
        if ($lat !== null) {
            $l['_dist'] = distance_km((float)$work['lat'], (float)$work['lng'], (float)$lat, (float)$lng);
        }
    }
    $results[] = $l;
}

$priceOf = fn($l) => isset($l['_quote']['total']) ? $l['_quote']['total'] : (float)$l['price_night'];
usort($results, match ($sort) {
    'preis' => fn($a, $b) => $priceOf($a) <=> $priceOf($b),
    'entfernung' => fn($a, $b) => ($a['_dist'] ?? 999) <=> ($b['_dist'] ?? 999),
    'groesse' => fn($a, $b) => (int)$b['max_guests'] <=> (int)$a['max_guests'],
    default => fn($a, $b) => 0,
});

$search = ['modus' => $modus, 'ort' => $ort ?: '', 'von' => $von, 'bis' => $bis, 'gaeste' => $gaeste, 'arbeitsort' => $arbeitsort ?: ''];
$cardQuery = $hasDates ? ['von' => $von, 'bis' => $bis, 'gaeste' => $gaeste, 'modus' => $modus] : ['modus' => $modus];

$filterGroups = $modus === 'business'
    ? ['schreibtisch', 'waschmaschine', 'verdunkelung', 'selfcheckin', 'einzelbetten', 'transporter', 'parkplatz', 'kueche']
    : ['hund', 'garten', 'sauna', 'kamin', 'fahrradgarage', 'kinderbett', 'barrierefrei', 'eladestation'];

page_header($modus === 'business' ? 'Wohnen auf Zeit im Emsland' : 'Urlaub im Emsland');
?>
<section class="section" style="padding-top:36px">
  <div class="wrap">
    <h1><?= $modus === 'business' ? 'Wohnen auf Zeit &amp; Business' : 'Ferienunterkünfte im Emsland' ?></h1>
    <?php if ($modus === 'business'): ?>
      <p class="lead">Für Vertretungsärztinnen und -ärzte, Pflegekräfte, Projekt- und Bauleiter und Montageteams. Ab 28 Nächten gilt der Monatspreis.</p>
    <?php endif; ?>

    <div class="results-layout">
      <form class="filters" method="get" action="<?= e(url('suche.php')) ?>" data-mode="<?= e($modus) ?>">
        <h2>Suche anpassen</h2>
        <input type="hidden" name="modus" value="<?= e($modus) ?>">
        <?php if ($modus === 'business'): ?>
          <div class="field">
            <label for="f-arbeitsort">Arbeitsort</label>
            <select id="f-arbeitsort" name="arbeitsort">
              <option value="">–</option>
              <?php foreach (places() as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $arbeitsort ? 'selected' : '' ?>><?= e($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="hint">Sortiert nach Entfernung (Luftlinie).</p>
          </div>
        <?php else: ?>
          <div class="field">
            <label for="f-ort">Ort</label>
            <select id="f-ort" name="ort">
              <option value="">Ganzes Emsland</option>
              <?php foreach (places() as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $ort ? 'selected' : '' ?>><?= e($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
        <div class="field-row">
          <div class="field"><label for="f-von">Anreise</label><input type="date" id="f-von" name="von" value="<?= e($von) ?>"></div>
          <div class="field"><label for="f-bis">Abreise</label><input type="date" id="f-bis" name="bis" value="<?= e($bis) ?>"></div>
        </div>
        <div class="field"><label for="f-gaeste">Personen</label><input type="number" id="f-gaeste" name="gaeste" min="1" max="20" value="<?= $gaeste ?>"></div>
        <div class="field">
          <label for="f-typ">Art der Unterkunft</label>
          <select id="f-typ" name="typ">
            <option value="">Alle</option>
            <?php foreach (LISTING_TYPES as $t): ?><option <?= $t === $typ ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
          </select>
        </div>
        <?php if ($modus === 'business'): ?>
          <div class="field">
            <label for="f-wlan">Internet mindestens</label>
            <select id="f-wlan" name="wlan">
              <?php foreach ([0 => 'egal', 50 => '50 Mbit/s', 100 => '100 Mbit/s', 250 => '250 Mbit/s'] as $v => $t): ?>
                <option value="<?= $v ?>" <?= $v === $wifiMin ? 'selected' : '' ?>><?= $t ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
        <fieldset>
          <legend>Ausstattung</legend>
          <?php foreach ($filterGroups as $k): ?>
            <label class="check"><input type="checkbox" name="f[]" value="<?= e($k) ?>" <?= in_array($k, $filters, true) ? 'checked' : '' ?>> <?= e(AMENITIES[$k][0]) ?></label>
          <?php endforeach; ?>
        </fieldset>
        <input type="hidden" name="sort" value="<?= e($sort) ?>">
        <button class="button button-block" type="submit">Ergebnisse anzeigen</button>
        <p class="hint" style="text-align:center"><a href="<?= e(url('suche.php', ['modus' => $modus === 'business' ? 'urlaub' : 'business'])) ?>">Zu „<?= $modus === 'business' ? 'Urlaub' : 'Wohnen auf Zeit' ?>“ wechseln</a></p>
      </form>

      <div class="results">
        <div class="results-head">
          <p style="margin:0"><strong><?= count($results) ?></strong> <?= count($results) === 1 ? 'Unterkunft' : 'Unterkünfte' ?><?= $hasDates ? ' frei vom ' . date_de($von) . ' bis ' . date_de($bis) : '' ?></p>
          <form method="get" action="<?= e(url('suche.php')) ?>">
            <?php foreach ($_GET as $k => $v): if ($k === 'sort') continue; ?>
              <?php if (is_array($v)): foreach ($v as $vv): ?><input type="hidden" name="<?= e($k) ?>[]" value="<?= e((string)$vv) ?>"><?php endforeach; else: ?>
              <input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>"><?php endif; ?>
            <?php endforeach; ?>
            <label for="sort" class="hp">Sortierung</label>
            <select id="sort" name="sort" data-autosubmit>
              <option value="empfohlen" <?= $sort === 'empfohlen' ? 'selected' : '' ?>>Empfohlen</option>
              <option value="preis" <?= $sort === 'preis' ? 'selected' : '' ?>>Preis aufsteigend</option>
              <?php if ($work): ?><option value="entfernung" <?= $sort === 'entfernung' ? 'selected' : '' ?>>Entfernung zum Arbeitsort</option><?php endif; ?>
              <option value="groesse" <?= $sort === 'groesse' ? 'selected' : '' ?>>Größe</option>
            </select>
            <noscript><button class="button button-small" type="submit">Sortieren</button></noscript>
          </form>
        </div>
        <?php if (!$results): ?>
          <div class="empty">
            <h2>Keine passende Unterkunft gefunden</h2>
            <p>Probieren Sie andere Daten oder weniger Filter. Für Firmen suchen wir auch persönlich.</p>
            <a class="button" href="<?= e(url('firmen.php')) ?>">Persönliche Suche anfragen</a>
          </div>
        <?php else: ?>
          <div class="listing-grid">
            <?php foreach ($results as $l) { include __DIR__ . '/inc/card.php'; } ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
<?php page_footer();
