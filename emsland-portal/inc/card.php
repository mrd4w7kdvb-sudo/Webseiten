<?php
/** Unterkunfts-Karte. Erwartet $l; optional $l['_quote'] (Preisberechnung), $l['_dist'] (km), $cardQuery (Suchparameter). */
$cover = listing_cover((int)$l['id']);
$q = $l['_quote'] ?? null;
$link = url('unterkunft.php', ['u' => $l['slug']] + ($cardQuery ?? []));
?>
<a class="card listing-card" href="<?= e($link) ?>">
  <img src="<?= e(image_url($cover['file'])) ?>" alt="" loading="lazy" width="400" height="300">
  <div class="body">
    <div class="badges">
      <?php if ($l['mode_urlaub']): ?><span class="badge">Urlaub</span><?php endif; ?>
      <?php if ($l['mode_business']): ?><span class="badge badge-business">Business</span><?php endif; ?>
      <?php if ($l['mode_langzeit']): ?><span class="badge badge-langzeit">Wohnen auf Zeit</span><?php endif; ?>
      <?php $cu = current_user(); if ($cu && $cu['role'] === 'firma' && (float)$cu['company_max_price'] > 0):
          $perNight = isset($q['total'], $q['nights']) ? $q['total'] / max(1, $q['nights']) : (float)$l['price_night'];
          if ($perNight > (float)$cu['company_max_price']): ?><span class="badge badge-bad">über Reiserichtlinie</span><?php endif; endif; ?>
    </div>
    <h3><?= e($l['title']) ?></h3>
    <p class="meta">
      <?= e($l['type']) ?> in <?= e($l['place_name'] ?? 'Emsland') ?> · bis <?= (int)$l['max_guests'] ?> Pers. · <?= (int)$l['bedrooms'] ?> Schlafz.
      <?php if (isset($l['_dist'])): ?><br><strong><?= number_format((float)$l['_dist'], 1, ',', '.') ?> km</strong> Luftlinie zum Arbeitsort<?php endif; ?>
      <?php if ((int)$l['wifi_mbit'] > 0): ?><br>WLAN <?= (int)$l['wifi_mbit'] ?> Mbit/s<?php endif; ?>
    </p>
    <div class="price">
      <?php if ($q && !isset($q['error'])): ?>
        <span class="muted"><?= (int)$q['nights'] ?> Nächte, Endpreis</span>
        <strong><?= money($q['total'], false) ?></strong>
      <?php elseif ($q && isset($q['error'])): ?>
        <span class="muted small"><?= e($q['error']) ?></span>
      <?php else: ?>
        <span class="muted">ab</span>
        <strong><?= money((float)$l['price_night'], false) ?> / Nacht</strong>
        <?php if ($l['mode_langzeit'] && (float)$l['month_price'] > 0): ?><span class="muted small">oder <?= money((float)$l['month_price'], false) ?> / Monat</span><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</a>
