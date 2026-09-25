<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/scoring.php';
require_once __DIR__ . '/../lib/layout.php';
require_login();

/** Legt fehlende Durchgänge einer Wettbewerb an und entfernt überzählige, sofern noch ohne Resultate. */
function sync_rounds(int $competitionId, int $count): array
{
    $count = max(1, min(30, $count));
    $target = (int) setting('default_target_time', 180);
    $existing = all_rounds($competitionId);
    $have = count($existing);
    $added = 0; $removed = 0; $kept = 0;

    for ($i = $have + 1; $i <= $count; $i++) {
        $st = db()->prepare('INSERT IGNORE INTO rounds (competition_id, round_number, target_time_seconds) VALUES (?, ?, ?)');
        $st->execute([$competitionId, $i, $target]);
        $added++;
    }

    if ($count < $have) {
        foreach (array_reverse($existing) as $r) {
            if ((int) $r['round_number'] <= $count) {
                break;
            }
            $st = db()->prepare('SELECT COUNT(*) FROM scores WHERE round_id = ?');
            $st->execute([$r['id']]);
            if ((int) $st->fetchColumn() > 0) {
                $kept++;
                continue;
            }
            $d = db()->prepare('DELETE FROM rounds WHERE id = ?');
            $d->execute([$r['id']]);
            $removed++;
        }
    }
    return [$added, $removed, $kept];
}

$competition = resolve_competition_param(competition_request_param());
$competitionCompleted = competition_is_completed((int) $competition['id']);
$competitionQS = '?competition=' . $competition['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($competitionCompleted) {
        flash('Dieser Wettbewerb ist abgeschlossen. Durchgänge und Wertung sind gesperrt.', 'err');
        redirect('wettbewerbe.php?competition=' . (int) $competition['id']);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        lock_open_competition($pdo, (int) $competition['id']);
        $action = post('action');

        if ($action === 'count') {
            $count = (int) post('rounds_count', '5');
            setting_set('rounds_count', (string) max(1, min(30, $count)));
            [$a, $r, $k] = sync_rounds($competition['id'], $count);
            $msg = "Durchgänge angepasst: $a neu, $r entfernt.";
            if ($k) { $msg .= " $k konnten nicht entfernt werden, weil bereits Resultate erfasst sind."; }
            flash($msg, $k ? 'info' : 'ok');

        } elseif ($action === 'targets') {
            $st = $pdo->prepare('UPDATE rounds SET target_time_seconds = ?, note = ?, is_included = ? WHERE id = ? AND competition_id = ?');
            foreach (is_array($_POST['target'] ?? null) ? $_POST['target'] : [] as $rid => $raw) {
                $rid = (int) $rid;
                $sec = is_scalar($raw) ? parse_time((string) $raw) : null;
                if ($sec === null || !is_finite($sec) || $sec <= 0 || $sec > 999999.9) { continue; }
                $noteRaw = $_POST['note'][$rid] ?? '';
                $note = is_scalar($noteRaw) ? text_limit((string) $noteRaw, 160) : '';
                $inc = isset($_POST['included'][$rid]) ? 1 : 0;
                $st->execute([(int) round($sec), $note ?: null, $inc, $rid, $competition['id']]);
            }
            flash('Durchgänge gespeichert. Bereits erfasste Resultate wurden nicht neu berechnet.', 'ok');

        } elseif ($action === 'activate') {
            $pdo->prepare('UPDATE rounds SET is_active = 0 WHERE competition_id = ?')->execute([$competition['id']]);
            $rid = (int) post('round_id');
            if ($rid) {
                $st = $pdo->prepare('UPDATE rounds SET is_active = 1 WHERE id = ? AND competition_id = ?');
                $st->execute([$rid, $competition['id']]);
            }
            flash($rid ? 'Durchgang freigegeben.' : 'Kein Durchgang mehr freigegeben.', 'ok');

        } elseif ($action === 'recalc') {
            $rid = (int) post('round_id');
            $st = $pdo->prepare('SELECT s.*, r.target_time_seconds FROM scores s
                                 JOIN rounds r ON r.id = s.round_id WHERE s.round_id = ? AND r.competition_id = ?');
            $st->execute([$rid, $competition['id']]);
            $up = $pdo->prepare('UPDATE scores SET time_penalty = ?, landing_penalty = ?, penalty = ? WHERE id = ?');
            $n = 0;
            foreach ($st as $s) {
                [$tp, $lp, $tot] = calc_penalty($s['status'],
                    $s['flight_time_seconds'] !== null ? (float) $s['flight_time_seconds'] : null,
                    $s['landing_value'] !== null ? (float) $s['landing_value'] : null,
                    (int) $s['target_time_seconds'],
                    (bool) ($s['motor'] ?? 0));
                $up->execute([$tp, $lp, $tot, $s['id']]);
                $n++;
            }
            flash("$n Resultate neu berechnet.", 'ok');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash($e instanceof DomainException
            ? $e->getMessage()
            : 'Die Durchgänge konnten nicht gespeichert werden. Bitte Eingaben prüfen.', 'err');
    }
    redirect('durchgaenge.php' . $competitionQS);
}

$rounds = all_rounds($competition['id']);
$counts = [];
foreach ($rounds as $r) {
    $st = db()->prepare('SELECT COUNT(*) FROM scores WHERE round_id = ?');
    $st->execute([$r['id']]);
    $counts[(int) $r['id']] = (int) $st->fetchColumn();
}

page_start('Durchgänge', 'admin', 'durchgaenge.php');
$notCurrent = (int) $competition['id'] !== current_competition_id();
?>
<h2>Durchgänge<?= $notCurrent ? ' – Wettbewerb ' . h($competition['name']) : '' ?></h2>
<p class="lead">Anzahl, Zielzeit und Wertung der einzelnen Durchgänge.</p>
<?php if ($notCurrent): ?>
    <div class="flash info">Achtung: Du bearbeitest den Wettbewerb „<?= h($competition['name']) ?>“, nicht den aktiven Wettbewerb.
        <a href="wettbewerbe.php">Wettbewerbe ansehen</a></div>
<?php endif; ?>
<?php if ($competitionCompleted): ?>
    <div class="flash info">Dieser Wettbewerb ist abgeschlossen. Durchgänge, Zielzeiten und Wertung sind gesperrt.</div>
<?php endif; ?>

<fieldset class="readonly-operations"<?= $competitionCompleted ? ' disabled' : '' ?>>
<div class="panel">
    <h3 style="margin-top:0">Anzahl Durchgänge</h3>
    <form method="post" class="btn-row">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="count">
        <input type="hidden" name="competition" value="<?= (int) $competition['id'] ?>">
        <input type="number" name="rounds_count" min="1" max="30" style="width:6rem"
               value="<?= (int) setting('rounds_count', 5) ?>">
        <button class="btn" type="submit">Übernehmen</button>
        <span class="hint">Neue Durchgänge werden mit der Standard-Zielzeit
            <?= h(fmt_time(setting_num('default_target_time', 180))) ?> angelegt.
            Durchgänge mit Resultaten bleiben erhalten.</span>
    </form>
</div>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="targets">
    <input type="hidden" name="competition" value="<?= (int) $competition['id'] ?>">
    <div class="panel" style="padding:0">
        <div class="table-scroll">
        <table class="data dense">
            <thead>
            <tr>
                <th>Durchgang</th><th>Zielzeit</th><th class="mid">Zählt für die Rangliste</th>
                <th>Bemerkung</th><th class="num">Resultate</th><th class="mid">Status</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rounds as $r): $rid = (int) $r['id']; ?>
                <tr>
                    <td><b>DG <?= (int) $r['round_number'] ?></b></td>
                    <td><input type="text" name="target[<?= $rid ?>]" style="width:7rem"
                               value="<?= h(fmt_time((float) $r['target_time_seconds'])) ?>" inputmode="decimal"></td>
                    <td class="mid"><input type="checkbox" name="included[<?= $rid ?>]" <?= $r['is_included'] ? 'checked' : '' ?>></td>
                    <td><input type="text" name="note[<?= $rid ?>]" value="<?= h($r['note'] ?? '') ?>" placeholder="z.B. abgebrochen wegen Regen"></td>
                    <td class="num"><?= $counts[$rid] ?></td>
                    <td class="mid">
                        <?php if ($r['is_active']): ?><span class="tag live">freigegeben</span><?php endif; ?>
                    </td>
                    <td class="nowrap">
                        <a class="btn ghost" href="erfassung.php?dg=<?= $rid ?><?= $notCurrent ? '&competition=' . $competition['id'] : '' ?>">Erfassen</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <div class="btn-row"><button class="btn" type="submit">Zielzeiten und Wertung speichern</button></div>
