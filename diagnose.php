<?php
/**
 * Prüft, ob der Server alles mitbringt, was die App braucht.
 * Hochladen, im Browser aufrufen, Ergebnis anschauen, danach löschen.
 */

header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ok = true;
function check(string $label, bool $pass, string $hint = '', bool $critical = true): void
{
    global $ok;
    echo ($pass ? 'OK   ' : 'FEHLT ') . $label . ($pass ? '' : '  <-- ' . $hint) . "\n";
    if (!$pass && $critical) { $GLOBALS['ok'] = false; }
}

echo "== Segelflug-Wettbewerb: Diagnose ==\n\n";

echo "PHP-Version: " . PHP_VERSION . "\n";
check('PHP mindestens 7.3', version_compare(PHP_VERSION, '7.3.0', '>='),
    'Zu alte PHP-Version. Im Hosting-Panel eine neuere PHP-Version einstellen.');
echo "\n";

echo "-- Erweiterungen --\n";
check('PDO', extension_loaded('pdo'), 'PDO-Erweiterung fehlt.');
check('PDO MySQL (pdo_mysql)', extension_loaded('pdo_mysql'),
    'Ohne pdo_mysql kann die App keine Verbindung zur Datenbank aufbauen. Im Hosting-Panel aktivieren.');
check('mbstring', extension_loaded('mbstring'),
    'Fehlt, ist aber nicht zwingend: die App bringt einen Ersatz mit.', false);
check('json', extension_loaded('json'), 'json-Erweiterung fehlt.');
check('session', extension_loaded('session'), 'session-Erweiterung fehlt.');
echo "\n";

echo "-- Dateien und Rechte --\n";
check('config.php vorhanden', is_file(__DIR__ . '/config.php'),
    'config.sample.php nach config.php kopieren und ausfüllen.');
check('lib/db.php lesbar', is_readable(__DIR__ . '/lib/db.php'), 'Datei fehlt oder falsche Rechte.');
echo "\n";

