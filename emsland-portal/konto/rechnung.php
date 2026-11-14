<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
$u = require_user('firma');
header('Cache-Control: no-store');

if (get('buchung') !== '') {
    $b = booking_by_id((int)get('buchung'));
    if (!$b || !user_can_see_booking($u, $b) || !$b['invoice_no']) {
        flash('error', 'Rechnung nicht gefunden.');
        redirect(url('konto/rechnungen.php'));
    }
    render_booking_invoice($b, url('konto/rechnungen.php'), 'Zurück zu den Rechnungen');
    exit;
}
$inv = invoice_by_id((int)get('id'));
if (!$inv || (int)$inv['company_id'] !== (int)$u['company_id']) {
    flash('error', 'Rechnung nicht gefunden.');
    redirect(url('konto/rechnungen.php'));
}
render_collective_invoice($inv, url('konto/rechnungen.php'), 'Zurück zu den Rechnungen');
