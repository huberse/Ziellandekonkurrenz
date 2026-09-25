<?php
declare(strict_types=1);

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/../config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('config.php fehlt. Kopiere config.sample.php nach config.php und trage die Datenbank-Zugangsdaten ein.');
        }
        $cfg = require $file;
    }
    return $cfg;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config();
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $c['db_host'], $c['db_port'] ?? 3306, $c['db_name']);
        try {
            $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            exit('Keine Verbindung zur Datenbank. Prüfe die Angaben in config.php.');
        }
    }
    return $pdo;
}

function settings_table_missing(PDOException $e): bool
{
    $code = (string) $e->getCode();
    $message = strtolower($e->getMessage());
    return in_array($code, ['42S02', '1146'], true)
        || strpos($message, "base table or view not found") !== false
        || (strpos($message, "doesn't exist") !== false && strpos($message, 'table') !== false);
}

function competition_settings_table_available(): bool
{
    if (function_exists('competition_schema_table_exists')) {
        return competition_schema_table_exists(db(), 'competition_settings');
    }
    try {
        db()->query('SELECT 1 FROM competition_settings LIMIT 1');
        return true;
    } catch (PDOException $e) {
        if (settings_table_missing($e)) {
            return false;
        }
        throw $e;
    }
}

/** Globale Einstellungen als Vorlage; sie sind nie vom Wettbewerbskontext abhängig. */
function global_settings(bool $reload = false): array
{
    static $global = null;
    if ($global === null || $reload) {
        $global = [];
        try {
            foreach (db()->query('SELECT skey, svalue FROM settings') as $row) {
                $global[$row['skey']] = $row['svalue'];
            }
        } catch (PDOException $e) {
            if (!settings_table_missing($e)) {
                throw $e;
            }
            // Vor dem Setup sind nur die Standardwerte verfügbar.
        }
        $global += setting_defaults();
    }
    return $global;
}

/** Alle Einstellungen des aktuell ausgewählten Wettbewerbs als Array key => value. */
function settings(bool $reload = false): array
{
    static $selected = null;
    if ($selected === null || $reload) {
        $selected = global_settings($reload);
        $contextId = isset($GLOBALS['competition_context_id']) && $GLOBALS['competition_context_id'] !== null
            ? (int) $GLOBALS['competition_context_id'] : null;
        if ($contextId !== null && $contextId > 0 && competition_settings_table_available()) {
            try {
                $st = db()->prepare('SELECT skey, svalue FROM competition_settings WHERE competition_id = ?');
                $st->execute([$contextId]);
                foreach ($st as $row) {
                    $selected[$row['skey']] = $row['svalue'];
                }
            } catch (PDOException $e) {
                if (!settings_table_missing($e)) {
                    throw $e;
                }
                // Vor der Wettbewerbsmigration sind globale Werte aktiv.
            }
        }
    }
    return $selected;
}

function setting(string $key, $default = null)
{
    $s = settings();
    return array_key_exists($key, $s) ? $s[$key] : $default;
}

function setting_num(string $key, float $default = 0): float
{
    return (float) setting($key, $default);
}

function setting_bool(string $key, bool $default = false): bool
{
    return (string) setting($key, $default ? '1' : '0') === '1';
}

function global_setting_set(string $key, $value): void
{
    $st = db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    $st->execute([$key, (string) $value]);
    global_settings(true);
    settings(true);
}

function setting_set(string $key, $value): void
{
    $contextId = isset($GLOBALS['competition_context_id']) && $GLOBALS['competition_context_id'] !== null
        ? (int) $GLOBALS['competition_context_id'] : null;
    if ($contextId !== null && $contextId > 0 && competition_settings_table_available()) {
        $st = db()->prepare('INSERT INTO competition_settings (competition_id, skey, svalue) VALUES (?, ?, ?)
                             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
        $st->execute([$contextId, $key, (string) $value]);
        settings(true);
        return;
    }
    // Vor der Wettbewerbsmigration werden Einstellungen als globale Vorlage gespeichert.
    global_setting_set($key, $value);
}

function setting_defaults(): array
{
    return [
        'competition_name'         => 'Segelflug-Wettbewerb',
        'competition_date'         => '',
        'competition_place'        => '',
        'rounds_count'             => '5',
        'default_target_time'      => '180',
        'penalty_per_second'       => '1',   // je Sekunde Abweichung, nach oben wie unten
        'penalty_per_meter'        => '1',   // je Landewert-Einheit
        'penalty_outlanding'       => '100', // Aussenlandung
        'penalty_not_started'      => '100', // nicht angetreten / kein Resultat
        'penalty_motor'            => '100', // Motor angelassen, bei Aussenlandung zusätzlich
        'drop_enabled'             => '1',
        'drop_min_rounds'          => '4',
        'ranking_scope'            => 'group', // group | overall
        'club_ranking_enabled'     => '1',
        'club_scoring_count'       => '3',
        'registration_open'        => '1',
        'registration_info'        => '',
        'registration_sender_email' => '',
        'registration_sender_name'  => '',
        'public_results'           => '1',
    ];
}
