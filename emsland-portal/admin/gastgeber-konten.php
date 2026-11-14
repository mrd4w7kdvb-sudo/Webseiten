<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/admin.php';
require_admin();
$pdo = db();

if (is_post()) {
    check_csrf();
    $uid = (int)post('user_id');
    switch (post('action')) {
        case 'create':
            $name = post('name');
            $email = mb_strtolower(post('email'));
            if (mb_strlen($name) < 2 || !valid_email($email)) {
                flash('error', 'Bitte Name und gültige E-Mail angeben.');
            } elseif (email_taken($email)) {
                flash('error', 'Für diese E-Mail-Adresse gibt es bereits einen Zugang.');
            } else {
                $pdo->prepare("INSERT INTO users (role, name, email, phone) VALUES ('gastgeber', ?, ?, ?)")->execute([$name, $email, post('phone')]);
                $uid = (int)$pdo->lastInsertId();
                foreach ((array)($_POST['listings'] ?? []) as $lid) {
                    $pdo->prepare('UPDATE listings SET host_user_id = ?, host_name = ?, host_email = ? WHERE id = ?')->execute([$uid, $name, $email, (int)$lid]);
                }
                $link = send_invite(['id' => $uid, 'name' => $name, 'email' => $email, 'role' => 'gastgeber']);
                flash('ok', "Gastgeber-Zugang angelegt und Einladung an $email gesendet. Falls die E-Mail nicht ankommt, diesen Link weitergeben: $link");
            }
            break;
        case 'invite':
            $st = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'gastgeber'");
            $st->execute([$uid]);
            if ($h = $st->fetch()) {
                $link = send_invite($h);
                flash('ok', "Neue Einladung gesendet. Link (7 Tage gültig): $link");
            }
            break;
        case 'toggle':
            $pdo->prepare("UPDATE users SET active = 1 - active WHERE id = ? AND role = 'gastgeber'")->execute([$uid]);
            flash('ok', 'Zugang geändert.');
            break;
        case 'assign':
            $pdo->prepare('UPDATE listings SET host_user_id = NULL WHERE host_user_id = ?')->execute([$uid]);
            foreach ((array)($_POST['listings'] ?? []) as $lid) {
                $pdo->prepare('UPDATE listings SET host_user_id = ? WHERE id = ?')->execute([$uid, (int)$lid]);
            }
            flash('ok', 'Zuordnung gespeichert.');
            break;
    }
    redirect(url('admin/gastgeber-konten.php'));
}

$hosts = $pdo->query("SELECT * FROM users WHERE role = 'gastgeber' ORDER BY name")->fetchAll();
$listings = $pdo->query('SELECT id, title, host_user_id FROM listings ORDER BY title')->fetchAll();
admin_header('Gastgeber-Zugänge', 'gastgeber');
?>
<div class="admin-top"><h1>Gastgeber-Zugänge</h1></div>
<div class="panel">
  <p>Gastgeber sehen nach dem Anmelden nur ihre eigenen Unterkünfte und deren Buchungen. Sie können Texte, Preise, Fotos und Sperrzeiten pflegen<?= setting('hosts_can_confirm', '1') === '1' ? ' und Anfragen selbst bestätigen oder ablehnen' : '' ?>. Ob Gastgeber Anfragen selbst bestätigen dürfen, stellen Sie unter <a href="<?= e(url('admin/einstellungen.php')) ?>">Einstellungen</a> ein.</p>
</div>

<?php foreach ($hosts as $h): ?>
  <div class="panel">
    <div class="admin-top" style="margin-bottom:10px"><h2 style="margin:0"><?= e($h['name']) ?> <?= $h['active'] ? '<span class="badge badge-ok">aktiv</span>' : '<span class="badge badge-muted">deaktiviert</span>' ?></h2>
      <span class="muted small"><?= e($h['email']) ?><?= $h['phone'] ? ' · ' . e($h['phone']) : '' ?> · <?= $h['password_hash'] === '' ? 'Einladung noch nicht angenommen' : 'Letzte Anmeldung: ' . e($h['last_login'] ?? '–') ?></span></div>
    <form method="post" action="<?= e(url('admin/gastgeber-konten.php')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="assign"><input type="hidden" name="user_id" value="<?= (int)$h['id'] ?>">
      <fieldset><legend>Unterkünfte</legend><div class="amenity-grid">
        <?php foreach ($listings as $l): ?><label class="check"><input type="checkbox" name="listings[]" value="<?= (int)$l['id'] ?>" <?= (int)$l['host_user_id'] === (int)$h['id'] ? 'checked' : '' ?>> <?= e($l['title']) ?></label><?php endforeach; ?>
      </div></fieldset>
      <button class="button button-small" type="submit">Zuordnung speichern</button>
    </form>
    <div class="actions" style="margin-top:10px">
        <form method="post" action="<?= e(url('admin/gastgeber-konten.php')) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="invite"><input type="hidden" name="user_id" value="<?= (int)$h['id'] ?>"><button class="button button-ghost button-small" type="submit">Einladung erneut senden</button></form>
        <form method="post" action="<?= e(url('admin/gastgeber-konten.php')) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="<?= (int)$h['id'] ?>"><button class="button button-ghost button-small" type="submit"><?= $h['active'] ? 'Deaktivieren' : 'Aktivieren' ?></button></form>
    </div>
  </div>
<?php endforeach; ?>

<form class="panel" method="post" action="<?= e(url('admin/gastgeber-konten.php')) ?>">
  <?= csrf_field() ?><input type="hidden" name="action" value="create">
  <h2>Neuen Gastgeber-Zugang anlegen</h2>
  <div class="field-row">
    <div class="field"><label for="h-name">Name</label><input required type="text" id="h-name" name="name"></div>
    <div class="field"><label for="h-email">E-Mail</label><input required type="email" id="h-email" name="email"></div>
    <div class="field"><label for="h-phone">Telefon</label><input type="tel" id="h-phone" name="phone"></div>
  </div>
  <fieldset><legend>Unterkünfte zuordnen</legend><div class="amenity-grid">
    <?php foreach ($listings as $l): ?><label class="check"><input type="checkbox" name="listings[]" value="<?= (int)$l['id'] ?>"> <?= e($l['title']) ?><?= $l['host_user_id'] ? ' <span class="muted small">(hat Gastgeber)</span>' : '' ?></label><?php endforeach; ?>
  </div></fieldset>
  <button class="button" type="submit">Anlegen &amp; Einladung senden</button>
  <p class="hint">Der Gastgeber erhält eine E-Mail mit einem Link, über den er sein Passwort selbst festlegt.</p>
</form>
<?php admin_footer();
