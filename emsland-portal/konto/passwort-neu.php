<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

$token = get('t') ?: post('t');
$u = password_token_user($token);
$error = '';
if ($u && is_post()) {
    check_csrf();
    $pw = (string)($_POST['password'] ?? '');
    if (mb_strlen($pw) < 10) {
        $error = 'Das Passwort muss mindestens 10 Zeichen lang sein.';
    } elseif ($pw !== (string)($_POST['password2'] ?? '')) {
        $error = 'Die Passwörter stimmen nicht überein.';
    } else {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
        db()->prepare('DELETE FROM password_tokens WHERE user_id = ?')->execute([$u['id']]);
        user_login($u);
        flash('ok', 'Ihr Passwort ist gespeichert. Willkommen!');
        redirect(url('konto/index.php'));
    }
}
page_header('Passwort festlegen');
?>
<section class="section"><div class="wrap narrow">
  <div class="card" style="padding:28px;max-width:480px">
    <h1 style="font-size:1.7rem">Passwort festlegen</h1>
    <?php if (!$u): ?>
      <p>Dieser Link ist ungültig oder abgelaufen.</p>
      <p><a class="button" href="<?= e(url('konto/passwort-vergessen.php')) ?>">Neuen Link anfordern</a></p>
    <?php else: ?>
      <p class="muted">Konto: <?= e($u['email']) ?></p>
      <?php if ($error): ?><div class="alert alert-error" style="width:auto;margin:0 0 14px"><?= e($error) ?></div><?php endif; ?>
      <form method="post" action="<?= e(url('konto/passwort-neu.php')) ?>">
        <?= csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>">
        <div class="field"><label for="password">Neues Passwort</label><input required type="password" id="password" name="password" minlength="10" autocomplete="new-password"><p class="hint">Mindestens 10 Zeichen.</p></div>
        <div class="field"><label for="password2">Wiederholen</label><input required type="password" id="password2" name="password2" minlength="10" autocomplete="new-password"></div>
        <button class="button button-block" type="submit">Speichern</button>
      </form>
    <?php endif; ?>
  </div>
</div></section>
<?php page_footer();
