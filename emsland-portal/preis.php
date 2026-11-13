<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$l = listing_by_slug(get('u'));
if (!$l || !$l['active']) {
    http_response_code(404);
    echo json_encode(['error' => 'Unterkunft nicht gefunden.']);
    exit;
}
$von = get('von');
$bis = get('bis');
$q = calc_price($l, $von, $bis, (int)get('gaeste'));
if (!isset($q['error']) && !is_available((int)$l['id'], $von, $bis)) {
    $q = ['error' => 'In diesem Zeitraum ist die Unterkunft schon belegt.'];
}
if (isset($q['error'])) {
    echo json_encode(['error' => $q['error']], JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode([
    'nights' => $q['nights'],
    'lines'  => array_map(fn($line) => ['text' => $line[0], 'amount' => money($line[1])], $q['lines']),
    'total'  => money($q['total']),
], JSON_UNESCAPED_UNICODE);
