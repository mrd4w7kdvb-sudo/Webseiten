<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/admin.php';
$admin = require_admin();

$keys = ['site_name', 'site_claim', 'contact_email', 'contact_phone', 'notify_email', 'company_name', 'company_address', 'tax_id', 'vat_rate', 'payment_info'];
$flags = ['small_business', 'noindex', 'hosts_can_confirm'];

if (is_post()) {
    check_csrf();
    $action = post('action');

    if ($action === 'settings') {
        $errors = [];
        foreach (['contact_email', 'notify_email'] as $k) {
            if (post($k) !== '' && !valid_email(post($k))) $errors[] = 'Ungültige E-Mail-Adresse: ' . post($k);
        }
        if (post('site_name') === '') $errors[] = 'Bitte einen Namen für das Portal angeben.';
        if ($errors) {
            foreach ($errors as $err) flash('error', $err);
        } else {
            foreach ($keys as $k) set_setting($k, post($k));
            foreach ($flags as $k) set_setting($k, post($k) === '1' ? '1' : '0');
            flash('ok', 'Einstellungen gespeichert.');
        }
    }

    if ($action === 'password') {
        $st = db()->prepare('SELECT password_hash FROM admins WHERE id = ?');
        $st->execute([$admin['id']]);
        $new = (string)($_POST['new'] ?? '');
        if (!password_verify((string)($_POST['current'] ?? ''), (string)$st->fetchColumn())) {
            flash('error', 'Das aktuelle Passwort ist falsch.');
        } elseif (mb_strlen($new) < 10) {
            flash('error', 'Das neue Passwort muss mindestens 10 Zeichen haben.');
        } elseif ($new !== (string)($_POST['new2'] ?? '')) {
            flash('error', 'Die neuen Passwörter stimmen nicht überein.');
        } else {
            db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
            session_regenerate_id(true);
            flash('ok', 'Passwort geändert.');
        }
    }

    if ($action === 'add_admin') {
        $name = post('name');
        $email = mb_strtolower(post('email'));
        $pw = (string)($_POST['password'] ?? '');
        if ($name === '' || !valid_email($email) || mb_strlen($pw) < 10) {
            flash('error', 'Bitte Name, gültige E-Mail und ein Passwort mit mindestens 10 Zeichen angeben.');
        } else {
            try {
                db()->prepare('INSERT INTO admins (name, email, password_hash) VALUES (?, ?, ?)')->execute([$name, $email, password_hash($pw, PASSWORD_DEFAULT)]);
                flash('ok', "Zugang für {$name} angelegt. Bitte das Passwort sicher übermitteln.");
            } catch (PDOException) {
                flash('error', 'Diese E-Mail-Adresse hat bereits einen Zugang.');
            }
        }
    }

    if ($action === 'delete_admin') {
        $aid = (int)post('id');
        if ($aid === (int)$admin['id']) {
            flash('error', 'Sie können Ihren eigenen Zugang nicht löschen.');
        } else {
            db()->prepare('DELETE FROM admins WHERE id = ?')->execute([$aid]);
            flash('ok', 'Zugang gelöscht.');
        }
    }
    redirect(url('admin/einstellungen.php'));
}

$admins = db()->query('SELECT id, name, email, last_login FROM admins ORDER BY id')->fetchAll();
admin_header('Einstellungen', 'einstellungen');
?>
<div class="admin-top"><h1>Einstellungen</h1></div>

