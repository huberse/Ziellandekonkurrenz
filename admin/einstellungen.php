<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/scoring.php';
require_once __DIR__ . '/../lib/layout.php';
$me = require_login();
$competition = resolve_competition_param(competition_request_param());
$competitionCompleted = competition_is_completed((int) $competition['id']);
$competitions = all_competitions();
$competitionQS = (int) $competition['id'] !== current_competition_id() ? '?competition=' . (int) $competition['id'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'settings') {
        if ($competitionCompleted) {
            flash('Dieser Wettbewerb ist abgeschlossen. Die Wettbewerbseinstellungen sind gesperrt.', 'err');
            redirect('wettbewerbe.php?competition=' . (int) $competition['id']);
        }
        $errors = [];
        $numberKeys = [
            'penalty_per_second', 'penalty_per_meter',
            'penalty_outlanding', 'penalty_not_started', 'penalty_motor',
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
            setting_set('registration_open', isset($_POST['registration_open']) ? '1' : '0');
            setting_set('club_ranking_enabled', isset($_POST['club_ranking_enabled']) ? '1' : '0');
            setting_set('public_results', isset($_POST['public_results']) ? '1' : '0');
            $pdo->commit();
            flash('Einstellungen gespeichert. Bereits erfasste Punkte bleiben stehen – bei geänderten Regeln unter Durchgänge neu berechnen.', 'ok');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            settings(true);
            flash('Die Einstellungen konnten nicht gespeichert werden.', 'err');
        }
        redirect('einstellungen.php' . $competitionQS);
    }

    if ($action === 'password') {
        $alt = post('old_password');
        $neu = post('new_password');
        if (!password_verify($alt, $me['password_hash'])) {
            flash('Das bisherige Passwort stimmt nicht.', 'err');
        } elseif (strlen($neu) < 8) {
            flash('Das neue Passwort braucht mindestens 8 Zeichen.', 'err');
        } elseif ($neu !== post('new_password2')) {
            flash('Die beiden neuen Passwörter stimmen nicht überein.', 'err');
        } else {
            $st = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $st->execute([password_hash($neu, PASSWORD_DEFAULT), $me['id']]);
            flash('Passwort geändert.', 'ok');
        }
        redirect('einstellungen.php' . $competitionQS);
    }

    if ($action === 'adduser') {
        flash('Neue Konten legt nur der SuperAdmin unter Benutzer an.', 'err');
        redirect('einstellungen.php' . $competitionQS);
    }

}

$users = db()->query('SELECT id, username, display_name, created_at FROM users ORDER BY username')->fetchAll();
$isAdmin = is_superadmin();

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

    <fieldset id="anmeldung">
        <legend>Anmeldung</legend>
        <div class="check">
            <input type="checkbox" id="ro" name="registration_open" <?= setting_bool('registration_open') ? 'checked' : '' ?>>
            <label for="ro">Anmeldeformular ist offen</label>
        </div>
        <div class="field">
            <label for="ri">Text über dem Formular</label>
            <textarea id="ri" name="registration_info" placeholder="Startgeld, Anmeldeschluss, Treffpunkt"><?= h(setting('registration_info')) ?></textarea>
        </div>
        <div class="grid-2">
            <div class="field">
                <label for="rsn">Absendername der Bestätigung</label>
                <input type="text" id="rsn" name="registration_sender_name" maxlength="120"
                       value="<?= h(setting('registration_sender_name')) ?>" placeholder="z.B. Wettkampfleitung MFV Brislach">
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

<div class="split" style="margin-top:24px">
    <div class="panel">
        <h3 style="margin-top:0">Passwort ändern</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="password">
            <div class="field"><label for="op">Bisheriges Passwort</label><input type="password" id="op" name="old_password" autocomplete="current-password"></div>
            <div class="field"><label for="np">Neues Passwort</label><input type="password" id="np" name="new_password" autocomplete="new-password"></div>
            <div class="field"><label for="np2">Neues Passwort wiederholen</label><input type="password" id="np2" name="new_password2" autocomplete="new-password"></div>
            <button class="btn ghost" type="submit">Passwort ändern</button>
        </form>
    </div>

    <div class="panel">
        <h3 style="margin-top:0">Konten</h3>
        <p class="lead"><?= count($users) . (count($users) === 1 ? ' Konto' : ' Konten') ?>:
            <?= h(implode(', ', array_column($users, 'username'))) ?></p>
        <?php if ($isAdmin): ?>
            <p class="lead">Als SuperAdmin pflegst du die Konten unter
                <a href="benutzer.php">Benutzer</a>: anlegen, Anzeigename, Rolle, Sperre, Passwort
                und Löschen.</p>
        <?php else: ?>
            <p class="lead">Neue Konten, Rollen und Sperren nimmt der SuperAdmin vor. Andere Rechte
                brauchst du nicht: Du kannst den gesamten Wettbewerb steuern und Dein Passwort
                oben ändern.</p>
        <?php endif; ?>
    </div>
</div>

<?php page_end();
