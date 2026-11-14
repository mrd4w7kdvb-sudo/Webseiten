<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/admin.php';
require_admin();

$inv = invoice_by_id((int)get('id'));
if (!$inv) {
    flash('error', 'Rechnung nicht gefunden.');
    redirect(url('admin/sammelrechnungen.php'));
}
render_collective_invoice($inv, url('admin/sammelrechnungen.php'), 'Zurück zu den Sammelrechnungen');
