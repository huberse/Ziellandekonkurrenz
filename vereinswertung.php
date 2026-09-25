<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/scoring.php';
require_once __DIR__ . '/lib/layout.php';

if (!schema_has_competitions()) {
    redirect('upgrade.php');
}

$competition = resolve_competition_param(competition_request_param());
$competitions = all_competitions();

if (!setting_bool('club_ranking_enabled', true) && !current_user()) {
    redirect('index.php');
}

if (!setting_bool('public_results', true) && !current_user()) {
    page_start('Vereinswertung', 'public', 'vereinswertung.php');
    echo '<div class="panel"><h2>Noch keine Rangliste</h2><p class="lead">Die Resultate werden nach dem Wettbewerb aufgeschaltet.</p></div>';
    page_end();
    exit;
}

$types  = all_model_types();
$typ    = get('typ', 'alle');
$typeId = $typ !== 'alle' && $typ !== '' ? (int) $typ : null;

$data  = build_club_ranking($typeId, $competition['id']);
$count = $data['count'];

page_start('Vereinswertung', 'public', 'vereinswertung.php', true);
$competitionQS = (int) $competition['id'] !== current_competition_id() ? '&competition=' . (int) $competition['id'] : '';
?>
<div class="row-between no-print">
    <div>
        <h2>Vereinswertung<?= count($competitions) > 1 ? ' – Wettbewerb ' . h($competition['name']) : '' ?></h2>
        <p class="lead">Die <?= $count ?> besten Piloten eines Vereins ergeben zusammen das Vereinsresultat.
            Wenige Punkte sind gut.</p>
    </div>
    <div class="btn-row dense">
        <a class="btn <?= $typeId === null ? '' : 'ghost' ?>" href="?typ=alle<?= $competitionQS ?>">Alle Modelltypen</a>
        <?php foreach ($types as $t): ?>
            <a class="btn <?= $typeId === (int) $t['id'] ? '' : 'ghost' ?>" href="?typ=<?= (int) $t['id'] ?><?= $competitionQS ?>"><?= h($t['name']) ?></a>
        <?php endforeach; ?>
        <?php if (count($competitions) > 1): ?>
            <form method="get" style="display:inline-block">
                <input type="hidden" name="typ" value="<?= h($typ) ?>">
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

<div class="panel" style="padding:0">
<?php if (!$data['rows']): ?>
    <p class="lead" style="padding:20px">Noch keine gewerteten Resultate.</p>
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
        <?php foreach ($data['rows'] as $r):
            $podium = $r['rank'] && $r['rank'] <= 3 ? ' podium-' . $r['rank'] : ''; ?>
            <tr class="<?= $podium ?>">
                <td class="rank"><?= $r['rank'] ? (int) $r['rank'] : '–' ?></td>
                <td>
                    <b><?= h($r['name']) ?></b>
                    <?php if (!$r['ranked']): ?>
                        <br><span class="small cell-missing">ausser Konkurrenz, nur <?= (int) $r['available'] ?> von <?= $count ?> Piloten</span>
                    <?php endif; ?>
                </td>
                <td class="small">
                    <?php $parts = [];
                    foreach ($r['scoring'] as $row) {
                        $parts[] = h(full_name($row['pilot'])) . ' <span class="muted">' . h(fmt_num($row['total'])) . '</span>';
                    }
                    echo implode(' · ', $parts); ?>
                </td>
                <td class="num muted"><?= (int) $r['available'] ?></td>
                <td class="num total"><?= $r['ranked'] ? h(fmt_num($r['total'])) : '–' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</div>
<p class="small muted">Ein Verein braucht <?= $count ?> Piloten mit mindestens einem Resultat, um gewertet zu werden.</p>
<?php page_end();
