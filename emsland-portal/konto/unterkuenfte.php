<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
$u = require_user('gastgeber');

$st = db()->prepare("SELECT l.*, p.name AS place_name,
    (SELECT COUNT(*) FROM bookings b WHERE b.listing_id = l.id AND b.status = 'angefragt' AND b.date_to >= date('now','localtime')) AS open_requests,
    (SELECT COUNT(*) FROM bookings b WHERE b.listing_id = l.id AND b.status = 'bestaetigt' AND b.date_to >= date('now','localtime')) AS upcoming
    FROM listings l LEFT JOIN places p ON p.id = l.place_id WHERE l.host_user_id = ? ORDER BY l.title");
$st->execute([$u['id']]);
$rows = $st->fetchAll();

konto_header('Meine Unterkünfte', 'unterkuenfte');
?>
<section class="account-main"><div class="wrap">
  <div class="admin-top"><h1>Meine Unterkünfte</h1></div>
  <?php if (!$rows): ?><div class="panel"><p class="muted">Ihrem Zugang ist noch keine Unterkunft zugeordnet.</p></div><?php endif; ?>
  <div class="listing-grid">
    <?php foreach ($rows as $l): $cover = listing_cover((int)$l['id']); ?>
      <a class="card listing-card" href="<?= e(url('konto/unterkunft.php', ['id' => $l['id']])) ?>">
        <img src="<?= e(image_url($cover['file'])) ?>" alt="" loading="lazy">
        <div class="body">
          <div class="badges"><?= $l['active'] ? '<span class="badge badge-ok">online</span>' : '<span class="badge badge-muted">offline</span>' ?>
            <?= $l['open_requests'] ? '<span class="badge badge-warn">' . (int)$l['open_requests'] . ' offene Anfrage(n)</span>' : '' ?></div>
          <h3><?= e($l['title']) ?></h3>
          <p class="meta"><?= e($l['type']) ?> in <?= e($l['place_name'] ?? 'Emsland') ?> · <?= (int)$l['upcoming'] ?> kommende Aufenthalte</p>
          <div class="price"><span class="muted">Nacht / Monat</span><strong><?= money((float)$l['price_night'], false) ?><?= $l['mode_langzeit'] && (float)$l['month_price'] > 0 ? ' / ' . money((float)$l['month_price'], false) : '' ?></strong></div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</div></section>
<?php page_footer();
