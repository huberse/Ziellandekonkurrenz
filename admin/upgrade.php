<?php
/**
 * Die Datenbankschritte ausfuehren.
 *
 * Frueher lag diese Seite im Hauptverzeichnis und war fuer jeden erreichbar,
 * der die Adresse kannte. Das ist kein Werkzeug, sondern ein Ablauf, und
 * jemand, der ihn anstoesst, haette an der Datenbank des Vereins zu tun.
 * Sie liegt deshalb in admin/ und laeuft nur fuer den angemeldeten SuperAdmin.
 *
 * Der Aufruf kommt aus zwei Richtungen: aus den Seiten, die ein zu altes
 * Schema erkannt haben und um Hilfe bitten, und aus der Aktualisierungsseite
 * im Wettkampfbuerout, die nach dem Datei-Update darauf hinweist.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/scoring.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/migrations.php';

$pdo = db();

// Nur der SuperAdmin. Das ist strenger als vorher, wo jedes angemeldete
// Konto genuegte - auch das eines Vereins, der sonst nichts anfasst.
//
// Der Hinweis an die eigene Wettkampfleitung ist bewusst derselbe Weg wie
// ueberall: zur Anmeldung und danach an diese Seite zurueck. Wer kein
// SuperAdmin ist, sieht nicht diese Meldung, sondern die der Benutzerverwaltung.
$benutzerTabellen = false;
try {
    $benutzerTabellen = migration_table_exists($pdo, 'users');
} catch (Throwable $e) {
    http_response_code(500);
    exit('Die Benutzertabelle konnte nicht geprüft werden: ' . $e->getMessage());
}

if ($benutzerTabellen) {
    $anzahl = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    // Vor der ersten Anmeldung gibt es noch keine Konten. Dann darf laufen,
    // sonst koennte niemand das Programm ueberhaupt in Betrieb nehmen.
    if ($anzahl > 0 && !current_user()) {
        redirect('login.php?next=' . urlencode('/admin/upgrade.php'));
    }
    if ($anzahl > 0 && !is_superadmin()) {
        flash('Die Datenbankaktualisierung ist dem SuperAdmin vorbehalten.', 'err');
        redirect('index.php');
    }
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

page_start('Aktualisierung', 'admin', 'aktualisieren.php');
?>
<div class="panel" style="max-width:720px">
<?php if ($done): ?>
    <h2>Aktualisiert</h2>
    <p class="lead">Alle versionierten Datenbankmigrationen wurden erfolgreich angewendet.</p>
    <?php if ($log): ?>
        <ul><?php foreach ($log as $line): ?><li><?= h($line) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <p class="lead">Prüfe die Vereine und Startnummern im Wettkampfbüro.</p>
    <p class="small muted">Diese Seite kann hier liegen bleiben: sie ist nur für den angemeldeten
        SuperAdmin erreichbar und läuft ohne ihn nichts. Wer sie nicht mehr braucht, kann sie
        löschen.</p>
    <p><a class="btn" href="vereine.php">Zu den Vereinen</a></p>
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
<?php page_end();
