<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
$u = require_user();

if (is_post()) {
    check_csrf();
    if (post('action') === 'profil') {
        $name = post('name');
        if (mb_strlen($name) < 2) {
            flash('error', 'Bitte Ihren Namen angeben.');
        } else {
            db()->prepare('UPDATE users SET name = ?, phone = ? WHERE id = ?')->execute([mb_substr($name, 0, 120), mb_substr(post('phone'), 0, 60), $u['id']]);
            flash('ok', 'Profil gespeichert.');
        }
    } elseif (post('action') === 'passwort') {
        $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$u['id']]);
        $new = (string)($_POST['new'] ?? '');
        if (!password_verify((string)($_POST['current'] ?? ''), (string)$st->fetchColumn())) {
            flash('error', 'Das aktuelle Passwort ist falsch.');
        } elseif (mb_strlen($new) < 10 || $new !== (string)($_POST['new2'] ?? '')) {
            flash('error', 'Das neue Passwort muss mindestens 10 Zeichen haben und zweimal gleich eingegeben werden.');
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            session_regenerate_id(true);
            flash('ok', 'Passwort geändert.');
        }
    }
    redirect(url('konto/profil.php'));
}
konto_header('Profil', 'profil');
?>
<section class="account-main"><div class="wrap">
  <div class="admin-top"><h1>Profil</h1></div>
  <form class="panel" method="post" action="<?= e(url('konto/profil.php')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="profil">
    <div class="form-grid">
      <div class="field"><label for="name">Name</label><input required type="text" id="name" name="name" value="<?= e($u['name']) ?>"></div>
      <div class="field"><label for="phone">Telefon</label><input type="tel" id="phone" name="phone" value="<?= e($u['phone']) ?>"></div>
      <div class="field"><label>E-Mail (Login)</label><input type="email" value="<?= e($u['email']) ?>" disabled><p class="hint">Zum Ändern der E-Mail-Adresse wenden Sie sich bitte an uns.</p></div>
    </div>
    <button class="button" type="submit">Speichern</button>
  </form>
  <form class="panel" method="post" action="<?= e(url('konto/profil.php')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="passwort">
    <h2>Passwort ändern</h2>
    <div class="field-row">
      <div class="field"><label for="current">Aktuelles Passwort</label><input required type="password" id="current" name="current" autocomplete="current-password"></div>
      <div class="field"><label for="new">Neues Passwort</label><input required type="password" id="new" name="new" minlength="10" autocomplete="new-password"></div>
      <div class="field"><label for="new2">Wiederholen</label><input required type="password" id="new2" name="new2" minlength="10" autocomplete="new-password"></div>
    </div>
    <button class="button" type="submit">Passwort ändern</button>
  </form>
</div></section>
<?php page_footer();
