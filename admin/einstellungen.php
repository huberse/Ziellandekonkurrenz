<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/scoring.php';
require_once __DIR__ . '/../lib/layout.php';
require_login();
$competition = resolve_competition_param(competition_request_param(), true);
// Startliste, Resultate und Einstellungen gehören genau diesem Wettbewerb.
// Die Prüfung steht hier und nicht versteckt in resolve_competition_param(),
// wo sie auch die öffentlichen Seiten betroffen hätte.
require_competition_access((int) ($competition['id'] ?? 0));
$competitionCompleted = competition_is_completed((int) $competition['id']);
$competitions = function_exists('accessible_competitions') ? accessible_competitions() : all_competitions();
$competitionQS = (int) $competition['id'] !== current_competition_id() ? '?competition=' . (int) $competition['id'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
      // Wird erst nach dem commit() auf true gesetzt.
      $gespeichert = false;

    if ($action === 'settings') {
        if ($competitionCompleted) {
            flash('Dieser Wettbewerb ist abgeschlossen. Die Wettbewerbseinstellungen sind gesperrt.', 'err');
            redirect('wettbewerbe.php?competition=' . (int) $competition['id']);
        }
        $errors = [];
        $numberKeys = [
            'penalty_per_second', 'penalty_per_meter',
            'penalty_outlanding', 'penalty_crash', 'penalty_not_started', 'penalty_motor',
        ];
        $numbers = [];
        foreach ($numberKeys as $key) {
            $value = nonnegative_number(post($key));
            if ($value === null || $value > 999999.99) {
                $errors[] = 'Die Zahl für ' . $key . ' muss zwischen 0 und 999999.99 liegen.';
            } else {
                $numbers[$key] = $value;
            }
        }

        $dropValue = nonnegative_number(post('drop_min_rounds'));
        if ($dropValue === null || floor($dropValue) !== $dropValue || $dropValue < 2 || $dropValue > 30) {
            $errors[] = 'Das Streichresultat muss zwischen 2 und 30 Durchgängen liegen.';
        }
        $clubCount = nonnegative_number(post('club_scoring_count'));
        if ($clubCount === null || floor($clubCount) !== $clubCount || $clubCount < 1 || $clubCount > 10) {
            $errors[] = 'Die Anzahl der gewerteten Piloten muss zwischen 1 und 10 liegen.';
        }

        $target = parse_time(post('default_target_time'));
        if ($target === null || !is_finite($target) || $target <= 0 || $target > 999999.9) {
            $errors[] = 'Die Standard-Zielzeit muss zwischen 0 und 999999.9 Sekunden liegen.';
        }
        $competitionName = text_limit(post('competition_name'), 160);
        if ($competitionName === '') {
            $errors[] = 'Der Wettbewerb braucht einen Namen.';
        } else {
            $duplicate = db()->prepare('SELECT id FROM competitions WHERE name = ? AND id <> ?');
            $duplicate->execute([$competitionName, $competition['id']]);
            if ($duplicate->fetchColumn()) {
                $errors[] = 'Diesen Wettbewerbnamen gibt es schon.';
            }
        }
        $date = post('competition_date');
        if ($date !== '' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)) {
            $errors[] = 'Das Wettbewerbsdatum ist ungültig.';
        }
        $senderEmail = text_limit(post('registration_sender_email'), 160);
        if ($senderEmail !== '' && !is_valid_email($senderEmail)) {
            $errors[] = 'Die Absenderadresse ist keine gültige E-Mail-Adresse.';
        }
        $senderName = text_limit(post('registration_sender_name'), 120);
        $scope = post('ranking_scope');
        if (!in_array($scope, ['group', 'overall'], true)) {
            $errors[] = 'Die Ranglisten-Ansicht ist ungültig.';
        }

        if ($errors) {
            flash(implode(' ', $errors), 'err');
            redirect('einstellungen.php' . $competitionQS);
        }

        $pdo = db();
        try {
            $pdo->beginTransaction();
            lock_open_competition($pdo, (int) $competition['id']);
            $st = $pdo->prepare('UPDATE competitions SET name = ? WHERE id = ?');
            $st->execute([$competitionName, $competition['id']]);
            if ($st->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT id FROM competitions WHERE id = ?');
                $exists->execute([$competition['id']]);
                if (!$exists->fetchColumn()) {
                    throw new RuntimeException('Wettbewerb nicht gefunden.');
                }
            }
            setting_set('competition_name', $competitionName);
            setting_set('competition_date', $date);
            setting_set('competition_place', text_limit(post('competition_place'), 200));
            foreach ($numbers as $key => $value) {
                setting_set($key, (string) $value);
            }
            setting_set('default_target_time', (string) ((int) round($target)));
            setting_set('drop_min_rounds', (string) ((int) $dropValue));
            setting_set('ranking_scope', $scope);
            setting_set('registration_info', post('registration_info'));
            setting_set('registration_sender_name', $senderName);
            setting_set('registration_sender_email', $senderEmail);
            setting_set('club_scoring_count', (string) ((int) $clubCount));
            setting_set('drop_enabled', isset($_POST['drop_enabled']) ? '1' : '0');
            // registration_open wird hier NICHT mehr gesetzt. Seit 2.2.0 gehoert
            // die Anmeldung zum Status des Wettbewerbs, und der wird unter
            // Wettbewerbe -> Status gesetzt. Bleibt diese Zeile stehen, wuerde
            // jedes Speichern dieser Seite die Anmeldung schliessen - ein Feld,
            // das niemand mehr sieht.
            setting_set('club_ranking_enabled', isset($_POST['club_ranking_enabled']) ? '1' : '0');
            setting_set('public_results', isset($_POST['public_results']) ? '1' : '0');
            // Die zugelassenen Modelltypen. Achtung: post() gibt immer einen
            // String zurueck und weist Nicht-Skalares ausdruecklich ab - ein
            // Ankreuzfeld mit [] waere damit immer leer, ohne Fehlermeldung.
            // Deshalb hier $_POST direkt, wie in admin/erfassung.php und
            // admin/modelltypen.php auch.
            //
            // Zusaetzlich nur Nummern, die es wirklich gibt: das Formular laesst
            // sich auch von Hand abschicken, und eine erfundene Nummer wuerde
            // einen Wettbewerb stilllegen, an dem niemand etwas eingestellt hat.
            $bekannt = array_map('intval', array_column(all_model_types(), 'id'));
            $typenRoh = is_array($_POST['model_types_ids'] ?? null) ? $_POST['model_types_ids'] : [];
            $typenIds = [];
            foreach ($typenRoh as $rohtyp) {
                if (!is_scalar($rohtyp)) {
                    continue;
                }
                $rohtyp = (int) $rohtyp;
                if ($rohtyp > 0 && in_array($rohtyp, $bekannt, true)) {
                    $typenIds[$rohtyp] = true;
                }
            }
            $typenIds = array_keys($typenIds);
            sort($typenIds);
            // Leer heisst "alle" - siehe competition_erlaubte_typ_ids().
            setting_set('model_types_ids', $typenIds ? implode(',', $typenIds) : '');
            // region_club_id wird hier nicht mehr gesetzt: der Regiocup laeuft
            // ueber ein ganzes Jahr und gehoert darum an das Programm. Der
            // SuperAdmin stellt es unter seinem Profil ein.
            $pdo->commit();
            // Ab hier ist gespeichert, und nichts mehr zuruecknehmbar. Der
            // Hinweis auf das Datum und der Hinweis auf einen belegten Tag
            // kommen danach - sie duerfen aber nicht mehr behaupten, es sei
            // nichts gespeichert worden.
            //
            // Genau das ist am 18. Oktober 2026 geschehen: Nach dem commit()
            // stolperte der Abschluss ueber eine nicht vorhandene Variable
            // und sagte "Die Einstellungen konnten nicht gespeichert werden" -
            // obwohl alles gespeichert war. Ein Satz, der das Gegenteil
            // behauptet, ist schlimmer als gar keiner: Man glaubt ihm und
            // speichert ein zweites Mal, oder man gibt auf und sucht weiter.
            $gespeichert = true;
            // Ein Datum in der Vergangenheit ist hier erlaubt - ein Wettbewerb
            // darf nach dem Flug nachgetragen werden, und das Datum darf nicht
            // nachtraeglich geaendert werden muessen, nur weil die Vergangenheit
            // weitergeht. Nur: der Wettbewerb nimmt dann keine Anmeldungen mehr
            // an. Das ist sonst der zweite Weg, auf dem jemand ohne erkennbare
            // Ursache vor einer geschlossenen Anmeldung steht.
            $vergangen = $date !== ''
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
                && strtotime($date . ' 23:59:59') < time()
                && empty($competition['completed_at'])
                && empty($competition['cancelled_at']);
            flash('Einstellungen gespeichert. Bereits erfasste Punkte bleiben stehen – bei geänderten Regeln unter Durchgänge neu berechnen.'
                . ($vergangen
                    ? ' Achtung: Das Wettbewerbsdatum liegt in der Vergangenheit, deshalb nimmt dieser Wettbewerb keine Anmeldungen mehr an.'
                    : ''), $vergangen ? 'info' : 'ok');
                // Und derselbe zweite Weg wie beim Anlegen: ein Tag, der schon
                // einem anderen Wettbewerb gehoert. Gewarnt, nicht verhindert -
                // zwei Vereine koennen am selben Tag fliegen.
                $belegt = competition_datum_belegt($date, (int) $competition['id']);
                if ($belegt !== null) {
                    flash('Der ' . date('d.m.Y', strtotime($date)) . ' ist bereits für „' . $belegt
                        . '“ belegt.', 'info');
                }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            settings(!empty($gespeichert));
            flash(empty($gespeichert)
                ? 'Die Einstellungen konnten nicht gespeichert werden.'
                : 'Die Einstellungen wurden gespeichert. Nur die Hinweise danach '
                  . 'konnten nicht ausgegeben werden.', 'err');
        }
        redirect('einstellungen.php' . $competitionQS);
    }

    if ($action === 'adduser') {
        flash('Neue Konten legt nur der SuperAdmin unter Benutzer an.', 'err');
        redirect('einstellungen.php' . $competitionQS);
    }

}

