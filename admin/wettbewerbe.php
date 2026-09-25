<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/scoring.php';
require_once __DIR__ . '/../lib/layout.php';
require_login();
$competition = resolve_competition_param(competition_request_param());
$competitionQS = (int) $competition['id'] !== current_competition_id() ? '?competition=' . (int) $competition['id'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'create') {
        $name = text_limit(post('name'), 160);
        $count = (int) post('rounds_count', '5');
        $targetValue = parse_time(post('target_time'));
        if ($name === '') {
            flash('Der Wettbewerb braucht einen Namen, zum Beispiel das Jahr.', 'err');
        } elseif ($targetValue === null || !is_finite($targetValue) || $targetValue <= 0 || $targetValue > 999999.9) {
            flash('Die Zielzeit muss zwischen 0 und 999999.9 Sekunden liegen.', 'err');
        } else {
            $target = (int) round($targetValue);
            try {
                $id = create_competition($name, $count, $target > 0 ? $target : 180, true);
                $competitionQS = '';
                flash("Wettbewerb „{$name}“ angelegt und aktiv gesetzt. Die Startliste ist leer, bis sich Piloten für diesen Wettbewerb anmelden.", 'ok');
            } catch (Throwable $e) {
                flash('Der Wettbewerb konnte nicht angelegt werden. Bitte Eingaben und Datenbank prüfen.', 'err');
            }
        }
    } elseif ($action === 'activate') {
        $id = (int) post('id');
        try {
            if (!find_competition($id)) {
                throw new RuntimeException('Wettbewerb nicht gefunden.');
            }
            set_current_competition($id);
            $competitionQS = '';
            flash('Wettbewerb aktiviert. Erfassung und Ranglisten zeigen jetzt diesen Wettbewerb.', 'ok');
        } catch (Throwable $e) {
            flash($e->getMessage(), 'err');
        }
    } elseif ($action === 'complete') {
        try {
            complete_competition((int) post('id'));
            flash('Wettbewerb abgeschlossen. Die Ergebnisse bleiben gespeichert und sind jetzt gesperrt.', 'ok');
        } catch (Throwable $e) {
            flash($e->getMessage(), 'err');
        }
    } elseif ($action === 'reopen') {
        try {
            reopen_competition((int) post('id'));
            flash('Wettbewerb wieder geöffnet.', 'ok');
        } catch (Throwable $e) {
            flash($e->getMessage(), 'err');
        }
    } elseif ($action === 'rename') {
        $id = (int) post('id');
        $name = text_limit(post('name'), 160);
        if ($name !== '') {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                $lock = $pdo->prepare('SELECT id FROM competitions WHERE id = ? FOR UPDATE');
                $lock->execute([$id]);
                if (!$lock->fetchColumn()) {
                    throw new RuntimeException('Wettbewerb nicht gefunden.');
                }
                $st = $pdo->prepare('UPDATE competitions SET name = ? WHERE id = ?');
                $st->execute([$name, $id]);
                if ($st->rowCount() !== 1 && !find_competition($id)) {
                    throw new RuntimeException('Wettbewerb nicht gefunden.');
                }
                $st = $pdo->prepare('INSERT INTO competition_settings (competition_id, skey, svalue) VALUES (?, ?, ?)
                                     ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
                $st->execute([$id, 'competition_name', $name]);
                $pdo->commit();
                flash('Wettbewerb umbenannt.', 'ok');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                flash('Der Wettbewerb konnte nicht umbenannt werden.', 'err');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) post('id');
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $check = $pdo->prepare('SELECT * FROM competitions WHERE id = ? FOR UPDATE');
            $check->execute([$id]);
            $competition = $check->fetch();
            if (!$competition) {
                throw new DomainException('Wettbewerb nicht gefunden.');
            }
            if ($competition['completed_at'] !== null) {
                throw new DomainException('Abgeschlossene Wettbewerbe werden als Archiv nicht gelöscht.');
            }

            $registrationStmt = $pdo->prepare('SELECT COUNT(*) FROM registrations WHERE competition_id = ?');
            $registrationStmt->execute([$id]);
            $registrationCount = (int) $registrationStmt->fetchColumn();
            $scoreStmt = $pdo->prepare('SELECT COUNT(*) FROM scores WHERE competition_id = ?');
            $scoreStmt->execute([$id]);
            $scoreCount = (int) $scoreStmt->fetchColumn();
            if ($scoreCount > 0 || $registrationCount > 0) {
                $reason = $scoreCount > 0 ? 'Resultate' : 'Anmeldungen';
                throw new DomainException('Dieser Wettbewerb hat noch ' . $reason
                    . ' und wird nicht gelöscht. Bitte die Daten zuerst bereinigen.');
            }

            $wasCurrent = (bool) $competition['is_current'];
            $latest = null;
            if ($wasCurrent) {
                $latestStmt = $pdo->prepare('SELECT id FROM competitions WHERE id <> ? AND completed_at IS NULL ORDER BY id DESC LIMIT 1');
                $latestStmt->execute([$id]);
                $latest = $latestStmt->fetchColumn();
                if (!$latest) {
                    throw new DomainException('Es ist kein weiterer offener Wettbewerb vorhanden; dieser Wettbewerb kann nicht gelöscht werden.');
                }
            }
            $pdo->prepare('DELETE FROM competitions WHERE id = ?')->execute([$id]);
            if ($wasCurrent) {
                $pdo->exec('UPDATE competitions SET is_current = 0');
                $pdo->prepare('UPDATE competitions SET is_current = 1 WHERE id = ?')->execute([(int) $latest]);
            }
            $pdo->commit();
            flash('Wettbewerb gelöscht.', 'ok');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash($e instanceof DomainException ? $e->getMessage() : 'Der Wettbewerb konnte nicht gelöscht werden.', 'err');
        }
    }
    redirect('wettbewerbe.php' . $competitionQS);
}

