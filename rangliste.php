<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/scoring.php';
require_once __DIR__ . '/lib/layout.php';

if (!is_file(__DIR__ . '/config.php')) {
    exit('config.php fehlt. Kopiere config.sample.php nach config.php.');
}
try {
    db()->query('SELECT 1 FROM rounds LIMIT 1');
} catch (PDOException $e) {
    redirect('install.php');
}
if (!schema_has_competitions()) {
    redirect('upgrade.php');
}

$competition = resolve_competition_param(competition_request_param());
$competitions = all_competitions();
$vereinSichtbar = setting_bool('club_ranking_enabled', true) || current_user() !== null;

if (!setting_bool('public_results', true) && !current_user()) {
    page_start('Rangliste', 'public', 'rangliste.php', false, false);
    // Keine Auswahlleiste: zur Startseite fuehrt der Titel in der Kopfzeile,
    // dort stehen die Kacheln aller Wettbewerbe.
    echo '<div class="panel"><h2>Noch keine Rangliste</h2><p class="lead">Die Resultate werden nach dem Wettbewerb aufgeschaltet.</p></div>';
    page_end();
    exit;
}

$types = all_model_types();
$scope = get('typ', setting('ranking_scope', 'group') === 'overall' ? 'alle' : (string) ($types[0]['id'] ?? 'alle'));

$blocks = [];
if ($scope === 'alle' || !$types) {
    $blocks[] = ['title' => 'Gesamtwertung', 'data' => build_ranking(null, null, $competition['id'])];
} else {
    $gid = (int) $scope;
    foreach ($types as $g) {
        if ((int) $g['id'] === $gid) {
            $blocks[] = ['title' => $g['name'], 'data' => build_ranking($gid, null, $competition['id'])];
        }
    }
    if (!$blocks) {
        $blocks[] = ['title' => 'Gesamtwertung', 'data' => build_ranking(null, null, $competition['id'])];
    }
}

// Die Vereinswertung steht auf derselben Seite und folgt demselben Filter.
// Sie hat ihre eigene Seite bekommen, weil es eine Leiste gab, in der sie
// liegen konnte. Die Leiste ist weg, also steht die Wertung unten an derselben
// Stelle, wo sie vorher stand - und man vergleicht beide ohne weiteres Klicken.
$vereine = $vereinSichtbar
    ? build_club_ranking($scope === 'alle' ? null : (int) $scope, $competition['id'])
    : null;

