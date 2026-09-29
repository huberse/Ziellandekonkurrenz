<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/competition.php';
require_once __DIR__ . '/scoring.php';

/**
 * Regiocup: Sammelwertung mehrerer Wettbewerbe eines Jahres.
 *
 * Je Wettbewerb zaehlt die Platzierung, nicht das Einzelergebnis: der Erste
 * bekommt 100 Punkte, der Zweite 80, der Dritte 60 und so weiter bis Rang 30
 * mit einem Punkt. Ab Rang 31 gibt es keine Punkte mehr. Gezaehlt werden die
 * besten vier Starts; wer fuenf Wettbewerbe fliegt, verliert seinen
 * schlechtesten Rang. Wer vier oder weniger fliegt, verliert nichts.
 *
 * Der Regiomeister ist, wer die meisten Punkte hat - bei den Wettbewerben
 * gewinnt dagegen, wer die wenigsten Strafpunkte hat. Die Summe ist deshalb
 * bewusst "Punkte sammeln" und nicht "Strafpunkte vermeiden".
 */

/**
 * Die FIS-Punkte je Rang: 1 -> 100, 2 -> 80, 3 -> 60, 4 -> 50, 5 -> 45,
 * 6 -> 40, 7 -> 36, 8 -> 32, 9 -> 29, 10 -> 26, 11 -> 24, 12 -> 22,
 * 13 -> 20, 14 -> 18, 15 -> 16, 16 -> 15, 17 -> 14, 18 -> 13, 19 -> 12,
 * 20 -> 11, 21 -> 10, 22 -> 9, 23 -> 8, 24 -> 7, 25 -> 6, 26 -> 5,
 * 27 -> 4, 28 -> 3, 29 -> 2, 30 -> 1. Ab 31 gibt es nichts.
 */
function region_fis_punkte(int $rang): float
{
    static $schema = [
        1 => 100.0, 2 => 80.0, 3 => 60.0, 4 => 50.0, 5 => 45.0, 6 => 40.0,
        7 => 36.0, 8 => 32.0, 9 => 29.0, 10 => 26.0, 11 => 24.0, 12 => 22.0,
        13 => 20.0, 14 => 18.0, 15 => 16.0, 16 => 15.0, 17 => 14.0, 18 => 13.0,
        19 => 12.0, 20 => 11.0, 21 => 10.0, 22 => 9.0, 23 => 8.0, 24 => 7.0,
        25 => 6.0, 26 => 5.0, 27 => 4.0, 28 => 3.0, 29 => 2.0, 30 => 1.0,
    ];
    if ($rang < 1) {
        return 0.0;
    }
    return $schema[$rang] ?? 0.0;
}

/** Wieviel Starts zaehlen? Vier - mehr nicht, auch wenn es fuenf Wettbewerbe gibt. */
function region_anzahl_gewertet(): int
{
    return 4;
}

/**
 * Die Punkte eines Piloten aus seinen Plaetzen in den Wettbewerben.
 *
 * Rueckgabe:
 *   'punkte'      Summe der gewerteten Starts
 *   'gewertet'    Liste der Plaetze, die gezaehlt haben (absteigend nach Punkten)
 *   'gestrichen'  der schlechteste Rang, falls weggestrichen
 *   'gestrichen_punkte' dessen Punktestand
 *   'starts'      wie viele Wettbewerbe ueberhaupt
 *   'einzeln'     Rang => Punkte je Wettbewerb
 */
