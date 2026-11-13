<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/admin.php';
require_admin();

$rows = db()->query("SELECT l.*, p.name AS place_name,
    (SELECT COUNT(*) FROM bookings b WHERE b.listing_id = l.id AND b.status = 'bestaetigt' AND b.date_to >= date('now','localtime')) AS upcoming,
    (SELECT COUNT(*) FROM bookings b WHERE b.listing_id = l.id AND b.status = 'angefragt') AS open_requests
    FROM listings l LEFT JOIN places p ON p.id = l.place_id ORDER BY l.active DESC, l.title")->fetchAll();

admin_header('Unterkünfte', 'unterkuenfte');
?>
<div class="admin-top"><h1>Unterkünfte</h1><a class="button" href="<?= e(url('admin/unterkunft.php')) ?>">+ Neue Unterkunft</a></div>
<div class="panel">
  <div class="table-wrap"><table class="data">
    <tr><th></th><th>Unterkunft</th><th>Ort</th><th>Modelle</th><th class="num">Nacht</th><th class="num">Monat</th><th>Buchungen</th><th>Status</th></tr>
    <?php foreach ($rows as $l): $cover = listing_cover((int)$l['id']); ?>
      <tr>
        <td><img src="<?= e(image_url($cover['file'])) ?>" alt="" style="width:72px;height:54px;object-fit:cover;border-radius:6px"></td>
        <td><a href="<?= e(url('admin/unterkunft.php', ['id' => $l['id']])) ?>"><strong><?= e($l['title']) ?></strong></a><br><span class="muted small"><?= e($l['type']) ?> · bis <?= (int)$l['max_guests'] ?> Pers.</span></td>
        <td><?= e($l['place_name'] ?? '–') ?></td>
        <td><?= e(implode(', ', listing_modes($l))) ?></td>
        <td class="num"><?= money((float)$l['price_night'], false) ?></td>
        <td class="num"><?= $l['mode_langzeit'] && (float)$l['month_price'] > 0 ? money((float)$l['month_price'], false) : '–' ?></td>
        <td><?= (int)$l['upcoming'] ?> künftig<?= $l['open_requests'] ? '<br><a class="small" href="' . e(url('admin/buchungen.php', ['status' => 'angefragt', 'unterkunft' => $l['id']])) . '">' . (int)$l['open_requests'] . ' offen</a>' : '' ?></td>
        <td><?= $l['active'] ? '<span class="badge badge-ok">online</span>' : '<span class="badge badge-muted">offline</span>' ?></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php admin_footer();
