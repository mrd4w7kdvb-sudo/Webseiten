<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$featured = db()->query('SELECT l.*, p.name AS place_name FROM listings l LEFT JOIN places p ON p.id = l.place_id
    WHERE l.active = 1 ORDER BY l.featured DESC, l.id DESC LIMIT 6')->fetchAll();

page_header('', 'Ferienwohnungen und Ferienhäuser im Emsland buchen – für Urlaub, Wochenende und Wohnen auf Zeit.');
?>
<section class="hero">
  <div class="wrap">
    <p class="eyebrow" style="color:#ffd9c4">Ferienwohnungen &amp; Häuser im Emsland</p>
    <h1>Am Wochenende Urlaub. Unter der Woche ein Zuhause auf Zeit.</h1>
    <p class="lead">Ob Radtour an der Ems, Familienurlaub oder drei Monate Praxisvertretung: Hier finden Sie Unterkünfte direkt bei Gastgebern aus der Region.</p>
    <?php include __DIR__ . '/inc/searchform.php'; ?>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <div class="section-head">
      <div>
        <p class="eyebrow">Unterkünfte</p>
        <h2>Ausgewählte Ferienwohnungen und Häuser</h2>
      </div>
      <a class="button button-ghost" href="<?= e(url('suche.php')) ?>">Alle Unterkünfte</a>
    </div>
    <div class="listing-grid">
      <?php foreach ($featured as $l) { include __DIR__ . '/inc/card.php'; } ?>
    </div>
  </div>
</section>

<section class="section section-tint">
  <div class="wrap">
    <p class="eyebrow">Für wen?</p>
    <h2>Eine Unterkunft für jeden Anlass</h2>
    <div class="audience">
      <div class="card">
        <h3>Wochenende &amp; Urlaub</h3>
        <p>Kurz raus ins Grüne oder zwei Wochen Familienurlaub: Radwege, Moor, Wasser und viel Ruhe.</p>
        <ul class="checks"><li>Sofort sehen, was es kostet, inklusive Endreinigung</li><li>Hund, Garten, Sauna, Fahrradgarage filtern</li><li>Ab 7 Nächten automatisch günstiger</li></ul>
        <a href="<?= e(url('suche.php', ['modus' => 'urlaub'])) ?>">Urlaubsunterkünfte ansehen →</a>
      </div>
      <div class="card">
        <h3>Ärztinnen, Ärzte &amp; Pflege auf Zeit</h3>
        <p>Für Praxis- und Klinikvertretungen: ruhig schlafen nach dem Dienst, kurze Wege zur Arbeit.</p>
        <ul class="checks"><li>Monatspreise inklusive Nebenkosten</li><li>Verdunkelung, Arbeitsplatz, Waschmaschine</li><li>Sortierung nach Entfernung zum Arbeitsort</li></ul>
        <a href="<?= e(url('suche.php', ['modus' => 'business'])) ?>">Wohnen auf Zeit finden →</a>
      </div>
      <div class="card">
        <h3>Projekt- &amp; Bauleiter, Monteure</h3>
        <p>Für Projekte von einer Woche bis zu mehreren Monaten, auch als Wochenpendler von Montag bis Freitag.</p>
        <ul class="checks"><li>Rechnung auf Firma mit Kostenstelle</li><li>Stellplatz für Transporter, Einzelbetten</li><li>Schnelles WLAN mit Angabe in Mbit/s</li></ul>
        <a href="<?= e(url('firmen.php')) ?>">Angebot für Firmen →</a>
      </div>
    </div>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <p class="eyebrow">So funktioniert's</p>
    <h2>In drei Schritten zur Unterkunft</h2>
    <ol class="steps">
      <li><h3>Suchen</h3><p>Zeitraum, Personen und auf Wunsch Ihren Arbeitsort eingeben. Der Endpreis steht sofort da.</p></li>
      <li><h3>Anfragen</h3><p>Sie schicken eine unverbindliche Buchungsanfrage, bei Bedarf mit Rechnungsdaten Ihrer Firma.</p></li>
      <li><h3>Bestätigung erhalten</h3><p>Wir prüfen die Verfügbarkeit und bestätigen Ihre Buchung in der Regel innerhalb von 24 Stunden.</p></li>
    </ol>
  </div>
</section>

<section class="wrap">
  <div class="cta-band">
    <div>
      <h2>Sie vermieten im Emsland?</h2>
      <p>Mehr Auslastung unter der Woche und im Winter, mit Gästen auf Zeit aus Kliniken, Industrie und Bau.</p>
    </div>
    <a class="button" href="<?= e(url('gastgeber.php')) ?>">Gastgeber werden</a>
  </div>
</section>
<?php page_footer();
