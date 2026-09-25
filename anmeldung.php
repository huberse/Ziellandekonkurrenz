<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/scoring.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/mail.php';

if (!schema_has_competitions()) {
    redirect('upgrade.php');
}

$competition = resolve_competition_param(competition_request_param());
// Beendete Wettbewerbe nehmen keine Anmeldungen mehr an und stehen deshalb
// nicht mehr in der Auswahl. Ein alter Lesezeichen zeigt weiterhin die
// Abschluss-Seite, damit klar ist, warum nichts mehr geht.
$competitions = open_competitions();
$competitionQS = (int) $competition['id'] !== current_competition_id() ? '?competition=' . (int) $competition['id'] : '';
$doneQS = ($competitionQS !== '' ? $competitionQS . '&' : '?');
$completed = competition_is_completed((int) $competition['id']);
$types = db()->query('SELECT * FROM model_types WHERE active = 1 ORDER BY sort_order, name')->fetchAll();
$clubs = db()->query('SELECT * FROM clubs WHERE active = 1 ORDER BY sort_order, name')->fetchAll();
$open   = !$completed && setting_bool('registration_open', true);
$errors = [];
$done   = get('eingegangen') === '1';
$mailSent = $done && get('mail') === 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $open) {
    csrf_check();

    $competitionId = (int) $competition['id'];
    $validClubIds = [];
    foreach ($clubs as $club) {
        $validClubIds[(int) $club['id']] = true;
    }
    $validTypeIds = [];
    foreach ($types as $type) {
        $validTypeIds[(int) $type['id']] = true;
    }

    $rawClubId = post('club_id');
    $rawTypeId = post('model_type_id');
    $data = [
        'first_name' => post('first_name'),
        'last_name'  => post('last_name'),
        'club_id'    => $rawClubId !== '' ? (int) $rawClubId : null,
        'club'       => $rawClubId !== '' ? '' : post('club_new'),
        'email'      => post('email'),
        'model_type_id' => $rawTypeId !== '' ? (int) $rawTypeId : null,
        'model_name' => post('model_name'),
        'notes'      => post('notes'),
    ];

    $lengths = [
        'first_name' => 80, 'last_name' => 80, 'club' => 120,
        'email' => 160, 'model_name' => 120, 'notes' => 500,
    ];
    foreach ($lengths as $field => $max) {
        if (text_length($data[$field]) > $max) {
            $errors[$field] = 'Dieses Feld ist zu lang.';
        }
    }

    if ($data['first_name'] === '') { $errors['first_name'] = 'Bitte Vornamen eintragen.'; }
    if ($data['last_name'] === '')  { $errors['last_name']  = 'Bitte Namen eintragen.'; }
    if ($data['email'] === '') {
        $errors['email'] = 'Bitte die E-Mail-Adresse eintragen, damit wir die Anmeldebestätigung schicken können.';
    } elseif (!is_valid_email($data['email'])) {
        $errors['email'] = 'Diese E-Mail-Adresse stimmt nicht.';
    }
    if ($data['club_id'] !== null && !isset($validClubIds[$data['club_id']])) {
        $errors['club_id'] = 'Bitte einen gültigen Verein wählen.';
    }
    if ($data['model_type_id'] !== null && !isset($validTypeIds[$data['model_type_id']])) {
        $errors['model_type_id'] = 'Bitte einen gültigen Modelltyp wählen.';
    }
    if ($types && !$data['model_type_id']) { $errors['model_type_id'] = 'Bitte Modelltyp wählen.'; }
    if ($data['club_id'] === null && trim((string) $data['club']) === '') {
        $errors['club_id'] = 'Bitte Verein wählen oder daneben eintragen.';
    }
    if (post('website') !== '') { $errors['website'] = 'Bitte nochmals versuchen.'; } // Honigtopf

    // Doppelanmeldung nur innerhalb desselben Wettbewerbs abfangen.
    if (!$errors) {
        $st = db()->prepare("SELECT COUNT(*) FROM registrations
                             WHERE competition_id = ? AND last_name = ? AND first_name = ? AND status IN ('pending','approved')");
        $st->execute([$competitionId, $data['last_name'], $data['first_name']]);
        if ((int) $st->fetchColumn() > 0) {
            $errors['last_name'] = 'Unter diesem Namen liegt bereits eine Anmeldung vor. Melde dich bei der Wettkampfleitung.';
        }
    }

    if (!$errors) {
        // Einmal-Token zuerst: ein Reload, ein zweiter Klick oder das
        // Zurückkommen im Browser darf keine zweite Anmeldung anlegen.
        if (!form_token_consume('anmeldung')) {
            flash('Diese Anmeldung wurde schon einmal abgeschickt. Sie steht in der Teilnehmerliste,'
                . ' sobald die Wettkampfleitung sie freigibt.', 'info');
            redirect('anmeldung.php' . $doneQS . 'eingegangen=1');
        }

        foreach ($lengths as $field => $max) {
            $data[$field] = text_limit($data[$field], $max);
        }
        $pdo = db();
        $registrationId = 0;
        try {
            $pdo->beginTransaction();
            lock_open_competition($pdo, $competitionId);
            // Die Adresse des Piloten wird bewusst nicht gespeichert: sie dient
            // nur dem Versand der Bestätigung.
            $st = $pdo->prepare('INSERT INTO registrations (first_name, last_name, club_id, club, model_type_id, model_name, notes, competition_id)
                                 VALUES (:first_name, :last_name, :club_id, :club, :model_type_id, :model_name, :notes, :competition_id)');
            $st->execute([
                'first_name' => $data['first_name'],
                'last_name'  => $data['last_name'],
                'club_id'    => $data['club_id'],
                'club'       => $data['club'] !== '' ? $data['club'] : null,
                'model_type_id' => $data['model_type_id'],
                'model_name' => $data['model_name'] !== '' ? $data['model_name'] : null,
                'notes'      => $data['notes'] !== '' ? $data['notes'] : null,
                'competition_id' => $competitionId,
            ]);
            $registrationId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors['_form'] = $e instanceof DomainException
                ? $e->getMessage()
                : 'Die Anmeldung konnte nicht gespeichert werden. Bitte später erneut versuchen.';
        }

        if (!$errors) {
            // Erst der Rückgriff auf die gespeicherte Zeile bestätigt den
            // Eintrag in der Datenbank – erst dann geht die Bestätigung raus.
            $saved = registration_read_back($registrationId, $competitionId);
            if ($saved && send_registration_confirmation($data['email'], $saved, $competition)) {
                $mailSent = true;
            } elseif (!$saved) {
                flash('Die Anmeldung ist gespeichert, liess sich aber nicht zurücklesen. Bitte unter Anmeldungen nachsehen.', 'err');
            } else {
                flash('Die Anmeldung ist gespeichert. Die Bestätigung per E-Mail konnte nicht verschickt werden.', 'info');
            }
            $done = true;
            redirect('anmeldung.php' . $doneQS . 'eingegangen=1&mail=' . ($mailSent ? 'ok' : 'nein'));
        }
    }
}

// Die Auswahl braucht es nur, wenn es eine Alternative zum aktuellen Wettbewerb gibt.
$showPicker = count($competitions) > 1 || (count($competitions) > 0 && $completed);

page_start('Anmeldung', 'public', 'anmeldung.php');
?>
<?php if ($showPicker): ?>
    <form method="get" class="btn-row narrow" style="margin-bottom:16px">
        <label for="competition-select" class="muted">Wettbewerb:</label>
        <select id="competition-select" name="competition" data-auto-submit>
            <?php if ($completed): ?>
                <option value="<?= (int) $competition['id'] ?>" selected><?= h($competition['name']) ?> (beendet)</option>
            <?php endif; ?>
            <?php foreach ($competitions as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === (int) $competition['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
<?php endif; ?>
<div class="panel narrow">
<?php if ($done): ?>
    <h2>Anmeldung eingegangen</h2>
    <p class="lead">Die Wettkampfleitung prüft die Anmeldung und trägt dich in die Startliste ein.
       Du erscheinst dann in der <a href="teilnehmer.php<?= $competitionQS ?>">Teilnehmerliste</a>.</p>
    <?php if ($mailSent): ?>
        <p class="small muted">Die Anmeldebestätigung ist unterwegs. Deine E-Mail-Adresse wurde dafür nicht gespeichert.</p>
    <?php endif; ?>
    <a class="btn ghost" href="anmeldung.php<?= $competitionQS ?>">Weitere Person anmelden</a>

<?php elseif ($completed): ?>
    <h2>Wettbewerb beendet</h2>
    <p class="lead">Für diesen Wettbewerb werden keine Anmeldungen mehr angenommen.</p>
    <a class="btn ghost" href="teilnehmer.php<?= $competitionQS ?>">Teilnehmerliste ansehen</a>

<?php elseif (!$open): ?>
    <h2>Anmeldung geschlossen</h2>
    <p class="lead">Für diesen Wettbewerb werden keine Anmeldungen mehr entgegengenommen.</p>
    <a class="btn ghost" href="teilnehmer.php<?= $competitionQS ?>">Teilnehmerliste ansehen</a>

<?php else: ?>
    <h2>Anmeldung</h2>
    <p class="lead">
        <?= setting('registration_info') !== ''
            ? nl2br(h(setting('registration_info')))
            : 'Trage dich hier für den Wettbewerb ein. Die Startnummer teilt dir die Wettkampfleitung zu.' ?>
    </p>

    <?php if ($errors): ?>
        <div class="flash err"><?= h($errors['_form'] ?? 'Bitte die markierten Felder prüfen.') ?></div>
    <?php endif; ?>

    <form method="post" novalidate data-submit-once>
        <?= csrf_field() ?>
        <?= form_token_field('anmeldung') ?>
        <input type="hidden" name="competition" value="<?= (int) $competition['id'] ?>">
        <div class="grid-2">
            <div class="field">
                <label for="fn">Vorname</label>
                <input type="text" id="fn" name="first_name" value="<?= h(post('first_name')) ?>" autocomplete="given-name" maxlength="80" required>
                <?php if (isset($errors['first_name'])): ?><p class="hint" style="color:var(--rot)"><?= h($errors['first_name']) ?></p><?php endif; ?>
            </div>
            <div class="field">
                <label for="ln">Name</label>
                <input type="text" id="ln" name="last_name" value="<?= h(post('last_name')) ?>" autocomplete="family-name" maxlength="80" required>
                <?php if (isset($errors['last_name'])): ?><p class="hint" style="color:var(--rot)"><?= h($errors['last_name']) ?></p><?php endif; ?>
            </div>
        </div>

        <div class="grid-2">
            <div class="field">
                <label for="cl">Verein</label>
                <select id="cl" name="club_id">
                    <option value="">– bitte wählen –</option>
                    <?php foreach ($clubs as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= post('club_id') === (string) $c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['club_id'])): ?><p class="hint" style="color:var(--rot)"><?= h($errors['club_id']) ?></p><?php endif; ?>
            </div>
            <div class="field">
                <label for="cn">Anderer Verein</label>
                <input type="text" id="cn" name="club_new" value="<?= h(post('club_new')) ?>" autocomplete="organization" maxlength="120"
                       placeholder="nur nötig, wenn oben keiner passt">
            </div>
        </div>

        <div class="field">
            <label for="em">E-Mail</label>
            <input type="email" id="em" name="email" value="<?= h(post('email')) ?>" autocomplete="email" maxlength="160" required>
            <p class="hint">Dient nur der Anmeldebestätigung. Die Adresse wird nicht gespeichert.</p>
            <?php if (isset($errors['email'])): ?><p class="hint" style="color:var(--rot)"><?= h($errors['email']) ?></p><?php endif; ?>
        </div>

        <div class="grid-2">
            <div class="field">
                <label for="gr">Modelltyp</label>
                <select id="gr" name="model_type_id">
                    <option value="">– bitte wählen –</option>
                    <?php foreach ($types as $g): ?>
                        <option value="<?= (int) $g['id'] ?>" <?= post('model_type_id') === (string) $g['id'] ? 'selected' : '' ?>>
                            <?= h($g['name']) ?><?= $g['info'] ? ' — ' . h($g['info']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['model_type_id'])): ?><p class="hint" style="color:var(--rot)"><?= h($errors['model_type_id']) ?></p><?php endif; ?>
            </div>
            <div class="field">
                <label for="mo">Modell</label>
                <input type="text" id="mo" name="model_name" value="<?= h(post('model_name')) ?>" maxlength="120" placeholder="z.B. ASW 20, 3.5 m">
            </div>
        </div>

        <div class="field">
            <label for="no">Bemerkung</label>
            <textarea id="no" name="notes" maxlength="500" placeholder="Was die Wettkampfleitung wissen sollte"><?= h(post('notes')) ?></textarea>
        </div>

        <div style="position:absolute;left:-9999px" aria-hidden="true">
            <label for="website">Bitte leer lassen</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>

        <button class="btn big" type="submit">Anmeldung senden</button>
    </form>
<?php endif; ?>
</div>
<?php page_end();
