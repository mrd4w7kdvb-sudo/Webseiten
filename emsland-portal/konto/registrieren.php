<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

if (current_user()) {
    redirect(url('konto/index.php'));
}
$f = ['company' => '', 'vat_id' => '', 'billing_address' => '', 'billing_email' => '', 'name' => '', 'email' => '', 'phone' => ''];
$errors = [];
if (is_post()) {
    check_csrf();
    foreach (array_keys($f) as $k) {
        $f[$k] = mb_substr(post($k), 0, $k === 'billing_address' ? 500 : 190);
    }
    $pw = (string)($_POST['password'] ?? '');
    if (looks_like_spam()) $errors[] = 'Bitte versuchen Sie es erneut.';
    if ($f['company'] === '') $errors[] = 'Bitte den Namen Ihrer Firma, Klinik oder Praxis angeben.';
    if (mb_strlen($f['billing_address']) < 8) $errors[] = 'Bitte die vollständige Rechnungsadresse angeben.';
    if ($f['billing_email'] !== '' && !valid_email($f['billing_email'])) $errors[] = 'Die E-Mail für Rechnungen ist ungültig.';
    if (mb_strlen($f['name']) < 2) $errors[] = 'Bitte Ihren Namen angeben.';
    if (!valid_email($f['email'])) $errors[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
    elseif (email_taken($f['email'])) $errors[] = 'Für diese E-Mail-Adresse gibt es bereits ein Konto. Bitte melden Sie sich an.';
    if (mb_strlen($pw) < 10) $errors[] = 'Das Passwort muss mindestens 10 Zeichen lang sein.';
    if (post('privacy') !== '1') $errors[] = 'Bitte bestätigen Sie den Datenschutzhinweis.';

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO companies (name, vat_id, billing_address, billing_email, phone) VALUES (?, ?, ?, ?, ?)')
            ->execute([$f['company'], $f['vat_id'], $f['billing_address'], $f['billing_email'] ?: mb_strtolower($f['email']), $f['phone']]);
        $cid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO users (role, company_id, is_owner, name, email, phone, password_hash) VALUES ('firma', ?, 1, ?, ?, ?, ?)")
            ->execute([$cid, $f['name'], mb_strtolower($f['email']), $f['phone'], password_hash($pw, PASSWORD_DEFAULT)]);
        $uid = (int)$pdo->lastInsertId();
        $pdo->commit();
        admin_notify('Neues Firmenkonto: ' . $f['company'], "Firma: {$f['company']}\nAnsprechpartner: {$f['name']} ({$f['email']})\nUSt-IdNr.: {$f['vat_id']}\n\nBitte im Admin-Bereich unter „Firmenkonten“ prüfen und freischalten.");
        user_login(['id' => $uid]);
        flash('ok', 'Ihr Firmenkonto ist angelegt. Sie können sofort Anfragen stellen; Buchung auf Rechnung ist nach unserer Prüfung möglich.');
        redirect(url('konto/index.php'));
    }
}
page_header('Firmenkonto beantragen');
?>
<section class="section" style="padding-top:40px">
  <div class="wrap two-col">
    <div>
      <p class="eyebrow">Für Firmen, Kliniken und Praxen</p>
      <h1>Firmenkonto beantragen</h1>
      <ul class="checks">
        <li>Buchen für Mitarbeitende, auch für mehrere Personen gleichzeitig</li>
        <li>Wochenpendler-Serien: jede Woche Montag bis Freitag</li>
        <li>Kostenstellen und Bestellnummern je Buchung</li>
        <li>Monatliche Sammelrechnung mit Zahlungsziel, Langzeitaufenthalte anteilig je Monat</li>
        <li>Mehrere Kolleginnen und Kollegen mit eigenem Zugang</li>
        <li>Preisobergrenze pro Nacht nach Ihrer Reiserichtlinie</li>
      </ul>
      <p class="muted">Nach dem Anlegen prüfen wir Ihre Angaben, in der Regel innerhalb eines Werktags. Bis dahin können Sie bereits Anfragen stellen.</p>
    </div>
    <div class="card" style="padding:24px">
      <?php if ($errors): ?><div class="alert alert-error" style="width:auto;margin:0 0 12px"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>
      <form method="post" action="<?= e(url('konto/registrieren.php')) ?>">
        <?= csrf_field() ?><?= spam_fields() ?>
        <fieldset><legend>Firma</legend>
          <div class="field"><label for="company">Firma / Klinik / Praxis</label><input required type="text" id="company" name="company" value="<?= e($f['company']) ?>" autocomplete="organization"></div>
          <div class="field"><label for="billing_address">Rechnungsadresse</label><textarea required id="billing_address" name="billing_address" rows="3" style="min-height:80px"><?= e($f['billing_address']) ?></textarea></div>
          <div class="field-row">
            <div class="field"><label for="vat_id">USt-IdNr. (optional)</label><input type="text" id="vat_id" name="vat_id" value="<?= e($f['vat_id']) ?>"></div>
            <div class="field"><label for="billing_email">E-Mail für Rechnungen</label><input type="email" id="billing_email" name="billing_email" value="<?= e($f['billing_email']) ?>" placeholder="z. B. buchhaltung@…"></div>
          </div>
        </fieldset>
        <fieldset><legend>Ihr Zugang</legend>
          <div class="field-row">
            <div class="field"><label for="name">Ihr Name</label><input required type="text" id="name" name="name" value="<?= e($f['name']) ?>" autocomplete="name"></div>
            <div class="field"><label for="phone">Telefon</label><input type="tel" id="phone" name="phone" value="<?= e($f['phone']) ?>" autocomplete="tel"></div>
          </div>
          <div class="field"><label for="email">E-Mail (Login)</label><input required type="email" id="email" name="email" value="<?= e($f['email']) ?>" autocomplete="username"></div>
          <div class="field"><label for="password">Passwort</label><input required type="password" id="password" name="password" minlength="10" autocomplete="new-password"><p class="hint">Mindestens 10 Zeichen.</p></div>
        </fieldset>
        <label class="check"><input required type="checkbox" name="privacy" value="1"> <span>Ich habe den <a href="<?= e(url('seite.php', ['s' => 'datenschutz'])) ?>" target="_blank">Datenschutzhinweis</a> und die <a href="<?= e(url('seite.php', ['s' => 'agb'])) ?>" target="_blank">Buchungsbedingungen</a> gelesen.</span></label>
        <button class="button button-accent button-block" type="submit" style="margin-top:10px">Firmenkonto anlegen</button>
      </form>
    </div>
  </div>
</section>
<?php page_footer();