page_start('Rangliste', 'public', 'rangliste.php', true, false);
$competitionQS = '&competition=' . (int) $competition['id'];
?>
<div class="row-between no-print">
    <div>
        <h2>Rangliste<?= count($competitions) > 1 ? ' – ' . h($competition['name']) : '' ?></h2>
        <p class="lead"><?= h(rules_summary()) ?>. Wenige Punkte sind gut.</p>
    </div>
    <?php // Ein Filter fuer beide Wertungen. "Gesamtwertung" heisst bei den
          // Vereinen dasselbe wie "alle Modelltypen". ?>
    <div class="btn-row dense">
        <?php if (count($types) > 0): ?>
            <a class="btn <?= $scope === 'alle' ? '' : 'ghost' ?>" href="?typ=alle<?= $competitionQS ?>">Gesamtwertung</a>
            <?php foreach ($types as $g): ?>
                <a class="btn <?= (string) $g['id'] === (string) $scope ? '' : 'ghost' ?>" href="?typ=<?= (int) $g['id'] ?><?= $competitionQS ?>"><?= h($g['name']) ?></a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php foreach ($blocks as $block):
    $rounds = $block['data']['rounds'];
    $rows   = $block['data']['rows'];
    ?>
    <div class="panel">
        <h3 style="margin-top:0"><?= h($block['title']) ?></h3>
        <?php if (!$rows): ?>
            <p class="lead">Für diese Auswahl sind noch keine Piloten erfasst.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table class="data">
            <thead>
            <tr>
                <th>Rang</th>
                <th class="num">Nr.</th>
                <th>Pilot</th>
                <th>Verein</th>
                <?php if ($scope === 'alle'): ?><th>Modelltyp</th><?php endif; ?>
                <?php foreach ($rounds as $r): ?>
                    <th class="num">DG <?= (int) $r['round_number'] ?></th>
                <?php endforeach; ?>
                <th class="num">Total</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row):
                $p = $row['pilot'];
                $podium = $row['rank'] && $row['rank'] <= 3 ? ' podium-' . $row['rank'] : '';
                ?>
                <tr class="<?= $podium ?>">
                    <td class="rank"><?= $row['rank'] ? (int) $row['rank'] : '–' ?></td>
                    <td class="num"><?= h($p['bib_number'] ?: '') ?></td>
                    <td><?= h(full_name($p)) ?></td>
                    <td class="small muted"><?= h($p['club_name'] ?: '') ?></td>
                    <?php if ($scope === 'alle'): ?><td class="small muted"><?= h($p['model_type_name'] ?: '') ?></td><?php endif; ?>
                    <?php foreach ($rounds as $r):
                        $c = $row['cells'][(int) $r['id']] ?? null;
                        $isDrop = $row['dropped'] === (int) $r['id'];
                        if ($c === null) {
                            echo '<td class="num cell-empty">·</td>';
                        } else {
                            $scored = score_is_flown($c['flags']) && empty($c['flags']['motor']);
                            $cls = 'num' . ($isDrop ? ' dropped' : '') . ($scored ? '' : ' cell-missing');
                            $title = $c['missing']
                                ? 'Kein Resultat erfasst'
                                : ($scored
                                    ? 'Zeit ' . fmt_time($c['time']) . ', Landewert ' . fmt_num($c['dist'])
                                    : score_outcome_label($c['flags']));
                            echo '<td class="' . $cls . '" title="' . h($title) . '">' . h(fmt_num($c['penalty'])) . '</td>';
                        }
                    endforeach; ?>
                    <td class="num total"><?= $row['has_any'] ? h(fmt_num($row['total'])) : '–' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="small muted" style="margin-bottom:0">
            Durchgestrichene Werte sind Streichresultate. Rote Werte sind Aussenlandungen, nicht gestartete
            Piloten, Motorstarts oder fehlende Resultate
            (<?= h(fmt_num(setting_num('penalty_not_started'))) ?> Punkte). Bei Punktegleichheit entscheidet das
            kleinere Streichresultat.
        </p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php if ($vereine !== null): ?>
    <div class="panel">
        <h3 style="margin-top:0">Vereinswertung</h3>
        <p class="lead">Die <?= (int) $vereine['count'] ?> besten Piloten eines Vereins ergeben zusammen
            das Vereinsresultat.</p>
        <?php if (!$vereine['rows']): ?>
            <p class="lead">Noch keine gewerteten Resultate.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table class="data">
            <thead>
            <tr>
                <th>Rang</th>
                <th>Verein</th>
                <th>Gewertete Piloten</th>
                <th class="num">Piloten am Start</th>
                <th class="num">Total</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($vereine['rows'] as $r):
                $podium = $r['rank'] && $r['rank'] <= 3 ? ' podium-' . $r['rank'] : ''; ?>
                <tr class="<?= $podium ?>">
                    <td class="rank"><?= $r['rank'] ? (int) $r['rank'] : '–' ?></td>
                    <td>
                        <b><?= h($r['name']) ?></b>
                        <?php if (!$r['ranked']): ?>
                            <br><span class="small cell-missing">ausser Konkurrenz, nur <?= (int) $r['available'] ?> von <?= (int) $vereine['count'] ?> Piloten</span>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php $teile = [];
                        foreach ($r['scoring'] as $zeile) {
                            $teile[] = h(full_name($zeile['pilot'])) . ' <span class="muted">' . h(fmt_num($zeile['total'])) . '</span>';
                        }
                        echo implode(' · ', $teile); ?>
                    </td>
                    <td class="num muted"><?= (int) $r['available'] ?></td>
                    <td class="num total"><?= $r['ranked'] ? h(fmt_num($r['total'])) : '–' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="small muted" style="margin-bottom:0">Ein Verein braucht <?= (int) $vereine['count'] ?>
            Piloten mit mindestens einem Resultat, um gewertet zu werden.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php page_end();
