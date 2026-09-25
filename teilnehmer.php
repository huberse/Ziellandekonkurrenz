<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/scoring.php';
require_once __DIR__ . '/lib/layout.php';

if (!schema_has_competitions()) {
    redirect('upgrade.php');
}

$competition = resolve_competition_param(competition_request_param());
$competitions = all_competitions();
$competitionQS = (int) $competition['id'] !== current_competition_id() ? '?competition=' . (int) $competition['id'] : '';
$competitionCompleted = competition_is_completed((int) $competition['id']);

$st = db()->prepare('SELECT p.*, t.name AS model_type_name, c.name AS club_name
                       FROM pilots p
                       LEFT JOIN model_types t ON t.id = p.model_type_id
                       LEFT JOIN clubs c ON c.id = p.club_id
                       WHERE p.competition_id = ?
                       ORDER BY p.active DESC, t.sort_order, t.name, p.bib_number + 0, p.last_name');
$st->execute([$competition['id']]);
$pilots = $st->fetchAll();

page_start('Teilnehmer', 'public', 'teilnehmer.php');
?>
<div class="row-between no-print">
    <div>
        <h2>Teilnehmerliste<?= count($competitions) > 1 ? ' – Wettbewerb ' . h($competition['name']) : '' ?></h2>
        <p class="lead"><?= count($pilots) ?> Piloten in der Startliste<?= $competitionCompleted ? ' (Archiv)' : '' ?>.</p>
    </div>
    <div class="btn-row dense">
        <?php if (count($competitions) > 1): ?>
            <form method="get" style="display:inline-block">
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

<div class="panel">
<?php if (!$pilots): ?>
    <p class="lead">Noch niemand angemeldet.
        <?php if ($competitionCompleted): ?>Die Anmeldung für diesen Wettbewerb ist geschlossen.
        <?php else: ?><a href="anmeldung.php<?= $competitionQS ?>">Jetzt anmelden</a>.<?php endif; ?></p>
<?php else: ?>
    <div class="table-scroll">
    <table class="data">
        <thead><tr><th>Nr.</th><th>Pilot</th><th>Verein</th><th>Modelltyp</th><th>Modell</th></tr></thead>
        <tbody>
        <?php foreach ($pilots as $p): ?>
            <tr>
                <td class="num"><?= h($p['bib_number'] ?: '') ?></td>
                <td><?= h(full_name($p)) ?><?php if (!$p['active']): ?> <span class="tag off">inaktiv</span><?php endif; ?></td>
                <td class="muted"><?= h($p['club_name'] ?: '') ?></td>
                <td><?= h($p['model_type_name'] ?: '–') ?></td>
                <td class="muted"><?= h($p['model_name'] ?: '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</div>
<?php page_end();
