<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/scoring.php';
require_once __DIR__ . '/../lib/layout.php';
require_login();
$competition = resolve_competition_param(competition_request_param(), true);
$competitionQS = (int) $competition['id'] !== current_competition_id() ? '?competition=' . (int) $competition['id'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'create') {
        $name = text_limit(post('name'), 160);
        $count = (int) post('rounds_count', '5');
        $targetValue = parse_time(post('target_time'));
        if (!is_superadmin() && user_club_id() === null) {
            flash('Deinem Konto ist noch kein Verein zugeordnet. Bitte wende dich an den SuperAdmin.', 'err');
        } elseif ($name === '') {
            flash('Der Wettbewerb braucht einen Namen, zum Beispiel das Jahr.', 'err');
        } elseif ($targetValue === null || !is_finite($targetValue) || $targetValue <= 0 || $targetValue > 999999.9) {
            flash('Die Zielzeit muss zwischen 0 und 999999.9 Sekunden liegen.', 'err');
        } else {
            $target = (int) round($targetValue);
            // Nur der SuperAdmin waehlt den Veranstalter; -1 bedeutet
            // ausdruecklich "ohne Verein". Alle anderen bekommen ihren
            // eigenen Verein automatisch und koennen nichts anderes waehlen.
            $clubId = null;
            if (is_superadmin()) {
                $clubId = (int) post('club_id', '-1');
                $clubId = $clubId > 0 ? $clubId : -1;
            }
            try {
                $id = create_competition($name, $count, $target > 0 ? $target : 180, true, $clubId,
                                        isset($_POST['region']));
                $competitionQS = '';
                // create_competition() aktiviert nur, wenn es sonst keinen
                // aktiven Wettbewerb gibt. Die Meldung sagt, was wirklich
                // eingetreten ist - und wie man weitermacht, wenn er offen
                // blieb. Jedes Stück bringt seinen eigenen Punkt mit, sonst
                // klebt der nächste Satz mitten dran.
                $st = db()->prepare('SELECT is_current FROM competitions WHERE id = ?');
                $st->execute([$id]);
                $aktiv = (int) $st->fetchColumn() === 1;
                $wie = $aktiv
                    ? 'und aktiv gesetzt. Erfassung und Rangliste zeigen jetzt diesen Wettbewerb.'
                    : 'und bleibt offen: der bisher aktive Wettbewerb läuft weiter. '
                      . (is_superadmin()
                          ? 'Aktivieren Sie ihn in der Liste unten, wenn er an der Reihe ist.'
                          : 'Der SuperAdmin aktiviert ihn in der Liste unten, wenn er an der Reihe ist.');
                $wer = $clubId !== null && $clubId > 0 ? 'Veranstalter: ' . club_name($clubId) . '. ' : '';
                $auch = isset($_POST['region']) ? 'Zählt zum Regiocup des Jahres. ' : '';
                flash("Wettbewerb „{$name}“ angelegt {$wie} {$wer}{$auch}"
                    . 'Die Startliste ist leer, bis sich Piloten für diesen Wettbewerb anmelden.', 'ok');
            } catch (Throwable $e) {
                flash($e instanceof DomainException
                    ? $e->getMessage()
                    : 'Der Wettbewerb konnte nicht angelegt werden. Bitte Eingaben und Datenbank prüfen.', 'err');
            }
        }
    } elseif ($action === 'activate') {
        // Nur der SuperAdmin. Aktivieren schaltet ALLE anderen Wettbewerbe auf
        // "nicht aktiv" - es ist ein Eingriff in den Betrieb aller Vereine und
        // nicht in den des eigenen. Ein Vereinskonto konnte das bisher, und ein
        // Klick hat einem anderen Verein mitten im Wettbewerbstag die Erfassung
        // weggenommen, ohne dass dieser etwas bemerkt hat.
        //
        // Der Knopf in der Kartenleiste ist fuer andere Konten nicht da; diese
        // Pruefung ist trotzdem noetig, weil ein Formular auch ohne Klick
        // abgeschickt werden kann.
        if (!is_superadmin()) {
            flash('Das Aktivieren ist dem SuperAdmin vorbehalten.', 'err');
        } else {
            $id = (int) post('id');
            try {
                if (!find_competition($id)) {
                    throw new RuntimeException('Wettbewerb nicht gefunden.');
                }
                require_competition_access($id);
                set_current_competition($id);
                $competitionQS = '';
                flash('Wettbewerb aktiviert. Erfassung und Rangliste zeigen jetzt diesen Wettbewerb.', 'ok');
            } catch (Throwable $e) {
                flash($e->getMessage(), 'err');
            }
        }
    } elseif ($action === 'complete' || $action === 'complete_trotzdem') {
        // "trotzdem" heisst: die Ergebnisse sind unvollstaendig, die Saison soll
        // trotzdem zu. Ohne diesen Weg gibt es bei einem Wettbewerb, dem ein
        // Pilot oder ein Durchgang fehlt, keinen Ausweg - und am Saisonende
        // bliebe genau dann ein Wettbewerb aktiv, den niemand mehr beenden
        // kann.
        $trotzdem = $action === 'complete_trotzdem';
        try {
            require_competition_access((int) post('id'));
            $stand = competition_result_progress((int) post('id'));
            complete_competition((int) post('id'), $trotzdem);
            $hinweis = $trotzdem && $stand['missing'] > 0
                ? ' ' . $stand['missing'] . ' Ergebnis(se) fehlen und bleiben für immer unausgewertet.'
                : '';
            flash('Wettbewerb abgeschlossen. Die Ergebnisse bleiben gespeichert und sind jetzt gesperrt.' . $hinweis, 'ok');
        } catch (Throwable $e) {
            flash($e->getMessage(), 'err');
        }
    } elseif ($action === 'cancel') {
        // Der Wettbewerb fand nicht statt, etwa wegen Wetter. Er wird wie ein
        // beendeter gesperrt; was geflogen wurde, bleibt und zaehlt weiter.
        try {
            require_competition_access((int) post('id'));
            $stand = cancel_competition((int) post('id'));
            $hinweis = $stand['missing'] > 0
                ? ' ' . $stand['missing'] . ' Ergebnis(se) fehlen und bleiben unausgewertet.'
                : '';
            // Der Regiocup-Ausschluss ist der Punkt, den man leicht übersieht
            // und dann später sucht, warum die Punkte fehlen.
            flash('Wettbewerb als abgesagt markiert. Er zählt nicht zum Regiocup.' . $hinweis
                . ' Fand er doch statt, lässt sich das mit „Fand doch statt“ zurücknehmen.', 'ok');
        } catch (Throwable $e) {
            flash($e->getMessage(), 'err');
        }
    } elseif ($action === 'occurred') {
        try {
            require_competition_access((int) post('id'));
            occurred_competition((int) post('id'));
            flash('Absage zurückgenommen. Der Wettbewerb gilt als stattgefunden.', 'ok');
        } catch (Throwable $e) {
            flash($e->getMessage(), 'err');
        }
    } elseif ($action === 'reopen') {
        try {
            require_competition_access((int) post('id'));
            reopen_competition((int) post('id'));
            flash('Wettbewerb wieder geöffnet.', 'ok');
        } catch (Throwable $e) {
            flash($e->getMessage(), 'err');
        }
    } elseif ($action === 'delete_all') {
        // Der SuperAdmin darf auch das, was sonst niemand darf: einen
        // beendeten Wettbewerb samt seiner Ergebnisse loeschen. Genau daran
        // scheitert sonst das Aufraeumen eines Testbestands, und genau das kam
        // aus dem Betrieb.
        //
        // Der normale Weg bleibt, wie er war: kein Loeschen von beendeten
        // Wettbewerben, keines mit Resultaten oder Anmeldungen. Wer dort nicht
        // weiterkommt und es wirklich meint, muss es ausdruecklich sagen - und
        // dann eben mit allem, was dranhaengt.
        if (!is_superadmin()) {
            flash('Das darf nur der SuperAdmin.', 'err');
        } else {
            $id = (int) post('id');
            $pdo = db();
            try {
                require_competition_access($id);
                $bestand = competition_bestand($id);
                $pdo->beginTransaction();
                $check = $pdo->prepare('SELECT * FROM competitions WHERE id = ? FOR UPDATE');
                $check->execute([$id]);
                $wettbewerb = $check->fetch();
                if (!$wettbewerb) {
                    throw new DomainException('Wettbewerb nicht gefunden.');
                }
                if ((int) $wettbewerb['is_current'] === 1) {
                    $offen = (int) $pdo->query('SELECT COUNT(*) FROM competitions
                                              WHERE completed_at IS NULL AND id <> ' . $id)->fetchColumn();
                    if ($offen === 0) {
                        throw new DomainException(
                            'Dieser Wettbewerb ist der einzige offene und kann nicht gelöscht werden. '
                            . 'Beende vorher einen anderen oder lege einen an.');
                    }
                }
                // Was mitgeht, wird nicht verschwiegen: Durchgaenge, Piloten
                // und Resultate fallen ueber die Fremdschluessel mit.
                //
                // Die Stammsaetze der Piloten bleiben stehen - sie gehoeren
                // nicht diesem einen Wettbewerb. Eine Anmeldung bleibt auch
                // stehen, verliert aber ihren Wettbewerb und taucht dadurch
                // nirgends mehr auf.
                $pdo->prepare('DELETE FROM competitions WHERE id = ?')->execute([$id]);
                $pdo->commit();
                flash(sprintf(
                    'Wettbewerb gelöscht: %d Durchgaenge, %d Piloten und %d Resultate sind mit weg. '
                    . 'Die Stammsaetze der Piloten bleiben erhalten.',
                    $bestand['rounds'], $bestand['pilots'], $bestand['scores']
                ) . ($bestand['registrations'] > 0
                    ? sprintf(' %d Anmeldung(en) gehören jetzt keinem Wettbewerb mehr.', $bestand['registrations'])
                    : ''), 'ok');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash($e instanceof DomainException ? $e->getMessage()
                    : 'Der Wettbewerb konnte nicht gelöscht werden.', 'err');
            }
        }
        redirect('wettbewerbe.php' . $competitionQS);
    } elseif ($action === 'region') {
        // Ein Wettbewerb kann nachträglich in den Regiocup aufgenommen oder
        // daraus genommen werden - das Jahr steht im Datum, nicht im Namen.
        $id = (int) post('id');
        // Den Wert selbst lesen, nicht isset(): isset('0') ist wahr, der String
        // "0" gilt als gesetzt. Mit isset stand hier immer 1, und der Umschalter
        // konnte nur einschalten, nie wieder ausschalten.
        $an = post('region', '0') === '1' ? 1 : 0;
        try {
            require_competition_access($id);
            $pdo = db();
            $st = $pdo->prepare('UPDATE competitions SET region = ? WHERE id = ?');
            $st->execute([$an, $id]);
            if ($st->rowCount() !== 1 && !find_competition($id)) {
                throw new RuntimeException('Wettbewerb nicht gefunden.');
            }
            flash($an
                ? 'Der Wettbewerb zählt jetzt zum Regiocup seines Jahres.'
                : 'Der Wettbewerb zählt nicht mehr zum Regiocup.', 'ok');
        } catch (Throwable $e) {
            flash($e->getMessage(), 'err');
        }
    } elseif ($action === 'rename') {
        $id = (int) post('id');
        $name = text_limit(post('name'), 160);
        if ($name !== '') {
            require_competition_access($id);
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
            require_competition_access($id);
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

$competitions = accessible_competitions();
$countsById = competition_counts();
$progressById = [];
foreach ($competitions as $s) {
    $progressById[(int) $s['id']] = competition_result_progress((int) $s['id']);
}
// Steht ueberhaupt schon einer auf "aktiv"? Nur dann loest das Aktivieren eines
// anderen etwas aus - und nur dann steht der Zusatz in der Rueckfrage.
// Ueber ALLE Wettbewerbe, nicht ueber die sichtbaren: massgeblich ist, ob
// irgendein Verein gerade erfasst, nicht ob man es selbst ist.
$irgendeinAktiver = (int) db()->query(
    'SELECT COUNT(*) FROM competitions
      WHERE is_current = 1 AND completed_at IS NULL AND cancelled_at IS NULL'
)->fetchColumn() > 0;

page_start('Wettbewerbe', 'admin', 'wettbewerbe.php');
?>
<h2>Wettbewerbe</h2>
<?php if (!is_superadmin() && user_club_id() === null): ?>
    <div class="flash err">Deinem Konto ist noch kein Verein zugeordnet. Du kannst keine Wettbewerbe anlegen oder
        bearbeiten, bis der SuperAdmin dir einen Verein zuweist.</div>
<?php endif; ?>
<p class="lead">Ein Wettbewerb hat eine eigene Startliste, eigene Durchgänge und eigene Einstellungen; Vereine und
    Modelltypen bleiben über alle Wettbewerbe bestehen. Ein neuer Wettbewerb beginnt leer – jeder Pilot meldet sich
    für ihn wieder neu an. Sobald für jeden Piloten in jedem gewerteten Durchgang ein Resultat vorliegt, kann der
    Wettbewerb beendet werden; danach bleibt er als Archiv erhalten.</p>

<div class="panel">
    <h3 style="margin-top:0">Neuen Wettbewerb anlegen</h3>
    <p class="lead">Der neue Wettbewerb ist zuerst <b>offen</b>, nicht aktiv: der bisher aktive Wettbewerb
        läuft unterbrechungsfrei weiter. <?= is_superadmin()
            ? 'Aktivieren Sie den neuen erst in der Liste unten, wenn er an der Reihe ist'
            : 'Der SuperAdmin aktiviert den neuen in der Liste unten, wenn er an der Reihe ist' ?>
        – dann zeigen Erfassung, Durchgänge und Rangliste ihn.</p>
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
            <?php if (is_superadmin()): ?>
            <div class="field">
                <label for="cv">Veranstalter</label>
                <select id="cv" name="club_id">
                    <option value="">– ohne Verein –</option>
                    <?php foreach (all_clubs() as $club): ?>
                        <option value="<?= (int) $club['id'] ?>"><?= h($club['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">Nur die Konten dieses Vereins dürfen den Wettbewerb steuern und bearbeiten.</p>
            </div>
            <?php else: ?>
            <div class="field">
                <label>Veranstalter</label>
                <p class="hint" style="margin:0">Wird automatisch dein Verein: <b><?= h((string) (current_user()['club_name'] ?? '')) ?></b></p>
            </div>
            <?php endif; ?>
        </div>
        <div class="check">
            <input type="checkbox" id="rg" name="region" value="1">
            <label for="rg">Dieser Wettbewerb zählt zum Regiocup</label>
            <p class="hint">Für die Regiowertung zählt das Jahr aus dem Wettbewerbsdatum, nicht der Name.
                Lass das Feld leer, wenn es ein Wettbewerb ohne Regiopunkte ist – später lässt sich das
                jederzeit ändern.</p>
        </div>
        <button class="btn big" type="submit">Wettbewerb anlegen</button>
    </form>
</div>

<div class="competition-cards">
    <?php foreach ($competitions as $s):
        $sid = (int) $s['id'];
        $counts = $countsById[$sid] ?? ['rounds' => 0, 'scores' => 0, 'registrations' => 0];
        $progress = $progressById[$sid];
        // Einmal je Karte, nicht viermal in der Rueckfrage.
        $bestand = competition_bestand($sid);
        $completed = $s['completed_at'] !== null;
        // "Abgesagt" heisst: fand nicht statt. Das ist etwas anderes als
        // "beendet" - dort sind alle Resultate da. Beide sind gesperrt, und
        // die Beschriftung sagt, welcher Fall vorliegt.
        $abgesagt = !empty($s['cancelled_at']);
        // Fuer den Knopf zaehlt nicht, ob "alles erfasst" gilt, sondern ob
        // ueberhaupt etwas fehlt. Eine Startliste ohne aktiven Piloten hat
        // nichts offen - da aber $progress['complete'] wegen der Bedingung
        // "pilots > 0" trotzdem falsch ist, wuerde sonst der Knopf
        // "Trotzdem beenden" dastehen und von 0 fehlenden Ergebnissen reden.
        $nichtsOffen = (int) $progress['missing'] === 0;
        $facts = [$counts['rounds'] . ($counts['rounds'] === 1 ? ' Durchgang' : ' Durchgänge')];
        if ($counts['registrations'] > 0) {
            $facts[] = $counts['registrations'] . ($counts['registrations'] === 1 ? ' offene Anmeldung' : ' offene Anmeldungen');
        }
        $imRegiocup = (int) ($s['region'] ?? 0) === 1;
    ?>
        <?php // Die Rahmenfarbe folgt demselben Vorrang: ein beendeter Wettbewerb
              // wird auch dann nicht als aktiver hervorgehoben. ?>
        <div class="competition-card<?= $s['is_current'] && !$completed ? ' current' : '' ?><?= $completed ? ' archived' : '' ?>">
            <div class="card-top">
                <span class="card-tags">
                    <?php // "beendet" vor "aktiv": ein beendeter Wettbewerb darf
                          // nicht als aktiv dastehen, auch wenn is_current noch
                          // so dasteht. Genau diese Reihenfolge hat den Eindruck
                          // "beendet, aber trotzdem aktiv" erzeugt. ?>
                    <?php if ($abgesagt): ?>
                        <span class="tag off">abgesagt</span>
                    <?php elseif ($completed): ?>
                        <span class="tag off">beendet</span>
                    <?php elseif ($s['is_current']): ?>
                        <span class="tag on">aktiv</span>
                    <?php else: ?>
                        <span class="tag live">offen</span>
                    <?php endif; ?>
                    <?php if ($imRegiocup): ?>
                        <span class="tag trophy" title="Zählt zum Regiocup seines Jahres">🏆 Regiocup</span>
                    <?php endif; ?>
                </span>
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
                <p class="card-note small muted">
                    <?php if ($abgesagt): ?>
                        Abgesagt am <?= h(date('d.m.Y', strtotime((string) $s['cancelled_at']))) ?>.
                        <?php if ($progress['missing'] > 0): ?>
                            <?= (int) $progress['missing'] ?> von <?= (int) $progress['total'] ?>
                            Ergebnissen fehlen. Der Wettbewerb zählt nicht zum Regiocup.
                        <?php else: ?>
                            Alle Ergebnisse liegen vor, gezählt wird er trotzdem nicht.
                        <?php endif; ?>
                    <?php else: ?>
                        Beendet am <?= h(date('d.m.Y H:i', strtotime((string) $s['completed_at']))) ?>.
                    <?php endif; ?>
                </p>
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
                <a class="btn ghost small" href="../rangliste.php?competition=<?= $sid ?>">↗ Rangliste</a>
                <?php // Fuenf Faelle, und jeder braucht andere Knoepfe. Die Kette
                      // wird bewusst in dieser Reihenfolge gebaut: ein
                      // abgesagter Wettbewerb ist auch abgeschlossen, faellt
                      // also durch jede andere Bedingung hindurch. ?>
                <?php if ($abgesagt): ?>
                    <?php // Zwei Wege zurueck, weil beides vorkommt: entweder fand
                          // der Wettbewerb doch statt, oder es gibt doch einen
                          // Ersatztermin. Beide heben die Absage auf. ?>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="occurred">
                        <input type="hidden" name="id" value="<?= $sid ?>">
                        <input type="hidden" name="competition" value="<?= $sid ?>">
                        <button class="btn ghost small" type="submit"
                                data-confirm-click="Absage zurücknehmen? <?= h($s['name']) ?> gilt dann wieder als stattgefunden und lässt sich wie jeder Wettbewerb beenden.">✓ Fand doch statt</button>
                    </form>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reopen">
                        <input type="hidden" name="id" value="<?= $sid ?>">
                        <input type="hidden" name="competition" value="<?= $sid ?>">
                        <button class="btn ghost small" type="submit"
                                data-confirm-click="Wettbewerb <?= h($s['name']) ?> wieder öffnen? Wird doch ein Ersatztermin gesucht, sind Startliste und Anmeldungen wieder bearbeitbar.">↺ Wieder öffnen</button>
                    </form>
                <?php elseif ($completed): ?>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reopen">
                        <input type="hidden" name="id" value="<?= $sid ?>">
                        <input type="hidden" name="competition" value="<?= $sid ?>">
                        <button class="btn ghost small" type="submit"
                                data-confirm-click="Wettbewerb <?= h($s['name']) ?> wieder öffnen? Danach sind Ergebnisse und Anmeldungen wieder bearbeitbar.">↺ Wieder öffnen</button>
                    </form>
                <?php elseif ($progress['complete']): ?>
                    <?php // Alle Ergebnisse liegen vor. Der Wettbewerb hat
                          // stattgefunden; "Abgesagt" gibt es hier bewusst
                          // nicht. ?>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="complete">
                        <input type="hidden" name="id" value="<?= $sid ?>">
                        <input type="hidden" name="competition" value="<?= $sid ?>">
                        <button class="btn small" type="submit"
                                data-confirm-click="Wettbewerb <?= h($s['name']) ?> wirklich beenden? Danach sind Ergebnisse und Anmeldungen gesperrt.">✓ Beenden</button>
                    </form>
                <?php elseif ($nichtsOffen): ?>
                    <?php // Nichts fehlt, es gibt nur nichts zu tun - etwa eine
                          // Startliste ohne aktiven Piloten. Das ist der
                          // normale Fall am Saisonende und darf nicht als
                          // "unvollstaendig" dastehen. ?>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="complete">
                        <input type="hidden" name="id" value="<?= $sid ?>">
                        <input type="hidden" name="competition" value="<?= $sid ?>">
                        <button class="btn small" type="submit"
                                data-confirm-click="Wettbewerb <?= h($s['name']) ?> beenden? Es sind keine Ergebnisse offen, danach ist er gesperrt.">✓ Beenden</button>
                    </form>
                <?php else: ?>
                    <?php // Hier fehlt etwas, also ist "Beenden" ohne
                          // Einschraenkung nicht an der Reihe. Der Ausweg
                          // heisst "Trotzdem beenden" - ohne den waere genau
                          // dieser Wettbewerb am Saisonende nie zu schliessen. ?>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="complete_trotzdem">
                        <input type="hidden" name="id" value="<?= $sid ?>">
                        <input type="hidden" name="competition" value="<?= $sid ?>">
                        <button class="btn ghost small" type="submit"
                                data-confirm-click="Wettbewerb <?= h($s['name']) ?> jetzt schon beenden? Es fehlen <?= (int) $progress['missing'] ?> Ergebnis(se). Die bleiben unausgewertet und lassen sich danach nicht mehr nachtragen. Nur wenn das so gewollt ist.">✓ Trotzdem beenden</button>
                    </form>
                <?php endif; ?>
                <?php // "Abgesagt" steht bei jedem offenen Wettbewerb, bei dem
                      // nicht alles erfasst ist - und nicht nur bei
                      // unvollstaendigen. Der haeufigste Fall ist der
                      // Wetterausfall vor dem ersten Durchgang: da fehlt gar
                      // nichts, es wurde nur nie geflogen. Ohne diesen Knopf
                      // waere genau dieser Wettbewerb nie als abgesagt zu
                      // markieren. ?>
                <?php if (!$abgesagt && !$completed && !$progress['complete']): ?>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="id" value="<?= $sid ?>">
                        <input type="hidden" name="competition" value="<?= $sid ?>">
                        <button class="btn ghost small" type="submit"
                                data-confirm-click="Wettbewerb <?= h($s['name']) ?> als abgesagt markieren? Er wird gesperrt wie ein beendeter und zählt nicht zum Regiocup. <?= (int) $progress['completed'] ?> von <?= (int) $progress['total'] ?> Ergebnissen sind erfasst; sie bleiben sichtbar, werden aber nicht gewertet.">☁ Abgesagt</button>
                    </form>
                <?php endif; ?>
                <?php // "Aktivieren" fehlte von 2.0.0 bis 2.0.7. Es war beim Umbau
                      // fuer "Abgesagt" weggefallen, ohne dass eine Meldung es
                      // angekuendigt haette - und der Text oben im Anlegeformular
                      // ("Aktivieren Sie den neuen erst in der Liste unten")
                      // versprach die ganze Zeit einen Knopf, den es nicht gab.
                      //
                      // Wer das nicht bemerkt hat, kam nie weiter: ein neuer
                      // Wettbewerb wird nur dann automatisch aktiv, wenn es
                      // ueberhaupt keinen aktiven gibt. Haelt ein anderer Verein
                      // den aktiven, blieb der neue fuer immer offen.
                      //
                      // Der Knopf gehoert dem SuperAdmin. Aktivieren heisst: alle
                      // anderen Wettbewerbe werden stillschweigend abgeschaltet -
                      // das ist ein Eingriff in den Betrieb aller Vereine und
                      // gehoert deshalb nicht in die Hand eines einzelnen. ?>
                <?php if (!$abgesagt && !$completed): ?>
                    <?php if ((int) $s['is_current'] === 0): ?>
                        <?php if (is_superadmin()): ?>
                        <form method="post" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="activate">
                            <input type="hidden" name="id" value="<?= $sid ?>">
                            <input type="hidden" name="competition" value="<?= $sid ?>">
                            <button class="btn small" type="submit"
                                    data-confirm-click="Wettbewerb <?= h($s['name']) ?> aktivieren? Erfassung, Durchgänge und Rangliste zeigen danach diesen Wettbewerb.<?= $irgendeinAktiver ? ' Der bisher aktive Wettbewerb wird abgeschaltet.' : '' ?>">◉ Aktivieren</button>
                        </form>
                        <?php else: ?>
                            <span class="hint" style="align-self:center">◉ Aktivieren – der SuperAdmin</span>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
                <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="region">
                    <input type="hidden" name="id" value="<?= $sid ?>">
                    <input type="hidden" name="competition" value="<?= $sid ?>">
                    <input type="hidden" name="region" value="<?= $imRegiocup ? '0' : '1' ?>">
                    <button class="btn small<?= $imRegiocup ? '' : ' ghost' ?>" type="submit"
                            data-confirm-click="<?= $imRegiocup
                                ? 'Wettbewerb ' . h($s['name']) . ' aus dem Regiocup nehmen? Er zählt dann nicht mehr zur Regiorangliste seines Jahres. Wieder aufnehmen geht jederzeit.'
                                : 'Wettbewerb ' . h($s['name']) . ' zum Regiocup hinzufügen? Er zählt dann zur Regiorangliste des Jahres, in dem er stattfindet.' ?>"
                    ><?= $imRegiocup ? '🏆 Regiocup' : '○ Regiocup' ?></button>
                </form>
<?php if ($counts['scores'] === 0 && !$completed): ?>
                    <button class="btn danger small" type="submit" form="delform<?= $sid ?>"
                            data-confirm-click="Wettbewerb <?= h($s['name']) ?> ohne Resultate löschen?">✕ Löschen</button>
                    <form method="post" id="delform<?= $sid ?>" style="display:none">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $sid ?>">
                    </form>
                <?php elseif (is_superadmin()): ?>
                    <?php // Zweite Loeschung, aber nur dort, wo die harmlose nicht
                          // angezeigt wird - also genau dann, wenn Ergebnisse
                          // dranhaengen oder der Wettbewerb beendet ist.
                          //
                          // Die Rueckfrage nennt die Zahlen. "Wirklich loeschen?"
                          // genuegt nicht: wer 110 Resultate wegwirft, hat danach
                          // keine Erinnerung mehr daran, wie viele es waren.
                          // Die Stammsaetze bleiben stehen - die gehoeren ja
                          // nicht diesem einen Wettbewerb. ?>
                    <button class="btn danger small" type="submit" form="delall<?= $sid ?>"
                            data-confirm-click="Wettbewerb <?= h($s['name']) ?> WIRKLICH löschen<?= $bestand['scores'] > 0 ? '? Dabei werden ' . (int) $bestand['scores'] . ' Resultate gelöscht' : '' ?><?= $bestand['rounds'] > 0 ? ', dazu ' . (int) $bestand['rounds'] . ' Durchgänge und ' . (int) $bestand['pilots'] . ' Startlisteneinträge' : '' ?>. Das lässt sich nicht zurücknehmen. Die Stammsätze der Piloten bleiben erhalten.">✕ Löschen samt Ergebnissen</button>
                    <form method="post" id="delall<?= $sid ?>" style="display:none">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_all">
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
