<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
$u = require_user('firma');
$cid = (int)$u['company_id'];
$owner = (bool)$u['is_owner'];
$pdo = db();

if (is_post()) {
    check_csrf();
    if (!$owner) {
        flash('error', 'Nur die Hauptansprechperson der Firma kann hier Änderungen vornehmen.');
        redirect(url('konto/team.php'));
    }
    switch (post('action')) {
        case 'firma':
            $addr = mb_substr(post('billing_address'), 0, 500);
            $mail = post('billing_email');
            if (mb_strlen($addr) < 8 || ($mail !== '' && !valid_email($mail))) {
                flash('error', 'Bitte Rechnungsadresse und eine gültige Rechnungs-E-Mail angeben.');
                break;
            }
            $pdo->prepare('UPDATE companies SET billing_address = ?, billing_email = ?, vat_id = ?, phone = ?, max_price_night = ? WHERE id = ?')
                ->execute([$addr, $mail, mb_substr(post('vat_id'), 0, 40), mb_substr(post('phone'), 0, 60), max(0, parse_decimal(post('max_price_night'))), $cid]);
            flash('ok', 'Firmendaten gespeichert.');
            break;
        case 'invite':
            $name = post('name');
            $email = mb_strtolower(post('email'));
            if (mb_strlen($name) < 2 || !valid_email($email)) {
                flash('error', 'Bitte Name und gültige E-Mail-Adresse angeben.');
            } elseif (email_taken($email)) {
                flash('error', 'Für diese E-Mail-Adresse gibt es bereits einen Zugang.');
            } else {
                $pdo->prepare("INSERT INTO users (role, company_id, name, email) VALUES ('firma', ?, ?, ?)")->execute([$cid, mb_substr($name, 0, 120), $email]);
                send_invite(['id' => (int)$pdo->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => 'firma']);
                flash('ok', "Einladung an $email gesendet. Der Link ist 7 Tage gültig.");
            }
            break;
        case 'toggle':
            $id = (int)post('id');
            if ($id === (int)$u['id']) break;
            $pdo->prepare('UPDATE users SET active = 1 - active WHERE id = ? AND company_id = ?')->execute([$id, $cid]);
            flash('ok', 'Zugang geändert.');
            break;
        case 'cc_add':
            $name = mb_substr(post('cc'), 0, 80);
            if ($name !== '') {
                $pdo->prepare('INSERT INTO cost_centers (company_id, name) VALUES (?, ?)')->execute([$cid, $name]);
                flash('ok', 'Kostenstelle hinzugefügt.');
            }
            break;
        case 'cc_delete':
            $pdo->prepare('DELETE FROM cost_centers WHERE id = ? AND company_id = ?')->execute([(int)post('id'), $cid]);
            flash('ok', 'Kostenstelle entfernt.');
            break;
    }
    redirect(url('konto/team.php'));
}

$st = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
$st->execute([$cid]);
$c = $st->fetch();
$st = $pdo->prepare('SELECT * FROM users WHERE company_id = ? ORDER BY is_owner DESC, name');
$st->execute([$cid]);
$team = $st->fetchAll();
$ccs = company_cost_centers($cid);
$dis = $owner ? '' : 'disabled';