function region_piloten_punkte(array $plaetze): array
{
    // Jeder Start zaehlt einzeln - auch wenn sich zwei Plaetze wiederholen.
    // Ein Sammelbehaelter je Rang waere falsch: fuenfmal Platz 1 ergaebe dann
    // nur einen Eintrag statt fuenf, und es wuerde nichts gestrichen.
    $paare = [];
    foreach ($plaetze as $rang) {
        $rang = (int) $rang;
        if ($rang < 1) {
            continue;                          // nicht in der Rangliste = nicht dabei
        }
        $paare[] = ['rang' => $rang, 'punkte' => region_fis_punkte($rang)];
    }

    // Nach Punkten sortieren, nicht nach Rang: bei geteilten Plaetzen kann ein
    // besserer Rang weniger Punkte bedeuten. Gleichstand: der bessere Rang zuerst.
    usort($paare, static function (array $a, array $b): int {
        if ($a['punkte'] !== $b['punkte']) {
            return $b['punkte'] <=> $a['punkte'];
        }
        return $a['rang'] <=> $b['rang'];
    });

    $gewertet = array_slice($paare, 0, region_anzahl_gewertet());
    $weg = array_slice($paare, region_anzahl_gewertet());

    $summe = 0.0;
    foreach ($gewertet as $paar) {
        $summe += $paar['punkte'];
    }

    return [
        'punkte'            => round($summe, 2),
        'gewertet'          => $gewertet,
        'gestrichen'        => $weg ? $weg[0]['rang'] : null,
        'gestrichen_punkte' => $weg ? $weg[0]['punkte'] : null,
        'starts'            => count($paare),
        'einzeln'           => array_map('intval', array_column($paare, 'rang')),
    ];
}

/**
 * Alle Wettbewerbe eines Jahres mit Datum, unabhaengig vom Regiocup-Kennzeichen.
 *
 * Das Jahr steht nicht in der Wettbewerbstabelle, sondern in den Einstellungen
 * als competition_date. Ohne Datum faellt der Wettbewerb hier heraus - er
 * laesst sich nicht einem Jahr zuordnen.
 *
 * Rueckgabe: Liste mit 'id', 'name', 'competition_date', 'jahr', 'club_id',
 * 'club_name' und 'region'.
 */
function region_wettbewerbe_des_jahres(int $jahr): array
{
    if (!competition_schema_table_exists(db(), 'competition_settings')) {
        return [];
    }
    $zeilen = db()->query(
        'SELECT c.id, c.name, c.club_id, c.region, cl.name AS club_name,
                cs.svalue AS competition_date
         FROM competitions c
         LEFT JOIN clubs cl ON cl.id = c.club_id
         LEFT JOIN competition_settings cs
                ON cs.competition_id = c.id AND cs.skey = \'competition_date\'
         WHERE cs.svalue IS NOT NULL AND cs.svalue <> \'\'
         ORDER BY cs.svalue DESC, c.id DESC'
    )->fetchAll();

    $auswahl = [];
    foreach ($zeilen as $zeile) {
        $jahrAusDatum = region_jahr_aus_datum((string) $zeile['competition_date']);
        if ($jahrAusDatum === null || $jahrAusDatum !== $jahr) {
            continue;
        }
        $zeile['jahr'] = $jahrAusDatum;
        $zeile['region'] = (int) $zeile['region'] === 1;
        $auswahl[] = $zeile;
    }
    return $auswahl;
}

/**
 * Die Wettbewerbe, die wirklich in die Regiowertung eines Jahres eingehen -
 * also nur die mit dem Kennzeichen. Ohne Jahr wird das juengste genommen, fuer
 * das es ueberhaupt ein Datum gibt.
 */
function region_wettbewerbe(?int $jahr = null): array
{
    if ($jahr === null) {
        $jahre = region_jahre();
        $jahr = $jahre ? (int) $jahre[0] : null;
    }
    if ($jahr === null) {
        return [];
    }
    $auswahl = [];
    foreach (region_wettbewerbe_des_jahres($jahr) as $zeile) {
        if ($zeile['region']) {
            $auswahl[] = $zeile;
        }
    }
    return $auswahl;
}

/** Die Jahre, fuer die es ueberhaupt Wettbewerbe mit Datum gibt. */
function region_jahre(): array
{
    if (!competition_schema_table_exists(db(), 'competition_settings')) {
        return [];
    }
    $jahre = [];
    foreach (db()->query(
        'SELECT DISTINCT svalue FROM competition_settings
         WHERE skey = \'competition_date\' AND svalue <> \'\''
    ) as $zeile) {
        $jahr = region_jahr_aus_datum((string) $zeile['svalue']);
        if ($jahr !== null) {
            $jahre[$jahr] = true;
        }
    }
    $liste = array_keys($jahre);
    rsort($liste);
    return $liste;
}

