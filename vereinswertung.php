<?php
/**
 * Die Vereinswertung steht jetzt am Ende der Rangliste, zusammen mit den
 * Piloten und unter demselben Filter. Sie hatte einmal eine eigene Seite,
 * weil es eine Navigationsleiste gab, in der sie liegen konnte. Die Leiste
 * ist seit 1.9.16 weg.
 *
 * Diese Datei bleibt, damit alte Links und Lesezeichen nicht ins Leere
 * laufen. Wettbewerb und Filter wandern mit, wer hier aufkommt, landet also
 * genau an der richtigen Stelle.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/scoring.php';
require_once __DIR__ . '/lib/layout.php';

$ziel = 'rangliste.php';
$query = [];
if (($wett = competition_request_param()) !== '') {
    $query['competition'] = $wett;
}
if (($typ = get('typ')) !== '') {
    $query['typ'] = $typ;
}
redirect($ziel . ($query ? '?' . http_build_query($query) : ''));
