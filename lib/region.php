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
 * Die Punkte fuer einen Rang aus der eingestellten Punkteliste.
 *
 * Ohne gespeicherte Liste gilt die FIS-Vorgabe: 1 -> 100, 2 -> 80, 3 -> 60,
 * 4 -> 50, 5 -> 45, 6 -> 40, 7 -> 36, 8 -> 32, 9 -> 29, 10 -> 26, 11 -> 24,
 * 12 -> 22, 13 -> 20, 14 -> 18, 15 -> 16, 16 -> 15, 17 -> 14, 18 -> 13,
 * 19 -> 12, 20 -> 11, 21 -> 10, 22 -> 9, 23 -> 8, 24 -> 7, 25 -> 6,
 * 26 -> 5, 27 -> 4, 28 -> 3, 29 -> 2, 30 -> 1. Ab 31 gibt es nichts.
 */
function region_fis_punkte(int $rang): float
{
    if ($rang < 1) {
        return 0.0;
    }
    $schema = region_fis_schema();
    return $schema[$rang] ?? 0.0;
}

/**
 * Die eingestellte Punkteliste: Rang => Punkte.
 *
 * Seit 1.9.23 stellbar, sonst fest die FIS-Vorgabe. Der SuperAdmin aendert sie
 * unter admin/regiocup.php. Ohne gespeicherte Liste gilt weiter die FIS-Vorgabe -
 * wer nichts einstellt, bekommt nichts zu sehen, das sich von vorher unterscheidet.
 */
function region_fis_schema(): array
{
    // Im Ganzen, nicht als statische Variable: nach dem Speichern muss die
    // neue Liste in derselben Anfrage gelten, sonst zeigt die Meldung noch
    // die alten Punkte.
    if (isset($GLOBALS['region_fis_schema_cache'])) {
        return $GLOBALS['region_fis_schema_cache'];
    }
    $cache = region_fis_vorgabe();
    try {
        $st = db()->prepare('SELECT svalue FROM settings WHERE skey = ?');
        $st->execute(['region_fis_punkte']);
        $roh = $st->fetchColumn();
    } catch (PDOException $e) {
        if (!settings_table_missing($e)) {
            throw $e;
        }
        return $cache;
    }
    if (!is_string($roh) || trim($roh) === '') {
        return $cache;
    }
    $daten = json_decode($roh, true);
    if (!is_array($daten)) {
        return $cache;
    }
    $gelesen = region_fis_pruefen($daten);
    // Eine halb gelesene Liste waere schlimmer als keine: die Punkte waeren
    // dann eine Mischung aus der Vorgabe und dem Gespeicherten. Deshalb nur
    // uebernehmen, was vollstaendig und in sich stimmig ist.
    if ($gelesen === []) {
        $gelesen = $cache;
    }
    $GLOBALS['region_fis_schema_cache'] = $gelesen;
    return $gelesen;
}

/** Die FIS-Vorgabe, wie sie vor der Einstellbarkeit fest verdrahtet war. */
function region_fis_vorgabe(): array
{
    return [
        1 => 100.0, 2 => 80.0, 3 => 60.0, 4 => 50.0, 5 => 45.0, 6 => 40.0,
        7 => 36.0, 8 => 32.0, 9 => 29.0, 10 => 26.0, 11 => 24.0, 12 => 22.0,
        13 => 20.0, 14 => 18.0, 15 => 16.0, 16 => 15.0, 17 => 14.0, 18 => 13.0,
        19 => 12.0, 20 => 11.0, 21 => 10.0, 22 => 9.0, 23 => 8.0, 24 => 7.0,
        25 => 6.0, 26 => 5.0, 27 => 4.0, 28 => 3.0, 29 => 2.0, 30 => 1.0,
    ];
}

/**
 * Die uebermittelte Liste in Zahlen uebersetzen.
 *
 * Ein Punktwert kann als "40.5", als "40,5" oder als " 40 " kommen. Die
 * Schreibweise dieser Listen stammt aus dem Sport, also wird das Komma nicht
 * abgelehnt, sondern gelesen. Was kein Number ist, bleibt Text und faellt
 * beim Pruefen auf.
 */