/**
 * Die Jahreszahl aus einem competition_date.
 *
 * Das Datum kann als 2026-06-19 oder als 19.06.2026 dastehen. Beide Formen
 * liefern dieselbe Zahl. Ein unbrauchbares Datum liefert null, damit der
 * Wettbewerb nicht still in einem falschen Jahr landet.
 */
function region_jahr_aus_datum(string $datum): ?int
{
    $datum = trim($datum);
    if ($datum === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $datum, $t)) {
        return (int) $t[1];
    }
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})/', $datum, $t)) {
        return (int) $t[3];
    }
    if (preg_match('/^(\d{4})$/', $datum, $t)) {
        return (int) $t[1];
    }
    return null;
}

/**
 * Der Schluessel, unter dem derselbe Pilot in verschiedenen Wettbewerben
 * wiedererkannt wird.
 *
 * Piloten sind je Wettbewerb eigene Datensaetze – es gibt keine gemeinsame
 * Pilotennummer über die Wettbewerbe hinweg. Übereinstimmen müssen deshalb
 * Vor- und Nachname. Für die Auswertung reicht das, weil die Regioliste ohnehin
 * nur lesbar ist; zwei Piloten mit demselben Namen fallen in der Liste zusammen.
 */
function region_pilot_schluessel(array $pilot): string
{
    $vorname = trim((string) ($pilot['first_name'] ?? ''));
    $nachname = trim((string) ($pilot['last_name'] ?? ''));
    return mb_strtolower($nachname . "\t" . $vorname, 'UTF-8');
}

/**
 * Die Regiorangliste aus mehreren Wettbewerben.
 *
 * Gelesen wird ausschliesslich – die Wertung der einzelnen Wettbewerbe bleibt
 * unberührt. Je Wettbewerb zaehlt der Rang, den der Pilot dort in der
 * Wettbewerbsrangliste hat; dieser Rang enthaelt schon die geteilten Plaetze, wie
 * sie auf rangliste.php erscheinen.
 *
 * @param int[] $competitionIds  die Wettbewerbe, die fuer den Regiocup zaehlen
 * @return array{
 *   'zeilen': array<int, array>,
 *   'wettbewerbe': array<int, array>,
 *   'piloten': int
 * }
 */
