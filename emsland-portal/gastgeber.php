<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/inquiry.php';

$in = [];
$errors = [];
$sent = handle_inquiry('gastgeber', $in, $errors);

page_header('Gastgeber werden', 'Vermieten Sie Ihre Ferienwohnung im Emsland an Urlauber und Fachkräfte auf Zeit.');
?>
<section class="section" style="padding-top:40px">
  <div class="wrap two-col">
    <div>
      <p class="eyebrow">Für Vermieterinnen und Vermieter</p>
      <h1>Mehr Gäste, auch unter der Woche</h1>
      <p class="lead">Urlauber kommen am Wochenende und in den Ferien. Ärztinnen, Projektleiter und Monteure brauchen Unterkünfte das ganze Jahr. Wir bringen beide zu Ihnen.</p>
      <ul class="checks">
        <li>Kurz- und Langzeitvermietung in einem Kalender</li>
        <li>Monatspreise und Wochenrabatte frei festlegen</li>
        <li>Abgleich mit Airbnb, Booking &amp; Co. per iCal-Kalender</li>
        <li>Geprüfte Firmenkunden mit Rechnung auf Firma</li>
        <li>Persönliche Betreuung aus der Region</li>
      </ul>
      <h2>So geht's</h2>
      <ol class="steps" style="grid-template-columns:1fr">
        <li><strong>Formular ausfüllen:</strong> Erzählen Sie uns kurz von Ihrer Unterkunft.</li>
        <li><strong>Gespräch:</strong> Wir melden uns, klären Preise und Fotos.</li>
        <li><strong>Online gehen:</strong> Wir legen Ihr Inserat an, Sie erhalten die ersten Anfragen.</li>
      </ol>
    </div>

    <div class="card" style="padding:24px" id="formular">
      <?php if ($sent): ?>
        <h2>Vielen Dank!</h2>
        <p>Wir haben Ihre Nachricht erhalten und melden uns in den nächsten Tagen bei Ihnen.</p>
      <?php else: ?>
        <h2>Unterkunft anmelden</h2>
        <?php if ($errors): ?><div class="alert alert-error" style="width:auto;margin:0 0 12px"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>
        <form method="post" action="<?= e(url('gastgeber.php')) ?>#formular">
          <?= csrf_field() ?><?= spam_fields() ?>
          <div class="field"><label for="g-name">Name</label><input required type="text" id="g-name" name="name" value="<?= e($in['name']) ?>"></div>
          <div class="field-row">
            <div class="field"><label for="g-email">E-Mail</label><input required type="email" id="g-email" name="email" value="<?= e($in['email']) ?>"></div>
            <div class="field"><label for="g-phone">Telefon</label><input type="tel" id="g-phone" name="phone" value="<?= e($in['phone']) ?>"></div>
          </div>
          <div class="field-row">
            <div class="field"><label for="g-place">Ort der Unterkunft</label><input type="text" id="g-place" name="place" value="<?= e($in['place']) ?>"></div>
            <div class="field"><label for="g-persons">Max. Personen</label><input type="number" min="1" id="g-persons" name="persons" value="<?= e($in['persons']) ?>"></div>
          </div>
          <div class="field"><label for="g-msg">Ihre Unterkunft</label><textarea id="g-msg" name="message" placeholder="Art, Größe, Ausstattung, ob auch Langzeit oder Monteure möglich sind …"><?= e($in['message']) ?></textarea></div>
          <label class="check"><input required type="checkbox" name="privacy" value="1"> <span>Ich habe den <a href="<?= e(url('seite.php', ['s' => 'datenschutz'])) ?>" target="_blank">Datenschutzhinweis</a> gelesen.</span></label>
          <button class="button button-accent button-block" type="submit" style="margin-top:10px">Absenden</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php page_footer();
