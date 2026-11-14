<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

if (current_user()) {
    redirect(url('konto/index.php'));
}
$error = '';
$email = post('email');
if (is_post()) {
    check_csrf();
    $ip = client_ip();
    if (login_blocked($ip)) {
        $error = 'Zu viele Versuche. Bitte in 15 Minuten erneut versuchen.';
    } else {
        $u = user_by_email($email);
        if ($u && $u['active'] && $u['password_hash'] !== '' && password_verify((string)($_POST['password'] ?? ''), $u['password_hash'])) {
            $st = db()->prepare('SELECT status FROM companies WHERE id = ?');
            $st->execute([(int)$u['company_id']]);
            if ($u['role'] === 'firma' && $st->fetchColumn() === 'gesperrt') {
                $error = 'Dieses Firmenkonto ist gesperrt. Bitte wenden Sie sich an uns.';
            } else {
                user_login($u);
                $to = (string)($_SESSION['after_login'] ?? '');
                unset($_SESSION['after_login']);
                // nur interne Pfade zulassen
                redirect(preg_match('#^/[^/\\\\]#', $to) ? $to : url('konto/index.php'));
            }
        } else {
            login_failed($ip);
            $error = 'E-Mail oder Passwort ist falsch.';
        }
    }
}
page_header('Anmelden');
?>
<section class="section" style="padding-top:48px">
  <div class="wrap two-col">
    <div class="card" style="padding:28px">
      <h1 style="font-size:1.8rem">Anmelden</h1>
      <p class="muted">Für Firmenkunden und Gastgeber.</p>
      <?php if ($error): ?><div class="alert alert-error" style="width:auto;margin:0 0 14px"><?= e($error) ?></div><?php endif; ?>
      <form method="post" action="<?= e(url('konto/login.php')) ?>">
        <?= csrf_field() ?>
        <div class="field"><label for="email">E-Mail</label><input required type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="username"></div>
        <div class="field"><label for="password">Passwort</label><input required type="password" id="password" name="password" autocomplete="current-password"></div>
        <button class="button button-block" type="submit">Anmelden</button>
      </form>
      <p class="small" style="margin-top:14px"><a href="<?= e(url('konto/passwort-vergessen.php')) ?>">Passwort vergessen?</a></p>
    </div>
    <div>
      <p class="eyebrow">Noch kein Konto?</p>
      <h2>Firmenkonto anlegen</h2>
      <p>Mit einem Firmenkonto buchen Sie Unterkünfte für Ihre Mitarbeitenden, ordnen Kostenstellen zu und erhalten eine monatliche Sammelrechnung.</p>
      <p><a class="button" href="<?= e(url('konto/registrieren.php')) ?>">Firmenkonto beantragen</a></p>
      <h2 style="margin-top:28px">Gastgeber?</h2>
      <p>Den Zugang für Gastgeber richten wir für Sie ein. <a href="<?= e(url('gastgeber.php')) ?>">Jetzt melden</a>.</p>
    </div>
  </div>
</section>
<?php page_footer();
