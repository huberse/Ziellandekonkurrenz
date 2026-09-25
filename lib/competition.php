<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Alle Wettbewerbe, neueste zuerst. */
function all_competitions(): array
{
    return db()->query('SELECT * FROM competitions ORDER BY id DESC')->fetchAll();
}

/** Wettbewerbe, die noch nicht beendet sind, neueste zuerst. */
function open_competitions(): array
{
    return db()->query('SELECT * FROM competitions WHERE completed_at IS NULL ORDER BY id DESC')->fetchAll();
}

/**
 * Aktiver Wettbewerb. Der Fallback auf seasons hält die Upgrade-Seite
 * auch dann lesbar, solange die Datenbankmigration noch nicht gelaufen ist.
 */
function current_competition(): array
{
    if (array_key_exists('current_competition_cache', $GLOBALS)) {
        return $GLOBALS['current_competition_cache'];
    }

    try {
        $row = db()->query('SELECT * FROM competitions WHERE is_current = 1 ORDER BY id DESC LIMIT 1')->fetch();
        if (!$row) {
            $hasCompletedColumn = function_exists('competition_schema_column_exists')
                && competition_schema_column_exists(db(), 'competitions', 'completed_at');
            $fallbackSql = $hasCompletedColumn
                ? 'SELECT * FROM competitions WHERE completed_at IS NULL ORDER BY id DESC LIMIT 1'
                : 'SELECT * FROM competitions ORDER BY id DESC LIMIT 1';
            $row = db()->query($fallbackSql)->fetch();
            if (!$row && $hasCompletedColumn) {
                $row = db()->query('SELECT * FROM competitions ORDER BY id DESC LIMIT 1')->fetch();
            }
            if ($row && (!$hasCompletedColumn || $row['completed_at'] === null)) {
                db()->prepare('UPDATE competitions SET is_current = 1 WHERE id = ?')->execute([$row['id']]);
                $row['is_current'] = 1;
            }
        }
    } catch (PDOException $e) {
        if (function_exists('competition_schema_table_exists')
            && competition_schema_table_exists(db(), 'competitions')) {
            throw $e;
        }
        try {
            $row = db()->query('SELECT * FROM seasons WHERE is_current = 1 ORDER BY id DESC LIMIT 1')->fetch();
            if (!$row) {
                $row = db()->query('SELECT * FROM seasons ORDER BY id DESC LIMIT 1')->fetch();
            }
        } catch (PDOException $ignored) {
            $row = false;
        }
    }

    if ($row) {
        $GLOBALS['current_competition_cache'] = $row;
        return $row;
    }

    // Ein leerer Wettbewerbsbestand wird nicht aus einem Lesezugriff heraus
    // verändert. Das Anlegen passiert explizit in Installation/Verwaltung.
    $GLOBALS['current_competition_cache'] = ['id' => 0, 'name' => '', 'is_current' => 0];
    return $GLOBALS['current_competition_cache'];
}

function current_competition_id(): int
{
    return (int) current_competition()['id'];
}

/** Kontext für alle pro Wettbewerb gespeicherten Einstellungen. */
function competition_context_id(): int
{
    if (isset($GLOBALS['competition_context_id']) && $GLOBALS['competition_context_id'] !== null) {
        return (int) $GLOBALS['competition_context_id'];
    }
    return current_competition_id();
}

/** Setzt den Wettbewerb, dessen Einstellungen in dieser Anfrage verwendet werden. */
function set_competition_context(?int $competitionId): void
{
    $GLOBALS['competition_context_id'] = $competitionId === null ? null : (int) $competitionId;
    settings(true);
}

/**
 * Stellt sicher, dass Seiten ohne eigene Auswahl den aktiven Wettbewerb
 * als Einstellungskontext verwenden.
 */
function ensure_competition_context(): int
{
    if (!isset($GLOBALS['competition_context_id']) || $GLOBALS['competition_context_id'] === null) {
        set_competition_context(current_competition_id());
    }
    return competition_context_id();
}

/** Wettbewerb, der durch den Kontext ausgewählt ist. */
function selected_competition(): array
{
    $id = competition_context_id();
    if ($id > 0) {
        $competition = find_competition($id);
        if ($competition) {
            return $competition;
        }
    }
    return current_competition();
}

