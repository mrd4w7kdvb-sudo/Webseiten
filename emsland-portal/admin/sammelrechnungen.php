<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/admin.php';
require_admin();
$pdo = db();

$month = preg_match('/^\d{4}-\d{2}$/', get('monat')) ? get('monat') : date('Y-m', strtotime('first day of last month'));
$from = $month . '-01';
$to = date('Y-m-d', strtotime($from . ' +1 month'));
$companyId = (int)get('firma');

if (is_post()) {
    check_csrf();
    $action = post('action');
    if ($action === 'create' || $action === 'create_all') {
        $m = preg_match('/^\d{4}-\d{2}$/', post('monat')) ? post('monat') : $month;
        $f = $m . '-01';
        $t = date('Y-m-d', strtotime($f . ' +1 month'));
        $ids = $action === 'create_all'
            ? $pdo->query("SELECT id FROM companies WHERE status = 'freigeschaltet'")->fetchAll(PDO::FETCH_COLUMN)
            : [(int)post('company_id')];
        $made = 0;
        foreach ($ids as $cid) {
            if (create_collective_invoice((int)$cid, $f, $t)) $made++;
        }
        flash($made ? 'ok' : 'info', $made ? "$made Sammelrechnung(en) erstellt." : 'Für diesen Zeitraum gibt es nichts abzurechnen.');
        redirect(url('admin/sammelrechnungen.php', ['monat' => $m]));
    }
    $inv = invoice_by_id((int)post('invoice_id'));
    if ($inv) {
        if ($action === 'paid') {
            $paid = $inv['status'] !== 'bezahlt';
            $pdo->prepare('UPDATE invoices SET status = ?, paid_at = ? WHERE id = ?')->execute([$paid ? 'bezahlt' : 'offen', $paid ? date('Y-m-d') : null, $inv['id']]);
            flash('ok', 'Rechnung ' . $inv['invoice_no'] . ($paid ? ' als bezahlt markiert.' : ' wieder offen.'));
        } elseif ($action === 'mail') {
            $to = valid_email($inv['billing_email']) ? $inv['billing_email'] : '';
            $ok = $to && send_mail($to, 'Sammelrechnung ' . $inv['invoice_no'] . ' – ' . setting('site_name'),
                "Guten Tag,\n\nIhre Sammelrechnung {$inv['invoice_no']} über " . money((float)$inv['total']) . ' für den Zeitraum ' . date_de($inv['period_from']) . ' – ' . date_de(date('Y-m-d', strtotime($inv['period_to'] . ' -1 day')))
                . " steht in Ihrem Firmenkonto bereit:\n" . absolute_url('konto/rechnung.php', ['id' => $inv['id']]) . "\n\nZahlbar bis " . date_de($inv['due_date']) . ".\n\nViele Grüße\n" . setting('site_name'));
            flash($ok ? 'ok' : 'error', $ok ? "Hinweis an $to gesendet." : 'E-Mail konnte nicht gesendet werden (Rechnungs-E-Mail der Firma prüfen).');
        } elseif ($action === 'delete') {
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$inv['id']]);
            flash('ok', 'Rechnung ' . $inv['invoice_no'] . ' gelöscht. Die Aufenthalte können neu abgerechnet werden.');
        }
    }
    redirect(url('admin/sammelrechnungen.php', ['monat' => $month]));
}

$companies = $pdo->query("SELECT id, name, status FROM companies ORDER BY name")->fetchAll();
$preview = [];
foreach ($companies as $c) {
    if ($companyId && (int)$c['id'] !== $companyId) continue;
    $items = collective_candidates((int)$c['id'], $from, $to);
    if ($items) $preview[] = [$c, $items];
}
$status = get('status');
$list = $pdo->query('SELECT i.*, c.name AS company_name FROM invoices i JOIN companies c ON c.id = i.company_id'
    . (in_array($status, ['offen', 'bezahlt'], true) ? ' WHERE i.status = ' . $pdo->quote($status) : '') . ' ORDER BY i.id DESC LIMIT 200')->fetchAll();
$months = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
$monthLabel = $months[(int)substr($month, 5, 2) - 1] . ' ' . substr($month, 0, 4);

