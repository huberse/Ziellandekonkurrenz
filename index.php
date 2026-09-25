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

if (!setting_bool('public_results', true) && !current_user()) {
    page_start('Rangliste', 'public', 'index.php');
    echo '<div class="panel"><h2>Noch keine Rangliste</h2><p class="lead">Die Resultate werden nach dem Wettbewerb aufgeschaltet.</p></div>';
    page_end();
    exit;
}

$types = all_model_types();
$scope  = get('typ', setting('ranking_scope', 'group') === 'overall' ? 'alle' : (string) ($types[0]['id'] ?? 'alle'));

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

page_start('Rangliste', 'public', 'index.php', true);
$competitionQS = (int) $competition['id'] !== current_competition_id() ? '&competition=' . (int) $competition['id'] : '';
?>
<div class="row-between no-print">
    <div>
        <h2>Rangliste<?= count($competitions) > 1 ? ' – Wettbewerb ' . h($competition['name']) : '' ?></h2>
        <p class="lead"><?= h(rules_summary()) ?>. Wenige Punkte sind gut.</p>
    </div>
    <div class="btn-row dense">
        <?php if (count($types) > 0): ?>
            <a class="btn <?= $scope === 'alle' ? '' : 'ghost' ?>" href="?typ=alle<?= $competitionQS ?>">Gesamtwertung</a>
            <?php foreach ($types as $g): ?>
                <a class="btn <?= (string) $g['id'] === (string) $scope ? '' : 'ghost' ?>" href="?typ=<?= (int) $g['id'] ?><?= $competitionQS ?>"><?= h($g['name']) ?></a>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if (count($competitions) > 1): ?>
            <form method="get" class="dense" style="display:inline-block">
                <?php if ($scope !== ''): ?><input type="hidden" name="typ" value="<?= h($scope) ?>"><?php endif; ?>
                <select name="competition" data-auto-submit style="width:auto;display:inline-block">
                    <?php foreach ($competitions as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === (int) $competition['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
        <a class="btn ghost" href="javascript:window.print()">Drucken</a>
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
                            $scored = $c['status'] === 'flown' && !$c['motor'];
                            $cls = 'num' . ($isDrop ? ' dropped' : '') . ($scored ? '' : ' cell-missing');
                            $title = $c['missing']
                                ? 'Kein Resultat erfasst'
                                : ($scored
                                    ? 'Zeit ' . fmt_time($c['time']) . ', Landewert ' . fmt_num($c['dist'])
                                    : score_outcome_label($c['status'], $c['motor']));
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
<?php page_end();
