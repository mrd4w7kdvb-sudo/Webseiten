<?php
declare(strict_types=1);

/**
 * Verarbeitet und zeigt das Kontaktformular für Firmen (type=firma) und Gastgeber (type=gastgeber).
 * Gibt true zurück, wenn gerade erfolgreich gesendet wurde.
 */
function handle_inquiry(string $type, array &$in, array &$errors): bool
{
    $in = ['name' => '', 'company' => '', 'email' => '', 'phone' => '', 'persons' => '', 'date_from' => '', 'duration' => '', 'place' => '', 'message' => ''];
    if (!is_post()) {
        return false;
    }
    check_csrf();
    foreach (array_keys($in) as $k) {
        $in[$k] = mb_substr(post($k), 0, $k === 'message' ? 3000 : 200);
    }
    if (looks_like_spam()) $errors[] = 'Ihre Nachricht konnte nicht gesendet werden.';
    if (mb_strlen($in['name']) < 2) $errors[] = 'Bitte Ihren Namen angeben.';
    if (!valid_email($in['email'])) $errors[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
    if ($type === 'firma' && $in['company'] === '') $errors[] = 'Bitte den Namen Ihrer Firma, Klinik oder Praxis angeben.';
    if ($in['date_from'] !== '' && !valid_date($in['date_from'])) $in['date_from'] = '';
    if (post('privacy') !== '1') $errors[] = 'Bitte bestätigen Sie den Datenschutzhinweis.';
    if ($errors) {
        return false;
    }
    db()->prepare('INSERT INTO inquiries (type, name, company, email, phone, persons, date_from, duration, place, message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$type, $in['name'], $in['company'], $in['email'], $in['phone'], (int)$in['persons'], $in['date_from'], $in['duration'], $in['place'], $in['message']]);
    $label = $type === 'firma' ? 'Firmenanfrage' : 'Gastgeber-Anfrage';
    admin_notify("Neue $label", "$label von {$in['name']}" . ($in['company'] ? " ({$in['company']})" : '') . "\nE-Mail: {$in['email']}\nTelefon: {$in['phone']}\n\n{$in['message']}");
    return true;
}
