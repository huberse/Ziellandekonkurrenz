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
// Für den Abschnitt "Ausgehende E-Mails": die Testmail und die Absenderadresse
// des Wettbewerbs. Nur der SuperAdmin braucht davon, aber das laden hängt an
// der Seite, nicht am Konto.
require_once __DIR__ . '/../lib/mail.php';

if (!is_file(__DIR__ . '/../config.php')) {
    exit('config.php fehlt. Kopiere config.sample.php nach config.php.');
}
if (!schema_has_competitions()) {
    redirect(upgrade_url());
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

    if ($action === 'mail') {
        // Nur der SuperAdmin stellt das ein: es wirkt auf alle Vereine.
        if (!is_superadmin()) {
            flash('Das ist eine Einstellung für den SuperAdmin.', 'err');
            redirect('profil.php');
        }
        global_setting_set('mail_absender_setzen', isset($_POST['mail_absender_setzen']) ? '1' : '0');
        flash(isset($_POST['mail_absender_setzen'])
            ? 'Der Absender wird jetzt auch als Umschlag gesetzt. Bitte unten eine Testmail schicken und in ein paar Minuten das Postfach prüfen – kommt sie an, hat der Hoster das zugelassen.'
            : 'Der Absender wird nicht mehr als Umschlag gesetzt. Das ist die Voreinstellung.',
            'ok');
        redirect('profil.php');
    }

    if ($action === 'mailtest') {
        if (!is_superadmin()) {
            flash('Das ist eine Einstellung für den SuperAdmin.', 'err');
            redirect('profil.php');
        }
        $ziel = competition_sender_email();
        if ($ziel === '') {
            flash('Es ist keine Absenderadresse eingestellt. Erst unter Einstellungen → Anmeldung '
                . 'des gewählten Wettbewerbs eine eintragen.', 'err');
            redirect('profil.php');
        }
        $ok = send_mail($ziel, 'Testmail aus dem Wettkampfbüro',
            "Das ist eine Testmail.\n\nWenn du sie liest, darf der Hoster den Absender als Umschlag setzen.\n"
            . "Kommt sie nicht an, obwohl hier nichts gemeldet wurde, liegt es am Hoster oder an SPF, DKIM\n"
            . "und DMARC seiner Domain – nicht am Programm.\n",
            $ziel, competition_sender_name());
        flash($ok ? 'Die Testmail wurde dem Mailserver übergeben. Jetzt das Postfach prüfen, '
                 . 'auch den Spam-Ordner – kommt sie dort an, ist sie angekommen.'
                 : 'Der Mailserver hat die Testmail abgewiesen. Häufigste Ursache: der Hoster erlaubt '
                 . 'keinen eigenen Absender. In den Einstellungen unter „Absender setzen“ wieder auschalten.',
            $ok ? 'ok' : 'err');
        redirect('profil.php');
    }
}

// Nach dem Speichern frisch lesen, sonst steht hier der alte Name.
$me = current_user() ?: $me;
$rolle = (int) ($me['is_superadmin'] ?? 0) === 1
    ? 'SuperAdmin – darf die Konten verwalten'
    : 'Wettkampfleitung';

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

<div class="panel">
    <h3 style="margin-top:0">Eigene Einstellungen</h3>
    <p class="lead">Hier ist noch nichts. Dieser Platz ist für Einstellungen, die nur dich betreffen –
        zum Beispiel, welche Vereine und Wettbewerbe du ohne Umweg sehen möchtest. Sobald es etwas
        gibt, steht es hier und nicht in den Wettbewerbseinstellungen.</p>
</div>

<?php if (is_superadmin()): ?>
<div class="panel">
    <h3 style="margin-top:0">Ausgehende E-Mails</h3>
    <p class="lead">Gilt für das ganze Programm, nicht nur für dich. Die Absenderadresse selbst steht
        je Wettbewerb unter <a href="einstellungen.php#anmeldung">Einstellungen → Anmeldung</a>.</p>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mail">
        <div class="check">
            <input type="checkbox" id="mas" name="mail_absender_setzen" <?= setting_bool('mail_absender_setzen') ? 'checked' : '' ?>>
            <label for="mas">Absender auch als Umschlag setzen (sendmail -f)</label>
        </div>
        <p class="hint">Aus: Der Mailserver des Hosters verschickt die Mail über seine eigene Adresse.
            Die Adresse im Kopf steht trotzdem auf deine – bei gmail und anderen grossen Anbietern
            landet sie dann im Spam oder wird abgewiesen.<br>
            An: Der Mailserver verschickt über deine Absenderadresse. Das hilft, wenn der Hoster
            für die Domain deines Vereins zuständig ist. Bei einer gmail-Adresse hilft es
            <b>nicht</b> – dort braucht es DKIM, und das kann nur der Mailserver des Hosters.
            Manche Hoster sperren den Umschlag aus Sicherheitsgründen; dann kommt nichts mehr
            an. Deshalb erst die Testmail.</p>
        <button class="btn" type="submit">Speichern</button>
    </form>

    <form method="post" style="margin-top:12px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mailtest">
        <button class="btn ghost" type="submit">Testmail an die Absenderadresse</button>
    </form>
    <p class="hint">Die Testmail geht an die Absenderadresse des gerade gewählten Wettbewerbs. Sie
        sagt mehr als jede Meldung hier: kommt sie an, ist der Weg frei; kommt sie nicht an, liegt es
        am Hoster – oder an SPF, DKIM und DMARC seiner Domain.</p>
</div>
<?php endif; ?>
