<?php
/**
 * Das eigene Profil: Anzeigename und Passwort.
 *
 * Bewusst getrennt von den Wettbewerbseinstellungen. Dort geht es um den
 * Wettbewerb, hier um die Person davor - ein Passwort hat mit Strafpunkten
 * nichts zu tun. Was später noch dazukommt, gehört ebenfalls hierher.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/layout.php';

if (!is_file(__DIR__ . '/../config.php')) {
    exit('config.php fehlt. Kopiere config.sample.php nach config.php.');
}
if (!schema_has_competitions()) {
    redirect('../upgrade.php');
}

$me = require_login();
$action = post('action');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ($action === 'profil') {
        $name = trim(post('display_name'));
        if (text_length($name) > 120) {
            flash('Der Anzeigename ist zu lang.', 'err');
        } else {
            $st = db()->prepare('UPDATE users SET display_name = ? WHERE id = ?');
            $st->execute([$name !== '' ? $name : null, $me['id']]);
            // current_user() merkt sich den alten Stand für die ganze Anfrage.
            unset($GLOBALS['current_user_cache']);
            flash('Anzeigename gespeichert.', 'ok');
        }
        redirect('profil.php');
    }

    if ($action === 'regiocup') {
        // Nur der SuperAdmin darf das setzen; die Seite ist fuer alle
        // Konten erreichbar, deshalb hier noch einmal nachsehen.
        if (!is_superadmin()) {
            flash('Das darf nur der SuperAdmin.', 'err');
            redirect('profil.php');
        }
        $verein = (int) post('region_club_id', '0');
        global_setting_set('region_club_id', (string) ($verein > 0 ? $verein : 0));
        // Die alten Werte aus den Wettbewerbseinstellungen mit wegraeumen.
        // Blieben sie stehen, wuerde region_club_id() zurueckfallen, sobald
        // hier "niemand" gesetzt wird - und der Verein haette die Freigabe
        // wieder, die der SuperAdmin gerade entzogen hat.
        db()->exec('DELETE FROM competition_settings WHERE skey = \'region_club_id\'');
        flash('Freigabe für die Regiorangliste gespeichert.', 'ok');
        redirect('profil.php?jahr=' . (int) post('jahr', '0'));
    }

    if ($action === 'password') {
        $alt = post('old_password');
        $neu = post('new_password');
        if (!password_verify($alt, (string) $me['password_hash'])) {
            flash('Das bisherige Passwort stimmt nicht.', 'err');
        } elseif (strlen($neu) < 8) {
            flash('Das neue Passwort braucht mindestens 8 Zeichen.', 'err');
        } elseif ($neu !== post('new_password2')) {
            flash('Die beiden neuen Passwörter stimmen nicht überein.', 'err');
        } elseif ($neu === $alt) {
            flash('Das neue Passwort ist das alte.', 'err');
        } else {
            $st = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $st->execute([password_hash($neu, PASSWORD_DEFAULT), $me['id']]);
            flash('Passwort geändert.', 'ok');
        }
        redirect('profil.php');
    }
}

// Nach dem Speichern frisch lesen, sonst steht hier der alte Name.
$me = current_user() ?: $me;
$rolle = (int) ($me['is_superadmin'] ?? 0) === 1
    ? 'SuperAdmin – darf die Konten verwalten'
    : 'Wettkampfleitung';

// Vorschau der Regiorangliste. Der SuperAdmin sieht sie hier auch dann, wenn
// sie sonst nirgends freigegeben ist - darum ist das gerade der Punkt dieser
// Seite: die Liste ansehen, BEVOR sie veroeffentlicht wird.
$regiJahre = region_jahre();
$regiJahr = (int) get('jahr', $regiJahre ? (string) $regiJahre[0] : '0');
if ($regiJahre && !in_array($regiJahr, array_map('intval', $regiJahre), true)) {
    $regiJahr = (int) $regiJahre[0];
}
$regiWettbewerbe = $regiJahr > 0 ? region_wettbewerbe($regiJahr) : [];
$regiDaten = $regiWettbewerbe
    ? region_rangliste(array_map(static function (array $w): int {
        return (int) $w['id'];
    }, $regiWettbewerbe))
    : ['zeilen' => []];
$regiVerein = region_club_id();
$regiVereinName = 'niemand';
foreach (all_clubs() as $c) {
    if ((int) $c['id'] === $regiVerein) {
        $regiVereinName = (string) $c['name'];
    }
}

page_start('Profil', 'admin', 'profil.php');
?>
<h2>Mein Profil</h2>
<p class="lead">Dein Konto für das Wettkampfbüro. Der Benutzername und deine Rechte gibt nur der
    SuperAdmin unter <a href="<?= is_superadmin() ? 'benutzer.php' : '../index.php' ?>"><?= is_superadmin() ? 'Benutzer' : 'Startseite' ?></a> frei.</p>

<div class="split">
    <div class="panel">
        <h3 style="margin-top:0">Konto</h3>
        <table class="data kv">
            <tr><th>Benutzername</th><td><?= h((string) $me['username']) ?></td></tr>
            <tr><th>Rechte</th><td><?= h($rolle) ?></td></tr>
            <tr><th>Verein</th><td><?= h((string) ($me['club_name'] ?? '')) ?: 'keinem zugeordnet' ?></td></tr>
            <tr><th>Konto seit</th><td><?= h(date('d.m.Y', strtotime((string) $me['created_at']))) ?></td></tr>
        </table>

        <h3>Dein Name in der Leiste</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="profil">
            <div class="field">
                <label for="dn">Anzeigename oben rechts</label>
                <p class="hint">Leer lassen, um nur den Benutzernamen zu zeigen.</p>
                <input type="text" id="dn" name="display_name" maxlength="120"
                       value="<?= h((string) ($me['display_name'] ?? '')) ?>"
                       placeholder="<?= h((string) $me['username']) ?>">
            </div>
            <button class="btn ghost" type="submit">Anzeigename speichern</button>
        </form>
    </div>

    <div class="panel">
        <h3 style="margin-top:0">Passwort ändern</h3>
        <form method="post" data-submit-once>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="password">
            <div class="field">
                <label for="op">Bisheriges Passwort</label>
                <input type="password" id="op" name="old_password" autocomplete="current-password" required>
            </div>
            <div class="field">
                <label for="np">Neues Passwort</label>
                <input type="password" id="np" name="new_password" autocomplete="new-password" minlength="8" required>
                <p class="hint">Mindestens 8 Zeichen.</p>
            </div>
            <div class="field">
                <label for="np2">Neues Passwort wiederholen</label>
                <input type="password" id="np2" name="new_password2" autocomplete="new-password" minlength="8" required>
            </div>
            <button class="btn" type="submit">Passwort ändern</button>
        </form>
    </div>
</div>

<?php if (is_superadmin()): ?>
<div class="panel">
    <h3 style="margin-top:0">Regiocup</h3>
    <p class="lead">Ein Regiocup-Jahr ist die Summe aller Wettbewerbe, die das Regiocup-Kennzeichen
        tragen. Wer hier freigeschaltet wird, sieht die Regiorangliste und kann sie als CSV
        herunterladen – auch dann, wenn die Resultate sonst nirgends öffentlich sind.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="regiocup">
        <input type="hidden" name="jahr" value="<?= $regiJahr ?>">
        <div class="field" style="max-width:420px">
            <label for="rci">Verein, der die Regiorangliste sehen darf</label>
            <select id="rci" name="region_club_id">
                <option value="0" <?= $regiVerein === null ? 'selected' : '' ?>>niemand</option>
                <?php foreach (all_clubs() as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $regiVerein === (int) $c['id'] ? 'selected' : '' ?>><?= h((string) $c['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="hint">Gilt für das ganze Programm, nicht für einen Wettbewerb: der Regiocup
                läuft über ein Jahr und damit über mehrere Wettbewerbe. Steht hier „niemand“,
                sieht die Liste nur, wer SuperAdmin ist – oder alle, solange die Resultate der
                Wettbewerbe ohnehin öffentlich sind.</p>
        </div>
        <button class="btn ghost" type="submit">Freigabe speichern</button>
    </form>
</div>

<div class="panel">
    <div class="row-between no-print">
        <div>
            <h3 style="margin-top:0">Regiorangliste <?= $regiJahr > 0 ? h((string) $regiJahr) : '' ?></h3>
            <p class="small muted" style="margin-bottom:0">
                <?php if (!$regiWettbewerbe): ?>
                    Kein Wettbewerb des Jahres hat das Regiocup-Kennzeichen.
                <?php else: ?>
                    <?= count($regiWettbewerbe) ?> Wettbewerb<?= count($regiWettbewerbe) === 1 ? '' : 'e' ?>:
                    <?php foreach ($regiWettbewerbe as $i => $w): ?>
                        <?= $i > 0 ? ' · ' : '' ?><?= h((string) $w['name']) ?>
                    <?php endforeach; ?>
                    <br>Freigegeben für <?= h($regiVereinName) ?>.
                <?php endif; ?>
            </p>
        </div>
        <div class="btn-row dense">
            <?php foreach ($regiJahre as $j): ?>
                <a class="btn <?= (int) $j === $regiJahr ? '' : 'ghost' ?>" href="profil.php?jahr=<?= (int) $j ?>"><?= (int) $j ?></a>
            <?php endforeach; ?>
            <?php if ($regiWettbewerbe): ?>
                <a class="btn ghost" href="../region.php?jahr=<?= $regiJahr ?>">Seite öffnen</a>
            <?php endif; ?>
        </div>
    </div>
    <?php region_table($regiDaten, $regiWettbewerbe); ?>
</div>
<?php else: ?>
<div class="panel">
    <h3 style="margin-top:0">Eigene Einstellungen</h3>
    <p class="lead">Hier ist noch nichts. Dieser Platz ist für Einstellungen, die nur dich betreffen –
        zum Beispiel, welche Vereine und Wettbewerbe du ohne Umweg sehen möchtest. Sobald es etwas
        gibt, steht es hier und nicht in den Wettbewerbseinstellungen.</p>
</div>
<?php endif; ?>
<?php page_end();
