<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/admin.php';
require_admin();

if (is_post()) {
    check_csrf();
    $iid = (int)post('id');
    if (post('action') === 'delete') {
        db()->prepare('DELETE FROM inquiries WHERE id = ?')->execute([$iid]);
        flash('ok', 'Anfrage gelöscht.');
    } else {
        $status = post('status') === 'erledigt' ? 'erledigt' : 'offen';
        db()->prepare('UPDATE inquiries SET status = ? WHERE id = ?')->execute([$status, $iid]);
        flash('ok', $status === 'erledigt' ? 'Als erledigt markiert.' : 'Wieder geöffnet.');
    }
    redirect(url('admin/anfragen.php', ['typ' => get('typ'), 'status' => get('status')]));
}

$typ = get('typ');
$status = get('status', 'offen');
$sql = 'SELECT * FROM inquiries WHERE 1=1';
$args = [];
if (in_array($typ, ['firma', 'gastgeber'], true)) { $sql .= ' AND type = ?'; $args[] = $typ; }
if (in_array($status, ['offen', 'erledigt'], true)) { $sql .= ' AND status = ?'; $args[] = $status; }
$st = db()->prepare($sql . ' ORDER BY created_at DESC');
$st->execute($args);
$rows = $st->fetchAll();

admin_header('Firmen & Gastgeber', 'anfragen');
?>
<div class="admin-top"><h1>Anfragen von Firmen &amp; Gastgebern</h1></div>
<div class="tabs">
  <?php foreach (['' => 'Alle', 'firma' => 'Firmen', 'gastgeber' => 'Gastgeber'] as $k => $label): ?>
    <a href="<?= e(url('admin/anfragen.php', ['typ' => $k, 'status' => $status])) ?>" class="<?= $typ === $k ? 'active' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
  <span style="width:16px"></span>
  <?php foreach (['offen' => 'Offen', 'erledigt' => 'Erledigt', 'alle' => 'Alle Status'] as $k => $label): ?>
    <a href="<?= e(url('admin/anfragen.php', ['typ' => $typ, 'status' => $k])) ?>" class="<?= $status === $k ? 'active' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$rows): ?><div class="panel"><p class="muted">Keine Anfragen.</p></div><?php endif; ?>
<?php foreach ($rows as $r): ?>
  <div class="panel">
    <div class="admin-top" style="margin-bottom:10px">
      <h2 style="margin:0"><?= e($r['company'] ?: $r['name']) ?> <span class="badge <?= $r['type'] === 'firma' ? 'badge-business' : '' ?>"><?= $r['type'] === 'firma' ? 'Firma' : 'Gastgeber' ?></span>
        <span class="badge <?= $r['status'] === 'offen' ? 'badge-warn' : 'badge-ok' ?>"><?= e(ucfirst($r['status'])) ?></span></h2>
      <span class="muted small"><?= e($r['created_at']) ?></span>
    </div>
    <dl class="kv">
      <dt>Kontakt</dt><dd><?= e($r['name']) ?> · <a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a><?= $r['phone'] ? ' · ' . e($r['phone']) : '' ?></dd>
      <?php if ($r['persons']): ?><dt>Personen</dt><dd><?= (int)$r['persons'] ?></dd><?php endif; ?>
      <?php if ($r['date_from']): ?><dt>Ab</dt><dd><?= date_de($r['date_from']) ?></dd><?php endif; ?>
      <?php if ($r['duration']): ?><dt>Dauer</dt><dd><?= e($r['duration']) ?></dd><?php endif; ?>
      <?php if ($r['place']): ?><dt>Ort</dt><dd><?= e($r['place']) ?></dd><?php endif; ?>
      <?php if ($r['message']): ?><dt>Nachricht</dt><dd><?= nl2br(e($r['message'])) ?></dd><?php endif; ?>
    </dl>
    <div class="actions" style="margin-top:12px">
      <form method="post" action="<?= e(url('admin/anfragen.php', ['typ' => $typ, 'status' => $status])) ?>" class="inline-form"><?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="<?= $r['status'] === 'offen' ? 'erledigt' : 'offen' ?>">
        <button class="button button-small" type="submit"><?= $r['status'] === 'offen' ? 'Als erledigt markieren' : 'Wieder öffnen' ?></button></form>
      <form method="post" action="<?= e(url('admin/anfragen.php', ['typ' => $typ, 'status' => $status])) ?>" class="inline-form" data-confirm="Anfrage löschen?"><?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="delete">
        <button class="button button-ghost button-small" type="submit">Löschen</button></form>
    </div>
  </div>
<?php endforeach; ?>
<?php admin_footer();
