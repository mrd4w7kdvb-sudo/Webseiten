<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/admin.php';

if (is_post()) {
    check_csrf();
    $_SESSION = [];
    session_regenerate_id(true);
    flash('ok', 'Sie wurden abgemeldet.');
}
redirect(url('admin/login.php'));
