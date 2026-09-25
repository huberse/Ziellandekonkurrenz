<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/scoring.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/auth.php';
$me = require_superadmin();

$users = db()->query('SELECT id, username, display_name, is_superadmin, active, created_at
                      FROM users ORDER BY is_superadmin DESC, username')->fetchAll();
$byId = [];
foreach ($users as $row) {
    $byId[(int) $row['id']] = $row;
}
$adminCount = superadmin_count();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'create') {
        $username = text_limit(post('username'), 60);
        $display = text_limit(post('display_name'), 120);
        $password = post('password');
        if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
            flash('Der Benutzername braucht 3 bis 60 Zeichen aus Buchstaben, Ziffern, Punkt, Unterstrich oder Bindestrich.', 'err');
        } elseif (strlen($password) < 8) {
            flash('Das Passwort braucht mindestens 8 Zeichen.', 'err');
        } else {
            try {
                $st = db()->prepare('INSERT INTO users (username, password_hash, display_name, is_superadmin)
                                     VALUES (?, ?, ?, ?)');
                $st->execute([$username, password_hash($password, PASSWORD_DEFAULT), $display ?: null,
                              isset($_POST['is_superadmin']) ? 1 : 0]);
                flash('Konto ' . $username . ' angelegt.', 'ok');
            } catch (PDOException $e) {
                flash('Diesen Benutzernamen gibt es schon.', 'err');
            }
        }
        redirect('benutzer.php');
    }

    $id = (int) post('id');
    $target = $byId[$id] ?? null;
    if (!$target) {
        flash('Dieses Konto gibt es nicht.', 'err');
        redirect('benutzer.php');
    }
    $name = (string) $target['username'];
    $isSelf = $id === (int) $me['id'];
    $wasSuper = (int) $target['is_superadmin'] === 1;
    $wasActive = (int) $target['active'] === 1;
    $soleAdmin = $wasSuper && $wasActive && $adminCount <= 1;

    if ($action === 'delete') {
        if ($isSelf) {
            flash('Du kannst dein eigenes Konto nicht löschen.', 'err');
        } elseif ($soleAdmin) {
            flash('Der letzte SuperAdmin kann nicht gelöscht werden.', 'err');
        } else {
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            flash('Konto ' . $name . ' gelöscht.', 'ok');
        }
        redirect('benutzer.php');
    }

    if ($action !== 'update') {
        flash('Unbekannte Aktion.', 'err');
        redirect('benutzer.php');
    }

    // Rolle und Status stehen beim eigenen Konto fest: wer sich selbst die
    // SuperAdmin-Rolle entziehen oder das eigene Konto sperren würde, sperrt
    // sich damit aus.
    if ($isSelf) {
        $super = $wasSuper;
        $active = $wasActive;
    } else {
        $super = isset($_POST['is_superadmin']);
        $active = isset($_POST['active']);
        if ($soleAdmin && (!$super || !$active)) {
            flash('Der letzte SuperAdmin muss SuperAdmin bleiben und darf nicht gesperrt werden.', 'err');
            redirect('benutzer.php');
        }
    }

    $newPassword = post('password');
    if ($newPassword !== '' && strlen($newPassword) < 8) {
        flash('Das neue Passwort braucht mindestens 8 Zeichen. Nichts gespeichert.', 'err');
        redirect('benutzer.php');
    }

    $display = text_limit(post('display_name'), 120);
    db()->prepare('UPDATE users SET display_name = ?, is_superadmin = ?, active = ? WHERE id = ?')
        ->execute([$display ?: null, $super ? 1 : 0, $active ? 1 : 0, $id]);
    if ($newPassword !== '') {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $id]);
    }

    $geaendert = [];
    if ($display !== (string) ($target['display_name'] ?? '')) {
        $geaendert[] = 'Anzeigename';
    }
    if ((int) $super !== (int) $wasSuper) {
        $geaendert[] = 'Rolle';
    }
    if ((int) $active !== (int) $wasActive) {
        $geaendert[] = 'Status';
    }
    if ($newPassword !== '') {
        $geaendert[] = 'Passwort';
    }
    flash('Konto ' . $name . ' gespeichert' . ($geaendert ? ': ' . implode(', ', $geaendert) : ' (nichts geändert)') . '.', 'ok');
    redirect('benutzer.php');
}

page_start('Benutzer', 'admin', 'benutzer.php');
?>
<h2>Benutzer</h2>
<p class="lead">Diese Seite ist dem SuperAdmin vorbehalten. Er legt Konten an, ändert sie, sperrt
    oder löscht sie. Alle anderen Konten steuern den gesamten Wettbewerb: Erfassung, Startliste,
    Durchgänge, Vereine, Modelltypen, Anmeldungen, Export und die Einstellungen des jeweiligen
    Wettbewerbs. Das eigene Passwort ändert jeder selbst unter
    <a href="einstellungen.php">Einstellungen</a>.</p>

