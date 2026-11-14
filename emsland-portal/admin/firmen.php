<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/admin.php';
require_admin();
$pdo = db();

$id = (int)get('id');
if (is_post()) {
    check_csrf();
    $cid = (int)post('company_id');
    switch (post('action')) {
        case 'save':
            $status = array_key_exists(post('status'), COMPANY_STATUS) ? post('status') : 'beantragt';
            $st = $pdo->prepare('SELECT status FROM companies WHERE id = ?');
            $st->execute([$cid]);
            $old = $st->fetchColumn();
            $pdo->prepare('UPDATE companies SET name = ?, vat_id = ?, billing_address = ?, billing_email = ?, phone = ?, status = ?, max_price_night = ?, payment_days = ?, admin_note = ? WHERE id = ?')
                ->execute([post('name'), post('vat_id'), post('billing_address'), post('billing_email'), post('phone'), $status,
                    max(0, parse_decimal(post('max_price_night'))), max(0, (int)post('payment_days')), post('admin_note'), $cid]);
            if ($old !== 'freigeschaltet' && $status === 'freigeschaltet' && post('notify') === '1') {
                $owners = $pdo->prepare('SELECT name, email FROM users WHERE company_id = ? AND is_owner = 1 AND active = 1');
                $owners->execute([$cid]);
                foreach ($owners as $o) {
                    send_mail($o['email'], 'Ihr Firmenkonto ist freigeschaltet', "Guten Tag {$o['name']},\n\nIhr Firmenkonto bei " . setting('site_name') . " ist freigeschaltet. Ab sofort rechnen wir Ihre bestätigten Buchungen monatlich per Sammelrechnung ab.\n\nAnmelden: " . absolute_url('konto/login.php') . "\n\nViele Grüße\n" . setting('site_name'));
                }
            }
            // Bestehende Buchungen der Firma mit neuer Rechnungsadresse/Firma abgleichen? Nur künftige Buchungen nutzen die neuen Daten.
            flash('ok', 'Firmenkonto gespeichert.');
            break;
        case 'invite':
            $name = post('name');
            $email = mb_strtolower(post('email'));
            if (mb_strlen($name) < 2 || !valid_email($email) || email_taken($email)) {
                flash('error', 'Bitte Name und eine noch nicht verwendete, gültige E-Mail angeben.');
            } else {
                $owner = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE company_id = ' . $cid . ' AND is_owner = 1')->fetchColumn() === 0 ? 1 : 0;
                $pdo->prepare("INSERT INTO users (role, company_id, is_owner, name, email) VALUES ('firma', ?, ?, ?, ?)")->execute([$cid, $owner, $name, $email]);
                $link = send_invite(['id' => (int)$pdo->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => 'firma']);
                flash('ok', "Einladung an $email gesendet. Falls die E-Mail nicht ankommt, diesen Link weitergeben: $link");
            }
            break;
        case 'toggle_user':
            $pdo->prepare('UPDATE users SET active = 1 - active WHERE id = ? AND company_id = ?')->execute([(int)post('user_id'), $cid]);
            flash('ok', 'Zugang geändert.');
            break;
        case 'create':
            $name = post('name');
            if ($name === '') {
                flash('error', 'Bitte einen Firmennamen angeben.');
                redirect(url('admin/firmen.php'));
            }
            $pdo->prepare("INSERT INTO companies (name, status) VALUES (?, 'freigeschaltet')")->execute([$name]);
            flash('ok', 'Firmenkonto angelegt. Tragen Sie jetzt Rechnungsdaten ein und laden Sie eine Ansprechperson ein.');
            redirect(url('admin/firmen.php', ['id' => $pdo->lastInsertId()]));
    }
    redirect(url('admin/firmen.php', ['id' => $cid ?: null]));
}

