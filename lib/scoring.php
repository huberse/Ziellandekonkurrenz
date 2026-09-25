<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/competition.php';

/**
 * Die möglichen Ausgänge eines Resultats. Der Schlüssel ist zugleich der Wert im
 * Auswahlfeld der Erfassung; er wird in Datenbank-Status und Motor-Kennzeichen
 * zerlegt. `label` ist die einzige Bezeichnung des Ausgangs und steht überall
 * gleich – in der Erfassung, auf dem Laufzettel, in der Rangliste und im Export.
 *
 * @return array<string, array{status:string, motor:bool, label:string}>
 */
function score_outcomes(): array
{
    return [
        'flown'     => ['status' => 'flown', 'motor' => false, 'label' => 'geflogen'],
        'dns'       => ['status' => 'dns',   'motor' => false, 'label' => 'nicht angetreten'],
        'dnf'       => ['status' => 'dnf',   'motor' => false, 'label' => 'Aussenlandung'],
        'motor'     => ['status' => 'flown', 'motor' => true,  'label' => 'Motor angelassen'],
        'motor_dnf' => ['status' => 'dnf',   'motor' => true,  'label' => 'Aussenlandung & Motor angelassen'],
    ];
}

/** Datenbanksatz -> Schlüssel aus score_outcomes(). */
function score_outcome_of(string $status, bool $motor): string
{
    if ($motor) {
        return $status === 'dnf' ? 'motor_dnf' : 'motor';
    }
    return in_array($status, ['flown', 'dnf', 'dns'], true) ? $status : 'flown';
}

/** Bezeichnung eines Ergebnisses, für Tooltips und Zellen der Rangliste. */
function score_outcome_label(string $status, bool $motor = false): string
{
    $outcomes = score_outcomes();
    return $outcomes[score_outcome_of($status, $motor)]['label'];
}

/** Eine der Feststrafen, innerhalb der Speichergrenze. */
function fixed_penalty(string $key, float $default = 100): float
{
    return min(999999.99, max(0.0, setting_num($key, $default)));
}

/**
 * Die drei Ankreuzfelder des Laufzettels. Sie decken alle vier Ausgänge ab, die
 * kein sauberer Flug sind: kein Feld heisst „geflogen“, nur „nicht angetreten“
 * heisst Nichtantritt, „Aussenlandung“ und „Motor“ einzeln, und beide zusammen
 * ergeben Aussenlandung mit Motor.
 *
 * @return array<int, array{label:string, setting:string}>
 */
function runsheet_penalty_boxes(): array
{
    return [
        ['label' => 'nicht angetreten', 'setting' => 'penalty_not_started'],
        ['label' => 'Aussenlandung',    'setting' => 'penalty_outlanding'],
        ['label' => 'Motor angelassen', 'setting' => 'penalty_motor'],
    ];
}

/**
 * Strafpunkte eines Fluges berechnen.
 *
 * Die Wertung besteht aus genau fünf Bausteinen: die Zeitabweichung als
 * positive Zahl – zu lang und zu kurz bringen denselben Satz je Sekunde –,
 * die Landepunkte und drei Feststrafen für Aussenlandung, Nichtantritt und
 * Motorstart. Eine Obergrenze gibt es nicht. Der Motor ist keine eigene
 * Ergebnisart, sondern eine Zusatzstrafe: bei einem geflogenen Flug ersetzt sie
 * Zeit und Landewert, bei einer Aussenlandung oder einem Nichtantritt kommt sie
 * zu der Feststrafe dazu.
 *
 * Wenige Punkte = gut. Rückgabe: [time_penalty, landing_penalty, total].
 */
function calc_penalty(string $status, ?float $flightTime, ?float $landingValue, int $targetTime, bool $motor = false): array
{
    $maxStoredPenalty = 999999.99;

    if ($status === 'flown' && !$motor) {
        $perSecond   = max(0.0, setting_num('penalty_per_second', 1));
        $meterFactor = max(0.0, setting_num('penalty_per_meter', 1));

        // Betrag statt Vorzeichen: zu lang und zu kurz zählen gleich.
        $diff = abs(($flightTime ?? 0.0) - $targetTime);
        $timePenalty = $diff * $perSecond;
        $landingPenalty = max(0.0, $landingValue ?? 0.0) * $meterFactor;

        $timePenalty    = min($maxStoredPenalty, round($timePenalty, 2));
        $landingPenalty = min($maxStoredPenalty, round($landingPenalty, 2));

        return [$timePenalty, $landingPenalty, min($maxStoredPenalty, round($timePenalty + $landingPenalty, 2))];
    }

    if ($status === 'flown') {
        // Geflogen, aber der Motor lief: es zählt allein die Motorstrafe.
        return [0.0, 0.0, fixed_penalty('penalty_motor')];
    }

    $fixed = $status === 'dnf' ? fixed_penalty('penalty_outlanding') : fixed_penalty('penalty_not_started');
    if ($motor) {
        $fixed += fixed_penalty('penalty_motor');
    }
    return [0.0, 0.0, min($maxStoredPenalty, round($fixed, 2))];
}