function competition_setting(int $competitionId, string $key, $default = null)
{
    try {
        $st = db()->prepare('SELECT svalue FROM competition_settings WHERE competition_id = ? AND skey = ?');
        $st->execute([$competitionId, $key]);
        $value = $st->fetchColumn();
        if ($value !== false) {
            return $value;
        }
    } catch (PDOException $e) {
        if (!settings_table_missing($e)) {
            throw $e;
        }
        // Vor der Wettbewerbsmigration bzw. vor dem Setup sind globale Werte aktiv.
    }

    $global = global_settings();
    if (array_key_exists($key, $global)) {
        return $global[$key];
    }
    return $default;
}

/** Wettbewerb zu einer ID, oder null. */
function find_competition(int $id): ?array
{
    try {
        $st = db()->prepare('SELECT * FROM competitions WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    } catch (PDOException $e) {
        if (function_exists('competition_schema_table_exists')
            && competition_schema_table_exists(db(), 'competitions')) {
            throw $e;
        }
        // Vor der Migration liefert die Upgrade-Seite noch den alten Saisonsdatensatz.
        try {
            $st = db()->prepare('SELECT * FROM seasons WHERE id = ?');
            $st->execute([$id]);
            return $st->fetch() ?: null;
        } catch (PDOException $ignored) {
            return null;
        }
    }
}

/** Wettbewerb aus einem competition-Query-Parameter auflösen und Kontext setzen. */
function resolve_competition_param(string $raw): array
{
    if ($raw !== '' && ($competition = find_competition((int) $raw))) {
        set_competition_context((int) $competition['id']);
        return $competition;
    }
    $competition = current_competition();
    set_competition_context((int) $competition['id']);
    return $competition;
}

/** Liest competition aus GET/POST und akzeptiert die alte season-URL als Kompatibilität. */
function competition_request_param(): string
{
    $value = null;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['competition'])) {
        $value = $_POST['competition'];
    } elseif (isset($_GET['competition'])) {
        $value = $_GET['competition'];
    } elseif (isset($_POST['competition'])) {
        $value = $_POST['competition'];
    } elseif (isset($_GET['season'])) {
        $value = $_GET['season'];
    } elseif (isset($_POST['season'])) {
        $value = $_POST['season'];
    }
    if (!is_scalar($value)) {
        return '';
    }
    $value = trim((string) $value);
    return preg_match('/^\d+$/', $value) ? (string) (int) $value : '';
}

/** Aktiviert einen Wettbewerb transaktional. */
function set_current_competition(int $id): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $check = $pdo->prepare('SELECT * FROM competitions WHERE id = ? FOR UPDATE');
        $check->execute([$id]);
        $row = $check->fetch();
        if (!$row) {
            throw new RuntimeException('Wettbewerb nicht gefunden.');
        }
        if ($row['completed_at'] !== null) {
            throw new DomainException('Abgeschlossene Wettbewerbe können nicht aktiviert werden.');
        }
        $pdo->exec('UPDATE competitions SET is_current = 0');
        $pdo->prepare('UPDATE competitions SET is_current = 1 WHERE id = ?')->execute([$id]);
        $pdo->commit();
        $GLOBALS['current_competition_cache'] = $row;
        $GLOBALS['current_competition_cache']['is_current'] = 1;
        set_competition_context($id);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Einstellungen, die ein neuer Wettbewerb nicht erbt. Die Absenderadresse der
 * Anmeldebestätigung gehört jedem Verein selbst und darf nicht aus dem
 * Wettbewerb eines anderen Vereins in einen neuen Wettbewerb kopiert werden.
 */
function competition_settings_not_inherited(): array
{
    return ['registration_sender_email', 'registration_sender_name'];
}

