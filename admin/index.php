<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/scoring.php';
require_once __DIR__ . '/../lib/layout.php';
require_login();

// Ein Konto, dessen Verein noch keinen Wettbewerb hat, konnte sich nicht
// einmal anmelden: require_competition_access() schickt es auf 'index.php'
// zurueck - und index.php ist diese Seite. Der Browser gab nach rund 20
// Umleitungen auf und zeigte ERR_TOO_MANY_REDIRECTS.
//
// Deshalb wird der Fall vorher erkannt und hier erklaert statt umgeleitet. Alle
// anderen Seiten (Erfassung, Startliste, Durchgaenge, Einstellungen, Export)
// leiten weiterhin hierher, also ist das auch ihr Weg heraus - die Schleife
// kann es damit nicht mehr geben.
$keinZugang = !is_superadmin() && accessible_competitions() === [];

if ($keinZugang) {
    $competition = ['id' => 0, 'name' => '', 'is_current' => 0];
    $competitionCompleted = false;
    $resultProgress = [];
    $competitionQS = '';
    $notCurrent = false;
    $pilotCount = 0;
    $rounds = [];
    $pending = 0;
    $progress = [];
    $active = null;
    $recent = [];
} else {
    $competition = resolve_competition_param(competition_request_param(), true);
    // Die Seite zeigt Startliste, Fortschritt und Resultate genau dieses
    // Wettbewerbs. Die Verwaltungsprüfung steht deshalb hier und nicht versteckt in
    // resolve_competition_param(), wo sie auch die öffentlichen Seiten betroffen hätte.
    require_competition_access((int) $competition['id']);
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

    $recentStmt = db()->prepare('SELECT s.*, r.round_number, pr.first_name, pr.last_name, pr.smv_number, p.bib_number
                           FROM scores s
                           JOIN rounds r ON r.id = s.round_id
                           JOIN pilots p ON p.id = s.pilot_id
                           JOIN pilot_profiles pr ON pr.id = p.profile_id
                           WHERE r.competition_id = ?
                           ORDER BY s.updated_at DESC, s.id DESC LIMIT 12');
    $recentStmt->execute([$competition['id']]);
    $recent = $recentStmt->fetchAll();
}

// Die Weiterleitung muss VOR page_start() gesetzt werden, weil der Meta-Tag in
// den <head> gehoert. Deshalb steht sie hier im PHP-Teil und nicht unten bei
// der Ausgabe.
if ($keinZugang) {
    seite_weiterleitung(3, 'wettbewerbe.php');
}
page_start('Übersicht', 'admin', 'index.php');
?>
<h2>Übersicht</h2>
<?php if ($keinZugang): ?>
    <?php // Ein Konto ohne Wettbewerb. Vorher stand hier die Schleife.
          // Die drei Sekunden und die Meldung sind dieselben wie auf den
          // anderen Seiten, damit man nicht an zwei Orten zwei Faelle lernt. ?>
    <p class="lead">Dein Verein hat noch keinen Wettbewerb.</p>
    <p class="small muted">Solange keiner da ist, gibt es für dich nichts zu erfassen, keine
        Startliste und keine Durchgänge. Der SuperAdmin legt unter <em>Wettbewerbe</em> einen an
        und setzt ihn auf deinen Verein; danach erscheint er hier. In drei Sekunden geht es von
        selbst dorthin.</p>
    <p><a class="btn" href="wettbewerbe.php">Wettbewerbe ansehen</a></p>
    <?php page_end(); return; ?>
<?php endif; ?>
<?php if ((int) $competition['id'] === 0): ?>
    <?php // Ohne Wettbewerb gab es vorher eine Tafel mit lauter Nullen: 0 Piloten,
          // 0/0 Resultate, 0 Durchgaenge. Das sah aus wie ein frisch angelegter
          // Wettbewerb, obwohl ueberhaupt keiner da war - man haette stundenlang
          // nach dem Eintrag gesucht, den es nicht gibt. Das ist nach "Betrieb auf
          // null setzen" der Normalfall und kein Randfall.
          //
          // Die Strafpunktregeln stehen hier bewusst nicht: ohne Wettbewerb
          // gehoeren sie zu keinem, und eine Tabelle ohne Gegenstand verwirrt
          // mehr, als sie hilft. ?>
    <p class="lead">Es ist noch kein Wettbewerb angelegt.</p>
    <p class="small muted">Solange keiner da ist, gibt es nichts zu erfassen und keine Startliste.
        Die Regiorangliste richtet sich aus den Wettbewerben, die zum Regiocup gehören – auch
        sie ist deshalb noch leer.</p>
    <p><a class="btn big" href="wettbewerbe.php">Wettbewerb anlegen</a></p>
    <?php page_end(); return; ?>
<?php endif; ?>
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
            // "beendet" waere bei einem abgesagten Wettbewerb falsch: er wurde
            // nicht zu Ende geflogen, sondern fiel aus.
            ? (!empty($competition['cancelled_at'])
                ? 'Wettbewerb abgesagt, es läuft kein Durchgang'
                : 'Wettbewerb beendet, es läuft kein Durchgang')
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
                        <td class="num"><?= score_is_flown(score_flags($s)) && empty($s['motor'])
                            ? h(fmt_time((float) $s['flight_time_seconds']))
                            : '<span class="cell-missing">' . h(score_outcome_label(score_flags($s))) . '</span>' ?></td>
                        <td class="num"><?= score_is_flown(score_flags($s)) && empty($s['motor']) ? h(fmt_num($s['landing_value'])) : '–' ?></td>
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
