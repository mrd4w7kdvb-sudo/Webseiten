<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/admin.php';
require_admin();

$pages = db()->query('SELECT * FROM pages ORDER BY slug')->fetchAll();
$slug = get('s', $pages[0]['slug'] ?? '');
$page = null;
foreach ($pages as $p) {
    if ($p['slug'] === $slug) $page = $p;
}

if (is_post() && $page) {
    check_csrf();
    $title = post('title');
    if ($title === '') {
        flash('error', 'Bitte einen Titel angeben.');
    } else {
        db()->prepare('UPDATE pages SET title = ?, body = ? WHERE slug = ?')->execute([$title, post('body'), $page['slug']]);
        flash('ok', 'Seite gespeichert.');
    }
    redirect(url('admin/seiten.php', ['s' => $page['slug']]));
}

admin_header('Seiten & Texte', 'seiten');
?>
<div class="admin-top"><h1>Seiten &amp; Texte</h1></div>
<div class="tabs">
  <?php foreach ($pages as $p): ?><a href="<?= e(url('admin/seiten.php', ['s' => $p['slug']])) ?>" class="<?= $p['slug'] === $slug ? 'active' : '' ?>"><?= e($p['title']) ?></a><?php endforeach; ?>
</div>
<?php if ($page): ?>
<form class="panel" method="post" action="<?= e(url('admin/seiten.php', ['s' => $page['slug']])) ?>">
  <?= csrf_field() ?>
  <div class="field"><label for="title">Titel</label><input type="text" id="title" name="title" value="<?= e($page['title']) ?>"></div>
  <div class="field"><label for="body">Text</label><textarea id="body" name="body" rows="20"><?= e($page['body']) ?></textarea><p class="hint">Leerzeile = neuer Absatz. HTML wird nicht ausgeführt.</p></div>
  <div class="actions"><button class="button" type="submit">Speichern</button><a href="<?= e(url('seite.php', ['s' => $page['slug']])) ?>" target="_blank">Seite ansehen ↗</a></div>
</form>
<?php endif; ?>
<?php admin_footer();