/** Kopiert Einstellungen als Vorlage in einen neuen Wettbewerb. */
function seed_competition_settings(int $competitionId, ?int $sourceCompetitionId = null): void
{
    $rows = [];
    if ($sourceCompetitionId !== null && $sourceCompetitionId > 0 && $sourceCompetitionId !== $competitionId) {
        try {
            $st = db()->prepare('SELECT skey, svalue FROM competition_settings WHERE competition_id = ?');
            $st->execute([$sourceCompetitionId]);
            foreach ($st as $row) {
                $rows[$row['skey']] = $row['svalue'];
            }
        } catch (PDOException $e) {
            if (!settings_table_missing($e)) {
                throw $e;
            }
            $rows = [];
        }
    }
    if (!$rows) {
        try {
            foreach (db()->query('SELECT skey, svalue FROM settings') as $row) {
                $rows[$row['skey']] = $row['svalue'];
            }
        } catch (PDOException $e) {
            if (!settings_table_missing($e)) {
                throw $e;
            }
            // Vor dem Setup sind nur die Standardwerte verfügbar.
        }
    }
    foreach (competition_settings_not_inherited() as $key) {
        unset($rows[$key]);
    }
    foreach (setting_defaults() as $key => $value) {
        if (!array_key_exists($key, $rows)) {
            $rows[$key] = (string) $value;
        }
    }

    $st = db()->prepare('INSERT INTO competition_settings (competition_id, skey, svalue) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    foreach ($rows as $key => $value) {
        $st->execute([$competitionId, $key, (string) $value]);
    }

    $name = db()->prepare('SELECT name FROM competitions WHERE id = ?');
    $name->execute([$competitionId]);
    $competitionName = (string) ($name->fetchColumn() ?: 'Wettbewerb');
    $st = db()->prepare('INSERT INTO competition_settings (competition_id, skey, svalue) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    $st->execute([$competitionId, 'competition_name', $competitionName]);
}

/** Neuen Wettbewerb mit eigenen Durchgängen und Einstellungen anlegen. */
function create_competition(string $name, int $roundsCount, int $targetTime, bool $makeCurrent = false): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('INSERT INTO competitions (name) VALUES (?)');
        $st->execute([$name]);
        $competitionId = (int) $pdo->lastInsertId();

        $sourceCompetitionId = null;
        if (isset($GLOBALS['competition_context_id']) && $GLOBALS['competition_context_id'] !== null) {
            $sourceCompetitionId = (int) $GLOBALS['competition_context_id'];
        } else {
            $sourceCompetitionId = (int) ($pdo->query('SELECT id FROM competitions WHERE is_current = 1 ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 0);
            $sourceCompetitionId = $sourceCompetitionId > 0 ? $sourceCompetitionId : null;
        }
        seed_competition_settings($competitionId, $sourceCompetitionId);

        $roundsCount = max(1, min(30, $roundsCount));
        $ins = $pdo->prepare('INSERT INTO rounds (competition_id, round_number, target_time_seconds, is_active) VALUES (?, ?, ?, ?)');
        for ($i = 1; $i <= $roundsCount; $i++) {
            $ins->execute([$competitionId, $i, $targetTime, $i === 1 ? 1 : 0]);
        }
        if ($makeCurrent) {
            $pdo->exec('UPDATE competitions SET is_current = 0');
            $pdo->prepare('UPDATE competitions SET is_current = 1 WHERE id = ?')->execute([$competitionId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    if ($makeCurrent) {
        $st = $pdo->prepare('SELECT * FROM competitions WHERE id = ?');
        $st->execute([$competitionId]);
        $GLOBALS['current_competition_cache'] = $st->fetch() ?: ['id' => $competitionId, 'name' => $name, 'is_current' => 1];
        $GLOBALS['current_competition_cache']['is_current'] = 1;
        set_competition_context($competitionId);
    }
    return $competitionId;
}

function ensure_competition_rounds(int $competitionId, int $roundsCount, int $targetTime): void
{
    $roundsCount = max(1, min(30, $roundsCount));
    $st = db()->prepare('SELECT round_number FROM rounds WHERE competition_id = ?');
    $st->execute([$competitionId]);
    $existing = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $ins = db()->prepare('INSERT INTO rounds (competition_id, round_number, target_time_seconds, is_active)
                          VALUES (?, ?, ?, ?)');
    for ($i = 1; $i <= $roundsCount; $i++) {
        if (!in_array($i, $existing, true)) {
            $ins->execute([$competitionId, $i, $targetTime, 0]);
        }
    }
    $active = db()->prepare('SELECT COUNT(*) FROM rounds WHERE competition_id = ? AND is_active = 1');
    $active->execute([$competitionId]);
    if ((int) $active->fetchColumn() === 0) {
        db()->prepare('UPDATE rounds SET is_active = 1 WHERE competition_id = ? AND round_number = 1')->execute([$competitionId]);
    }
}

function competition_stats(int $competitionId): array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT COUNT(*) FROM rounds WHERE competition_id = ?');
    $st->execute([$competitionId]);
    $rounds = (int) $st->fetchColumn();

    $st = $pdo->prepare('SELECT COUNT(DISTINCT s.pilot_id) FROM scores s
                         JOIN rounds r ON r.id = s.round_id
                         JOIN pilots p ON p.id = s.pilot_id AND p.active = 1
                         WHERE r.competition_id = ?');
    $st->execute([$competitionId]);
    $pilots = (int) $st->fetchColumn();

    $st = $pdo->prepare('SELECT COUNT(*) FROM scores s JOIN rounds r ON r.id = s.round_id WHERE r.competition_id = ?');
    $st->execute([$competitionId]);
    $scores = (int) $st->fetchColumn();

    return ['rounds' => $rounds, 'pilots' => $pilots, 'scores' => $scores];
}

/** Anzahl Piloten je Wettbewerb als ID => Anzahl, für Auswahlfelder. */
function competition_pilot_counts(): array
{
    $counts = [];
    foreach (db()->query('SELECT competition_id, COUNT(*) AS total FROM pilots GROUP BY competition_id') as $row) {
        $counts[(int) $row['competition_id']] = (int) $row['total'];
    }
    return $counts;
}

/**
 * Durchgänge, Resultate und offene Anmeldungen je Wettbewerb in einer Abfrage:
 * id => ['rounds' => int, 'scores' => int, 'registrations' => int].
 */
function competition_counts(): array
{
    $rows = db()->query(
        'SELECT c.id,
                (SELECT COUNT(*) FROM rounds r WHERE r.competition_id = c.id) AS rounds,
                (SELECT COUNT(*) FROM scores s WHERE s.competition_id = c.id) AS scores,
                (SELECT COUNT(*) FROM registrations g
                  WHERE g.competition_id = c.id AND g.status = \'pending\') AS registrations
         FROM competitions c'
    )->fetchAll();
    $counts = [];
    foreach ($rows as $row) {
        $counts[(int) $row['id']] = [
            'rounds' => (int) $row['rounds'],
            'scores' => (int) $row['scores'],
            'registrations' => (int) $row['registrations'],
        ];
    }
    return $counts;
}

/** Fortschritt der vollständigen Resultate: aktive Piloten × alle gewerteten Runden. */
function competition_result_progress(int $competitionId): array
{
    $pdo = db();
    $pilotStmt = $pdo->prepare('SELECT COUNT(*) FROM pilots WHERE competition_id = ? AND active = 1');
    $pilotStmt->execute([$competitionId]);
    $pilots = (int) $pilotStmt->fetchColumn();

    $roundStmt = $pdo->prepare('SELECT COUNT(*) FROM rounds WHERE competition_id = ? AND is_included = 1');
    $roundStmt->execute([$competitionId]);
    $rounds = (int) $roundStmt->fetchColumn();

    $total = $pilots * $rounds;
    $missingStmt = $pdo->prepare('SELECT COUNT(*)
                                  FROM pilots p
                                  JOIN rounds r ON r.competition_id = p.competition_id
                                    AND r.is_included = 1
                                  WHERE p.competition_id = ? AND p.active = 1
                                    AND NOT EXISTS (
                                        SELECT 1
                                        FROM scores s
                                        WHERE s.pilot_id = p.id
                                          AND s.round_id = r.id
                                          AND s.competition_id = p.competition_id
                                    )');
    $missingStmt->execute([$competitionId]);
    $missing = min($total, max(0, (int) $missingStmt->fetchColumn()));
    $completed = $total - $missing;

    return [
        'pilots' => $pilots,
        'rounds' => $rounds,
        'total' => $total,
        'completed' => $completed,
        'missing' => $missing,
        'percent' => $total > 0 ? (int) round($completed / $total * 100) : 0,
        'complete' => $pilots > 0 && $rounds > 0 && $missing === 0,
    ];
}

function competition_is_completed(int $competitionId): bool
{
    $st = db()->prepare('SELECT completed_at FROM competitions WHERE id = ?');
    $st->execute([$competitionId]);
    $value = $st->fetchColumn();
    return $value !== false && $value !== null;
}

/**
 * Liest eine Anmeldung direkt nach dem Schreiben zurück. Erst dieser Treffer
 * belegt, dass die Zeile wirklich in der Datenbank steht; danach darf die
 * Anmeldebestätigung verschickt werden.
 */
function registration_read_back(int $registrationId, int $competitionId): ?array
{
    if ($registrationId <= 0 || $competitionId <= 0) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM registrations WHERE id = ? AND competition_id = ?');
    $st->execute([$registrationId, $competitionId]);
    return $st->fetch() ?: null;
}

function global_category_used_in_completed(string $category, int $categoryId): bool
{
    $column = $category === 'club' ? 'club_id' : ($category === 'model_type' ? 'model_type_id' : null);
    if ($column === null) {
        throw new InvalidArgumentException('Unbekannte globale Kategorie.');
    }
    $pdo = db();
    $st = $pdo->prepare("SELECT COUNT(*)
                         FROM pilots p
                         JOIN competitions c ON c.id = p.competition_id
                         WHERE p.$column = ? AND c.completed_at IS NOT NULL");
    $st->execute([$categoryId]);
    if ((int) $st->fetchColumn() > 0) {
        return true;
    }
    $st = $pdo->prepare("SELECT COUNT(*)
                         FROM registrations r
                         JOIN competitions c ON c.id = r.competition_id
                         WHERE r.$column = ? AND c.completed_at IS NOT NULL");
    $st->execute([$categoryId]);
    return (int) $st->fetchColumn() > 0;
}

/**
 * Sperrt einen Wettbewerb für die Dauer einer Schreibtransaktion.
 * Dadurch kann ein Abschluss nicht zwischen Statusprüfung und Schreibvorgang liegen.
 */
function lock_all_competitions(PDO $pdo): void
{
    $pdo->query('SELECT id FROM competitions ORDER BY id FOR UPDATE');
}

function lock_open_competition(PDO $pdo, int $competitionId): array
{
    $st = $pdo->prepare('SELECT * FROM competitions WHERE id = ? FOR UPDATE');
    $st->execute([$competitionId]);
    $competition = $st->fetch();
    if (!$competition) {
        throw new RuntimeException('Wettbewerb nicht gefunden.');
    }
    if ($competition['completed_at'] !== null) {
        throw new DomainException('Dieser Wettbewerb ist abgeschlossen und gesperrt.');
    }
    return $competition;
}

/** Wettbewerb abschliessen; der aktive Wettbewerb bleibt dabei unverändert. */
function complete_competition(int $competitionId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM competitions WHERE id = ? FOR UPDATE');
        $st->execute([$competitionId]);
        $competition = $st->fetch();
        if (!$competition) {
            throw new RuntimeException('Wettbewerb nicht gefunden.');
        }
        $progress = competition_result_progress($competitionId);
        $alreadyCompleted = $competition['completed_at'] !== null;
        if (!$alreadyCompleted && !$progress['complete']) {
            throw new DomainException('Noch nicht alle Resultate sind erfasst.');
        }
        if (!$alreadyCompleted) {
            $update = $pdo->prepare('UPDATE competitions SET completed_at = NOW() WHERE id = ? AND completed_at IS NULL');
            $update->execute([$competitionId]);
        }
        $pdo->commit();
        return $progress;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Einen abgeschlossenen Wettbewerb bewusst wieder zur Bearbeitung öffnen. */
function reopen_competition(int $competitionId): void
{
    $st = db()->prepare('UPDATE competitions SET completed_at = NULL WHERE id = ?');
    $st->execute([$competitionId]);
    if ($st->rowCount() === 0) {
        $check = db()->prepare('SELECT id FROM competitions WHERE id = ?');
        $check->execute([$competitionId]);
        if (!$check->fetchColumn()) {
            throw new RuntimeException('Wettbewerb nicht gefunden.');
        }
    }
}

function competition_schema_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$table]);
    return (int) $st->fetchColumn() > 0;
}

function competition_schema_column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    return (int) $st->fetchColumn() > 0;
}

function competition_schema_column_definition(PDO $pdo, string $table, string $column): ?array
{
    $st = $pdo->prepare('SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
                         FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    $row = $st->fetch();
    return $row ?: null;
}

function competition_schema_status_is_valid(PDO $pdo): bool
{
    $definition = competition_schema_column_definition($pdo, 'scores', 'status');
    if (!$definition) {
        return false;
    }
    $type = strtolower((string) $definition['COLUMN_TYPE']);
    $type = preg_replace('/\\s+/', '', $type);
    return $type === "enum('flown','dnf','dns')"
        && (string) $definition['IS_NULLABLE'] === 'NO';
}

function competition_schema_completed_column_is_valid(PDO $pdo): bool
{
    $definition = competition_schema_column_definition($pdo, 'competitions', 'completed_at');
    if (!$definition) {
        return false;
    }
    return stripos((string) $definition['COLUMN_TYPE'], 'datetime') === 0
        && (string) $definition['IS_NULLABLE'] === 'YES';
}

function competition_schema_index(PDO $pdo, string $table, string $index): ?array
{
    $st = $pdo->prepare('SELECT COLUMN_NAME, NON_UNIQUE
                         FROM information_schema.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
                         ORDER BY SEQ_IN_INDEX');
    $st->execute([$table, $index]);
    $rows = $st->fetchAll();
    if (!$rows) {
        return null;
    }
    return [
        'columns' => array_map(static function (array $row): string { return (string) $row['COLUMN_NAME']; }, $rows),
        'unique' => (int) $rows[0]['NON_UNIQUE'] === 0,
    ];
}

function competition_schema_foreign_key(PDO $pdo, string $table, string $constraint): ?array
{
    $st = $pdo->prepare('SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                         FROM information_schema.KEY_COLUMN_USAGE
                         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
                         ORDER BY ORDINAL_POSITION');
    $st->execute([$table, $constraint]);
    $rows = $st->fetchAll();
    if (!$rows || $rows[0]['REFERENCED_TABLE_NAME'] === null) {
        return null;
    }
    return [
        'columns' => array_map(static function (array $row): string { return (string) $row['COLUMN_NAME']; }, $rows),
        'referenced_table' => (string) $rows[0]['REFERENCED_TABLE_NAME'],
        'referenced_columns' => array_map(static function (array $row): string { return (string) $row['REFERENCED_COLUMN_NAME']; }, $rows),
    ];
}

function competition_schema_foreign_key_count(PDO $pdo, string $table): int
{
    $st = $pdo->prepare('SELECT COUNT(DISTINCT CONSTRAINT_NAME)
                         FROM information_schema.KEY_COLUMN_USAGE
                         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?
                           AND REFERENCED_TABLE_NAME IS NOT NULL');
    $st->execute([$table]);
    return (int) $st->fetchColumn();
}

function competition_schema_diagnostics(PDO $pdo, bool $withLaterColumns = true): array
{
    $issues = [];
    try {
        foreach (['competitions', 'competition_settings', 'pilots', 'rounds', 'scores', 'registrations'] as $table) {
            if (!competition_schema_table_exists($pdo, $table)) {
                $issues[] = 'Tabelle ' . $table . ' fehlt';
            }
        }
        if (competition_schema_table_exists($pdo, 'seasons')) {
            $issues[] = 'Tabelle seasons ist noch vorhanden';
        }
        $columns = [
            'competitions' => ['id', 'name', 'is_current', 'completed_at'],
            'competition_settings' => ['competition_id', 'skey', 'svalue'],
            'pilots' => ['id', 'competition_id', 'bib_number', 'active'],
            'rounds' => ['id', 'competition_id', 'round_number', 'is_included'],
            'scores' => ['id', 'pilot_id', 'round_id', 'competition_id', 'status'],
            'registrations' => ['competition_id'],
        ];
        if ($withLaterColumns) {
            $columns['scores'][] = 'motor';
            $columns['users'] = ['id', 'username', 'is_superadmin', 'active'];
        }
        foreach ($columns as $table => $required) {
            foreach ($required as $column) {
                if (!competition_schema_column_exists($pdo, $table, $column)) {
                    $issues[] = $table . '.' . $column . ' fehlt';
                }
            }
        }
        if (!competition_schema_status_is_valid($pdo)) {
            $issues[] = 'scores.status muss ein NOT NULL ENUM mit flown, dnf und dns sein';
        }
        if (!competition_schema_completed_column_is_valid($pdo)) {
            $issues[] = 'competitions.completed_at muss ein nullable DATETIME sein';
        }
        $length = $pdo->prepare('SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $length->execute(['competitions', 'name']);
        if ((int) $length->fetchColumn() < 160) {
            $issues[] = 'competitions.name ist kürzer als 160 Zeichen';
        }
        $indexes = [
            ['competitions', 'uq_competition_name', ['name'], true],
            ['pilots', 'idx_pilot_competition', ['competition_id'], false],
            ['pilots', 'uq_pilot_id_competition', ['id', 'competition_id'], true],
            ['pilots', 'uq_pilot_competition_bib', ['competition_id', 'bib_number'], true],
            ['rounds', 'uq_round_number', ['competition_id', 'round_number'], true],
            ['rounds', 'uq_round_id_competition', ['id', 'competition_id'], true],
            ['scores', 'uq_pilot_round', ['pilot_id', 'round_id'], true],
            ['scores', 'idx_score_competition', ['competition_id'], false],
            ['scores', 'idx_score_pilot_competition', ['pilot_id', 'competition_id'], false],
            ['scores', 'idx_score_round_competition', ['round_id', 'competition_id'], false],
            ['registrations', 'idx_registration_competition', ['competition_id'], false],
            ['registrations', 'idx_registration_pilot', ['pilot_id'], false],
            ['competition_settings', 'PRIMARY', ['competition_id', 'skey'], true],
        ];
        foreach ($indexes as [$table, $name, $expected, $unique]) {
            $actual = competition_schema_index($pdo, $table, $name);
            if ($actual === null) {
                $issues[] = 'Index ' . $table . '.' . $name . ' fehlt';
            } elseif ($actual['columns'] !== $expected || $actual['unique'] !== $unique) {
                $issues[] = 'Index ' . $table . '.' . $name . ' hat eine andere Definition';
            }
        }
        $foreignKeys = [
            ['pilots', 'fk_pilot_competition', ['competition_id'], 'competitions', ['id']],
            ['rounds', 'fk_round_competition', ['competition_id'], 'competitions', ['id']],
            ['registrations', 'fk_registration_competition', ['competition_id'], 'competitions', ['id']],
            ['scores', 'fk_score_pilot_competition', ['pilot_id', 'competition_id'], 'pilots', ['id', 'competition_id']],
            ['scores', 'fk_score_round_competition', ['round_id', 'competition_id'], 'rounds', ['id', 'competition_id']],
            ['registrations', 'fk_registration_pilot', ['pilot_id'], 'pilots', ['id']],
            ['competition_settings', 'fk_competition_settings_competition', ['competition_id'], 'competitions', ['id']],
        ];
        foreach ($foreignKeys as [$table, $name, $columns, $referencedTable, $referencedColumns]) {
            $actual = competition_schema_foreign_key($pdo, $table, $name);
            if ($actual === null) {
                $issues[] = 'Foreign Key ' . $table . '.' . $name . ' fehlt';
            } elseif ($actual['columns'] !== $columns || $actual['referenced_table'] !== $referencedTable
                || $actual['referenced_columns'] !== $referencedColumns) {
                $issues[] = 'Foreign Key ' . $table . '.' . $name . ' hat eine andere Definition';
            }
        }
        foreach (['pilots' => 3, 'rounds' => 1, 'scores' => 2, 'registrations' => 2, 'competition_settings' => 1] as $table => $expected) {
            if (competition_schema_foreign_key_count($pdo, $table) < $expected) {
                $issues[] = 'Zu wenige Foreign Keys auf ' . $table;
            }
        }
        foreach (['pilots', 'rounds', 'scores', 'registrations'] as $table) {
            if (competition_schema_column_exists($pdo, $table, 'season_id')) {
                $issues[] = $table . '.season_id ist noch vorhanden';
            }
        }
    } catch (Throwable $e) {
        $issues[] = 'Schemainformationen nicht lesbar: ' . $e->getMessage();
    }
    return array_values(array_unique($issues));
}

/**
 * Strikte Prüfung der kanonischen Wettbewerbsstruktur ohne Migration-Marker.
 *
 * $withLaterColumns verlangt zusätzlich die Spalten, die erst mit den späteren
 * Migrationen kommen (scores.motor, users.is_superadmin, users.active). Das wird
 * von der Wettbewerbsmigration selbst nicht erwartet, weil sie vorher läuft.
 */
function competition_schema_is_ready(PDO $pdo, bool $withLaterColumns = true): bool
{
    try {
        foreach (['competitions', 'competition_settings', 'pilots', 'rounds', 'scores', 'registrations'] as $table) {
            if (!competition_schema_table_exists($pdo, $table)) {
                return false;
            }
        }
        if (competition_schema_table_exists($pdo, 'seasons')) {
            return false;
        }
        $columns = [
            'competitions' => ['id', 'name', 'is_current', 'completed_at'],
            'competition_settings' => ['competition_id', 'skey', 'svalue'],
            'pilots' => ['id', 'competition_id', 'bib_number', 'active'],
            'rounds' => ['id', 'competition_id', 'round_number', 'is_included'],
            'scores' => ['id', 'pilot_id', 'round_id', 'competition_id', 'status'],
            'registrations' => ['competition_id'],
        ];
        if ($withLaterColumns) {
            $columns['scores'][] = 'motor';
            $columns['users'] = ['id', 'username', 'is_superadmin', 'active'];
        }
        foreach ($columns as $table => $required) {
            foreach ($required as $column) {
                if (!competition_schema_column_exists($pdo, $table, $column)) {
                    return false;
                }
            }
        }
        if (!competition_schema_status_is_valid($pdo) || !competition_schema_completed_column_is_valid($pdo)) {
            return false;
        }
        foreach (['pilots', 'rounds', 'scores', 'registrations'] as $table) {
            if (competition_schema_column_exists($pdo, $table, 'season_id')) {
                return false;
            }
        }
        if (competition_schema_table_exists($pdo, 'pilot_migration_map')
            && competition_schema_column_exists($pdo, 'pilot_migration_map', 'season_id')) {
            return false;
        }
        // Alte Namen/zusätzliche gleichartige Constraints werden von der
        // Migration bestmöglichst bereinigt, sind aber kein Grund, einen
        // otherwise vollständigen Wettbewerb als nicht lauffähig abzuweisen.
        $length = $pdo->prepare('SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $length->execute(['competitions', 'name']);
        if ((int) $length->fetchColumn() < 160) {
            return false;
        }

        $indexes = [
            ['competitions', 'uq_competition_name', ['name'], true],
            ['pilots', 'idx_pilot_competition', ['competition_id'], false],
            ['pilots', 'uq_pilot_id_competition', ['id', 'competition_id'], true],
            ['pilots', 'uq_pilot_competition_bib', ['competition_id', 'bib_number'], true],
            ['rounds', 'uq_round_number', ['competition_id', 'round_number'], true],
            ['rounds', 'uq_round_id_competition', ['id', 'competition_id'], true],
            ['scores', 'uq_pilot_round', ['pilot_id', 'round_id'], true],
            ['scores', 'idx_score_competition', ['competition_id'], false],
            ['scores', 'idx_score_pilot_competition', ['pilot_id', 'competition_id'], false],
            ['scores', 'idx_score_round_competition', ['round_id', 'competition_id'], false],
            ['registrations', 'idx_registration_competition', ['competition_id'], false],
            ['registrations', 'idx_registration_pilot', ['pilot_id'], false],
            ['competition_settings', 'PRIMARY', ['competition_id', 'skey'], true],
        ];
        foreach ($indexes as [$table, $name, $expected, $unique]) {
            $actual = competition_schema_index($pdo, $table, $name);
            if ($actual === null || $actual['columns'] !== $expected || $actual['unique'] !== $unique) {
                return false;
            }
        }

        $foreignKeys = [
            ['pilots', 'fk_pilot_competition', ['competition_id'], 'competitions', ['id']],
            ['rounds', 'fk_round_competition', ['competition_id'], 'competitions', ['id']],
            ['registrations', 'fk_registration_competition', ['competition_id'], 'competitions', ['id']],
            ['scores', 'fk_score_pilot_competition', ['pilot_id', 'competition_id'], 'pilots', ['id', 'competition_id']],
            ['scores', 'fk_score_round_competition', ['round_id', 'competition_id'], 'rounds', ['id', 'competition_id']],
            ['registrations', 'fk_registration_pilot', ['pilot_id'], 'pilots', ['id']],
            ['competition_settings', 'fk_competition_settings_competition', ['competition_id'], 'competitions', ['id']],
        ];
        foreach ($foreignKeys as [$table, $name, $columns, $referencedTable, $referencedColumns]) {
            $actual = competition_schema_foreign_key($pdo, $table, $name);
            if ($actual === null || $actual['columns'] !== $columns
                || $actual['referenced_table'] !== $referencedTable
                || $actual['referenced_columns'] !== $referencedColumns) {
                return false;
            }
        }
        foreach (['pilots' => 3, 'rounds' => 1, 'scores' => 2, 'registrations' => 2, 'competition_settings' => 1] as $table => $expected) {
            if (competition_schema_foreign_key_count($pdo, $table) < $expected) {
                return false;
            }
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Prüft, ob die vollständige Wettbewerbsmigration abgeschlossen ist. */
function schema_has_competitions(): bool
{
    try {
        if (!competition_schema_is_ready(db())) {
            return false;
        }
        $st = db()->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations');
        return (int) $st->fetchColumn() >= 6;
    } catch (Throwable $e) {
        return false;
    }
}