<div class="panel">
    <h3 style="margin-top:0">Neues Konto anlegen</h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="grid-2">
            <div class="field">
                <label for="nu">Benutzername</label>
                <input type="text" id="nu" name="username" maxlength="60" autocomplete="off" required>
                <p class="hint">Wird für die Anmeldung getippt: Buchstaben, Ziffern, Punkt,
                    Unterstrich oder Bindestrich.</p>
            </div>
            <div class="field">
                <label for="nd">Anzeigename</label>
                <input type="text" id="nd" name="display_name" maxlength="120">
                <p class="hint">Erscheint oben im Kopf, zum Beispiel „Zeitnahme Nord“.</p>
            </div>
            <div class="field">
                <label for="npw">Passwort</label>
                <input type="password" id="npw" name="password" minlength="8" autocomplete="new-password" required>
                <p class="hint">Mindestens 8 Zeichen. Es gibt bewusst keine Rücksetzung per E-Mail;
                    ein vergessenes Passwort setzt der SuperAdmin hier neu.</p>
            </div>
            <div class="check" style="margin-top:26px">
                <input type="checkbox" id="ns" name="is_superadmin" value="1">
                <label for="ns">SuperAdmin – darf selbst Benutzer verwalten</label>
            </div>
        </div>
        <button class="btn" type="submit">Konto anlegen</button>
    </form>
</div>

<div class="panel" style="margin-top:20px">
    <h3 style="margin-top:0">Vorhandene Konten</h3>
    <p class="lead"><?= count($users) . (count($users) === 1 ? ' Konto' : ' Konten') ?>, davon
        <?= $adminCount . ($adminCount === 1 ? ' SuperAdmin' : ' SuperAdmins') ?>.<?php
        if ($adminCount === 1) {
            echo ' Für eine zweite Person mit Benutzerverwaltung leg ein Konto mit dieser Rolle an.';
        }
    ?></p>

    <?php // Ein Formular je Konto, ausserhalb der Tabelle. Die Felder darin binden
          // sich über das form-Attribut, damit die Tabelle gültiges HTML bleibt. ?>
    <?php foreach ($users as $u): ?>
        <form method="post" id="konto<?= (int) $u['id'] ?>" style="display:none">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
        </form>
    <?php endforeach; ?>

    <div class="table-scroll">
    <table class="data dense">
        <thead>
            <tr>
                <th>Konto</th>
                <th>Anzeigename</th>
                <th class="mid">SuperAdmin</th>
                <th class="mid">aktiv</th>
                <th>Passwort neu setzen</th>
                <th>Angelegt</th>
                <th class="no-print"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u):
            $uid = (int) $u['id'];
            $fid = 'konto' . $uid;
            $isSelf = $uid === (int) $me['id'];
            $super = (int) $u['is_superadmin'] === 1;
            $active = (int) $u['active'] === 1;
            $soleAdmin = $super && $active && $adminCount <= 1;
        ?>
            <tr<?= $active ? '' : ' class="cell-missing"' ?>>
                <td>
                    <b><?= h($u['username']) ?></b>
                    <?php if ($isSelf): ?> <span class="tag on">du</span><?php endif; ?>
                    <?php if ($super && !$isSelf): ?> <span class="tag live">SuperAdmin</span><?php endif; ?>
                    <?php if (!$active): ?> <span class="tag off">gesperrt</span><?php endif; ?>
                </td>
                <td>
                    <input type="text" form="<?= $fid ?>" name="display_name" maxlength="120"
                           style="min-width:11rem" value="<?= h($u['display_name'] ?? '') ?>"
                           aria-label="Anzeigename von <?= h($u['username']) ?>">
                </td>
                <td class="mid">
                    <?php if ($isSelf): ?>
                        <span class="tag live" title="Die eigene Rolle lässt sich nicht ändern">ja</span>
                    <?php else: ?>
                        <input type="checkbox" form="<?= $fid ?>" name="is_superadmin" value="1"<?= $super ? ' checked' : '' ?>
                               aria-label="<?= h($u['username']) ?> ist SuperAdmin"
                               <?= $soleAdmin ? 'disabled title="Der letzte SuperAdmin muss SuperAdmin bleiben"' : '' ?>>
                    <?php endif; ?>
                </td>
                <td class="mid">
                    <?php if ($isSelf): ?>
                        <span class="tag on" title="Das eigene Konto lässt sich nicht sperren">ja</span>
                    <?php else: ?>
                        <input type="checkbox" form="<?= $fid ?>" name="active" value="1"<?= $active ? ' checked' : '' ?>
                               aria-label="<?= h($u['username']) ?> ist aktiv"
                               <?= $soleAdmin ? 'disabled title="Der letzte SuperAdmin darf nicht gesperrt werden"' : '' ?>>
                    <?php endif; ?>
                </td>
                <td>
                    <input type="password" form="<?= $fid ?>" name="password" minlength="8"
                           autocomplete="new-password" placeholder="unverändert lassen" style="min-width:11rem"
                           aria-label="Neues Passwort für <?= h($u['username']) ?>">
                </td>
                <td class="small muted nowrap"><?= h(date('d.m.Y', strtotime($u['created_at']))) ?></td>
                <td class="nowrap no-print">
                    <button class="btn ghost" type="submit" form="<?= $fid ?>" name="action" value="update">Übernehmen</button>
                    <button class="btn danger" type="submit" form="<?= $fid ?>" name="action" value="delete"
                            formnovalidate
                            data-confirm-click="Konto <?= h($u['username']) ?> wirklich löschen? Das lässt sich nicht rückgängig machen."<?= ($isSelf || $soleAdmin) ? ' disabled title="' . ($isSelf ? 'Das eigene Konto kann nicht gelöscht werden' : 'Der letzte SuperAdmin kann nicht gelöscht werden') . '"' : '' ?>>Löschen</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="hint" style="margin-top:10px">
        Ein gesperrtes Konto kann sich nicht anmelden, und eine bestehende Sitzung endet beim
        nächsten Aufruf. Das eigene Konto und der letzte SuperAdmin lassen sich weder sperren
        noch löschen. Ein leeres Passwortfeld lässt das Passwort unverändert.
    </p>
</div>

<?php page_end();