$competitions = all_competitions();
$countsById = competition_counts();
$progressById = [];
foreach ($competitions as $s) {
    $progressById[(int) $s['id']] = competition_result_progress((int) $s['id']);
}

page_start('Wettbewerbe', 'admin', 'wettbewerbe.php');
?>
<h2>Wettbewerbe</h2>
<p class="lead">Ein Wettbewerb hat eine eigene Startliste, eigene Durchgänge und eigene Einstellungen; Vereine und
    Modelltypen bleiben über alle Wettbewerbe bestehen. Ein neuer Wettbewerb beginnt leer – jeder Pilot meldet sich
    für ihn wieder neu an. Sobald für jeden Piloten in jedem gewerteten Durchgang ein Resultat vorliegt, kann der
    Wettbewerb beendet werden; danach bleibt er als Archiv erhalten.</p>

<div class="panel">
    <h3 style="margin-top:0">Neuen Wettbewerb anlegen</h3>
    <p class="lead">Wird sofort zum aktiven Wettbewerb. Erfassung, Durchgänge und Rangliste zeigen danach diesen Wettbewerb;
        ältere Wettbewerbe bleiben über die Liste unten erreichbar.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="competition" value="<?= (int) $competition['id'] ?>">
        <div class="grid-2">
            <div class="field">
                <label for="n">Name</label>
                <input type="text" id="n" name="name" maxlength="160" placeholder="MFV Brislach - Schwarzbubenfliegen <?= (int) date('Y') + 1 ?>" required>
                <p class="hint">Der Name darf den Veranstalter und das Jahr enthalten, z.B. „MG Breitenbach - Erlencup <?= (int) date('Y') + 1 ?>“.</p>
            </div>
            <div class="field">
                <label for="rc">Anzahl Durchgänge</label>
                <input type="number" id="rc" name="rounds_count" min="1" max="30" value="<?= (int) setting('rounds_count', 5) ?>">
            </div>
            <div class="field">
                <label for="tt">Standard-Zielzeit</label>
                <input type="text" id="tt" name="target_time" value="<?= h(fmt_time(setting_num('default_target_time', 180))) ?>">
            </div>
        </div>
        <button class="btn big" type="submit">Wettbewerb anlegen und aktivieren</button>
    </form>
</div>

