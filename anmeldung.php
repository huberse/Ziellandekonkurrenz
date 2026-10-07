<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/scoring.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/mail.php';
require_once __DIR__ . '/lib/profiles.php';

if (!schema_has_competitions()) {
    redirect('upgrade.php');
}

$competition = resolve_competition_param(competition_request_param());
// Nur Wettbewerbe, die wirklich noch Anmeldungen annehmen: nicht beendet, nicht
// abgeschaltet und der Tag noch nicht vorbei. Die Rangliste gibt es zu jedem
// Wettbewerb, die Anmeldung nicht. Ein altes Lesezeichen auf einen abgeschlossenen
// Wettbewerb zeigt weiterhin die Abschluss-Seite, damit klar ist, warum nichts
// mehr geht.
$competitions = array_values(array_filter(all_competitions(), 'competition_nimmt_anmeldungen_an'));
$competitionQS = (int) $competition['id'] !== current_competition_id() ? '?competition=' . (int) $competition['id'] : '';
$doneQS = ($competitionQS !== '' ? $competitionQS . '&' : '?');
$completed = competition_is_completed((int) $competition['id']);
$vergangen = competition_ist_vergangen($competition);
$types = db()->query('SELECT * FROM model_types WHERE active = 1 ORDER BY sort_order, name')->fetchAll();
$clubs = db()->query('SELECT * FROM clubs WHERE active = 1 ORDER BY sort_order, name')->fetchAll();
$open   = competition_nimmt_anmeldungen_an($competition)
    && !$completed && setting_bool('registration_open', true);
$errors = [];
$done   = get('eingegangen') === '1';
$mailSent = $done && get('mail') === 'ok';

/**
 * Was vor dem Formular schon dasteht.
 *
 * Die SMV-Nummer ist das erste Feld und kommt aus dem Link: der Verein
 * verschickt je Mitglied "anmeldung.php?smv=123456". Was der Pilot eintippt,
 * geht vor - sonst waere ein Tippfehler nicht mehr zu korrigieren, ohne den
 * Link zu aendern.
 *
 * Steht die Nummer in den Stammdaten, wird der Name mitgeliefert. Das ist
 * Absicht und nicht Versehen: die Nummer ist oeffentlich, und wer sie kennt,
 * sieht damit den Namen. Ohne diese Vor Fuellung muesste jeder Pilot seinen
 * Namen bei jedem Wettbewerb neu eintippen.
 *
 * Eine Nummer, die es noch nicht gibt, legt nichts an - das passiert erst
 * beim Abschicken. Sonst wuerde jeder Besuch eines Links die Stammliste
 * aufblaehen, auch der von jemandem, der gar nicht anmeldet.
 */
$smvAusLink = post('smv_number', get('smv'));
$smvAusLink = pilot_smv_normalisieren((string) $smvAusLink);
$smvBekannt = $smvAusLink !== null && post('smv_number') === '' ? profile_finden($smvAusLink) : null;
$vorVorname = post('first_name') !== '' ? post('first_name') : (string) ($smvBekannt['first_name'] ?? '');
$vorNachname = post('last_name') !== '' ? post('last_name') : (string) ($smvBekannt['last_name'] ?? '');
$smvIstBekannt = $smvBekannt !== null;