page_start('Einstellungen', 'admin', 'einstellungen.php');
?>
<h2>Einstellungen<?= count($competitions) > 1 ? ' – ' . h($competition['name']) : '' ?></h2>
<p class="lead">Wettbewerb, Wertungsregeln und Zugänge<?= count($competitions) > 1 ? '. Die Einstellungen gelten für den ausgewählten Wettbewerb.' : '.' ?></p>
<?php if ($competitionCompleted): ?>
    <div class="flash info">Dieser Wettbewerb ist abgeschlossen. Wettbewerbs- und Wertungseinstellungen sind gesperrt;
        der Name kann weiterhin in der <a href="wettbewerbe.php?competition=<?= (int) $competition['id'] ?>">Wettbewerbsverwaltung</a> geändert werden.</div>
<?php endif; ?>
<?php if (count($competitions) > 1): ?>
    <div class="competition-switch-row">
        <?php competition_switch($competitions, $competition, 'einstellungen.php'); ?>
    </div>
<?php endif; ?>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="settings">
    <input type="hidden" name="competition" value="<?= (int) $competition['id'] ?>">
    <fieldset class="readonly-operations"<?= $competitionCompleted ? ' disabled' : '' ?>>

    <fieldset>
        <legend>Wettbewerb</legend>
        <div class="grid-2">
            <div class="field">
                <label for="cn">Name</label>
                <input type="text" id="cn" name="competition_name" maxlength="160" value="<?= h(setting('competition_name')) ?>">
            </div>
            <div class="field">
                <label for="cd">Datum</label>
                <input type="date" id="cd" name="competition_date" value="<?= h(setting('competition_date')) ?>">
            </div>
            <div class="field">
                <label for="cp">Ort</label>
                <input type="text" id="cp" name="competition_place" value="<?= h(setting('competition_place')) ?>">
            </div>
            <div class="field">
                <label for="dt">Standard-Zielzeit</label>
                <input type="text" id="dt" name="default_target_time" value="<?= h(fmt_time(setting_num('default_target_time', 180))) ?>">
                <p class="hint">Gilt für neu angelegte Durchgänge. <code>3:00</code> oder <code>180</code>.</p>
            </div>
        </div>
        <p class="hint">Die Anzahl Durchgänge stellst du unter <a href="durchgaenge.php<?= $competitionQS ?>">Durchgänge</a> ein.</p>
    </fieldset>

    <fieldset>
        <legend>Strafpunkte</legend>
        <p class="lead">Wenige Punkte sind gut. Die Regeln gelten nur für diesen Wettbewerb, jeder Verein legt
            seine eigenen fest. Es gibt zwei Arten von Strafen: die Punkte für einen gelungenen Flug und die
            festen Beträge für die Ausgänge, bei denen der Flug nicht gewertet wird.</p>

        <div class="rules">
            <div class="rule-group">
                <h4>Geflogener Flug</h4>
                <p class="rule-intro">Strafpunkte = Zeitabweichung + Landepunkte. Die Abweichung zählt als
                    Betrag: 2 Sekunden zu lang kosten gleich viel wie 2 Sekunden zu kurz. Beim Landewert
                    entweder Meter eingeben oder direkt die Punkte aus eurer Landetabelle.</p>
                <div class="rule">
                    <label for="pp">Zeitabweichung</label>
                    <input type="text" id="pp" name="penalty_per_second" value="<?= h(setting('penalty_per_second')) ?>" inputmode="decimal">
                    <span class="rule-note">Punkte je Sekunde</span>
                </div>
                <div class="rule">
                    <label for="pm">Landewert</label>
                    <input type="text" id="pm" name="penalty_per_meter" value="<?= h(setting('penalty_per_meter')) ?>" inputmode="decimal">
                    <span class="rule-note">Punkte je Einheit</span>
                </div>
            </div>

            <div class="rule-group">
                <h4>Feste Strafen</h4>
                <p class="rule-intro">Fester Betrag je Ausgang, unabhängig von Zeit und Landewert. Der Motor
                    zählt beim gelungenen Flug allein, sonst kommt er zur Feststrafe dazu.</p>
                <div class="rule">
                    <label for="po">Aussenlandung</label>
                    <input type="text" id="po" name="penalty_outlanding" value="<?= h(setting('penalty_outlanding')) ?>" inputmode="decimal">
                    <span class="rule-note">nicht gelandet</span>
                </div>
                <div class="rule">
                    <label for="pc">Bruchlandung</label>
                    <input type="text" id="pc" name="penalty_crash" value="<?= h(setting('penalty_crash')) ?>" inputmode="decimal">
                    <span class="rule-note">Modell beschädigt</span>
                </div>
                <div class="rule">
                    <label for="pn">Nicht angetreten</label>
                    <input type="text" id="pn" name="penalty_not_started" value="<?= h(setting('penalty_not_started')) ?>" inputmode="decimal">
                    <span class="rule-note">auch ohne Resultat</span>
                </div>
                <div class="rule">
                    <label for="pmt">Motor angelassen</label>
                    <input type="text" id="pmt" name="penalty_motor" value="<?= h(setting('penalty_motor')) ?>" inputmode="decimal">
                    <span class="rule-note">allein bzw. zusätzlich</span>
                </div>
            </div>
        </div>

        <?php
        // Beispiel mit festen, geraden Werten: macht die Rechnung ohne Zusatzprüfung nachvollziehbar.
        $abweichung = 2;
        $landwert = 10;
        $jeSekunde = max(0.0, setting_num('penalty_per_second', 1));
        $jeLandwert = max(0.0, setting_num('penalty_per_meter', 1));
        $zeitPunkte = round($abweichung * $jeSekunde, 2);
        $landPunkte = round($landwert * $jeLandwert, 2);
        ?>
        <p class="rule-sample">
            <b>So liest sich das mit den gespeicherten Werten:</b>
            ein Flug <?= $abweichung ?> s neben der Zielzeit mit Landewert <?= $landwert ?> ergibt
            <?= $abweichung ?> &times; <?= h(fmt_num($jeSekunde, 2)) ?> + <?= $landwert ?> &times; <?= h(fmt_num($jeLandwert, 2)) ?>
            = <b><?= h(fmt_num(round($zeitPunkte + $landPunkte, 2))) ?></b> Punkte.
            Fest: Aussenlandung <?= h(fmt_num(setting_num('penalty_outlanding'))) ?>,
            Bruchlandung <?= h(fmt_num(setting_num('penalty_crash'))) ?>,
            nicht angetreten <?= h(fmt_num(setting_num('penalty_not_started'))) ?>,
            Motor <?= h(fmt_num(setting_num('penalty_motor'))) ?>,
            Aussenlandung mit Motor <?= h(fmt_num(round(setting_num('penalty_outlanding') + setting_num('penalty_motor'), 2))) ?>.
        </p>
    </fieldset>

    <fieldset>
        <legend>Rangliste</legend>
        <div class="check">
            <input type="checkbox" id="de" name="drop_enabled" <?= setting_bool('drop_enabled') ? 'checked' : '' ?>>
            <label for="de">Schlechtestes Resultat streichen</label>
        </div>
        <div class="grid-2">
            <div class="field">
                <label for="dm">Streichresultat ab wie vielen geflogenen Durchgängen</label>
                <input type="number" id="dm" name="drop_min_rounds" min="2" max="30" value="<?= (int) setting('drop_min_rounds') ?>">
            </div>
            <div class="field">
                <label for="rs">Die öffentliche Rangliste zeigt zuerst</label>
                <select id="rs" name="ranking_scope">
                    <option value="group" <?= setting('ranking_scope') === 'group' ? 'selected' : '' ?>>die erste Modelltyp</option>
                    <option value="overall" <?= setting('ranking_scope') === 'overall' ? 'selected' : '' ?>>alle Piloten zusammen</option>
                </select>
            </div>
        </div>
        <div class="check">
            <input type="checkbox" id="pr" name="public_results" <?= setting_bool('public_results') ? 'checked' : '' ?>>
            <label for="pr">Rangliste ist öffentlich sichtbar</label>
        </div>
    </fieldset>

    <fieldset id="vereinswertung">
        <legend>Vereinswertung</legend>
        <div class="check">
            <input type="checkbox" id="cre" name="club_ranking_enabled" <?= setting_bool('club_ranking_enabled') ? 'checked' : '' ?>>
            <label for="cre">Vereinswertung anzeigen</label>
        </div>
        <div class="field" style="max-width:320px">
            <label for="csc">Wie viele Piloten je Verein zählen</label>
            <input type="number" id="csc" name="club_scoring_count" min="1" max="10" value="<?= (int) setting('club_scoring_count') ?>">
            <p class="hint">Die besten dieser Anzahl ergeben zusammen das Vereinsresultat. Vereine mit weniger
                gewerteten Piloten erscheinen ausser Konkurrenz.</p>
        </div>
    </fieldset>

    <fieldset id="modeltypen">
        <legend>Modelltypen</legend>
        <?php
        // Ohne Auswahl darf jeder Typ. Eine Anzeige mit lauter Haken waere
        // eine Lüge, denn sie hiesse "das ist eingestellt".
        $erlaubt = competition_erlaubte_typ_ids((int) $competition['id']);
        $alleTypen = all_model_types();
        ?>
        <?php if (!$alleTypen): ?>
            <p class="hint">Der SuperAdmin hat noch keinen Modelltyp angelegt. Unter <b>Modelltypen</b> im
                Benutzermenü legt er die Typen fest, die es im Verein überhaupt gibt.</p>
        <?php else: ?>
            <?php if ($erlaubt === null): ?>
                <p class="hint">Für diesen Wettbewerb ist nichts eingestellt: <b>alle Modelltypen sind
                    zugelassen</b>. Wähle unten die Typen an, die hier fliegen, dann stehen in der Startliste
                    und im Anmeldeformular nur noch diese zur Wahl.</p>
            <?php else: ?>
                <p class="hint">In der Startliste und im Anmeldeformular stehen nur diese Typen zur Wahl.</p>
            <?php endif; ?>
            <div class="check-grid">
                <?php foreach ($alleTypen as $typ): ?>
                    <div class="check">
                        <input type="checkbox" id="mt<?= (int) $typ['id'] ?>" name="model_types_ids[]"
                               value="<?= (int) $typ['id'] ?>"
                               <?= $erlaubt !== null && in_array((int) $typ['id'], $erlaubt, true) ? 'checked' : '' ?>
                               <?= empty($typ['active']) ? 'disabled' : '' ?>>
                        <label for="mt<?= (int) $typ['id'] ?>"><?= h($typ['name']) ?></label>
                        <?php if (empty($typ['active'])): ?>
                            <span class="hint" style="margin:0">– vom SuperAdmin abgeschaltet</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="hint">Nichts ankreuzen heisst: alle sind zugelassen. Wer einen Piloten schon in der
                Startliste hat, bleibt dort – diese Auswahl wirkt nur beim Eintragen, nicht auf die Rangliste,
                den Laufzettel und den Export.</p>
        <?php endif; ?>
    </fieldset>

    <fieldset id="anmeldung">
        <legend>Anmeldung</legend>            <p class="hint">Ob das Anmeldeformular offen ist, steht im <b>Status</b> des Wettbewerbs –
                unter <a href="wettbewerbe.php?competition=<?= (int) $competition['id'] ?>">Wettbewerbe</a>.
                Zur Zeit: <b><?= h(wettbewerb_status_text(competition_status($competition))) ?></b>.</p>
        <div class="field">
            <label for="ri">Text über dem Formular</label>
            <textarea id="ri" name="registration_info" placeholder="Startgeld, Anmeldeschluss, Treffpunkt"><?= h(setting('registration_info')) ?></textarea>
        </div>
        <div class="grid-2">
            <div class="field">
                <label for="rsn">Absendername der Bestätigung</label>
                <input type="text" id="rsn" name="registration_sender_name" maxlength="120"
                       value="<?= h(setting('registration_sender_name')) ?>" placeholder="z. B. Wettkampfleitung Musterverein">
                <p class="hint">Leer lassen, um den Namen des Wettbewerbs zu verwenden.</p>
            </div>
            <div class="field">
                <label for="rse">Absenderadresse der Bestätigung</label>
                <input type="email" id="rse" name="registration_sender_email" maxlength="160"
                       value="<?= h(setting('registration_sender_email')) ?>" placeholder="anmeldung@verein.ch">
                <p class="hint">Von dieser Adresse kommt die Anmeldebestätigung, und sie steht in CC –
                    jede neue Anmeldung landet damit in diesem Postfach. Ohne Adresse wird nichts
                    verschickt. Die Adresse gehört nur zu diesem Wettbewerb und wird nicht an neu
                    angelegte Wettbewerbe vererbt.</p>
            </div>
        </div>
    </fieldset>

    <div class="btn-row"><button class="btn" type="submit">Einstellungen speichern</button></div>
    </fieldset>
</form>

<?php page_end();