<form class="panel" method="post" action="<?= e(url('admin/einstellungen.php')) ?>">
  <?= csrf_field() ?><input type="hidden" name="action" value="settings">
  <h2>Portal &amp; Kontakt</h2>
  <div class="form-grid">
    <div class="field"><label for="site_name">Name des Portals</label><input type="text" id="site_name" name="site_name" value="<?= e(setting('site_name')) ?>"></div>
    <div class="field"><label for="site_claim">Untertitel</label><input type="text" id="site_claim" name="site_claim" value="<?= e(setting('site_claim')) ?>"></div>
    <div class="field"><label for="contact_email">Kontakt-E-Mail (öffentlich, Absender)</label><input type="email" id="contact_email" name="contact_email" value="<?= e(setting('contact_email')) ?>"><p class="hint">Sollte eine Adresse Ihrer Domain sein, damit E-Mails ankommen.</p></div>
    <div class="field"><label for="contact_phone">Telefon (öffentlich)</label><input type="tel" id="contact_phone" name="contact_phone" value="<?= e(setting('contact_phone')) ?>"></div>
    <div class="field"><label for="notify_email">Benachrichtigungen an</label><input type="email" id="notify_email" name="notify_email" value="<?= e(setting('notify_email')) ?>"><p class="hint">Hierhin gehen neue Anfragen.</p></div>
  </div>
  <label class="check"><input type="checkbox" name="noindex" value="1" <?= setting('noindex') === '1' ? 'checked' : '' ?>> <span><strong>Vor Suchmaschinen verbergen</strong> (noindex). Zum Start empfohlen; ausschalten, wenn das Portal öffentlich gefunden werden soll.</span></label>

  <label class="check"><input type="checkbox" name="hosts_can_confirm" value="1" <?= setting('hosts_can_confirm', '1') === '1' ? 'checked' : '' ?>> <span><strong>Gastgeber dürfen Anfragen selbst bestätigen oder ablehnen</strong> (sonst nur Sie im Admin-Bereich)</span></label>

  <h2 style="margin-top:22px">Rechnungen</h2>
  <div class="form-grid">
    <div class="field"><label for="company_name">Rechnungssteller (Name/Firma)</label><input type="text" id="company_name" name="company_name" value="<?= e(setting('company_name')) ?>"></div>
    <div class="field"><label for="tax_id">Steuernummer oder USt-IdNr.</label><input type="text" id="tax_id" name="tax_id" value="<?= e(setting('tax_id')) ?>"></div>
    <div class="field"><label for="company_address">Anschrift</label><textarea id="company_address" name="company_address" rows="3"><?= e(setting('company_address')) ?></textarea></div>
    <div class="field"><label for="payment_info">Zahlungshinweis (Bankverbindung)</label><textarea id="payment_info" name="payment_info" rows="3"><?= e(setting('payment_info')) ?></textarea></div>
    <div class="field"><label for="vat_rate">Umsatzsteuersatz Beherbergung (%)</label><input type="text" id="vat_rate" name="vat_rate" value="<?= e(setting('vat_rate')) ?>"></div>
    <div class="field"><label class="check" style="margin-top:28px"><input type="checkbox" name="small_business" value="1" <?= setting('small_business') === '1' ? 'checked' : '' ?>> Kleinunternehmer (§ 19 UStG, keine Umsatzsteuer ausweisen)</label></div>
  </div>
  <p class="hint">Bitte lassen Sie Steuersatz und Rechnungsangaben von Ihrer Steuerberatung prüfen, besonders bei langen Aufenthalten über 6 Monaten.</p>
  <button class="button" type="submit">Einstellungen speichern</button>
</form>

<div class="panel">
  <h2>Admin-Zugänge</h2>
  <div class="table-wrap"><table class="data">
    <tr><th>Name</th><th>E-Mail</th><th>Letzte Anmeldung</th><th></th></tr>
    <?php foreach ($admins as $a): ?>
      <tr><td><?= e($a['name']) ?><?= (int)$a['id'] === (int)$admin['id'] ? ' (Sie)' : '' ?></td><td><?= e($a['email']) ?></td><td><?= e($a['last_login'] ?? '–') ?></td>
        <td><?php if ((int)$a['id'] !== (int)$admin['id']): ?><form method="post" action="<?= e(url('admin/einstellungen.php')) ?>" class="inline-form" data-confirm="Zugang von <?= e($a['name']) ?> löschen?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_admin"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="button button-ghost button-small" type="submit">Löschen</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <details style="margin-top:14px"><summary><strong>Weiteren Zugang anlegen</strong></summary>
    <form method="post" action="<?= e(url('admin/einstellungen.php')) ?>" style="margin-top:12px">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_admin">
      <div class="field-row">
        <div class="field"><label for="a-name">Name</label><input required type="text" id="a-name" name="name"></div>
        <div class="field"><label for="a-email">E-Mail</label><input required type="email" id="a-email" name="email"></div>
        <div class="field"><label for="a-pw">Passwort (mind. 10 Zeichen)</label><input required type="password" id="a-pw" name="password" minlength="10" autocomplete="new-password"></div>
      </div>
      <button class="button" type="submit">Zugang anlegen</button>
    </form>
  </details>
</div>

<form class="panel" method="post" action="<?= e(url('admin/einstellungen.php')) ?>">
  <?= csrf_field() ?><input type="hidden" name="action" value="password">
  <h2>Eigenes Passwort ändern</h2>
  <div class="field-row">
    <div class="field"><label for="current">Aktuelles Passwort</label><input required type="password" id="current" name="current" autocomplete="current-password"></div>
    <div class="field"><label for="new">Neues Passwort</label><input required type="password" id="new" name="new" minlength="10" autocomplete="new-password"></div>
    <div class="field"><label for="new2">Wiederholen</label><input required type="password" id="new2" name="new2" minlength="10" autocomplete="new-password"></div>
  </div>
  <button class="button" type="submit">Passwort ändern</button>
</form>
<?php admin_footer();