function region_fis_normalisieren(array $roh): array
{
    $liste = [];
    foreach ($roh as $rang => $punkte) {
        $wert = trim(str_replace(',', '.', (string) $punkte));
        $liste[(int) $rang] = $wert === '' ? null : $wert;
    }
    ksort($liste);
    return $liste;
}

/**
 * Die uebermittelte Liste lesen und pruefen: Rang => Punkte.
 *
 * Zurueckgegeben wird nur eine Liste, die in sich stimmt - aufsteigende Raenge
 * ab 1, absteigende Punkte, keine Luecken dazwischen. Sonst [], und der
 * Aufrufer nimmt die Vorgabe.
 */
function region_fis_pruefen(array $roh): array
{
    $liste = [];
    foreach (region_fis_normalisieren($roh) as $rang => $punkte) {
        if ($rang < 1 || $punkte === null || !is_numeric($punkte)) {
            return [];
        }
        $liste[$rang] = (float) $punkte;
    }
    if (!$liste) {
        return [];
    }
    $erwartet = 1;
    $vorher = null;
    foreach ($liste as $rang => $punkte) {
        if ($rang !== $erwartet || $punkte < 0) {
            return [];
        }
        // Gleichstand ist erlaubt - 5. und 6. Platz koennen gleich viele Punkte
        // bekommen. Erst wenn es wieder mehr werden, stimmt die Liste nicht.
        if ($vorher !== null && $punkte > $vorher) {
            return [];
        }
        $vorher = $punkte;
        $erwartet++;
    }
    return $liste;
}

/**
 * Warum eine uebermittelte Liste nicht brauchbar ist - in Worten, oder null,
 * wenn nichts dagegen einzuwenden ist.
 *
 * region_fis_pruefen() sagt nur "brauchbar oder nicht". Fuer die Meldung im
 * Formular reicht das nicht: wer drei Zeilen lang sucht, ohne zu finden,
 * laesst es liegen. Deshalb wird der erste konkrete Mangel genannt.
 */
function region_fis_mangel(array $roh): ?string
{
    $liste = region_fis_normalisieren($roh);
    if (!$liste) {
        return 'Es wurde kein Punktwert eingetragen.';
    }
    $vorher = null;
    $vorherRang = 0;
    foreach ($liste as $rang => $punkte) {
        if ($rang < 1) {
            return 'Rang ' . $rang . ' gibt es nicht.';
        }
        // Keine Luecke: das Formular schickt alle Raenge, aber diese Funktion
        // soll auch ausserhalb davon das Richtige sagen und nicht "alles gut".
        if ($rang !== $vorherRang + 1) {
            return 'Rang ' . ($vorherRang + 1) . ' fehlt.';
        }
        $vorherRang = $rang;
        if ($punkte === null) {
            return 'Für Rang ' . $rang . ' ist kein Punktwert eingetragen.';
        }
        if (!is_numeric($punkte)) {
            return 'Bei Rang ' . $rang . ' steht „' . $punkte . '“ statt einer Zahl.';
        }
        $wert = (float) $punkte;
        if ($wert < 0) {
            return 'Rang ' . $rang . ' hat negative Punkte.';
        }
        if ($vorher !== null && $wert > $vorher) {
            return 'Rang ' . $rang . ' bekommt mehr Punkte als Rang ' . ($rang - 1)
                . '. Der beste Platz muss die meisten Punkte bekommen.';
        }
        $vorher = $wert;
    }
    return null;
}

/** Die Liste als JSON, wie sie in den Einstellungen liegt. */function region_fis_speichern(array $liste): void
{
    unset($GLOBALS['region_fis_schema_cache']);
    global_setting_set('region_fis_punkte', json_encode($liste, JSON_UNESCAPED_UNICODE));
}

