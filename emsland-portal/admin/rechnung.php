<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/admin.php';
require_admin();

$b = booking_by_id((int)get('id'));
if (!$b || !$b['invoice_no']) {
    flash('error', 'Rechnung nicht gefunden.');
    redirect(url('admin/buchungen.php'));
}
render_booking_invoice($b, url('admin/buchung.php', ['id' => $b['id']]), 'Zurück zur Buchung');