</form>

<div class="split" style="margin-top:20px">
    <div class="panel">
        <h3 style="margin-top:0">Freigegebener Durchgang</h3>
        <p class="lead">Markiert, welcher Durchgang gerade geflogen wird. Die Erfassungsseite öffnet standardmässig diesen.</p>
        <form method="post" class="btn-row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="activate">
            <input type="hidden" name="competition" value="<?= (int) $competition['id'] ?>">
            <select name="round_id" style="width:auto">
                <option value="0">– keiner –</option>
                <?php foreach ($rounds as $r): ?>
                    <option value="<?= (int) $r['id'] ?>" <?= $r['is_active'] ? 'selected' : '' ?>>
                        Durchgang <?= (int) $r['round_number'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn ghost" type="submit">Setzen</button>
        </form>
    </div>

    <div class="panel">
        <h3 style="margin-top:0">Punkte neu berechnen</h3>
        <p class="lead">Nach einer Änderung der Zielzeit oder der Strafpunkt-Regeln bleiben bereits erfasste Punkte
            stehen. Hier rechnest du einen Durchgang mit den aktuellen Regeln nach.</p>
        <form method="post" class="btn-row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="recalc">
            <input type="hidden" name="competition" value="<?= (int) $competition['id'] ?>">
            <select name="round_id" style="width:auto">
                <?php foreach ($rounds as $r): ?>
                    <option value="<?= (int) $r['id'] ?>">Durchgang <?= (int) $r['round_number'] ?> (<?= $counts[(int) $r['id']] ?>)</option>
                <?php endforeach; ?>
            </select>
            <button class="btn ghost" type="submit">Neu berechnen</button>
        </form>
    </div>
</div>
</fieldset>
<?php page_end();
