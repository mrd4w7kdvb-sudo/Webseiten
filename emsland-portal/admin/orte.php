<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/admin.php';
require_admin();

if (is_post()) {
    check_csrf();
    if (post('action') === 'delete') {
        $st = db()->prepare('SELECT COUNT(*) FROM listings WHERE place_id = ?');
        $st->execute([(int)post('id')]);
        if ((int)$st->fetchColumn() > 0) {
            flash('error', 'Dieser Ort wird von Unterkünften verwendet und kann nicht gelöscht werden.');
        } else {
            db()->prepare('DELETE FROM places WHERE id = ?')->execute([(int)post('id')]);
            flash('ok', 'Ort gelöscht.');
        }
    } else {
        $name = post('name');
        $lat = parse_decimal(post('lat'));
        $lng = parse_decimal(post('lng'));
        if ($name === '' || $lat < 47 || $lat > 55 || $lng < 5 || $lng > 15) {
            flash('error', 'Bitte Namen und gültige Koordinaten in Deutschland angeben (z. B. 52.69 / 7.29).');
        } else {
            try {
                db()->prepare('INSERT INTO places (name, lat, lng) VALUES (?, ?, ?)')->execute([$name, $lat, $lng]);
                flash('ok', "Ort „{$name}“ hinzugefügt.");
            } catch (PDOException) {
                flash('error', 'Diesen Ort gibt es schon.');
            }
        }
    }
    redirect(url('admin/orte.php'));
}

$rows = db()->query('SELECT p.*, (SELECT COUNT(*) FROM listings l WHERE l.place_id = p.id) AS n FROM places p ORDER BY name')->fetchAll();
admin_header('Orte', 'orte');
?>
<div class="admin-top"><h1>Orte</h1></div>
<div class="panel">
  <p>Orte erscheinen in der Suche (Ort und Arbeitsort). Die Koordinaten dienen zur Berechnung der Entfernung per Luftlinie. Koordinaten finden Sie z. B. auf openstreetmap.org (Rechtsklick → „Adresse anzeigen“).</p>
  <form method="post" action="<?= e(url('admin/orte.php')) ?>">
    <?= csrf_field() ?>
    <div class="field-row" style="align-items:end">
      <div class="field"><label for="name">Name</label><input required type="text" id="name" name="name" placeholder="z. B. Rhede (Ems)"></div>
      <div class="field"><label for="lat">Breitengrad</label><input required type="text" id="lat" name="lat" placeholder="53.06"></div>
      <div class="field"><label for="lng">Längengrad</label><input required type="text" id="lng" name="lng" placeholder="7.27"></div>
      <div class="field"><button class="button" type="submit">Hinzufügen</button></div>
    </div>
  </form>
</div>
<div class="panel"><div class="table-wrap"><table class="data">
  <tr><th>Ort</th><th>Koordinaten</th><th>Unterkünfte</th><th></th></tr>
  <?php foreach ($rows as $p): ?>
    <tr><td><?= e($p['name']) ?></td><td><?= e(number_format((float)$p['lat'], 4, '.', '') . ', ' . number_format((float)$p['lng'], 4, '.', '')) ?></td><td><?= (int)$p['n'] ?></td>
      <td><?php if (!(int)$p['n']): ?><form method="post" action="<?= e(url('admin/orte.php')) ?>" class="inline-form" data-confirm="Ort löschen?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="button button-ghost button-small" type="submit">Löschen</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
</table></div></div>
<?php admin_footer();
