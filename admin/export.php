<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/scoring.php';
require_once __DIR__ . '/../lib/auth.php';
require_login();

$what = get('was', 'rangliste');
$typeId = get('typ') !== '' && get('typ') !== 'alle' ? (int) get('typ') : null;
$competition = resolve_competition_param(competition_request_param(), true);
require_competition_access((int) $competition['id']);

$competitionSlug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $competition['name']);
$file = ($competitionSlug ?: 'Wettbewerb') . '_' . $what . '_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $file . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM, damit Excel Umlaute richtig zeigt

if ($what === 'einzelresultate') {
    fputcsv($out, ['Startnummer', 'SMV-Nummer', 'Vorname', 'Name', 'Verein', 'Modelltyp', 'Durchgang',
        'Zielzeit s', 'Flugzeit s', 'Landewert', 'Wertung', 'Zeitstrafe', 'Landestrafe', 'Strafpunkte'], ';');

    $rows = db()->prepare('SELECT p.bib_number, pr.first_name, pr.last_name, pr.smv_number,
                                  c.name AS club_name, t.name AS model_type_name,
                                r.round_number, r.target_time_seconds, s.*
                         FROM scores s
                         JOIN pilots p ON p.id = s.pilot_id
                         JOIN pilot_profiles pr ON pr.id = p.profile_id
                         JOIN rounds r ON r.id = s.round_id
                         LEFT JOIN model_types t ON t.id = p.model_type_id
                         LEFT JOIN clubs c ON c.id = p.club_id
                         WHERE r.competition_id = ?
                         ORDER BY r.round_number, p.bib_number + 0');
    $rows->execute([$competition['id']]);
    $rows = $rows->fetchAll();
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['bib_number'], pilot_smv_anzeige($r['smv_number'] ?? null),
            $r['first_name'], $r['last_name'], $r['club_name'], $r['model_type_name'],
            $r['round_number'], $r['target_time_seconds'], $r['flight_time_seconds'], $r['landing_value'],
            score_outcome_label(score_flags($r)),
            $r['time_penalty'], $r['landing_penalty'], $r['penalty'],
        ], ';');
    }
} elseif ($what === 'vereine') {
    $data = build_club_ranking($typeId, $competition['id']);
    $count = $data['count'];
    $header = ['Rang', 'Verein', 'Total', 'Piloten am Start'];
    for ($i = 1; $i <= $count; $i++) {
        $header[] = "Pilot $i";
        $header[] = "Punkte $i";
    }
    fputcsv($out, $header, ';');
    foreach ($data['rows'] as $r) {
        $line = [$r['rank'] ?? '', $r['name'], $r['ranked'] ? $r['total'] : '', $r['available']];
        for ($i = 0; $i < $count; $i++) {
            $row = $r['scoring'][$i] ?? null;
            $line[] = $row ? full_name($row['pilot']) : '';
            $line[] = $row ? $row['total'] : '';
        }
        fputcsv($out, $line, ';');
    }
} else {
    $data = build_ranking($typeId, null, $competition['id']);
    $header = ['Rang', 'Startnummer', 'Vorname', 'Name', 'Verein', 'Modelltyp'];
    foreach ($data['rounds'] as $r) {
        $header[] = 'DG ' . $r['round_number'];
    }
    $header[] = 'Streichresultat';
    $header[] = 'Total';
    fputcsv($out, $header, ';');

    foreach ($data['rows'] as $row) {
        $p = $row['pilot'];
        $line = [$row['rank'] ?? '', $p['bib_number'], $p['first_name'], $p['last_name'], $p['club_name'], $p['model_type_name']];
        foreach ($data['rounds'] as $r) {
            $c = $row['cells'][(int) $r['id']] ?? null;
            $line[] = $c ? $c['penalty'] : '';
        }
        $dropRound = '';
        foreach ($data['rounds'] as $r) {
            if ($row['dropped'] === (int) $r['id']) {
                $dropRound = 'DG ' . $r['round_number'];
            }
        }
        $line[] = $dropRound;
        $line[] = $row['has_any'] ? $row['total'] : '';
        fputcsv($out, $line, ';');
    }
}
fclose($out);