if ($id) {
    $st = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) {
        flash('error', 'Firmenkonto nicht gefunden.');
        redirect(url('admin/firmen.php'));
    }
    $users = $pdo->prepare('SELECT * FROM users WHERE company_id = ? ORDER BY is_owner DESC, name');
    $users->execute([$id]);
    $users = $users->fetchAll();
    $bk = $pdo->prepare("SELECT b.*, l.title FROM bookings b JOIN listings l ON l.id = b.listing_id WHERE b.company_id = ? ORDER BY b.date_from DESC LIMIT 30");
    $bk->execute([$id]);
    $bookings = $bk->fetchAll();
    $inv = $pdo->prepare('SELECT * FROM invoices WHERE company_id = ? ORDER BY id DESC');
    $inv->execute([$id]);
    $invoices = $inv->fetchAll();
    admin_header($c['name'], 'firmen');
    ?>
    <p class="small"><a href="<?= e(url('admin/firmen.php')) ?>">← Alle Firmenkonten</a></p>
    <div class="admin-top"><h1><?= e($c['name']) ?></h1>
      <a class="button" href="<?= e(url('admin/sammelrechnungen.php', ['firma' => $c['id']])) ?>">Sammelrechnung erstellen</a></div>
    <form class="panel" method="post" action="<?= e(url('admin/firmen.php')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="company_id" value="<?= (int)$c['id'] ?>">
      <div class="form-grid">
        <div class="field"><label for="name">Firmenname</label><input required type="text" id="name" name="name" value="<?= e($c['name']) ?>"></div>
        <div class="field"><label for="status">Status</label><select id="status" name="status">
          <?php foreach (COMPANY_STATUS as $k => $label): ?><option value="<?= $k ?>" <?= $k === $c['status'] ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select>
          <p class="hint">Freigeschaltet = Buchung auf Rechnung (Sammelrechnung). Gesperrt = keine Anmeldung möglich.</p></div>
        <div class="field"><label for="billing_address">Rechnungsadresse</label><textarea id="billing_address" name="billing_address" rows="3"><?= e($c['billing_address']) ?></textarea></div>
        <div>
          <div class="field"><label for="billing_email">E-Mail für Rechnungen</label><input type="email" id="billing_email" name="billing_email" value="<?= e($c['billing_email']) ?>"></div>
          <div class="field"><label for="vat_id">USt-IdNr.</label><input type="text" id="vat_id" name="vat_id" value="<?= e($c['vat_id']) ?>"></div>
        </div>
        <div class="field"><label for="phone">Telefon</label><input type="tel" id="phone" name="phone" value="<?= e($c['phone']) ?>"></div>
        <div class="field"><label for="payment_days">Zahlungsziel (Tage)</label><input type="number" min="0" id="payment_days" name="payment_days" value="<?= (int)$c['payment_days'] ?>"></div>
        <div class="field"><label for="max_price_night">Preisobergrenze / Nacht (€, 0 = keine)</label><input type="text" id="max_price_night" name="max_price_night" value="<?= e(rtrim(rtrim(number_format((float)$c['max_price_night'], 2, ',', ''), '0'), ',')) ?>"></div>
        <div class="field full"><label for="admin_note">Interne Notiz</label><textarea id="admin_note" name="admin_note" rows="2"><?= e($c['admin_note']) ?></textarea></div>
      </div>
      <label class="check"><input type="checkbox" name="notify" value="1" checked> Bei Freischaltung die Hauptansprechperson per E-Mail informieren</label>
      <button class="button" type="submit">Speichern</button>
      <p class="hint">Registriert am <?= e($c['created_at']) ?>.</p>
    </form>

    <div class="panel">
      <h2>Zugänge</h2>
      <div class="table-wrap"><table class="data">
        <tr><th>Name</th><th>E-Mail</th><th>Letzte Anmeldung</th><th>Status</th><th></th></tr>
        <?php foreach ($users as $us): ?>
          <tr><td><?= e($us['name']) ?><?= $us['is_owner'] ? ' <span class="badge">Hauptansprechperson</span>' : '' ?></td><td><?= e($us['email']) ?></td>
            <td><?= e($us['last_login'] ?? ($us['password_hash'] === '' ? 'Einladung offen' : '–')) ?></td>
            <td><?= $us['active'] ? '<span class="badge badge-ok">aktiv</span>' : '<span class="badge badge-muted">deaktiviert</span>' ?></td>
            <td><form method="post" action="<?= e(url('admin/firmen.php')) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_user"><input type="hidden" name="company_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="user_id" value="<?= (int)$us['id'] ?>"><button class="button button-ghost button-small" type="submit"><?= $us['active'] ? 'Deaktivieren' : 'Aktivieren' ?></button></form></td></tr>
        <?php endforeach; ?>
      </table></div>
      <form method="post" action="<?= e(url('admin/firmen.php')) ?>" style="margin-top:12px">
        <?= csrf_field() ?><input type="hidden" name="action" value="invite"><input type="hidden" name="company_id" value="<?= (int)$c['id'] ?>">
        <div class="field-row" style="align-items:end">
          <div class="field"><label for="i-name">Name</label><input required type="text" id="i-name" name="name"></div>
          <div class="field"><label for="i-email">E-Mail</label><input required type="email" id="i-email" name="email"></div>
          <div class="field"><button class="button" type="submit">Zugang einladen</button></div>
        </div>
      </form>
    </div>

    <div class="panel">
      <h2>Sammelrechnungen</h2>
      <?php if (!$invoices): ?><p class="muted">Noch keine.</p><?php else: ?>
      <div class="table-wrap"><table class="data"><tr><th>Nr.</th><th>Zeitraum</th><th>Fällig</th><th class="num">Betrag</th><th>Status</th></tr>
        <?php foreach ($invoices as $i): ?><tr><td><a href="<?= e(url('admin/sammelrechnung.php', ['id' => $i['id']])) ?>"><?= e($i['invoice_no']) ?></a></td>
          <td><?= date_de($i['period_from']) ?> – <?= date_de(date('Y-m-d', strtotime($i['period_to'] . ' -1 day'))) ?></td><td><?= date_de($i['due_date']) ?></td><td class="num"><?= money((float)$i['total']) ?></td>
          <td><?= $i['status'] === 'bezahlt' ? '<span class="badge badge-ok">Bezahlt</span>' : '<span class="badge badge-warn">Offen</span>' ?></td></tr><?php endforeach; ?>
      </table></div>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h2>Buchungen (letzte 30)</h2>
      <?php if (!$bookings): ?><p class="muted">Noch keine.</p><?php else: ?>
      <div class="table-wrap"><table class="data"><tr><th>Nr.</th><th>Gast</th><th>Unterkunft</th><th>Zeitraum</th><th>Kostenstelle</th><th class="num">Preis</th><th>Status</th></tr>
        <?php foreach ($bookings as $b): ?><tr><td><a href="<?= e(url('admin/buchung.php', ['id' => $b['id']])) ?>"><?= e($b['ref']) ?></a></td><td><?= e($b['name']) ?></td><td><?= e($b['title']) ?></td>
          <td><?= date_de($b['date_from']) ?> – <?= date_de($b['date_to']) ?></td><td><?= e($b['cost_center']) ?></td><td class="num"><?= money((float)$b['price_total']) ?></td><td><?= status_badge($b['status']) ?></td></tr><?php endforeach; ?>
      </table></div>
      <?php endif; ?>
    </div>
    <?php
    admin_footer();
    exit;
}

