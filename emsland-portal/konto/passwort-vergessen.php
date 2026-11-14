<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

$sent = false;
if (is_post()) {
    check_csrf();
    if (!login_blocked(client_ip())) {
        login_failed(client_ip()); // begrenzt auch hier die Anzahl der Anfragen
        $u = user_by_email(post('email'));
        if ($u && $u['active']) {
            $link = password_link((int)$u['id'], 2);
            send_mail($u['email'], 'Passwort zurücksetzen – ' . setting('site_name'),
                "Guten Tag {$u['name']},\n\nüber diesen Link legen Sie ein neues Passwort fest (2 Stunden gültig):\n$link\n\nFalls Sie das nicht angefordert haben, ignorieren Sie diese E-Mail.\n\n" . setting('site_name'));
        }
    }
    $sent = true; // immer gleiche Antwort, damit nicht erkennbar ist, ob die Adresse existiert
}
page_header('Passwort vergessen');
?>
<section class="section"><div class="wrap narrow">
  <div class="card" style="padding:28px;max-width:480px">
    <h1 style="font-size:1.7rem">Passwort vergessen</h1>
    <?php if ($sent): ?>
      <p>Wenn die Adresse bei uns registriert ist, haben wir Ihnen einen Link zum Zurücksetzen geschickt. Bitte prüfen Sie auch den Spam-Ordner.</p>
      <p><a href="<?= e(url('konto/login.php')) ?>">Zur Anmeldung</a></p>
    <?php else: ?>
      <form method="post" action="<?= e(url('konto/passwort-vergessen.php')) ?>">
        <?= csrf_field() ?>
        <div class="field"><label for="email">E-Mail</label><input required type="email" id="email" name="email" autocomplete="username"></div>
        <button class="button button-block" type="submit">Link anfordern</button>
      </form>
    <?php endif; ?>
  </div>
</div></section>
<?php page_footer();