/** Alle gewerteten Durchgänge (is_included = 1) eines Wettbewerbs, aufsteigend. Ohne Angabe: der aktuelle Wettbewerb. */
function included_rounds(?int $competitionId = null): array
{
    if ($competitionId !== null) {
        set_competition_context($competitionId);
    }
    $competitionId = $competitionId ?? current_competition_id();
    $st = db()->prepare('SELECT * FROM rounds WHERE competition_id = ? AND is_included = 1 ORDER BY round_number');
    $st->execute([$competitionId]);
    return $st->fetchAll();
}

function all_rounds(?int $competitionId = null): array
{
    if ($competitionId !== null) {
        set_competition_context($competitionId);
    }
    $competitionId = $competitionId ?? current_competition_id();
    $st = db()->prepare('SELECT * FROM rounds WHERE competition_id = ? ORDER BY round_number');
    $st->execute([$competitionId]);
    return $st->fetchAll();
}

function all_model_types(): array
{
    return db()->query('SELECT * FROM model_types ORDER BY sort_order, name')->fetchAll();
}

function all_clubs(): array
{
    return db()->query('SELECT * FROM clubs ORDER BY sort_order, name')->fetchAll();
}

/** Prüft, ob eine Startnummer in einem Wettbewerb bereits vergeben ist. */
function competition_bib_number_exists(int $competitionId, ?string $bibNumber, ?int $exceptPilotId = null): bool
{
    if ($bibNumber === null || trim($bibNumber) === '') {
        return false;
    }
    $sql = 'SELECT COUNT(*) FROM pilots WHERE competition_id = ? AND bib_number = ?';
    $args = [$competitionId, trim($bibNumber)];
    if ($exceptPilotId !== null) {
        $sql .= ' AND id <> ?';
        $args[] = $exceptPilotId;
    }
    $st = db()->prepare($sql);
    $st->execute($args);
    return (int) $st->fetchColumn() > 0;
}

/** Verein anhand des Namens finden oder neu anlegen. */
function club_id_for_name(?string $name): ?int
{
    $name = text_limit((string) $name, 120);
    if ($name === '') {
        return null;
    }
    $st = db()->prepare('SELECT id FROM clubs WHERE name = ?');
    $st->execute([$name]);
    $id = $st->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    $ins = db()->prepare('INSERT INTO clubs (name, sort_order) VALUES (?, ?)');
    $ins->execute([$name, 0]);
    return (int) db()->lastInsertId();
}

/**
 * Rangliste berechnen.
 *
 * @param int|null $typeId  nur diese Modelltyp, null = alle
 * @return array{rounds: array, rows: array}
 */
