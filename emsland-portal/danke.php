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

page_header('Vielen Dank für Ihre Anfrage');
?>
<section class="section">
  <div class="wrap narrow">
    <p class="eyebrow">Anfrage gesendet</p>
    <h1>Vielen Dank!</h1>
    <?php if ($booking): ?>
      <p class="lead">Ihre Anfrage für <strong><?= e($booking['title']) ?></strong> vom <?= date_de($booking['date_from']) ?> bis <?= date_de($booking['date_to']) ?> ist bei uns eingegangen.</p>
      <div class="card" style="padding:20px;margin:20px 0">
        <dl class="kv">
          <dt>Anfrage-Nr.</dt><dd><strong><?= e($booking['ref']) ?></strong></dd>
          <dt>Personen</dt><dd><?= (int)$booking['guests'] ?></dd>
          <dt>Preis laut Anfrage</dt><dd><?= money((float)$booking['price_total']) ?></dd>
          <dt>Bestätigung an</dt><dd><?= e($booking['email']) ?></dd>
        </dl>
      </div>
    <?php else: ?>
      <p class="lead">Ihre Anfrage ist bei uns eingegangen.</p>
    <?php endif; ?>
    <p>Wir prüfen die Verfügbarkeit und melden uns in der Regel innerhalb von 24 Stunden per E-Mail. Erst mit unserer Bestätigung ist die Buchung verbindlich.</p>
    <p><a class="button" href="<?= e(url()) ?>">Zur Startseite</a></p>
  </div>
</section>
<?php page_footer();
