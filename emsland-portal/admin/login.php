<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/admin.php';

$setupMode = admin_count() === 0;
$codeFile = DATA_DIR . '/einrichtungscode.txt';
if ($setupMode && !is_file($codeFile)) {
    file_put_contents($codeFile, random_ref(10) . "\n");
    @chmod($codeFile, 0640);
}
if (current_admin()) {
    redirect(url('admin/index.php'));
}

$error = '';
$in = ['name' => post('name'), 'email' => post('email')];

if (is_post()) {
    check_csrf();
    $ip = client_ip();
    if (login_blocked($ip)) {
        $error = 'Zu viele Versuche. Bitte in 15 Minuten erneut versuchen.';
    } elseif ($setupMode) {
        $code = is_file($codeFile) ? trim((string)file_get_contents($codeFile)) : '';
        $pw = (string)($_POST['password'] ?? '');
        if ($code === '' || !hash_equals($code, strtoupper(post('code')))) {
            login_failed($ip);
            $error = 'Der Einrichtungscode stimmt nicht.';
        } elseif (mb_strlen($in['name']) < 2 || !valid_email($in['email'])) {
            $error = 'Bitte Name und gültige E-Mail-Adresse angeben.';
        } elseif (mb_strlen($pw) < 10) {
            $error = 'Das Passwort muss mindestens 10 Zeichen lang sein.';
        } elseif ($pw !== (string)($_POST['password2'] ?? '')) {
            $error = 'Die Passwörter stimmen nicht überein.';
        } else {
            db()->prepare('INSERT INTO admins (name, email, password_hash) VALUES (?, ?, ?)')
                ->execute([$in['name'], mb_strtolower($in['email']), password_hash($pw, PASSWORD_DEFAULT)]);
            @unlink($codeFile);
            if (setting('notify_email') === '') {
                set_setting('notify_email', mb_strtolower($in['email']));
            }
            admin_login(['id' => (int)db()->lastInsertId()]);
            flash('ok', 'Willkommen! Ihr Admin-Zugang ist eingerichtet.');
            redirect(url('admin/index.php'));
        }
    } else {
        $st = db()->prepare('SELECT * FROM admins WHERE email = ?');
        $st->execute([mb_strtolower($in['email'])]);
        $admin = $st->fetch();
        $pw = (string)($_POST['password'] ?? '');
        if ($admin && password_verify($pw, $admin['password_hash'])) {
            if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $admin['id']]);
            }
            admin_login($admin);
            redirect(url('admin/index.php'));
        }
        login_failed($ip);
        $error = 'E-Mail oder Passwort ist falsch.';
    }
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $setupMode ? 'Einrichtung' : 'Anmelden' ?> – <?= e(setting('site_name')) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body class="admin-body">
<div class="login-wrap">
  <div class="card login-card">
    <p class="brand" style="margin-bottom:18px"><span class="brand-mark" aria-hidden="true"></span><?= e(setting('site_name')) ?></p>
    <?php if ($setupMode): ?>
      <h1 style="font-size:1.5rem">Admin-Zugang einrichten</h1>
      <p class="muted small">Einmalig: Legen Sie Ihr Konto an. Den Einrichtungscode finden Sie auf dem Server in der Datei <code>data/einrichtungscode.txt</code> (per FTP oder Dateimanager öffnen).</p>
    <?php else: ?>
      <h1 style="font-size:1.5rem">Anmelden</h1>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error" style="width:auto;margin:0 0 14px"><?= e($error) ?></div><?php endif; ?>
    <?= flashes() ?>
    <form method="post" action="<?= e(url('admin/login.php')) ?>">
      <?= csrf_field() ?>
      <?php if ($setupMode): ?>
        <div class="field"><label for="code">Einrichtungscode</label><input required type="text" id="code" name="code" autocomplete="off" autocapitalize="characters"></div>
        <div class="field"><label for="name">Ihr Name</label><input required type="text" id="name" name="name" value="<?= e($in['name']) ?>" autocomplete="name"></div>
      <?php endif; ?>
      <div class="field"><label for="email">E-Mail</label><input required type="email" id="email" name="email" value="<?= e($in['email']) ?>" autocomplete="username"></div>
      <div class="field"><label for="password">Passwort</label><input required type="password" id="password" name="password" autocomplete="<?= $setupMode ? 'new-password' : 'current-password' ?>" <?= $setupMode ? 'minlength="10"' : '' ?>></div>
      <?php if ($setupMode): ?>
        <div class="field"><label for="password2">Passwort wiederholen</label><input required type="password" id="password2" name="password2" autocomplete="new-password" minlength="10"><p class="hint">Mindestens 10 Zeichen.</p></div>
      <?php endif; ?>
      <button class="button button-block" type="submit"><?= $setupMode ? 'Zugang anlegen' : 'Anmelden' ?></button>
    </form>
    <p class="small" style="margin-top:16px"><a href="<?= e(url()) ?>">← Zur Webseite</a></p>
  </div>
</div>
</body>
</html>