// Fuer das Nachschlagen beim Tippen: dieselbe Auskunft wie der Link, nur als
// Antwort auf eine Frage und ohne eine Seite zu bauen.
//
// Es gibt damit nichts Neues preis: ueber "?smv=" erfaehrt seit 2.0.0 jeder
// den Namen, der eine Nummer kennt. Das war eine bewusste Entscheidung des
// Nutzers - die Nummer ist oeffentlich, und wer sie kennt, sieht damit den
// Namen. Hier wird derselbe Weg nur ein zweites Mal angeboten, damit man die
// Nummer nicht in einen Link schreiben muss.
//
// Es legt nichts an und aendert nichts. Eine unbekannte Nummer ist eine leere
// Antwort und keine Fehlermeldung: eine Fehlermeldung wuerde verraten, welche
// Nummern es gibt.
if (get('json') !== '' && $smvAusLink !== null) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $antwort = ['vorhanden' => false, 'vorname' => '', 'name' => ''];
    if ($smvBekannt !== null) {
        $antwort = [
            'vorhanden' => true,
            'vorname' => (string) $smvBekannt['first_name'],
            'name' => (string) $smvBekannt['last_name'],
        ];
    }
    echo json_encode($antwort, JSON_UNESCAPED_UNICODE);
    exit;
}


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
        'smv_number' => post('smv_number'),
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

    if (!pilot_smv_ist_gueltig((string) $data['smv_number'])) {
        $errors['smv_number'] = 'Die SMV-Nummer besteht aus bis zu sechs Ziffern. Leer lassen, wenn es keine gibt.';
    }
    $data['smv_number'] = pilot_smv_normalisieren((string) $data['smv_number']) ?? '';
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
    // Und noch einmal ueber die SMV-Nummer: derselbe Pilot, der seinen Namen
    // im Formular anders schreibt, ist derselbe. Ohne diese zweite Pruefung
    // kaeme derselbe Mensch zweimal in der Startliste.
    if (!$errors && $data['smv_number'] !== '') {
        $st = db()->prepare("SELECT COUNT(*) FROM registrations
                             WHERE competition_id = ? AND smv_number = ? AND status IN ('pending','approved')");
        $st->execute([$competitionId, $data['smv_number']]);
        if ((int) $st->fetchColumn() > 0) {
            $errors['smv_number'] = 'Mit dieser SMV-Nummer liegt für diesen Wettbewerb schon eine Anmeldung vor.';
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
            $st = $pdo->prepare('INSERT INTO registrations (smv_number, first_name, last_name, club_id, club, model_type_id, model_name, notes, competition_id)
                                 VALUES (:smv_number, :first_name, :last_name, :club_id, :club, :model_type_id, :model_name, :notes, :competition_id)');
            $st->execute([
                'smv_number' => $data['smv_number'] !== '' ? $data['smv_number'] : null,
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

page_start('Anmeldung', 'public', 'anmeldung.php', false, false);
?>
<?php
// Keine Auswahlliste: wer sich anmelden will, klickt den Knopf "Anmelden" auf
// der Kachel des Wettbewerbs. Der Weg dorthin fuehrt mit competition= an die
// richtige Stelle; ohne diesen Parameter zeigt die Seite den aktiven
// Wettbewerb.
?>
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
    <?php anmeldehinweis($competitions, true); ?>
    <a class="btn ghost" href="teilnehmer.php<?= $competitionQS ?>">Teilnehmerliste ansehen</a>

<?php elseif (!$open): ?>
    <h2><?= $vergangen ? 'Wettbewerb vorbei' : 'Anmeldung geschlossen' ?></h2>
    <p class="lead">Für diesen Wettbewerb werden keine Anmeldungen mehr entgegengenommen.</p>
    <?php anmeldehinweis($competitions, true); ?>
    <a class="btn ghost" href="teilnehmer.php<?= $competitionQS ?>">Teilnehmerliste ansehen</a>

<?php else: ?>
    <?php // Eine Zeile, eine Groesse. Vorher stand hier eine Ueberschrift
          // "Anmeldung" in eigener Schriftgroesse und darunter ein Absatz -
          // zwei Schriftgrade fuer einen einzigen Satz. Der Name des
          // Wettbewerbs genuegt; ein "hinzufuegen" darunter war zuviel. ?>
    <h2 class="anmeldung-kopf">Anmeldung &ndash; <?= h((string) $competition['name']) ?></h2>
    <?php if (setting('registration_info') !== ''): ?>
        <p class="lead"><?= nl2br(h(setting('registration_info'))) ?></p>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="flash err"><?= h($errors['_form'] ?? 'Bitte die markierten Felder prüfen.') ?></div>
    <?php endif; ?>

    <form method="post" novalidate data-submit-once>
        <?= csrf_field() ?>
        <?= form_token_field('anmeldung') ?>
        <input type="hidden" name="competition" value="<?= (int) $competition['id'] ?>">
        <div class="field">
            <label for="sm">SMV-Nummer</label>
            <input type="text" id="sm" name="smv_number" inputmode="numeric" maxlength="6"
                   value="<?= h(post('smv_number') !== '' ? post('smv_number') : (string) ($smvAusLink ?? '')) ?>">
<p class="hint" id="sm-hinweis">
      Deine Nummer beim Schweizerischen Modellflugverband. Du findest sie auf
      deiner Mitgliederkarte; sie hat bis zu sechs Ziffern.
      <span id="sm-bekannt"><?php if ($smvIstBekannt): ?>
          <strong>Wir kennen dich schon: <?= h(profile_name($smvBekannt)) ?>.</strong>
          Der Name ist ausgefüllt. Ändere ihn, falls er nicht mehr stimmt - die Änderung
          gilt dann für alle künftigen Wettbewerbe.
      <?php else: ?>
          Kennst du sie nicht, lass das Feld einfach leer.
      <?php endif; ?></span>
  </p>
            <?php if (isset($errors['smv_number'])): ?><p class="hint" style="color:var(--rot)"><?= h($errors['smv_number']) ?></p><?php endif; ?>
        </div>

        <div class="grid-2">
            <div class="field">
                <label for="fn">Vorname</label>
                <input type="text" id="fn" name="first_name" value="<?= h($vorVorname) ?>" autocomplete="given-name" maxlength="80" required>
                <?php if (isset($errors['first_name'])): ?><p class="hint" style="color:var(--rot)"><?= h($errors['first_name']) ?></p><?php endif; ?>
            </div>
            <div class="field">
                <label for="ln">Name</label>
                <input type="text" id="ln" name="last_name" value="<?= h($vorNachname) ?>" autocomplete="family-name" maxlength="80" required>
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
<?php // Der Name kommt auch dann, wenn die Nummer von Hand eingetippt wurde und
      // nicht in einem Link stand. Ohne JavaScript bleibt es beim Link - das
      // Formular ist ohne diese Bequemlichkeit vollstaendig bedienbar.
      //
      // Nachgeschlagen wird nur, wenn die Namensfelder leer sind. Ein
      // eingetragener Name gehoert dem Besucher, und ein Ueberschreiben wuerde
      // er nicht einmal bemerken.
      //
      // Es wird nichts angelegt und nichts gespeichert: die Antwort ist ein
      // Blick in die Stammliste, sonst nichts. ?>
<script>
(function () {
    var nummer = document.getElementById('sm');
    var vorname = document.getElementById('fn');
    var nachname = document.getElementById('ln');
    var traeger = document.getElementById('sm-bekannt');
    if (!nummer || !vorname || !nachname || !traeger) return;

    var standard = 'Kennst du sie nicht, lass das Feld einfach leer.';
    var zuletztGefragt = '';
    var vonUnsVorname = '';
    var vonUnsNachname = '';

    function hinweisKnoechen() {
        // Kein innerHTML mit fremdem Text. Der Name kommt aus der Datenbank,
        // aber er ist trotzdem Text, den man nicht ohne Pruefung einhaengt.
        while (traeger.firstChild) traeger.removeChild(traeger.firstChild);
    }

    function gehoerenUns() {
        // Nur rueckwaerts ausraeumen, was wir selbst eingetragen haben. Hat der
        // Besucher am Namen gefeilt, bleibt sein Name stehen - und dann auch
        // der Hinweis dazu.
        if (vonUnsVorname === '' && vonUnsNachname === '') return;
        if (vorname.value === vonUnsVorname) vorname.value = '';
        if (nachname.value === vonUnsNachname) nachname.value = '';
        vonUnsVorname = '';
        vonUnsNachname = '';
    }

    function nachschlagen() {
        var wert = nummer.value.replace(/[\s-]/g, '');
        if (wert === zuletztGefragt) {
            // Dieselbe Zahl noch einmal fragen ist meistens Rauschen - z.B. wenn
            // der Besucher mit dem Tab zurueck in das Feld springt. Es lohnt
            // sich aber, wenn er den von uns ausgetragenen Namen wieder
            // geloescht hat: dann steht dort nichts, und genau dann soll der
            // Name wiederkommen.
            var felderLeer = vorname.value.trim() === '' && nachname.value.trim() === '';
            if (!felderLeer) return;
        }
        gehoerenUns();
        zuletztGefragt = wert;
        if (wert === '') {
            hinweisKnoechen();
            traeger.appendChild(document.createTextNode(standard));
            return;
        }
        if (vorname.value.trim() !== '' || nachname.value.trim() !== '') return;
        fetch('anmeldung.php?competition=<?= (int) $competition['id'] ?>&smv='
              + encodeURIComponent(wert) + '&json=1')
            .then(function (antwort) { return antwort.ok ? antwort.json() : null; })
            .then(function (daten) {
                // In der Zwischenzeit kann der Besucher weitergearbeitet haben.
                if (!daten || wert !== nummer.value.replace(/[\s-]/g, '')) return;
                hinweisKnoechen();
                if (!daten.vorhanden) {
                    traeger.appendChild(document.createTextNode(standard));
                    return;
                }
                vorname.value = daten.vorname;
                nachname.value = daten.name;
                vonUnsVorname = daten.vorname;
                vonUnsNachname = daten.name;
                var fett = document.createElement('strong');
                fett.textContent = 'Wir kennen dich schon: ' + daten.vorname + ' ' + daten.name + '.';
                traeger.appendChild(fett);
                traeger.appendChild(document.createTextNode(
                    ' Der Name ist ausgefüllt. Ändere ihn, falls er nicht mehr stimmt - die Änderung'
                    + ' gilt dann für alle künftigen Wettbewerbe.'));
            })
            .catch(function () {
                // Kein Netz, kein JavaScript, ein Tippfehler: nichts tun. Der
                // Besucher tippt seinen Namen eben selbst - so wie bisher.
            });
    }

      // "change" deckt beides ab: das Verlassen des Feldes und die
      // Tabulatortaste. Beide zuzuhoeren ist billig und deckt den Fall ab, in
      // dem nur eines davon feuert - etwa wenn ein Programm das Feld leert.
      nummer.addEventListener('change', nachschlagen);
      nummer.addEventListener('blur', nachschlagen);
})();
</script>
<?php page_end();