/** Die Liste wieder auf die Vorgabe zuruecksetzen. */
function region_fis_zuruecksetzen(): void
{
    unset($GLOBALS['region_fis_schema_cache']);
    db()->exec("DELETE FROM settings WHERE skey = 'region_fis_punkte'");
}

/** Ist ueberhaupt eine eigene Liste gespeichert? */
function region_fis_ist_eingestellt(): bool
{
    try {
        $st = db()->prepare('SELECT svalue FROM settings WHERE skey = ?');
        $st->execute(['region_fis_punkte']);
        $roh = $st->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
    return is_string($roh) && trim($roh) !== '';
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
        'SELECT c.id, c.name, c.club_id, c.region, c.completed_at, c.cancelled_at,
                cl.name AS club_name, cs.svalue AS competition_date
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
        // Ein abgesagter Wettbewerb fand nicht statt und zaehlt daher gar
        // nicht - auch nicht mit den Ergebnissen, die vor der Absage vielleicht
        // schon da waren. Sonst hinge der Punktestand eines Piloten davon ab, an
        // welchem Tag der Wettbewerb abgesagt wurde, und nicht davon, ob er
        // geflogen ist. Fuer den Regiocup zaehlen nur Wettbewerbe, die
        // stattgefunden haben; das sind offene und beendete.
        if ($zeile['cancelled_at'] !== null) {
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
 * Seit 2.0.0 ist das die SMV-Nummer: eine Person hat eine, ueber alle
 * Jahre hinweg. Vorher konnte hier nur der Name verglichen werden, und das
 * hatte zwei Fehler, die beide still passierten: zwei Verschiedene mit
 * demselben Namen fielen zu einer Person zusammen, und wer seinen Namen
 * aendert, tauchte in zwei Jahren als zwei Piloten auf.
 *
 * Ohne Nummer bleibt der Name. Das ist schlechter als die Nummer, aber immer
 * noch so gut wie vorher - und zwei Piloten ohne Nummer sind ueberhaupt nicht
 * zu unterscheiden, auch nicht mit ihrem Namen, wenn sie gleich heissen.
 */
function region_pilot_schluessel(array $pilot): string
{
    $nummer = trim((string) ($pilot['smv_number'] ?? ''));
    if ($nummer !== '') {
        return 'smv\t' . $nummer;
    }
    $schluessel = pilot_name_schluessel((string) ($pilot['first_name'] ?? ''), (string) ($pilot['last_name'] ?? ''));
    return $schluessel === '' ? '' : 'name\t' . $schluessel;
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

/** Ein kurzes Kuerzel fuer die Spaltenueberschrift, z. B. "Pokal 2027" -> "Pokal". */
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
 * Name eines bestimmten Vereins eine stille Kopplung, die beim naechsten
 * Verein dann falsch waere.
 */
function region_club_id(): ?int
{
    // Der Regiocup laeuft ueber ein ganzes Jahr und damit ueber mehrere
    // Wettbewerbe. Die Auswahl gehoert deshalb zum Programm und nicht zu dem
    // einen Wettbewerb, in dessen Einstellungen sie bis 1.9.21 stand - sonst
    // haette jedes Jahr eine andere Sichtbarkeit, je nachdem welcher
    // Wettbewerb gerade aktiv ist.
    //
    // Bis 1.9.21 stand sie in den Wettbewerbseinstellungen. Die Einrichtung
    // legt ihren Vorgabewert (0) aber selbst global an, deshalb reicht "global
    // vorhanden" nicht als Kennzeichen fuer "programmgross gesetzt". Es gilt
    // deshalb: was programmgross steht, gewinnt; steht dort nichts, werden die
    // alten Wettbewerbswerte gelesen, damit niemand seinen Verein verliert.
    //
    // Gelesen werden ALLE Wettbewerbe, nicht nur der aktive. Sonst haette der
    // Rueckfall nur gegriffen, wenn gerade der Wettbewerb mit dem alten Wert
    // aktiv waere - und still nichts angezeigt, obwohl der Verein eingestellt
    // war. Der SuperAdmin raeumt die alten Zeilen beim Speichern mit auf.
    $programm = (float) (global_settings()['region_club_id'] ?? 0);
    if ($programm > 0) {
        return (int) $programm;
    }

    $st = db()->prepare("SELECT competition_id, svalue
                         FROM competition_settings
                         WHERE skey = 'region_club_id' AND svalue <> '0' AND svalue <> ''
                         ORDER BY competition_id");
    $st->execute();
    $alt = [];
    foreach ($st->fetchAll() as $zeile) {
        $alt[(int) $zeile['competition_id']] = (int) $zeile['svalue'];
    }
    if (!$alt) {
        return null;
    }
    if (count(array_unique($alt)) === 1) {
        return (int) reset($alt);
    }
    // Mehrere verschiedene Vereine in alten Daten: der gerade aktive
    // Wettbewerb entscheidet, sonst der aelteste Eintrag (die Liste ist
    // aufsteigend nach Wettbewerb sortiert).
    $aktiv = current_competition_id();
    return isset($alt[$aktiv]) ? $alt[$aktiv] : (int) reset($alt);
}

/**
 * Das Jahr, dessen Regiorangliste oeffentlich steht, oder 0.
 *
 * Es ist bewusst **ein** Jahr und nicht eines je Jahr. Der Nutzer wollte:
 * am Ende der Saison freigeben, und danach soll nur das laufende Jahr zu sehen
 * sein. Ein Zustand je Jahr wuerde bedeuten, dass nach der naechsten Freigabe
 * zwei Jahre nebeneinander oeffentlich stehen - und die Jahresknopf-Leiste
 * waere genau die Auswahl, die er nicht wollte.
 *
 * Gespeichert wird er als programmeigene Einstellung wie der Regionsverein
 * (`region_club_id`), nicht je Wettbewerb: der Cup laeuft ueber ein ganzes
 * Jahr und darf nicht davon abhaengen, welcher Wettbewerb gerade aktiv ist.
 */
function region_jahr_oeffentlich(): int
{
    $jahr = (int) (float) (global_settings()['region_public_jahr'] ?? 0);
    // Nur ein Jahr, das es im Bestand ueberhaupt gibt. Sonst wuerde ein
    // eingetragener Wert aus einem Wegloeschen der Wettbewerbe stehen bleiben
    // und eine leere Seite oeffentlich machen.
    if ($jahr <= 0) {
        return 0;
    }
    return in_array($jahr, array_map('intval', region_jahre()), true) ? $jahr : 0;
}

/**
 * Darf dieses Jahr oeffentlich freigegeben werden?
 *
 * Nur wenn kein Wettbewerb dieses Jahres mehr offen ist. Der Grund ist nicht
 * die Sorgfalt, sondern der Ablauf: eine Regiorangliste, in der zur Haelfte der
 * Wettbewerbe noch Starts fehlen, ist ein Zwischenstand. Wer sie im Oktober
 * veroeffentlicht und im November drei Wettbewerbe nachzaehlt, hat im Oktober
 * etwas falsch versprochen.
 *
 * @return array{offen:int, jahre:array} Zaehler und Namen der offenen Wettbewerbe
 */
function region_freigabe_bereit(int $jahr): array
{
    $wettbewerbe = region_wettbewerbe_des_jahres($jahr);
    $offen = [];
    foreach ($wettbewerbe as $w) {
        if (empty($w['completed_at']) && empty($w['cancelled_at'])) {
            $offen[] = (string) $w['name'];
        }
    }
    return ['offen' => count($offen), 'jahre' => $offen];
}

/**
 * Ein Jahr oeffentlich machen - oder die Freigabe wieder zuruecknehmen.
 *
 * @param int $jahr  das Jahr, oder 0 fuer "zuruecknehmen"
 * @return string[]  Logzeilen fuer die Meldung
 */
function region_jahr_freigeben(int $jahr): array
{
    if ($jahr <= 0) {
        global_setting_set('region_public_jahr', 0);
        global_setting_set('region_public_am', '');
        return ['Die oeffentliche Freigabe ist zurueckgenommen.'];
    }
    $jahre = array_map('intval', region_jahre());
    if (!in_array($jahr, $jahre, true)) {
        throw new DomainException(sprintf(
            'Fuer %d gibt es keine Regiorangliste. Vorhanden sind: %s.',
            $jahr,
            $jahre ? implode(', ', $jahre) : 'keine'
        ));
    }
    $stand = region_freigabe_bereit($jahr);
    if ($stand['offen'] > 0) {
        // Hier wird nicht hart abgewiesen, sondern der Weg gewiesen. Der
        // SuperAdmin kann einen Wettbewerb auch absagen; dann ist er nicht
        // mehr offen und die Freigabe wird moeglich. Einfach zu verweigern
        // wuerde ihn raten lassen.
        throw new DomainException(sprintf(
            'In diesem Jahr %s noch offen: %s. Beende %s, oder markiere %s als abgesagt.',
            $stand['offen'] === 1 ? 'ist ein Wettbewerb' : 'sind ' . $stand['offen'] . ' Wettbewerbe',
            implode(', ', $stand['jahre']),
            $stand['offen'] === 1 ? 'ihn' : 'sie',
            $stand['offen'] === 1 ? 'ihn' : 'sie'
        ));
    }
    $vorher = region_jahr_oeffentlich();
    global_setting_set('region_public_jahr', $jahr);
    global_setting_set('region_public_am', date('Y-m-d H:i:s'));
    if ($vorher > 0 && $vorher !== $jahr) {
        return [sprintf('Die Regiorangliste %d steht oeffentlich. %d ist es nicht mehr - '
            . 'wie gewuenscht ist nur das laufende Jahr sichtbar.', $jahr, $vorher)];
    }
    return [sprintf('Die Regiorangliste %d steht jetzt oeffentlich.', $jahr)];
}

/**
 * Darf der Betrachter dieses Jahr sehen?
 *
 * Die Sichtbarkeit der Seite und die Sichtbarkeit eines Jahres sind zwei
 * Fragen. Nach einer oeffentlichen Freigabe darf ein Besucher die Seite
 * ueberhaupt betreten - aber nur das freigegebene Jahr. Ohne diese zweite
 * Pruefung kaeme er ueber `?jahr=2025` an jedes Vorjahr, und die Freigabe waere
 * eine halbe Massnahme.
 *
 * Die Sonderfaelle bleiben wie vorher: sind die Ergebnisse weltweit oeffentlich,
 * sieht jeder alles, und der SuperAdmin sowieso.
 */
function region_darf_jahr_sehen(int $jahr): bool
{
    if (setting_bool('public_results', true) || is_superadmin()) {
        return true;
    }
    $clubId = region_club_id();
    if ($clubId !== null && user_club_id() === $clubId) {
        return true;
    }
    return region_jahr_oeffentlich() === $jahr;
}

/** Die Jahre, die dieser Betrachter sehen darf - absteigend. */
function region_sichtbare_jahre(): array
{
    $frei = region_jahr_oeffentlich();
    return array_values(array_filter(array_map('intval', region_jahre()),
        static function (int $j) use ($frei): bool {
            return region_darf_jahr_sehen($j);
        }));
}

/** Darf der angemeldete Benutzer die Regiorangliste sehen, auch ohne oeffentliche Ergebnisse? */
function region_darf_sehen(): bool
{
    if (setting_bool('public_results', true)) {
        return true;
    }
    // Eine oeffentliche Freigabe des laufenden Jahres macht genau die
    // Regiorangliste oeffentlich - sonst nichts. Die Ranglisten der einzelnen
    // Wettbewerbe bleiben bei ihrem eigenen Schalter.
    if (region_jahr_oeffentlich() > 0) {
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