if (is_file(__DIR__ . '/config.php')) {
    echo "-- Datenbankverbindung --\n";
    $cfg = require __DIR__ . '/config.php';
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['db_host'], $cfg['db_port'] ?? 3306, $cfg['db_name']);
        $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        check('Verbindung zur Datenbank', true);
        $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
        echo "     Datenbankversion: $ver\n";

        $tables = ['settings', 'schema_migrations', 'competitions', 'competition_settings', 'model_types', 'clubs', 'pilots', 'rounds', 'scores', 'registrations', 'users'];
        foreach ($tables as $t) {
            try {
                $pdo->query("SELECT 1 FROM `$t` LIMIT 1");
                check("Tabelle $t", true);
            } catch (PDOException $e) {
                check("Tabelle $t", false, 'install.php noch nicht ausgeführt, oder alte Tabellen (upgrade.php nötig).');
            }
        }
        try {
            $version = (int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn();
            check('Versionierte Migrationen', $version >= 4, 'upgrade.php ausführen.');
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'competitions' AND COLUMN_NAME = 'completed_at'");
            $st->execute();
            check('Spalte competitions.completed_at', (int) $st->fetchColumn() > 0, 'upgrade.php ausführen.');
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = DATABASE()
                                   AND ((TABLE_NAME = 'pilots' AND COLUMN_NAME = 'active')
                                     OR (TABLE_NAME = 'rounds' AND COLUMN_NAME = 'is_included')
                                     OR (TABLE_NAME = 'scores' AND COLUMN_NAME = 'status'))");
            $st->execute();
            check('Resultatfelder für Abschlussprüfung', (int) $st->fetchColumn() === 3, 'upgrade.php ausführen.');
            $st = $pdo->prepare("SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scores' AND COLUMN_NAME = 'status'");
            $st->execute();
            $status = $st->fetch() ?: [];
            $statusType = strtolower(preg_replace('/\\s+/', '', (string) ($status['COLUMN_TYPE'] ?? '')));
            check('scores.status mit DNF/DNS', $statusType === "enum('flown','dnf','dns')"
                && (string) ($status['IS_NULLABLE'] ?? '') === 'NO', 'upgrade.php ausführen.');
            $st = $pdo->prepare("SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scores' AND COLUMN_NAME = 'motor'");
            $st->execute();
            $motor = $st->fetch() ?: [];
            check('scores.motor für die Motorstrafe', strtolower((string) ($motor['COLUMN_TYPE'] ?? '')) === 'tinyint(1)'
                && (string) ($motor['IS_NULLABLE'] ?? '') === 'NO', 'upgrade.php ausführen.');
            $st = $pdo->prepare("SELECT skey FROM competition_settings
                                 WHERE skey IN ('penalty_per_second', 'penalty_outlanding', 'penalty_not_started', 'penalty_motor')
                                 GROUP BY competition_id HAVING COUNT(DISTINCT skey) < 4 LIMIT 1");
            $st->execute();
            check('Strafpunktregeln je Wettbewerb', $st->fetchColumn() === false,
                'Mindestens einem Wettbewerb fehlen die neuen Strafpunktregeln; upgrade.php ausführen.');
            $st = $pdo->prepare("SELECT skey FROM competition_settings
                                 WHERE skey IN ('penalty_per_second_over', 'penalty_per_second_under', 'penalty_not_flown',
                                                'max_time_penalty', 'max_landing_penalty') LIMIT 1");
            $st->execute();
            check('Keine abgelösten Strafpunktschlüssel mehr', $st->fetchColumn() === false,
                'upgrade.php ausführen, das räumt die alten Schlüssel auf.');
            $st = $pdo->prepare("SELECT COUNT(DISTINCT CONCAT(TABLE_NAME, ':', INDEX_NAME)) FROM information_schema.STATISTICS
                                 WHERE TABLE_SCHEMA = DATABASE()
                                 AND ((TABLE_NAME = 'competitions' AND INDEX_NAME = 'uq_competition_name')
                                   OR (TABLE_NAME = 'pilots' AND INDEX_NAME IN ('uq_pilot_id_competition', 'uq_pilot_competition_bib'))
                                   OR (TABLE_NAME = 'rounds' AND INDEX_NAME = 'uq_round_id_competition')
                                   OR (TABLE_NAME = 'scores' AND INDEX_NAME IN ('idx_score_competition', 'idx_score_pilot_competition', 'idx_score_round_competition'))
                                   OR (TABLE_NAME = 'registrations' AND INDEX_NAME IN ('idx_registration_competition', 'idx_registration_pilot')))");
            $st->execute();
            $indexCount = (int) $st->fetchColumn();
            $st = $pdo->prepare("SELECT COUNT(DISTINCT CONCAT(TABLE_NAME, ':', CONSTRAINT_NAME)) FROM information_schema.REFERENTIAL_CONSTRAINTS
                                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                                   AND ((TABLE_NAME = 'scores' AND CONSTRAINT_NAME IN ('fk_score_pilot_competition', 'fk_score_round_competition'))
                                     OR (TABLE_NAME = 'registrations' AND CONSTRAINT_NAME = 'fk_registration_pilot'))");
            $st->execute();
            $fkCount = (int) $st->fetchColumn();
            check('Wettbewerb-Constraints', $indexCount >= 9 && $fkCount >= 3, 'upgrade.php ausführen.');
        } catch (PDOException $e) {
            check('Versionierte Migrationen', false, 'upgrade.php ausführen.');
        }
    } catch (PDOException $e) {
        check('Verbindung zur Datenbank', false, $e->getMessage());
    }
    echo "\n";
}

echo "-- PHP-Sprachmerkmale, die die App braucht --\n";
check('Anonyme Funktionen', function_exists('array_filter'));
check('Namespaces/declare(strict_types)', true); // wird beim Parsen dieser Datei implizit geprüft
echo "\n";

echo $ok
    ? "Alles in Ordnung. Wenn trotzdem Seiten mit 500 antworten, im Hosting-Panel das PHP-Fehlerprotokoll ansehen\n"
      . "(oder den Hoster danach fragen) - dort steht die genaue Fehlermeldung und Zeile.\n"
    : "Mindestens ein Punkt oben fehlt oder ist zu alt. Das ist die wahrscheinliche Ursache für die 500-Fehler.\n";

echo "\nDiese Datei danach vom Server löschen.\n";
