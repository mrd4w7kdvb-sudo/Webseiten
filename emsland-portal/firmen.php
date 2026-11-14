<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/inquiry.php';

$in = [];
$errors = [];
$sent = handle_inquiry('firma', $in, $errors);

page_header('Unterkünfte für Firmen, Kliniken und Projekte', 'Unterkünfte im Emsland für Mitarbeitende auf Zeit: Sammelrechnung, Kontingente, persönliche Suche.');
?>
<section class="section" style="padding-top:40px">
  <div class="wrap two-col">
    <div>
      <p class="eyebrow">Für Firmen, Kliniken und Praxen</p>
      <h1>Unterkunft für Ihre Fachkräfte auf Zeit</h1>
      <p class="lead">Sie holen Vertretungsärzte, Projektleiter oder Montageteams ins Emsland? Wir finden die passende Unterkunft und rechnen direkt mit Ihnen ab.</p>
      <ul class="checks">
        <li><strong>Rechnung auf Firma</strong> mit USt-IdNr., Kostenstelle und Bestellnummer</li>
        <li><strong>Monatspreise</strong> ab 28 Nächten, inklusive Nebenkosten, WLAN und Zwischenreinigung</li>
        <li><strong>Wochenpendler</strong>: Unterkünfte nur von Montag bis Freitag</li>
        <li><strong>Teams</strong>: Wohnungen mit Einzelbetten und Stellplatz für Transporter</li>
        <li><strong>Kontingente</strong>: feste Apartments für wechselnde Mitarbeitende, z. B. für Kliniken</li>
        <li><strong>Firmenkonto</strong>: mehrere Kolleginnen und Kollegen, Kostenstellen, Preisobergrenze und monatliche Sammelrechnung</li>
        <li><strong>Persönliche Suche</strong>: Schicken Sie uns Ihren Bedarf, wir melden uns mit passenden Angeboten</li>
      </ul>
      <p><a class="button" href="<?= e(url('konto/registrieren.php')) ?>">Firmenkonto beantragen</a> <a class="button button-ghost" href="<?= e(url('suche.php', ['modus' => 'business'])) ?>">Selbst suchen</a></p>
    </div>

    <div class="card" style="padding:24px" id="formular">
      <?php if ($sent): ?>
        <h2>Vielen Dank!</h2>
        <p>Ihre Anfrage ist eingegangen. Wir melden uns innerhalb eines Werktags mit passenden Unterkünften.</p>
      <?php else: ?>
        <h2>Persönliche Suche anfragen</h2>
        <?php if ($errors): ?><div class="alert alert-error" style="width:auto;margin:0 0 12px"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>
        <form method="post" action="<?= e(url('firmen.php')) ?>#formular">
          <?= csrf_field() ?><?= spam_fields() ?>
          <div class="field"><label for="i-company">Firma / Klinik / Praxis</label><input required type="text" id="i-company" name="company" value="<?= e($in['company']) ?>"></div>
          <div class="field-row">
            <div class="field"><label for="i-name">Ansprechpartner/in</label><input required type="text" id="i-name" name="name" value="<?= e($in['name']) ?>"></div>
            <div class="field"><label for="i-phone">Telefon</label><input type="tel" id="i-phone" name="phone" value="<?= e($in['phone']) ?>"></div>
          </div>
          <div class="field"><label for="i-email">E-Mail</label><input required type="email" id="i-email" name="email" value="<?= e($in['email']) ?>"></div>
          <div class="field-row">
            <div class="field"><label for="i-persons">Personen</label><input type="number" min="1" id="i-persons" name="persons" value="<?= e($in['persons']) ?>"></div>
            <div class="field"><label for="i-date">Ab</label><input type="date" id="i-date" name="date_from" value="<?= e($in['date_from']) ?>"></div>
          </div>
          <div class="field-row">
            <div class="field"><label for="i-duration">Dauer</label><input type="text" id="i-duration" name="duration" placeholder="z. B. 3 Monate" value="<?= e($in['duration']) ?>"></div>
            <div class="field"><label for="i-place">Einsatzort</label><input type="text" id="i-place" name="place" placeholder="z. B. Lingen" value="<?= e($in['place']) ?>"></div>
          </div>
          <div class="field"><label for="i-msg">Was ist wichtig?</label><textarea id="i-msg" name="message" placeholder="z. B. Einzelbetten, Stellplatz für 2 Transporter, nur Mo–Fr"><?= e($in['message']) ?></textarea></div>
          <label class="check"><input required type="checkbox" name="privacy" value="1"> <span>Ich habe den <a href="<?= e(url('seite.php', ['s' => 'datenschutz'])) ?>" target="_blank">Datenschutzhinweis</a> gelesen.</span></label>
          <button class="button button-accent button-block" type="submit" style="margin-top:10px">Anfrage senden</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php page_footer();
