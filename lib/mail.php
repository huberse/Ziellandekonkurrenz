<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Absenderadresse des Wettbewerbs im aktuellen Kontext. Leer, wenn der Verein
 * unter Einstellungen noch keine eingetragen hat; dann wird nichts versendet.
 */
function competition_sender_email(): string
{
    $email = trim((string) setting('registration_sender_email', ''));
    return is_valid_email($email) ? $email : '';
}

/** Anzeigename des Absenders; ohne eigene Angabe der Name des Wettbewerbs. */
function competition_sender_name(): string
{
    $name = trim((string) setting('registration_sender_name', ''));
    if ($name !== '') {
        return text_limit($name, 120);
    }
    $competition = text_limit(trim((string) setting('competition_name', '')), 120);
    return $competition !== '' ? $competition : 'Wettkampfleitung';
}

/** Betreff- und Kopfzeilen-Text für UTF-8-taugliche Mailprogramme. */
function mail_header_value(string $value): string
{
    $value = trim(str_replace(["\r", "\n"], ' ', $value));
    if ($value === '') {
        return '';
    }
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }
    if (preg_match('/^[\x20-\x7E]*$/', $value)) {
        return $value;
    }
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

/**
 * Verschickt eine einzelne Textmail und meldet zurück, ob der Server den
 * Versand angenommen hat. Adressen und Kopfzeilen werden vorher geprüft, damit
 * sich über die Eingaben keine zusätzlichen Kopfzeilen einschleusen lassen.
 *
 * @param string[] $cc  weitere Empfänger; nicht ausgelieferte und doppelte
 *                      Adressen fallen weg, der Empfänger selbst wird nie
 *                      ein zweites Mal zugestellt.
 */
function send_mail(string $to, string $subject, string $body, string $fromEmail, string $fromName = '', array $cc = []): bool
{
    if (!function_exists('mail') || !is_valid_email($to) || !is_valid_email($fromEmail)) {
        return false;
    }
    $recipient = trim($to);
    $from = mail_header_value($fromName);
    $from = $from !== '' ? $from . ' <' . trim($fromEmail) . '>' : trim($fromEmail);
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        'Content-Transfer-Encoding: 8bit',
        'From: ' . $from,
    ];

    // CC-Adresse case-insensitiv vergleichen: Mailadressen sind so nicht case-frei.
    $seen = [mb_strtolower($recipient) => true];
    $copies = [];
    foreach ($cc as $copy) {
        $copy = trim((string) $copy);
        $key = mb_strtolower($copy);
        if ($key === '' || isset($seen[$key]) || !is_valid_email($copy)) {
            continue;
        }
        $seen[$key] = true;
        $copies[] = $copy;
    }
    if ($copies) {
        $headers[] = 'Cc: ' . implode(', ', $copies);
    }

    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = str_replace("\n", "\r\n", $body);

    return @mail($recipient, mail_header_value($subject), $body, implode("\r\n", $headers));
}

/** Anzeigewert aus einer Anmeldung: Freitext, sonst der verknüpfte Datensatz. */
function registration_contact_value(array $registration, ?int $id, string $table, string $textFallback = ''): string
{
    $text = trim($textFallback);
    if ($text !== '') {
        return $text;
    }
    if ($id === null || $id <= 0 || !preg_match('/^[a-z_]+$/', $table)) {
        return '';
    }
    $st = db()->prepare("SELECT name FROM `$table` WHERE id = ?");
    $st->execute([$id]);
    return trim((string) ($st->fetchColumn() ?: ''));
}

/**
 * Anmeldebestätigung für eine frisch gespeicherte Anmeldung. Wird erst nach
 * dem Commit aufgerufen, damit der Versand den belegten Datenbankeintrag
 * voraussetzt. Die Adresse des Piloten wird dabei nicht gespeichert.
 */
function send_registration_confirmation(string $to, array $registration, array $competition): bool
{
    $from = competition_sender_email();
    if ($from === '') {
        return false;
    }

    $competitionName = trim((string) ($competition['name'] ?? ''));
    $when = [];
    $date = trim((string) setting('competition_date', ''));
    if ($date !== '' && strtotime($date) !== false) {
        $when[] = date('d.m.Y', (int) strtotime($date));
    }
    $place = trim((string) setting('competition_place', ''));
    if ($place !== '') {
        $when[] = $place;
    }

    $details = ['Wettbewerb' => $competitionName];
    if ($when) {
        $details['Wann und wo'] = implode(', ', $when);
    }
    $details['Verein'] = registration_contact_value(
        $registration,
        isset($registration['club_id']) ? (int) $registration['club_id'] : null,
        'clubs',
        (string) ($registration['club'] ?? '')
    );
    $details['Modelltyp'] = registration_contact_value(
        $registration,
        isset($registration['model_type_id']) ? (int) $registration['model_type_id'] : null,
        'model_types'
    );
    $details['Modell'] = trim((string) ($registration['model_name'] ?? ''));
    $details['Bemerkung'] = trim((string) ($registration['notes'] ?? ''));

    $lines = [];
    $firstName = trim((string) ($registration['first_name'] ?? ''));
    $lines[] = $firstName !== '' ? 'Hallo ' . $firstName . ',' : 'Hallo,';
    $lines[] = '';
    $lines[] = 'deine Anmeldung ist bei der Wettkampfleitung eingegangen. Sie wird geprüft;'
        . ' die Startnummer teilt sie dir rechtzeitig mit.';
    $lines[] = '';
    $lines[] = 'Deine Angaben:';
    $lines[] = '';
    foreach ($details as $label => $value) {
        if ($value === '') {
            continue;
        }
        $lines[] = '  ' . $label . ': ' . $value;
    }
    $lines[] = '';
    $url = site_url('teilnehmer.php?competition=' . (int) ($competition['id'] ?? 0));
    if ($url !== '') {
        $lines[] = 'In der Teilnehmerliste siehst du, wer aufgenommen wurde:';
        $lines[] = '  ' . $url;
        $lines[] = '';
    }
    $lines[] = 'Bis bald';
    $lines[] = competition_sender_name();
    // Der Absender erhält dieselbe Mail in CC. Ein persönlicher Anhang oder eine
    // Antwortadresse wäre hier irreführend; die Zeile weist die Kopie aus.
    $lines[] = '';
    $lines[] = 'Kopie an die Wettkampfleitung.';

    $body = implode("\n", array_map(static function (string $line): string {
        return wordwrap($line, 74, "\n", false);
    }, $lines));

    $subject = 'Anmeldebestätigung: ' . ($competitionName !== '' ? $competitionName : 'Segelflug');

    // Die Absenderadresse kommt in CC: so landet jede Anmeldung auch im Postfach
    // der Wettkampfleitung, ohne dass eine Adresse gespeichert werden muss.
    return send_mail($to, $subject, $body, $from, competition_sender_name(), [$from]);
}
