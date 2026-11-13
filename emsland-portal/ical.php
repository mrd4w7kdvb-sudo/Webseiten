<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

/* Kalender-Export (iCal) einer Unterkunft für Airbnb, Booking & Co. Aufruf: ical.php?u=<slug>&t=<token> */
$l = listing_by_slug(get('u'));
if (!$l || !hash_equals($l['ical_token'], get('t'))) {
    http_response_code(404);
    exit('Nicht gefunden');
}

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="' . $l['slug'] . '.ics"');

$host = preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
$lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//EmsQuartier//Belegung//DE', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH'];
$stamp = gmdate('Ymd\THis\Z');
$from = date('Y-m-d', strtotime('-30 days'));

$st = db()->prepare("SELECT id, date_from, date_to FROM bookings WHERE listing_id = ? AND status = 'bestaetigt' AND date_to >= ?");
$st->execute([$l['id'], $from]);
foreach ($st as $b) {
    $lines = array_merge($lines, ['BEGIN:VEVENT', "UID:booking-{$b['id']}@$host", "DTSTAMP:$stamp",
        'DTSTART;VALUE=DATE:' . str_replace('-', '', $b['date_from']), 'DTEND;VALUE=DATE:' . str_replace('-', '', $b['date_to']),
        'SUMMARY:Belegt', 'END:VEVENT']);
}
// Nur manuelle Sperren exportieren (importierte stammen ja aus dem anderen Kalender)
$st = db()->prepare("SELECT id, date_from, date_to FROM blocks WHERE listing_id = ? AND source = 'manuell' AND date_to >= ?");
$st->execute([$l['id'], $from]);
foreach ($st as $b) {
    $lines = array_merge($lines, ['BEGIN:VEVENT', "UID:block-{$b['id']}@$host", "DTSTAMP:$stamp",
        'DTSTART;VALUE=DATE:' . str_replace('-', '', $b['date_from']), 'DTEND;VALUE=DATE:' . str_replace('-', '', $b['date_to']),
        'SUMMARY:Nicht verfügbar', 'END:VEVENT']);
}
$lines[] = 'END:VCALENDAR';
echo implode("\r\n", $lines) . "\r\n";
