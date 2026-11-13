<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$st = db()->prepare('SELECT * FROM pages WHERE slug = ?');
$st->execute([get('s')]);
$page = $st->fetch();
if (!$page) {
    not_found();
}
page_header($page['title']);
?>
<section class="section">
  <div class="wrap narrow prose">
    <h1><?= e($page['title']) ?></h1>
    <?= paragraphs($page['body']) ?>
  </div>
</section>
<?php page_footer();