$filter = get('status');
$sql = "SELECT c.*, (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id) AS users,
    (SELECT COUNT(*) FROM bookings b WHERE b.company_id = c.id AND b.status = 'bestaetigt' AND b.date_to >= date('now','localtime')) AS active_bookings,
    (SELECT COALESCE(SUM(total), 0) FROM invoices i WHERE i.company_id = c.id AND i.status = 'offen') AS open_amount
    FROM companies c" . (array_key_exists($filter, COMPANY_STATUS) ? ' WHERE c.status = ' . db()->quote($filter) : '') . ' ORDER BY (c.status = \'beantragt\') DESC, c.name';
$rows = $pdo->query($sql)->fetchAll();
admin_header('Firmenkonten', 'firmen');
?>
<div class="admin-top"><h1>Firmenkonten</h1></div>
<div class="tabs">
  <a href="<?= e(url('admin/firmen.php')) ?>" class="<?= $filter === '' ? 'active' : '' ?>">Alle</a>
  <?php foreach (COMPANY_STATUS as $k => $label): ?><a href="<?= e(url('admin/firmen.php', ['status' => $k])) ?>" class="<?= $filter === $k ? 'active' : '' ?>"><?= $label ?></a><?php endforeach; ?>
</div>
<div class="panel">
  <?php if (!$rows): ?><p class="muted">Noch keine Firmenkonten. Firmen registrieren sich unter „Anmelden → Firmenkonto beantragen“.</p><?php else: ?>
  <div class="table-wrap"><table class="data">
    <tr><th>Firma</th><th>Status</th><th>Zugänge</th><th>Aktive Buchungen</th><th class="num">Offene Rechnungen</th><th>Seit</th></tr>
    <?php foreach ($rows as $c): ?>
      <tr><td><a href="<?= e(url('admin/firmen.php', ['id' => $c['id']])) ?>"><strong><?= e($c['name']) ?></strong></a></td>
        <td><span class="badge <?= ['beantragt' => 'badge-warn', 'freigeschaltet' => 'badge-ok', 'gesperrt' => 'badge-bad'][$c['status']] ?? '' ?>"><?= e(COMPANY_STATUS[$c['status']] ?? $c['status']) ?></span></td>
        <td><?= (int)$c['users'] ?></td><td><?= (int)$c['active_bookings'] ?></td><td class="num"><?= money((float)$c['open_amount']) ?></td><td><?= date_de(substr($c['created_at'], 0, 10)) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
<form class="panel" method="post" action="<?= e(url('admin/firmen.php')) ?>">
  <?= csrf_field() ?><input type="hidden" name="action" value="create">
  <h2>Firmenkonto selbst anlegen</h2>
  <div class="field-row" style="align-items:end">
    <div class="field"><label for="c-name">Firmenname</label><input required type="text" id="c-name" name="name"></div>
    <div class="field"><button class="button" type="submit">Anlegen</button></div>
  </div>
</form>
<?php admin_footer();