<div class="competition-cards">
    <?php foreach ($competitions as $s):
        $sid = (int) $s['id'];
        $counts = $countsById[$sid] ?? ['rounds' => 0, 'scores' => 0, 'registrations' => 0];
        $progress = $progressById[$sid];
        $completed = $s['completed_at'] !== null;
        $facts = [$counts['rounds'] . ($counts['rounds'] === 1 ? ' Durchgang' : ' Durchgänge')];
        if ($counts['registrations'] > 0) {
            $facts[] = $counts['registrations'] . ($counts['registrations'] === 1 ? ' offene Anmeldung' : ' offene Anmeldungen');
        }
    ?>
        <div class="competition-card<?= $s['is_current'] ? ' current' : '' ?><?= $completed ? ' archived' : '' ?>">
            <div class="card-top">
                <?php if ($s['is_current']): ?>
                    <span class="tag on">aktiv</span>
                <?php elseif ($completed): ?>
                    <span class="tag off">beendet</span>
                <?php else: ?>
                    <span class="tag live">offen</span>
                <?php endif; ?>
                <span class="small muted"><?= h(implode(' · ', $facts)) ?></span>
            </div>

            <form method="post" class="competition-rename-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="id" value="<?= $sid ?>">
                <input type="hidden" name="competition" value="<?= $sid ?>">
                <input type="text" name="name" value="<?= h($s['name']) ?>" maxlength="160"
                       aria-label="Name des Wettbewerbs <?= h($s['name']) ?>" required>
                <button class="btn ghost small" type="submit">Speichern</button>
            </form>

            <?php if ($completed): ?>
                <p class="card-note small muted">Beendet am <?= h(date('d.m.Y H:i', strtotime($s['completed_at']))) ?>.</p>
            <?php elseif ((int) $progress['total'] === 0): ?>
                <p class="card-note small muted">Noch keine Piloten in der Startliste.</p>
            <?php else: ?>
                <div class="card-note">
                    <div class="progress<?= $progress['complete'] ? ' complete' : '' ?>">
                        <i style="width:<?= (int) $progress['percent'] ?>%"></i>
                    </div>
                    <p class="small muted" style="margin:4px 0 0"><?php if ($progress['complete']): ?>
                        Alle Resultate erfasst.
                    <?php else: ?>
                        <?= (int) $progress['completed'] ?> von <?= (int) $progress['total'] ?> Resultaten ·
                        <?= (int) $progress['missing'] ?> fehlen.
                    <?php endif; ?></p>
                </div>
            <?php endif; ?>

            <div class="card-actions no-print">
                <a class="btn ghost small" href="erfassung.php?competition=<?= $sid ?>">✎ Erfassen</a>
                <a class="btn ghost small" href="durchgaenge.php?competition=<?= $sid ?>">⚙ Durchgänge</a>
                <a class="btn ghost small" href="../index.php?competition=<?= $sid ?>">↗ Rangliste</a>
                <?php if ($completed): ?>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reopen">
                        <input type="hidden" name="id" value="<?= $sid ?>">
                        <input type="hidden" name="competition" value="<?= $sid ?>">
                        <button class="btn ghost small" type="submit"
                                data-confirm-click="Wettbewerb <?= h($s['name']) ?> wieder öffnen? Danach sind Ergebnisse und Anmeldungen wieder bearbeitbar.">↺ Wieder öffnen</button>
                    </form>
                <?php elseif ($progress['complete']): ?>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="complete">
                        <input type="hidden" name="id" value="<?= $sid ?>">
                        <input type="hidden" name="competition" value="<?= $sid ?>">
                        <button class="btn small" type="submit"
                                data-confirm-click="Wettbewerb <?= h($s['name']) ?> wirklich beenden? Danach sind Ergebnisse und Anmeldungen gesperrt.">✓ Beenden</button>
                    </form>
                <?php endif; ?>
                <?php if (!$s['is_current'] && !$completed): ?>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="activate">
                        <input type="hidden" name="id" value="<?= $sid ?>">
                        <input type="hidden" name="competition" value="<?= $sid ?>">
                        <button class="btn small" type="submit">◉ Aktivieren</button>
                    </form>
                <?php endif; ?>
                <?php if ($counts['scores'] === 0 && !$completed): ?>
                    <button class="btn danger small" type="submit" form="delform<?= $sid ?>"
                            data-confirm-click="Wettbewerb <?= h($s['name']) ?> ohne Resultate löschen?">✕ Löschen</button>
                    <form method="post" id="delform<?= $sid ?>" style="display:none">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $sid ?>">
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php if (!$competitions): ?>
    <p class="lead">Es ist noch kein Wettbewerb angelegt.</p>
<?php endif; ?>
<?php page_end();
