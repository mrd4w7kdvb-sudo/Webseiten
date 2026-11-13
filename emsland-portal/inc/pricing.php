<?php
declare(strict_types=1);

/**
 * Preisberechnung für einen Aufenthalt.
 * - unter 28 Nächten: Nachtpreis, ab 7 Nächten Wochenrabatt
 * - ab 28 Nächten (wenn "Wohnen auf Zeit" aktiv und Monatspreis gesetzt): Monatspreis anteilig (Monat = 30 Nächte)
 * - Aufpreis je weiterem Gast über der Grundbelegung, Endreinigung einmalig
 *
 * @return array{nights:int, lines:list<array{0:string,1:float}>, total:float, tariff:string}|array{error:string}
 */
function calc_price(array $l, string $from, string $to, int $guests): array
{
    $d1 = valid_date($from);
    $d2 = valid_date($to);
    if (!$d1 || !$d2) {
        return ['error' => 'Bitte An- und Abreisedatum wählen.'];
    }
    $nights = (int)$d1->diff($d2)->format('%r%a');
    if ($nights <= 0) {
        return ['error' => 'Die Abreise muss nach der Anreise liegen.'];
    }
    if ($nights < (int)$l['min_nights']) {
        return ['error' => 'Mindestaufenthalt: ' . (int)$l['min_nights'] . ' Nächte.'];
    }
    if ($guests < 1 || $guests > (int)$l['max_guests']) {
        return ['error' => 'Diese Unterkunft ist für höchstens ' . (int)$l['max_guests'] . ' Personen.'];
    }
    if ($nights >= 28 && !$l['mode_langzeit']) {
        return ['error' => 'Diese Unterkunft ist nur für Aufenthalte unter 28 Nächten buchbar.'];
    }

    $lines = [];
    $monthly = $nights >= 28 && (float)$l['month_price'] > 0;
    if ($monthly) {
        $base = round((float)$l['month_price'] * $nights / 30, 2);
        $lines[] = [sprintf('Monatspreis %s × %s Monate (%d Nächte)', money((float)$l['month_price']), number_format($nights / 30, 2, ',', '.'), $nights), $base];
        $tariff = 'langzeit';
    } else {
        $base = round($nights * (float)$l['price_night'], 2);
        $lines[] = [sprintf('%d %s × %s', $nights, $nights === 1 ? 'Nacht' : 'Nächte', money((float)$l['price_night'])), $base];
        if ($nights >= 7 && (float)$l['weekly_discount'] > 0) {
            $disc = round($base * (float)$l['weekly_discount'] / 100, 2);
            $lines[] = [sprintf('Rabatt ab 7 Nächten (−%s %%)', rtrim(rtrim(number_format((float)$l['weekly_discount'], 1, ',', ''), '0'), ',')), -$disc];
        }
        $tariff = 'nacht';
    }

    $extra = max(0, $guests - (int)$l['base_guests']);
    if ($extra > 0 && (float)$l['extra_guest_fee'] > 0 && !$monthly) {
        $lines[] = [sprintf('Zusätzliche Gäste: %d × %d Nächte × %s', $extra, $nights, money((float)$l['extra_guest_fee'])), round($extra * $nights * (float)$l['extra_guest_fee'], 2)];
    }
    if ((float)$l['cleaning_fee'] > 0) {
        $lines[] = ['Endreinigung', (float)$l['cleaning_fee']];
    }

    $total = round(array_sum(array_column($lines, 1)), 2);
    return ['nights' => $nights, 'lines' => $lines, 'total' => $total, 'tariff' => $tariff];
}

/** Prüft, ob der Zeitraum frei ist (bestätigte Buchungen + Sperrzeiten). Abreisetag = neuer Anreisetag möglich. */
function is_available(int $listingId, string $from, string $to, ?int $excludeBooking = null): bool
{
    $st = db()->prepare("SELECT COUNT(*) FROM bookings WHERE listing_id = ? AND status = 'bestaetigt'
        AND date_from < ? AND date_to > ? AND id != ?");
    $st->execute([$listingId, $to, $from, $excludeBooking ?? 0]);
    if ((int)$st->fetchColumn() > 0) {
        return false;
    }
    $st = db()->prepare('SELECT COUNT(*) FROM blocks WHERE listing_id = ? AND date_from < ? AND date_to > ?');
    $st->execute([$listingId, $to, $from]);
    return (int)$st->fetchColumn() === 0;
}

/** Alle belegten Nächte (Y-m-d => true) im Zeitraum. */
function occupied_nights(int $listingId, string $from, string $to): array
{
    $ranges = [];
    $st = db()->prepare("SELECT date_from, date_to FROM bookings WHERE listing_id = ? AND status = 'bestaetigt' AND date_from < ? AND date_to > ?");
    $st->execute([$listingId, $to, $from]);
    $ranges = array_merge($ranges, $st->fetchAll());
    $st = db()->prepare('SELECT date_from, date_to FROM blocks WHERE listing_id = ? AND date_from < ? AND date_to > ?');
    $st->execute([$listingId, $to, $from]);
    $ranges = array_merge($ranges, $st->fetchAll());

    $nights = [];
    foreach ($ranges as $r) {
        $d = valid_date($r['date_from']);
        $end = valid_date($r['date_to']);
        if (!$d || !$end) continue;
        for ($i = 0; $d < $end && $i < 1000; $i++, $d = $d->modify('+1 day')) {
            $nights[$d->format('Y-m-d')] = true;
        }
    }
    return $nights;
}

/** Monatskalender als HTML (belegte Nächte markiert). */
function render_month(int $year, int $month, array $occupied): string
{
    $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $months = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    $today = date('Y-m-d');
    $html = '<div class="cal-month"><h3 class="cal-title">' . $months[$month - 1] . ' ' . $year . '</h3><table class="cal"><thead><tr>';
    foreach (['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'] as $wd) {
        $html .= '<th scope="col">' . $wd . '</th>';
    }
    $html .= '</tr></thead><tbody><tr>';
    $offset = (int)$first->format('N') - 1;
    $html .= str_repeat('<td></td>', $offset);
    $days = (int)$first->format('t');
    for ($day = 1; $day <= $days; $day++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $cls = [];
        if (isset($occupied[$date])) $cls[] = 'busy';
        if ($date < $today) $cls[] = 'past';
        $label = isset($occupied[$date]) ? 'belegt' : 'frei';
        $html .= '<td class="' . implode(' ', $cls) . '" data-date="' . $date . '"><span title="' . $label . '">' . $day . '</span></td>';
        if (($offset + $day) % 7 === 0 && $day < $days) {
            $html .= '</tr><tr>';
        }
    }
    $rest = (7 - (($offset + $days) % 7)) % 7;
    $html .= str_repeat('<td></td>', $rest) . '</tr></tbody></table></div>';
    return $html;
}