function build_ranking(?int $typeId = null, ?int $clubId = null, ?int $competitionId = null): array
{
    if ($competitionId !== null) {
        set_competition_context($competitionId);
    }
    $competitionId = $competitionId ?? current_competition_id();
    $rounds = included_rounds($competitionId);
    $roundIds = array_column($rounds, 'id');

    $sql = 'SELECT p.*, t.name AS model_type_name, c.name AS club_name, c.short_name AS club_short
            FROM pilots p
            LEFT JOIN model_types t ON t.id = p.model_type_id
            LEFT JOIN clubs c ON c.id = p.club_id
            WHERE p.active = 1 AND p.competition_id = ?';
    $args = [$competitionId];
    if ($typeId !== null) {
        $sql .= ' AND p.model_type_id = ?';
        $args[] = $typeId;
    }
    if ($clubId !== null) {
        $sql .= ' AND p.club_id = ?';
        $args[] = $clubId;
    }
    $sql .= ' ORDER BY p.last_name, p.first_name';
    $st = db()->prepare($sql);
    $st->execute($args);
    $pilots = $st->fetchAll();

    // Resultate laden
    $scores = [];
    if ($roundIds) {
        $in = implode(',', array_fill(0, count($roundIds), '?'));
        $st = db()->prepare("SELECT s.* FROM scores s
                             JOIN pilots p ON p.id = s.pilot_id
                             WHERE s.round_id IN ($in) AND p.active = 1");
        $st->execute($roundIds);
        foreach ($st as $s) {
            $scores[(int) $s['pilot_id']][(int) $s['round_id']] = $s;
        }
    }

    // Wird ein Streichresultat angewendet?
    $dropEnabled  = setting_bool('drop_enabled', true);
    $dropMin      = max(1, (int) setting_num('drop_min_rounds', 4));
    $missing      = fixed_penalty('penalty_not_started');

    // Nur Durchgänge mit mindestens einem erfassten Resultat gelten als geflogen
    $flownRounds = [];
    foreach ($rounds as $r) {
        foreach ($scores as $byRound) {
            if (isset($byRound[(int) $r['id']])) {
                $flownRounds[] = (int) $r['id'];
                break;
            }
        }
    }
    $applyDrop = $dropEnabled && count($flownRounds) >= $dropMin;

    $rows = [];
    foreach ($pilots as $p) {
        $cells = [];
        $values = [];   // round_id => Strafpunkte für die Wertung
        $hasAny = false;

        foreach ($rounds as $r) {
            $rid = (int) $r['id'];
            $s   = $scores[(int) $p['id']][$rid] ?? null;

            if ($s) {
                $hasAny = true;
                $cells[$rid] = [
                    'penalty' => (float) $s['penalty'],
                    'status'  => $s['status'],
                    'motor'   => (bool) ($s['motor'] ?? 0),
                    'time'    => $s['flight_time_seconds'] !== null ? (float) $s['flight_time_seconds'] : null,
                    'dist'    => $s['landing_value'] !== null ? (float) $s['landing_value'] : null,
                    'missing' => false,
                ];
                $values[$rid] = (float) $s['penalty'];
            } elseif (in_array($rid, $flownRounds, true)) {
                // Durchgang wurde geflogen, dieser Pilot hat kein Resultat → nicht angetreten
                $cells[$rid] = ['penalty' => $missing, 'status' => 'dns', 'motor' => false, 'time' => null, 'dist' => null, 'missing' => true];
                $values[$rid] = $missing;
            } else {
                $cells[$rid] = null; // Durchgang noch nicht geflogen
            }
        }

        // Wer nirgends ein Resultat hat, ist gar nicht angetreten: leere Zeile statt lauter Aussenlandungenn
        if (!$hasAny) {
            $cells = array_map(function ($c) { return null; }, $cells);
            $values = [];
        }

        $dropped = null;
        $droppedValue = null;
        $total = array_sum($values);
        if ($applyDrop && count($values) >= $dropMin) {
            $worstRid = null;
            $worstVal = -INF;
            foreach ($values as $rid => $v) {
                if ($v > $worstVal) {
                    $worstVal = $v;
                    $worstRid = $rid;
                }
            }
            if ($worstRid !== null) {
                $dropped = $worstRid;
                $droppedValue = $worstVal;
                $total -= $worstVal;
            }
        }

        $counted = $values;
        if ($dropped !== null) {
            unset($counted[$dropped]);
        }

        $rows[] = [
            'pilot'         => $p,
            'cells'         => $cells,
            'total'         => round($total, 2),
            'dropped'       => $dropped,
            'dropped_value' => $droppedValue,
            'flights'       => count(array_filter($cells, function ($c) { return $c && !$c['missing'] && $c['status'] === 'flown' && !$c['motor']; })),
            'best'          => $counted ? min($counted) : null,
            'has_any'       => $hasAny,
        ];
    }

    // Piloten ohne jedes Resultat ans Ende
    usort($rows, function ($a, $b) {
        if ($a['has_any'] !== $b['has_any']) {
            return $a['has_any'] ? -1 : 1;
        }
        if ($a['total'] !== $b['total']) {
            return $a['total'] <=> $b['total'];
        }
        // Bei Punktegleichheit gewinnt das kleinere Streichresultat.
        if ($a['dropped_value'] !== null && $b['dropped_value'] !== null && $a['dropped_value'] !== $b['dropped_value']) {
            return $a['dropped_value'] <=> $b['dropped_value'];
        }
        if ($a['flights'] !== $b['flights']) {
            return $b['flights'] <=> $a['flights'];   // mehr gültige Flüge zuerst
        }
        $ab = $a['best'] ?? INF;
        $bb = $b['best'] ?? INF;
        if ($ab !== $bb) {
            return $ab <=> $bb;                        // besseres Einzelresultat zuerst
        }
        return strcmp(full_name($a['pilot']), full_name($b['pilot']));
    });

    // Ränge setzen: gleicher Rang nur, wenn alle offiziellen Tie-Break-Werte übereinstimmen.
    $rank = 0;
    $seen = 0;
    $prevKey = null;
    foreach ($rows as $i => &$row) {
        $seen++;
        if (!$row['has_any']) {
            $row['rank'] = null;
            continue;
        }
        $key = $row['total'] . '|' . ($row['dropped_value'] ?? '')
            . '|' . $row['flights'] . '|' . ($row['best'] ?? '');
        if ($prevKey === null || $key !== $prevKey) {
            $rank = $seen;
            $prevKey = $key;
        }
        $row['rank'] = $rank;
    }
    unset($row);

    return ['rounds' => $rounds, 'rows' => $rows];
}

/**
 * Vereinswertung: Die besten N Piloten eines Vereins ergeben zusammen das Vereinsresultat.
 *
 * @param int|null $typeId  nur diesen Modelltyp werten, null = alle
 * @return array{count:int, rows:array}  rows je Verein, gewertete zuerst
 */
function build_club_ranking(?int $typeId = null, ?int $competitionId = null): array
{
    if ($competitionId !== null) {
        set_competition_context($competitionId);
    }
    $count = max(1, (int) setting_num('club_scoring_count', 3));
    $base  = build_ranking($typeId, null, $competitionId);

    $clubs = [];
    foreach ($base['rows'] as $row) {
        if (!$row['has_any']) {
            continue;  // wer nie angetreten ist, zählt für den Verein nicht
        }
        $p = $row['pilot'];
        $cid = $p['club_id'] !== null ? (int) $p['club_id'] : 0;
        if (!isset($clubs[$cid])) {
            $clubs[$cid] = [
                'club_id' => $cid ?: null,
                'name'    => $p['club_name'] ?: 'Ohne Verein',
                'pilots'  => [],
            ];
        }
        $clubs[$cid]['pilots'][] = $row;
    }

    $rows = [];
    foreach ($clubs as $c) {
        usort($c['pilots'], function ($a, $b) {
            if ($a['total'] != $b['total']) {
                return $a['total'] <=> $b['total'];
            }
            if ($a['dropped_value'] !== null && $b['dropped_value'] !== null
                && $a['dropped_value'] != $b['dropped_value']) {
                return $a['dropped_value'] <=> $b['dropped_value'];
            }
            if ($a['flights'] !== $b['flights']) {
                return $b['flights'] <=> $a['flights'];
            }
            $ab = $a['best'] ?? INF;
            $bb = $b['best'] ?? INF;
            if ($ab != $bb) {
                return $ab <=> $bb;
            }
            return strcmp(full_name($a['pilot']), full_name($b['pilot']));
        });
        $scoring = array_slice($c['pilots'], 0, $count);
        $rows[] = [
            'club_id'   => $c['club_id'],
            'name'      => $c['name'],
            'pilots'    => $c['pilots'],
            'scoring'   => $scoring,
            'total'     => round(array_sum(array_column($scoring, 'total')), 2),
            'available' => count($c['pilots']),
            'ranked'    => count($c['pilots']) >= $count,
        ];
    }

    usort($rows, function ($a, $b) {
        if ($a['ranked'] !== $b['ranked']) {
            return $a['ranked'] ? -1 : 1;
        }
        if ($a['total'] != $b['total']) {
            return $a['total'] <=> $b['total'];
        }
        return strcmp($a['name'], $b['name']);
    });

    $rank = 0; $seen = 0; $prev = null;
    foreach ($rows as &$r) {
        $seen++;
        if (!$r['ranked']) {
            $r['rank'] = null;
            continue;
        }
        if ($prev === null || $r['total'] != $prev) {
            $rank = $seen;
            $prev = $r['total'];
        }
        $r['rank'] = $rank;
    }
    unset($r);

    return ['count' => $count, 'rows' => $rows];
}

/** Regeln in einem Satz, für Kopfzeilen und Ausdrucke. */
function rules_summary(): string
{
    $punkt = function (string $n): string { return $n === '1' ? 'Strafpunkt' : 'Strafpunkte'; };

    $parts = [];
    $perSecond = fmt_num(setting_num('penalty_per_second', 1), 2);
    $parts[] = "$perSecond {$punkt($perSecond)} je Sekunde Abweichung, gleich ob zu lang oder zu kurz";
    $meter = fmt_num(setting_num('penalty_per_meter', 1), 2);
    $parts[] = "$meter {$punkt($meter)} je Landewert-Einheit";
    $parts[] = fmt_num(setting_num('penalty_outlanding', 100)) . ' bei Aussenlandung';
    $parts[] = fmt_num(setting_num('penalty_not_started', 100)) . ' bei Nichtantritt';
    $parts[] = fmt_num(setting_num('penalty_motor', 100)) . ' bei Motorstart';
    if (setting_bool('drop_enabled', true)) {
        $parts[] = 'Streichresultat ab ' . (int) setting_num('drop_min_rounds', 4) . ' geflogenen Durchgängen';
    }
    return implode(' · ', $parts);
}
