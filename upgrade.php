<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/scoring.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/migrations.php';

$pdo = db();

// Wenn die Benutzertabelle existiert, darf nur die angemeldete Wettkampfleitung aktualisieren.
// Fehler bei der Prüfung werden bewusst nicht als "keine Benutzer" interpretiert.
try {
    if (migration_table_exists($pdo, 'users')) {
        $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($userCount > 0 && !current_user()) {
            redirect('admin/login.php?next=' . urlencode('/upgrade.php'));
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit('Die Benutzer-/Upgrade-Berechtigung konnte nicht geprüft werden.');
}

$log = [];
$done = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $log = run_pending_migrations($pdo);
        $done = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

page_start('Aktualisierung', 'public', '');
?>
<div class="panel" style="max-width:720px">
<?php if ($done): ?>
    <h2>Aktualisiert</h2>
    <p class="lead">Alle versionierten Datenbankmigrationen wurden erfolgreich angewendet.</p>
    <?php if ($log): ?>
        <ul><?php foreach ($log as $line): ?><li><?= h($line) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <p class="lead">Prüfe die Vereine und Startnummern im Wettkampfbüro und lösche danach <code>upgrade.php</code> vom Server.</p>
    <p><a class="btn" href="admin/vereine.php">Zu den Vereinen</a></p>
<?php else: ?>
    <h2>Versionierte Datenbankaktualisierung</h2>
    <p class="lead">Diese Seite führt die Datenbankschritte in einer festen, versionierten Reihenfolge aus.
        Jeder Schritt wird erst nach erfolgreicher Ausführung in <code>schema_migrations</code> protokolliert
        und kann nach einem Abbruch wiederholt werden.</p>
    <p class="lead">Vorher bitte eine Sicherung der Datenbank anlegen. Die Migration kann bei doppelten
        Startnummern oder unpassenden Score-Zuordnungen anhalten, ohne diese Daten automatisch zu löschen.</p>
    <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <button class="btn big" type="submit">Jetzt aktualisieren</button>
    </form>
<?php endif; ?>
</div>
<?php page_end(); ?>
