<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/scoring.php';
require_once __DIR__ . '/../lib/runsheet_pdf.php';
require_once __DIR__ . '/../lib/layout.php';
require_login();

$competition = resolve_competition_param(competition_request_param());
$rounds = all_rounds($competition['id']);
$pilotStmt = db()->prepare('SELECT p.*, t.name AS model_type_name, c.name AS club_name
                             FROM pilots p
                             LEFT JOIN model_types t ON t.id = p.model_type_id
                             LEFT JOIN clubs c ON c.id = p.club_id
                             WHERE p.active = 1 AND p.competition_id = ?
                             ORDER BY p.bib_number + 0, p.bib_number, p.last_name, p.first_name');
$pilotStmt->execute([(int) $competition['id']]);
$pilots = $pilotStmt->fetchAll();

$sortPilots = function (array $list): array {
    usort($list, function (array $a, array $b): int {
        $aNumber = (int) ($a['bib_number'] ?? 0);
        $bNumber = (int) ($b['bib_number'] ?? 0);
        if ($aNumber !== $bNumber) {
            return $aNumber <=> $bNumber;
        }
        return strcmp(full_name($a), full_name($b));
    });
    return $list;
};

$odd = $even = $none = [];
foreach ($pilots as $pilot) {
    $bib = trim((string) ($pilot['bib_number'] ?? ''));
    if ($bib !== '' && ctype_digit($bib)) {
        if (((int) $bib) % 2 === 0) {
            $even[] = $pilot;
        } else {
            $odd[] = $pilot;
        }
    } else {
        $none[] = $pilot;
    }
}
$odd = $sortPilots($odd);
$even = $sortPilots($even);
$none = $sortPilots($none);

// Piloten ohne Startnummer gehören zur ungeraden Liste, damit trotzdem genau zwei
// Zeitnehmerblätter je Durchgang entstehen. Der Abschnitt wird deutlich markiert.
$odd = array_merge($odd, $none);
$maxRows = max(count($odd), count($even), 1);
$rowHeightMm = max(3.5, min(8.5, 210 / $maxRows));

$sheets = [];
foreach ($rounds as $round) {
    $sheets[] = [
        'round' => $round,
        'title' => 'Zeitnehmer 1 – ungerade Startnummern',
        'short' => 'Ungerade Startnummern',
        'pilots' => $odd,
        'has_none' => (bool) $none,
    ];
    $sheets[] = [
        'round' => $round,
        'title' => 'Zeitnehmer 2 – gerade Startnummern',
        'short' => 'Gerade Startnummern',
        'pilots' => $even,
        'has_none' => false,
    ];
}
$lastSheet = count($sheets) - 1;
$notCurrent = (int) $competition['id'] !== current_competition_id();
$pdfUrl = 'laufzettel.php?format=pdf' . ($notCurrent ? '&competition=' . (int) $competition['id'] : '');
$date = setting('competition_date', '');
$dateLabel = $date !== '' ? date('d.m.Y', strtotime($date)) : '';
$place = setting('competition_place', '');

if (get('format') === 'pdf') {
    $pdf = build_runsheet_pdf($sheets, [
        'competition_name' => setting('competition_name', 'Segelflug-Wettbewerb'),
        'date' => $dateLabel,
        'place' => $place,
    ]);
    $fileBase = (string) $competition['name'];
    $file = preg_replace('/[^A-Za-z0-9_-]+/', '_', $fileBase) ?: 'laufzettel';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $file . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

page_start('Laufzettel', 'admin', 'laufzettel.php');
?>
<div class="row-between no-print">
    <div>
        <h2>Laufzettel<?= $notCurrent ? ' – Wettbewerb ' . h($competition['name']) : '' ?></h2>
        <p class="lead">Je Durchgang entstehen zwei A4-Seiten: eine für die ungeraden und eine für die geraden
            Startnummern. Jede Seite enthält alle Piloten dieses Zeitnehmers, die Eintragungsfelder für den
            jeweiligen Durchgang und eine Übergabequittung.</p>
        <p class="lead">Direkter PDF-Download im A4-Format. Die HTML-Ansicht darunter ist nur noch als
            Druck-Fallback vorhanden.</p>
    </div>
    <div class="btn-row">
        <?php if ($notCurrent): ?>
            <a class="btn ghost" href="erfassung.php?competition=<?= (int) $competition['id'] ?>">Zur Erfassung</a>
        <?php endif; ?>
        <a class="btn" href="<?= h($pdfUrl) ?>">PDF herunterladen</a>
        <button class="btn ghost" type="button" data-print>Druckansicht</button>
    </div>
</div>
<?php if ($notCurrent): ?>
    <div class="flash info no-print">Achtung: Du siehst den Wettbewerb „<?= h($competition['name']) ?>“, nicht den aktiven Wettbewerb.
        <a href="wettbewerbe.php">Wettbewerbe ansehen</a></div>
<?php endif; ?>
<?php if (competition_is_completed((int) $competition['id'])): ?>
    <div class="flash info no-print">Dieser Wettbewerb ist abgeschlossen. Der Laufzettel bleibt als Archiv sichtbar und kann weiterhin als PDF heruntergeladen werden.</div>
<?php endif; ?>

<?php if (!$rounds): ?>
    <div class="panel"><p class="lead">Für diesen Wettbewerb sind noch keine Durchgänge angelegt.</p></div>
<?php elseif (!$pilots): ?>
    <div class="panel"><p class="lead">Keine aktiven Piloten erfasst.</p></div>
<?php endif; ?>

<?php foreach ($sheets as $index => $sheet):
    $round = $sheet['round'];
    $sheetPilots = $sheet['pilots'];
?>
    <div class="runsheet-card<?= $index < $lastSheet ? ' runsheet-break' : '' ?>" style="--runsheet-row-height:<?= h((string) $rowHeightMm) ?>mm">
        <div class="runsheet-page-head">
            <div>
                <b><?= h(setting('competition_name', 'Segelflug-Wettbewerb')) ?></b>
                <span class="muted"><?= $dateLabel !== '' ? ' · ' . h($dateLabel) : '' ?><?= $place !== '' ? ' · ' . h($place) : '' ?></span>
            </div>
            <div class="runsheet-round">
                <b>Durchgang <?= (int) $round['round_number'] ?></b>
                <span>Zielzeit <?= h(fmt_time((float) $round['target_time_seconds'])) ?></span>
            </div>
        </div>

        <div class="runsheet-sheet-head">
            <b><?= h($sheet['title']) ?></b>
            <span><?= count($sheetPilots) ?> Pilot<?= count($sheetPilots) === 1 ? '' : 'en' ?></span>
        </div>

        <table class="runsheet-table">
            <thead>
                <tr>
                    <th>Nr.</th>
                    <th>Pilot / Verein</th>
                    <th>Modell</th>
                    <th>Flugzeit</th>
                    <th>Landewert</th>
                    <?php foreach (runsheet_penalty_boxes() as $box): ?>
                        <th class="mid penalty-head"><?= h($box['label']) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($sheet['has_none']): ?>
                    <tr class="runsheet-section"><td colspan="8">Ohne Startnummer – vor dem Wettbewerb bitte zuweisen</td></tr>
                <?php endif; ?>
                <?php if (!$sheetPilots): ?>
                    <tr><td colspan="8" class="runsheet-empty">Keine Piloten in dieser Gruppe.</td></tr>
                <?php else: ?>
                    <?php foreach ($sheetPilots as $pilot): ?>
                        <tr>
                            <td class="runsheet-number"><?= h($pilot['bib_number'] ?: '–') ?></td>
                            <td>
                                <b><?= h(full_name($pilot)) ?></b>
                                <?php if ($pilot['club_name']): ?><br><span class="muted"><?= h($pilot['club_name']) ?></span><?php endif; ?>
                            </td>
                            <td><?= h($pilot['model_name'] ?: $pilot['model_type_name'] ?: '–') ?></td>
                            <td class="runsheet-write"></td>
                            <td class="runsheet-write"></td>
                            <?php foreach (runsheet_penalty_boxes() as $box): ?>
                                <td class="runsheet-check">☐</td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <p class="runsheet-legend">
            <?php foreach (runsheet_penalty_boxes() as $box): ?>
                <span><b><?= h($box['label']) ?></b> = <?= h(fmt_num(fixed_penalty($box['setting']))) ?> Punkte</span>
            <?php endforeach; ?>
            <span>Kein Feld angekreuzt heisst „geflogen“. Aussenlandung und Motor zusammen ergeben
                „Aussenlandung &amp; Motor angelassen“.</span>
        </p>

        <div class="runsheet-footline">
            <span>Zeitnehmer: ____________________</span>
            <span>Übergeben an: ____________________</span>
            <span>Datum / Uhrzeit: ____________________</span>
        </div>
    </div>
<?php endforeach; ?>
<?php page_end(); ?>