admin_header('Sammelrechnungen', 'sammelrechnungen');
?>
<div class="admin-top"><h1>Sammelrechnungen</h1></div>
<form class="panel" method="get" action="<?= e(url('admin/sammelrechnungen.php')) ?>">
  <p>Bestätigte Aufenthalte von Firmenkonten werden monatlich abgerechnet. Aufenthalte über mehrere Monate erscheinen anteilig nach Nächten; die letzte Teilrechnung gleicht Rundungen aus. Buchungen mit Einzelrechnung sind ausgenommen.</p>
  <div class="field-row" style="align-items:end">
    <div class="field"><label for="monat">Abrechnungsmonat</label><input type="month" id="monat" name="monat" value="<?= e($month) ?>"></div>
    <div class="field"><label for="firma">Firma</label><select id="firma" name="firma"><option value="">Alle</option>
      <?php foreach ($companies as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $companyId ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><button class="button" type="submit">Vorschau</button></div>
  </div>
</form>

<div class="panel">
  <h2>Abzurechnen für <?= e($monthLabel) ?></h2>
  <?php if (!$preview): ?><p class="muted">Keine offenen Aufenthalte in diesem Monat.</p><?php endif; ?>
  <?php foreach ($preview as [$c, $items]): ?>
    <h3><?= e($c['name']) ?> <?= $c['status'] !== 'freigeschaltet' ? '<span class="badge badge-warn">' . e(COMPANY_STATUS[$c['status']] ?? $c['status']) . '</span>' : '' ?></h3>
    <div class="table-wrap"><table class="data">
      <tr><th>Position</th><th>Zeitraum</th><th>Nächte</th><th class="num">Betrag</th></tr>
      <?php foreach ($items as $it): ?><tr><td><?= e($it['description']) ?></td><td><?= date_de($it['period_from']) ?> – <?= date_de($it['period_to']) ?></td><td><?= (int)$it['nights'] ?></td><td class="num"><?= money($it['amount']) ?></td></tr><?php endforeach; ?>
      <tr><td colspan="3"><strong>Summe</strong></td><td class="num"><strong><?= money(array_sum(array_column($items, 'amount'))) ?></strong></td></tr>
    </table></div>
    <form method="post" action="<?= e(url('admin/sammelrechnungen.php', ['monat' => $month])) ?>" style="margin:10px 0 18px">
      <?= csrf_field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="company_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="monat" value="<?= e($month) ?>">
      <button class="button button-small" type="submit">Sammelrechnung für <?= e($c['name']) ?> erstellen</button>
    </form>
  <?php endforeach; ?>
  <?php if (count($preview) > 1): ?>
    <form method="post" action="<?= e(url('admin/sammelrechnungen.php', ['monat' => $month])) ?>" data-confirm="Für alle freigeschalteten Firmen Sammelrechnungen für <?= e($monthLabel) ?> erstellen?">
      <?= csrf_field() ?><input type="hidden" name="action" value="create_all"><input type="hidden" name="monat" value="<?= e($month) ?>">
      <button class="button" type="submit">Alle freigeschalteten Firmen abrechnen</button>
    </form>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Erstellte Sammelrechnungen</h2>
  <div class="tabs">
    <?php foreach (['' => 'Alle', 'offen' => 'Offen', 'bezahlt' => 'Bezahlt'] as $k => $label): ?><a href="<?= e(url('admin/sammelrechnungen.php', ['monat' => $month, 'status' => $k])) ?>" class="<?= $status === $k ? 'active' : '' ?>"><?= $label ?></a><?php endforeach; ?>
  </div>
  <?php if (!$list): ?><p class="muted">Noch keine Sammelrechnungen.</p><?php else: ?>
  <div class="table-wrap"><table class="data">
    <tr><th>Nr.</th><th>Firma</th><th>Zeitraum</th><th>Fällig</th><th class="num">Betrag</th><th>Status</th><th></th></tr>
    <?php foreach ($list as $i): $overdue = $i['status'] === 'offen' && $i['due_date'] < date('Y-m-d'); ?>
      <tr>
        <td><a href="<?= e(url('admin/sammelrechnung.php', ['id' => $i['id']])) ?>" target="_blank"><?= e($i['invoice_no']) ?></a></td>
        <td><a href="<?= e(url('admin/firmen.php', ['id' => $i['company_id']])) ?>"><?= e($i['company_name']) ?></a></td>
        <td><?= date_de($i['period_from']) ?> – <?= date_de(date('Y-m-d', strtotime($i['period_to'] . ' -1 day'))) ?></td>
        <td><?= date_de($i['due_date']) ?></td>
        <td class="num"><?= money((float)$i['total']) ?></td>
        <td><?= $i['status'] === 'bezahlt' ? '<span class="badge badge-ok">Bezahlt ' . date_de($i['paid_at']) . '</span>' : ($overdue ? '<span class="badge badge-bad">Überfällig</span>' : '<span class="badge badge-warn">Offen</span>') ?></td>
        <td class="actions">
          <form method="post" action="<?= e(url('admin/sammelrechnungen.php', ['monat' => $month])) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="paid"><input type="hidden" name="invoice_id" value="<?= (int)$i['id'] ?>"><button class="button button-ghost button-small" type="submit"><?= $i['status'] === 'bezahlt' ? 'Offen' : 'Bezahlt' ?></button></form>
          <form method="post" action="<?= e(url('admin/sammelrechnungen.php', ['monat' => $month])) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="mail"><input type="hidden" name="invoice_id" value="<?= (int)$i['id'] ?>"><button class="button button-ghost button-small" type="submit">Per E-Mail ankündigen</button></form>
          <?php if ($i['status'] === 'offen'): ?><form method="post" action="<?= e(url('admin/sammelrechnungen.php', ['monat' => $month])) ?>" class="inline-form" data-confirm="Rechnung <?= e($i['invoice_no']) ?> löschen? Nur tun, wenn sie noch nicht verschickt wurde – sonst eine Gutschrift/Storno-Rechnung erstellen."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="invoice_id" value="<?= (int)$i['id'] ?>"><button class="button button-ghost button-small" type="submit">Löschen</button></form><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
<?php admin_footer();
