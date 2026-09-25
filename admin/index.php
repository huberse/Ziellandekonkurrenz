<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/scoring.php';
require_once __DIR__ . '/../lib/layout.php';
require_login();

$competition = resolve_competition_param(competition_request_param());
$competitionCompleted = competition_is_completed((int) $competition['id']);
$resultProgress = competition_result_progress((int) $competition['id']);
$competitionQS = (int) $competition['id'] !== current_competition_id() ? '&competition=' . (int) $competition['id'] : '';
$notCurrent = (int) $competition['id'] !== current_competition_id();
$pilotStmt = db()->prepare('SELECT COUNT(*) FROM pilots WHERE active = 1 AND competition_id = ?');
$pilotStmt->execute([$competition['id']]);
$pilotCount = (int) $pilotStmt->fetchColumn();
$rounds = all_rounds($competition['id']);
$pending = pending_registrations((int) $competition['id']);

// Fortschritt pro Durchgang
$progress = [];
foreach ($rounds as $r) {
    $st = db()->prepare('SELECT COUNT(*)
                         FROM scores s
                         JOIN pilots p ON p.id = s.pilot_id
                         WHERE s.round_id = ? AND s.competition_id = ?
                           AND p.competition_id = ? AND p.active = 1');
    $st->execute([$r['id'], $competition['id'], $competition['id']]);
    $progress[(int) $r['id']] = (int) $st->fetchColumn();
}

// Ein beendeter Wettbewerb hat keinen laufenden Durchgang. Die Markierung in der
// Datenbank bleibt erhalten, damit sie nach einem Wieder-Öffnen wieder da ist – hier
// darf sie aber nicht als „läuft gerade“ erscheinen.
$active = null;
if (!$competitionCompleted) {
    foreach ($rounds as $r) {
        if ($r['is_active']) { $active = $r; break; }
    }
}

$recent = db()->prepare('SELECT s.*, r.round_number, p.first_name, p.last_name, p.bib_number
                       FROM scores s
                       JOIN rounds r ON r.id = s.round_id
                       JOIN pilots p ON p.id = s.pilot_id
                       WHERE r.competition_id = ?
                       ORDER BY s.updated_at DESC, s.id DESC LIMIT 12');
$recent->execute([$competition['id']]);
$recent = $recent->fetchAll();

page_start('Übersicht', 'admin', 'index.php');
?>
<h2>Übersicht</h2>
<p class="lead"><?= h(rules_summary()) ?></p>
<?php if ($notCurrent): ?>
    <div class="flash info">Du bearbeitest den Wettbewerb „<?= h($competition['name']) ?>“, nicht den aktiven Wettbewerb.
        <a href="wettbewerbe.php">Wettbewerbe ansehen</a></div>
<?php endif; ?>
<?php if ($competitionCompleted): ?>
    <div class="flash info">Dieser Wettbewerb ist abgeschlossen. Ergebnisse, Startliste, Anmeldungen und Wettbewerbseinstellungen sind gesperrt; die Ansicht bleibt verfügbar.
        <a href="wettbewerbe.php?competition=<?= (int) $competition['id'] ?>">Abschlussstatus und Wieder-Öffnen</a></div>
<?php elseif ($resultProgress['complete']): ?>
    <div class="flash info">Alle Resultate für die aktiven Piloten und alle <?= (int) $resultProgress['rounds'] ?> gewerteten Durchgänge sind erfasst.
        <form method="post" action="wettbewerbe.php" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="complete">
            <input type="hidden" name="id" value="<?= (int) $competition['id'] ?>">
            <input type="hidden" name="competition" value="<?= (int) $competition['id'] ?>">
            <button class="btn small" type="submit"
                    data-confirm-click="Wettbewerb <?= h($competition['name']) ?> wirklich beenden? Danach sind Ergebnisse und Anmeldungen gesperrt.">Wettbewerb beenden</button>
        </form>
    </div>
<?php endif; ?>

<div class="stat-strip">
    <div class="stat"><b><?= $pilotCount ?></b><span>Piloten in der Startliste</span></div>
    <div class="stat">
        <b><?= (int) $resultProgress['completed'] ?>/<?= (int) $resultProgress['total'] ?></b>
        <span>Resultate erfasst</span>
        <?php if ($resultProgress['total'] > 0): ?>
            <div class="progress"><i style="width:<?= (int) $resultProgress['percent'] ?>%"></i></div>
        <?php endif; ?>
    </div>
    <div class="stat"><b><?= count($rounds) ?></b><span>Durchgänge geplant</span></div>
    <div class="stat">
        <b><?= $active ? (int) $active['round_number'] : '–' ?></b>
        <span><?= $competitionCompleted
            ? 'Wettbewerb beendet, es läuft kein Durchgang'
            : ($active ? 'läuft gerade, Zielzeit ' . fmt_time((float) $active['target_time_seconds']) : 'kein Durchgang freigegeben') ?></span>
        <?php if ($active && $pilotCount): $pc = min(100, (int) round($progress[(int) $active['id']] / $pilotCount * 100)); ?>
            <div class="progress"><i style="width:<?= $pc ?>%"></i></div>
            <span class="small"><?= $progress[(int) $active['id']] ?> von <?= $pilotCount ?> erfasst</span>
        <?php endif; ?>
    </div>
    <div class="stat"><b><?= $pending ?></b><span>offene Anmeldungen</span></div>
</div>

<div class="split">
    <div class="panel">
        <h3 style="margin-top:0">Erfassung</h3>
        <p class="lead">Resultate vom Papier in die Liste übertragen, ein Durchgang nach dem anderen.</p>
        <div class="rounds-strip">
            <?php foreach ($rounds as $r):
                $cls = 'round-chip' . ($r['is_active'] && !$competitionCompleted ? ' active' : '') . ($r['is_included'] ? '' : ' excluded'); ?>
                <a class="<?= $cls ?>" href="erfassung.php?dg=<?= (int) $r['id'] ?><?= $competitionQS ?>">
                    <b>Durchgang <?= (int) $r['round_number'] ?></b>
                    <span><?= $progress[(int) $r['id']] ?>/<?= $pilotCount ?> · <?= fmt_time((float) $r['target_time_seconds']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="btn-row dense">
            <a class="btn" href="erfassung.php<?= $notCurrent ? '?competition=' . (int) $competition['id'] : '' ?>"><?= $competitionCompleted ? 'Zur Erfassung' : 'Zum aktiven Durchgang' ?></a>
            <a class="btn ghost" href="laufzettel.php?format=pdf<?= $competitionQS ?>">Laufzettel-PDF</a>
            <a class="btn ghost" href="export.php?was=rangliste<?= $competitionQS ?>">Rangliste als CSV</a>
            <a class="btn ghost" href="export.php?was=einzelresultate<?= $competitionQS ?>">Einzelresultate als CSV</a>
            <a class="btn ghost" href="export.php?was=vereine<?= $competitionQS ?>">Vereinswertung als CSV</a>
            <a class="btn ghost" href="wettbewerbe.php?competition=<?= (int) $competition['id'] ?>">Wettbewerb verwalten</a>
        </div>
    </div>

    <div class="panel">
        <h3 style="margin-top:0">Zuletzt erfasst</h3>
        <?php if (!$recent): ?>
            <p class="lead">Noch keine Resultate. Fang mit <a href="erfassung.php<?= $notCurrent ? '?competition=' . (int) $competition['id'] : '' ?>">Durchgang 1</a> an.</p>
        <?php else: ?>
            <table class="data">
                <thead><tr><th class="num">DG</th><th>Pilot</th><th class="num">Zeit</th><th class="num">Landewert</th><th class="num">Punkte</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $s): ?>
                    <tr>
                        <td class="num"><?= (int) $s['round_number'] ?></td>
                        <td><?= h(trim($s['first_name'] . ' ' . $s['last_name'])) ?></td>
                        <td class="num"><?= $s['status'] === 'flown' && !$s['motor']
                            ? h(fmt_time((float) $s['flight_time_seconds']))
                            : '<span class="cell-missing">' . h(score_outcome_label((string) $s['status'], (bool) $s['motor'])) . '</span>' ?></td>
                        <td class="num"><?= $s['status'] === 'flown' && !$s['motor'] ? h(fmt_num($s['landing_value'])) : '–' ?></td>
                        <td class="num"><b><?= h(fmt_num($s['penalty'])) ?></b></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php if ($pending): ?>
    <div class="flash info">
        <?= $pending ?> Anmeldung<?= $pending > 1 ? 'en warten' : ' wartet' ?> auf Freigabe.
        <a href="anmeldungen.php<?= $notCurrent ? '?competition=' . (int) $competition['id'] : '' ?>">Jetzt ansehen</a>
    </div>
<?php endif; ?>

<?php page_end();