function region_rangliste(array $competitionIds): array
{
    $wettbewerbe = [];
    $plaetze = [];       // Schluessel => [wettbewerbs_id => rang]
    $angaben  = [];      // Schluessel => Name, Verein

    foreach ($competitionIds as $competitionId) {
        $competitionId = (int) $competitionId;
        $competition = find_competition($competitionId);
        if ($competition === null) {
            continue;
        }
        $rangliste = build_ranking(null, null, $competitionId);
        $wettbewerbe[$competitionId] = [
            'id'    => $competitionId,
            'name'  => (string) $competition['name'],
            'short' => competition_kuerzel((string) $competition['name']),
        ];

        foreach ($rangliste['rows'] as $row) {
            // Wer in diesem Wettbewerb gar kein Resultat hat, hat hier nicht
            // teilgenommen und kommt in diesem Start nicht vor.
            if (!$row['has_any'] || !$row['rank']) {
                continue;
            }
            $schluessel = region_pilot_schluessel($row['pilot']);
            if ($schluessel === "\t") {
                continue;                       // Pilot ohne Namen
            }
            $plaetze[$schluessel][$competitionId] = (int) $row['rank'];
            if (!isset($angaben[$schluessel])) {
                $angaben[$schluessel] = [
                    'name'     => full_name($row['pilot']),
                    'club_id'  => $row['pilot']['club_id'] !== null ? (int) $row['pilot']['club_id'] : null,
                    'club'     => (string) ($row['pilot']['club_name'] ?? ''),
                ];
            }
        }
    }

    $zeilen = [];
    foreach ($plaetze as $schluessel => $plaetzeJeWettbewerb) {
        $wertung = region_piloten_punkte(array_values($plaetzeJeWettbewerb));
        $zeilen[] = [
            'schluessel'   => $schluessel,
            'name'         => $angaben[$schluessel]['name'],
            'club_id'      => $angaben[$schluessel]['club_id'],
            'club'         => $angaben[$schluessel]['club'],
            'plaetze'      => $plaetzeJeWettbewerb,
            'punkte'       => $wertung['punkte'],
            'gestrichen'   => $wertung['gestrichen'],
            'gestrichen_punkte' => $wertung['gestrichen_punkte'],
            'starts'       => $wertung['starts'],
            'rang'         => null,
        ];
    }

    // Die meisten Punkte zuerst – bei den Wettbewerben sind es die wenigsten
    // Strafpunkte, hier ist es umgekehrt.
    usort($zeilen, static function (array $a, array $b): int {
        if ($a['punkte'] !== $b['punkte']) {
            return $b['punkte'] <=> $a['punkte'];
        }
        // Gleicher Rang gibt es nur, wenn auch die weiteren Werte uebereinstimmen.
        // Sonst waere die Reihenfolge der Liste zufuell.
        if ($a['gestrichen'] !== $b['gestrichen']) {
            return ($a['gestrichen'] ?? PHP_INT_MAX) <=> ($b['gestrichen'] ?? PHP_INT_MAX);
        }
        if ($a['starts'] !== $b['starts']) {
            return $b['starts'] <=> $a['starts'];
        }
        return strcmp($a['name'], $b['name']);
    });

    $rang = 0;
    $seen = 0;
    $prevKey = null;
    foreach ($zeilen as $i => &$zeile) {
        $seen++;
        $key = $zeile['punkte'] . '|' . ($zeile['gestrichen'] ?? '')
            . '|' . $zeile['starts'] . '|' . $zeile['name'];
        if ($prevKey === null || $key !== $prevKey) {
            $rang = $seen;
            $prevKey = $key;
        }
        $zeile['rang'] = $rang;
    }
    unset($zeile);

    return ['zeilen' => $zeilen, 'wettbewerbe' => $wettbewerbe, 'piloten' => count($zeilen)];
}

/** Ein kurzes Kuerzel fuer die Spaltenueberschrift, z. B. "Erlencup 2027" -> "Erlencup". */
function competition_kuerzel(string $name): string
{
    $name = trim(preg_replace('/\s*\d{4}\s*$/', '', $name) ?? $name);
    if ($name === '') {
        return $name;
    }
    if (mb_strlen($name, 'UTF-8') <= 10) {
        return $name;
    }
    return mb_substr($name, 0, 10, 'UTF-8') . '…';
}

/**
 * Wessen Mitglieder die Regiorangliste sehen duerfen, auch wenn die allgemeine
 * Ergebnisveroeffentlichung abgeschaltet ist.
 *
 * Der Verein steht als Einstellung `region_club_id` – fest im Code waere der
 * Name "RMV Nordwest" eine stille Kopplung, die beim naechsten Verein dann
 * falsch waere.
 */
function region_club_id(): ?int
{
    $wert = setting_num('region_club_id', 0);
    return $wert > 0 ? (int) $wert : null;
}

/** Darf der angemeldete Benutzer die Regiorangliste sehen, auch ohne oeffentliche Ergebnisse? */
function region_darf_sehen(): bool
{
    if (setting_bool('public_results', true)) {
        return true;
    }
    if (is_superadmin()) {
        return true;
    }
    $clubId = region_club_id();
    return $clubId !== null && user_club_id() === $clubId;
}

/** Nur der SuperAdmin waehlt die Wettbewerbe aus und veraendert etwas. */
function region_darf_bearbeiten(): bool
{
    return is_superadmin();
}
