<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/migrations.php';

$pdo = db();
$step = '';
$error = '';

// Tabellen vorhanden?
$installed = false;
try {
    $pdo->query('SELECT 1 FROM users LIMIT 1');
    $installed = true;
} catch (PDOException $e) {
    $installed = false;
}

$hasAdmin = $installed && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$hasAdmin) {
    $user = post('username');
    $pass = post('password');
    $pass2 = post('password2');
    $compName = text_limit(post('competition_name', 'Segelflug-Wettbewerb'), 160);

    if (strlen($user) < 3) {
        $error = 'Benutzername braucht mindestens 3 Zeichen.';
    } elseif (strlen($pass) < 8) {
        $error = 'Das Passwort braucht mindestens 8 Zeichen.';
    } elseif ($pass !== $pass2) {
        $error = 'Die beiden Passwörter stimmen nicht überein.';
    } else {
        // 1. Schema
        $sql = file_get_contents(__DIR__ . '/sql/schema.sql');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $stmt = trim((string) preg_replace('/^(?:\s*--[^\n]*(?:\n|$))+/', '', $stmt));
            if (stripos($stmt, 'CREATE') === 0 || stripos($stmt, 'SET') === 0) {
                $pdo->exec($stmt);
            }
        }
        if (!migration_schema_is_current($pdo)) {
            run_pending_migrations($pdo);
        } else {
            migration_mark_current($pdo);
        }

        // 2. Einstellungen
        foreach (setting_defaults() as $k => $v) {
            $st = $pdo->prepare('INSERT IGNORE INTO settings (skey, svalue) VALUES (?, ?)');
            $st->execute([$k, $v]);
        }
        setting_set('competition_name', $compName);

        // 3. Modelltypen
        foreach ([['Segler', 10], ['Elektro', 20]] as [$t, $o]) {
            $st = $pdo->prepare('INSERT IGNORE INTO model_types (name, sort_order) VALUES (?, ?)');
            $st->execute([$t, $o]);
        }

        // 4. Ersten Wettbewerb mit Durchgängen anlegen bzw. einen begonnenen Setup fortsetzen
        require_once __DIR__ . '/lib/competition.php';
        $count = (int) setting('rounds_count', 5);
        $target = (int) setting('default_target_time', 180);
        $competitionCount = (int) $pdo->query('SELECT COUNT(*) FROM competitions')->fetchColumn();
        if ($competitionCount === 0) {
            create_competition($compName !== '' ? $compName : 'Segelflug-Wettbewerb', $count, $target, true);
        } else {
            $existingId = current_competition_id();
            seed_competition_settings($existingId, null);
            ensure_competition_rounds($existingId, $count, $target);
        }

        // 5. Erstes Konto
        if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
            $st = $pdo->prepare('INSERT INTO users (username, password_hash, display_name) VALUES (?, ?, ?)');
            $st->execute([$user, password_hash($pass, PASSWORD_DEFAULT), 'Wettkampfleitung']);
        }

        $step = 'done';
    }
}

require_once __DIR__ . '/lib/layout.php';
page_start('Einrichtung', 'public', '');
?>
<div class="panel" style="max-width:560px">
<?php if ($step === 'done'): ?>
    <h2>Fertig</h2>
    <p class="lead">Datenbank angelegt, Konto erstellt. Lösche jetzt <code>install.php</code> vom Server.</p>
    <p><a class="btn" href="admin/login.php">Zum Wettkampfbüro</a></p>
<?php elseif ($hasAdmin): ?>
    <h2>Bereits eingerichtet</h2>
    <p class="lead">Es existiert schon ein Konto. Lösche <code>install.php</code> vom Server.</p>
    <p><a class="btn" href="admin/login.php">Anmelden</a></p>
<?php else: ?>
    <h2>Einrichtung</h2>
    <p class="lead">Legt die Tabellen an und erstellt das erste Konto für die Wettkampfleitung.</p>
    <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <div class="field">
            <label for="cn">Name des Wettbewerbs</label>
            <input type="text" id="cn" name="competition_name" maxlength="160" value="<?= h(post('competition_name', 'Segelflug-Wettbewerb')) ?>">
        </div>
        <div class="field">
            <label for="u">Benutzername</label>
            <input type="text" id="u" name="username" value="<?= h(post('username')) ?>" autocomplete="username" required>
        </div>
        <div class="grid-2">
            <div class="field">
                <label for="p">Passwort</label>
                <input type="password" id="p" name="password" autocomplete="new-password" required>
                <p class="hint">Mindestens 8 Zeichen.</p>
            </div>
            <div class="field">
                <label for="p2">Passwort wiederholen</label>
                <input type="password" id="p2" name="password2" autocomplete="new-password" required>
            </div>
        </div>
        <button class="btn big" type="submit">Einrichten</button>
    </form>
<?php endif; ?>
</div>
<?php page_end();
