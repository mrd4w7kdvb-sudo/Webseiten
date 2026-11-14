<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$ref = get('ref');
// Details nur für die eigene, gerade gesendete Anfrage anzeigen
$booking = null;
if ($ref !== '' && ($_SESSION['last_ref'] ?? '') === $ref) {
    $st = db()->prepare('SELECT b.*, l.title FROM bookings b JOIN listings l ON l.id = b.listing_id WHERE b.ref = ?');
    $st->execute([$ref]);
    $booking = $st->fetch() ?: null;
}
$series = ($booking && $booking['series_ref']) ? series_bookings($booking['series_ref']) : [];

page_header('Vielen Dank für Ihre Anfrage');
?>
<section class="section">
  <div class="wrap narrow">
    <p class="eyebrow">Anfrage gesendet</p>
    <h1>Vielen Dank!</h1>
    <?php if ($booking): ?>
      <?php if ($series): ?>
        <p class="lead">Ihre Serienanfrage für <strong><?= e($booking['title']) ?></strong> mit <?= count($series) ?> Terminen ist bei uns eingegangen.</p>
        <ul class="series-list"><?php foreach ($series as $s): ?><li><?= date_de($s['date_from'], true) ?> – <?= date_de($s['date_to'], true) ?> · <?= money((float)$s['price_total']) ?></li><?php endforeach; ?></ul>
      <?php else: ?>
        <p class="lead">Ihre Anfrage für <strong><?= e($booking['title']) ?></strong> vom <?= date_de($booking['date_from']) ?> bis <?= date_de($booking['date_to']) ?> ist bei uns eingegangen.</p>
      <?php endif; ?>
      <div class="card" style="padding:20px;margin:20px 0">
        <dl class="kv">
          <dt>Anfrage-Nr.</dt><dd><strong><?= e($booking['series_ref'] ?: $booking['ref']) ?></strong></dd>
          <dt>Personen</dt><dd><?= (int)$booking['guests'] ?></dd>
          <dt>Preis laut Anfrage</dt><dd><?= money($series ? array_sum(array_column($series, 'price_total')) : (float)$booking['price_total']) ?></dd>
          <dt>Bestätigung an</dt><dd><?= e($booking['email']) ?></dd>
        </dl>
      </div>
    <?php else: ?>
      <p class="lead">Ihre Anfrage ist bei uns eingegangen.</p>
    <?php endif; ?>
    <p>Wir prüfen die Verfügbarkeit und melden uns in der Regel innerhalb von 24 Stunden per E-Mail. Erst mit unserer Bestätigung ist die Buchung verbindlich.</p>
    <p><a class="button" href="<?= e(url()) ?>">Zur Startseite</a>
      <?php if (current_user()): ?> <a class="button button-ghost" href="<?= e(url('konto/buchungen.php')) ?>">Zu meinen Buchungen</a><?php endif; ?></p>
  </div>
</section>
<?php page_footer();
