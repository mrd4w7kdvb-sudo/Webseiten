<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

if (is_post()) {
    check_csrf();
    unset($_SESSION['user_id'], $_SESSION['user_seen']);
    session_regenerate_id(true);
    flash('ok', 'Sie wurden abgemeldet.');
}
redirect(url());