konto_header('Team & Kostenstellen', 'team');
?>
<section class="account-main"><div class="wrap">
  <div class="admin-top"><h1><?= e($c['name']) ?></h1><span class="badge <?= $c['status'] === 'freigeschaltet' ? 'badge-ok' : 'badge-warn' ?>"><?= e(COMPANY_STATUS[$c['status']] ?? $c['status']) ?></span></div>
  <?php if (!$owner): ?><div class="alert alert-info" style="width:auto">Änderungen kann nur die Hauptansprechperson Ihrer Firma vornehmen.</div><?php endif; ?>

  <form class="panel" method="post" action="<?= e(url('konto/team.php')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="firma">
    <h2>Firmendaten &amp; Reiserichtlinie</h2>
    <div class="form-grid">
      <div class="field"><label for="billing_address">Rechnungsadresse</label><textarea id="billing_address" name="billing_address" rows="3" <?= $dis ?>><?= e($c['billing_address']) ?></textarea></div>
      <div>
        <div class="field"><label for="billing_email">E-Mail für Rechnungen</label><input type="email" id="billing_email" name="billing_email" value="<?= e($c['billing_email']) ?>" <?= $dis ?>></div>
        <div class="field"><label for="vat_id">USt-IdNr.</label><input type="text" id="vat_id" name="vat_id" value="<?= e($c['vat_id']) ?>" <?= $dis ?>></div>
      </div>
      <div class="field"><label for="phone">Telefon</label><input type="tel" id="phone" name="phone" value="<?= e($c['phone']) ?>" <?= $dis ?>></div>
      <div class="field"><label for="max_price_night">Preisobergrenze pro Nacht (€, 0 = keine)</label><input type="text" inputmode="decimal" id="max_price_night" name="max_price_night" value="<?= e(rtrim(rtrim(number_format((float)$c['max_price_night'], 2, ',', ''), '0'), ',')) ?>" <?= $dis ?>>
        <p class="hint">Unterkünfte über der Grenze werden in der Suche markiert; Buchungen darüber erhalten einen Hinweis.</p></div>
    </div>
    <?php if ($owner): ?><button class="button" type="submit">Speichern</button><?php endif; ?>
  </form>

  <div class="panel">
    <h2>Team</h2>
    <div class="table-wrap"><table class="data">
      <tr><th>Name</th><th>E-Mail</th><th>Letzte Anmeldung</th><th>Status</th><th></th></tr>
      <?php foreach ($team as $t): ?>
        <tr><td><?= e($t['name']) ?><?= $t['is_owner'] ? ' <span class="badge">Hauptansprechperson</span>' : '' ?></td><td><?= e($t['email']) ?></td>
          <td><?= e($t['last_login'] ?? ($t['password_hash'] === '' ? 'Einladung offen' : '–')) ?></td>
          <td><?= $t['active'] ? '<span class="badge badge-ok">aktiv</span>' : '<span class="badge badge-muted">deaktiviert</span>' ?></td>
          <td><?php if ($owner && (int)$t['id'] !== (int)$u['id']): ?>
            <form method="post" action="<?= e(url('konto/team.php')) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <button class="button button-ghost button-small" type="submit"><?= $t['active'] ? 'Deaktivieren' : 'Aktivieren' ?></button></form><?php endif; ?></td></tr>
      <?php endforeach; ?>
    </table></div>
    <?php if ($owner): ?>
    <form method="post" action="<?= e(url('konto/team.php')) ?>" style="margin-top:14px">
      <?= csrf_field() ?><input type="hidden" name="action" value="invite">
      <div class="field-row" style="align-items:end">
        <div class="field"><label for="i-name">Name</label><input required type="text" id="i-name" name="name"></div>
        <div class="field"><label for="i-email">E-Mail</label><input required type="email" id="i-email" name="email"></div>
        <div class="field"><button class="button" type="submit">Kollegin/Kollegen einladen</button></div>
      </div>
    </form>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>Kostenstellen &amp; Projekte</h2>
    <p class="muted small">Stehen bei der Buchung zur Auswahl und erscheinen auf der Rechnung.</p>
    <?php if (!$ccs): ?><p class="muted">Noch keine Kostenstellen.</p><?php endif; ?>
    <ul class="checks">
      <?php foreach ($ccs as $cc): ?>
        <li><?= e($cc['name']) ?>
          <?php if ($owner): ?><form method="post" action="<?= e(url('konto/team.php')) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="cc_delete"><input type="hidden" name="id" value="<?= (int)$cc['id'] ?>"> <button class="button button-ghost button-small" type="submit" style="padding:0 10px">entfernen</button></form><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($owner): ?>
    <form method="post" action="<?= e(url('konto/team.php')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="cc_add">
      <div class="field-row" style="align-items:end">
        <div class="field"><label for="cc">Neue Kostenstelle / Projekt</label><input required type="text" id="cc" name="cc" placeholder="z. B. Projekt Netzausbau Lingen"></div>
        <div class="field"><button class="button" type="submit">Hinzufügen</button></div>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div></section>
<?php page_footer();
