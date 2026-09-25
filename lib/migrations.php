<?php
declare(strict_types=1);

require_once __DIR__ . '/scoring.php';

/** Tabellenprüfung für Migrationen. */
function migration_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$table]);
    return (int) $st->fetchColumn() > 0;
}

/** Spaltenprüfung für Migrationen. */
function migration_column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    return (int) $st->fetchColumn() > 0;
}

function migration_index_exists(PDO $pdo, string $table, string $index): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $st->execute([$table, $index]);
    return (int) $st->fetchColumn() > 0;
}

function migration_index_columns(PDO $pdo, string $table, string $index): array
{
    $st = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
                         ORDER BY SEQ_IN_INDEX');
    $st->execute([$table, $index]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function migration_fk_exists(PDO $pdo, string $table, string $constraint): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?');
    $st->execute([$table, $constraint]);
    return (int) $st->fetchColumn() > 0;
}

function migration_single_column_unique_indexes(PDO $pdo, string $table, string $column): array
{
    $st = $pdo->prepare('SELECT INDEX_NAME FROM information_schema.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0
                           AND INDEX_NAME <> \'PRIMARY\'
                         GROUP BY INDEX_NAME
                         HAVING COUNT(*) = 1 AND MAX(COLUMN_NAME) = ?');
    $st->execute([$table, $column]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function migration_add_index(PDO $pdo, string $table, string $index, string $definition): void
{
    if (!migration_index_exists($pdo, $table, $index)) {
        $pdo->exec("ALTER TABLE `$table` ADD $definition");
    }
}

function migration_add_fk(PDO $pdo, string $table, string $constraint, string $definition): void
{
    if (!migration_fk_exists($pdo, $table, $constraint)) {
        $pdo->exec("ALTER TABLE `$table` ADD CONSTRAINT `$constraint` $definition");
    }
}

function migration_ensure_table(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        version INT NOT NULL PRIMARY KEY,
        description VARCHAR(255) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function migration_ensure_pilot_map(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS pilot_migration_map (
        source_pilot_id INT NOT NULL,
        season_id INT NOT NULL,
        target_pilot_id INT NOT NULL,
        PRIMARY KEY (source_pilot_id, season_id),
        KEY idx_pilot_map_target (target_pilot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function migration_any_index_exists(PDO $pdo, string $table, array $names): bool
{
    foreach ($names as $name) {
        if (migration_index_exists($pdo, $table, $name)) {
            return true;
        }
    }
    return false;
}

function migration_any_fk_exists(PDO $pdo, string $table, array $names): bool
{
    foreach ($names as $name) {
        if (migration_fk_exists($pdo, $table, $name)) {
            return true;
        }
    }
    return false;
}

function migration_schema_is_current(PDO $pdo, bool $withResultFlags = true): bool
{
    return function_exists('competition_schema_is_ready') && competition_schema_is_ready($pdo, $withResultFlags);
}

function migration_applied_versions(PDO $pdo): array
{
    migration_ensure_table($pdo);
    return array_map('intval', $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN));
}

/** Markiert ein frisch nach dem kanonischen Schema installiertes System als aktuell. */
function migration_mark_current(PDO $pdo): void
{
    migration_ensure_table($pdo);
    if (!migration_schema_is_current($pdo)) {
        throw new RuntimeException('Das vorhandene Schema ist nicht aktuell. Bitte upgrade.php verwenden.');
    }
    foreach (migration_definitions() as $version => $definition) {
        $st = $pdo->prepare('INSERT IGNORE INTO schema_migrations (version, description) VALUES (?, ?)');
        $st->execute([$version, $definition['description']]);
    }
}

function migration_current_season_id(PDO $pdo): int
{
    $id = (int) $pdo->query('SELECT id FROM seasons WHERE is_current = 1 ORDER BY id DESC LIMIT 1')->fetchColumn();
    if (!$id) {
        $id = (int) $pdo->query('SELECT id FROM seasons ORDER BY id DESC LIMIT 1')->fetchColumn();
    }
    if (!$id) {
        $global = global_settings();
        $name = !empty($global['competition_date']) ? date('Y', strtotime((string) $global['competition_date'])) : date('Y');
        $pdo->prepare('INSERT INTO seasons (name, is_current) VALUES (?, 1)')->execute([$name]);
        $id = (int) $pdo->lastInsertId();
    }
    $pdo->prepare('UPDATE seasons SET is_current = 0')->execute();
    $pdo->prepare('UPDATE seasons SET is_current = 1 WHERE id = ?')->execute([$id]);
    return $id;
}

/**
 * Bringt eine ältere, saisonlose oder teilweise migrierte Installation in die
 * Grundstruktur der aktuellen Anwendung. Jeder Schritt ist separat idempotent.
 */
function migration_legacy_schema(PDO $pdo): array
{
    $log = [];

    if (!migration_table_exists($pdo, 'settings')) {
        $pdo->exec('CREATE TABLE settings (
            skey VARCHAR(64) NOT NULL PRIMARY KEY,
            svalue TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $log[] = 'Tabelle settings wurde angelegt.';
    }

    if (migration_table_exists($pdo, 'model_groups') && !migration_table_exists($pdo, 'model_types')) {
        $pdo->exec('RENAME TABLE model_groups TO model_types');
        $log[] = 'model_groups wurde in model_types umbenannt.';
    }
    if (!migration_table_exists($pdo, 'model_types')) {
        $pdo->exec('CREATE TABLE model_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            info VARCHAR(200) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uq_type_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $log[] = 'Tabelle model_types wurde angelegt.';
    }
    foreach (['pilots', 'registrations'] as $table) {
        if (migration_column_exists($pdo, $table, 'group_id')
            && !migration_column_exists($pdo, $table, 'model_type_id')) {
            $pdo->exec("ALTER TABLE `$table` CHANGE `group_id` `model_type_id` INT NULL");
            $log[] = "$table.group_id wurde in model_type_id umbenannt.";
        }
    }

    if (!migration_table_exists($pdo, 'clubs')) {
        $pdo->exec('CREATE TABLE clubs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            short_name VARCHAR(30) NULL,
            place VARCHAR(120) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uq_club_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $log[] = 'Tabelle clubs wurde angelegt.';
    }
    foreach (['pilots', 'registrations'] as $table) {
        if (!migration_column_exists($pdo, $table, 'club_id')) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN club_id INT NULL");
            $log[] = "Spalte $table.club_id wurde angelegt.";
        }
    }
    if (migration_column_exists($pdo, 'pilots', 'club')) {
        $names = $pdo->query("SELECT DISTINCT club FROM pilots WHERE club IS NOT NULL AND club <> ''")->fetchAll();
        $moved = 0;
        foreach ($names as $row) {
            $clubId = club_id_for_name(text_limit((string) $row['club'], 120));
            $st = $pdo->prepare('UPDATE pilots SET club_id = ? WHERE club = ? AND club_id IS NULL');
            $st->execute([$clubId, $row['club']]);
            $moved += $st->rowCount();
        }
        $pdo->exec('ALTER TABLE pilots DROP COLUMN club');
        $log[] = "$moved Piloten wurden " . count($names) . ' Vereinen zugeordnet.';
    }

    migration_add_index($pdo, 'pilots', 'idx_pilot_type', 'INDEX idx_pilot_type (model_type_id)');
    migration_add_index($pdo, 'pilots', 'idx_pilot_club', 'INDEX idx_pilot_club (club_id)');
    migration_add_fk($pdo, 'pilots', 'fk_pilot_type', 'FOREIGN KEY (model_type_id) REFERENCES model_types(id) ON DELETE SET NULL');
    migration_add_fk($pdo, 'pilots', 'fk_pilot_club', 'FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE SET NULL');

    foreach (['club_ranking_enabled' => '1', 'club_scoring_count' => '3'] as $key => $value) {
        $st = $pdo->prepare('INSERT IGNORE INTO settings (skey, svalue) VALUES (?, ?)');
        $st->execute([$key, $value]);
    }
    $log[] = 'Einstellungen für die Vereinswertung wurden ergänzt.';

    if (migration_column_exists($pdo, 'scores', 'landing_dist')
        && !migration_column_exists($pdo, 'scores', 'landing_value')) {
        $pdo->exec('ALTER TABLE scores CHANGE `landing_dist` `landing_value` DECIMAL(6,1) NULL');
        $log[] = 'landing_dist wurde in landing_value umbenannt.';
    }

    if (!migration_table_exists($pdo, 'seasons')) {
        $pdo->exec('CREATE TABLE seasons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(60) NOT NULL,
            is_current TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_season_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $log[] = 'Tabelle seasons wurde angelegt.';
    }
    $seasonId = migration_current_season_id($pdo);

    if (!migration_column_exists($pdo, 'rounds', 'season_id')) {
        $pdo->exec('ALTER TABLE rounds ADD COLUMN season_id INT NULL AFTER id');
        $pdo->prepare('UPDATE rounds SET season_id = ? WHERE season_id IS NULL')->execute([$seasonId]);
        $log[] = 'Bisherige Durchgänge wurden dem ersten Wettbewerb zugeordnet.';
    } else {
        $pdo->prepare('UPDATE rounds SET season_id = ? WHERE season_id IS NULL')->execute([$seasonId]);
    }
    $pdo->exec('ALTER TABLE rounds MODIFY COLUMN season_id INT NOT NULL');
    foreach (migration_single_column_unique_indexes($pdo, 'rounds', 'round_number') as $index) {
        $pdo->exec("ALTER TABLE rounds DROP INDEX `$index`");
    }
    migration_add_index($pdo, 'rounds', 'uq_round_number', 'UNIQUE KEY uq_round_number (season_id, round_number)');
    migration_add_fk($pdo, 'rounds', 'fk_round_season', 'FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE');

    if (!migration_column_exists($pdo, 'registrations', 'season_id')) {
        $pdo->exec('ALTER TABLE registrations ADD COLUMN season_id INT NULL AFTER id');
        $pdo->prepare('UPDATE registrations SET season_id = ? WHERE season_id IS NULL')->execute([$seasonId]);
        $log[] = 'Bisherige Anmeldungen wurden dem ersten Wettbewerb zugeordnet.';
    } else {
        $pdo->prepare('UPDATE registrations SET season_id = ? WHERE season_id IS NULL')->execute([$seasonId]);
    }
    migration_add_fk($pdo, 'registrations', 'fk_registration_season', 'FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE SET NULL');

    if (!migration_column_exists($pdo, 'pilots', 'season_id')) {
        $pdo->exec('ALTER TABLE pilots ADD COLUMN season_id INT NULL AFTER id');
    }

    // Ein altes globales Unique-Index auf bib_number muss vor dem Kopieren entfernt werden.
    foreach (migration_single_column_unique_indexes($pdo, 'pilots', 'bib_number') as $index) {
        $pdo->exec("ALTER TABLE pilots DROP INDEX `$index`");
        $log[] = "Altes globales Startnummern-Index $index wurde entfernt.";
    }

    // Reparatur auch dann ausführen, wenn ein früherer Migrationsversuch genau hier abgebrochen wurde.
    migration_ensure_pilot_map($pdo);
    $pdo->prepare('UPDATE pilots SET season_id = ? WHERE season_id IS NULL')->execute([$seasonId]);
    $pilotRows = $pdo->query('SELECT id, season_id, bib_number, first_name, last_name, club_id, email, phone, model_type_id, model_name, notes, active, created_at FROM pilots')->fetchAll();
    $seasonOf = $pdo->prepare('SELECT DISTINCT r.season_id FROM scores s
                               JOIN rounds r ON r.id = s.round_id
                               WHERE s.pilot_id = ? ORDER BY r.season_id');
    $setSeason = $pdo->prepare('UPDATE pilots SET season_id = ? WHERE id = ?');
    $mapLookup = $pdo->prepare('SELECT target_pilot_id FROM pilot_migration_map
                                WHERE source_pilot_id = ? AND season_id = ?');
    $mapInsert = $pdo->prepare('INSERT INTO pilot_migration_map (source_pilot_id, season_id, target_pilot_id)
                                VALUES (?, ?, ?)');
    $mapDelete = $pdo->prepare('DELETE FROM pilot_migration_map
                                WHERE source_pilot_id = ? AND season_id = ?');
    $targetExists = $pdo->prepare('SELECT id FROM pilots WHERE id = ? AND season_id = ?');
    $findCopy = $pdo->prepare('SELECT id FROM pilots
                               WHERE season_id = ? AND first_name = ? AND last_name = ? AND (bib_number <=> ?)
                                 AND (club_id <=> ?) AND (email <=> ?) AND (phone <=> ?)
                                 AND (model_type_id <=> ?) AND (model_name <=> ?) AND (notes <=> ?)
                               ORDER BY id LIMIT 1');
    $copyPilot = $pdo->prepare('INSERT INTO pilots
        (season_id, bib_number, first_name, last_name, club_id, email, phone, model_type_id, model_name, notes, active, created_at)
        SELECT ?, bib_number, first_name, last_name, club_id, email, phone, model_type_id, model_name, notes, active, created_at
        FROM pilots WHERE id = ?');
    $repoint = $pdo->prepare('UPDATE scores s JOIN rounds r ON r.id = s.round_id
                              SET s.pilot_id = ? WHERE s.pilot_id = ? AND r.season_id = ?');
    $duplicated = 0;
    foreach ($pilotRows as $pilot) {
        $originalId = (int) $pilot['id'];
        $seasonOf->execute([$originalId]);
        $scoreSeasons = array_map('intval', $seasonOf->fetchAll(PDO::FETCH_COLUMN));
        $assignedSeason = (int) ($pilot['season_id'] ?? 0);
        if (!$scoreSeasons) {
            if (!$assignedSeason) {
                $setSeason->execute([$seasonId, $originalId]);
            }
            continue;
        }
        if (!$assignedSeason || !in_array($assignedSeason, $scoreSeasons, true)) {
            $assignedSeason = $scoreSeasons[0];
            $setSeason->execute([$assignedSeason, $originalId]);
        }
        foreach ($scoreSeasons as $scoreSeason) {
            if ($scoreSeason === $assignedSeason) {
                continue;
            }

            $mapLookup->execute([$originalId, $scoreSeason]);
            $copyId = (int) ($mapLookup->fetchColumn() ?: 0);
            if ($copyId) {
                $targetExists->execute([$copyId, $scoreSeason]);
                if (!$targetExists->fetchColumn()) {
                    $mapDelete->execute([$originalId, $scoreSeason]);
                    $copyId = 0;
                }
            }
            if (!$copyId) {
                $findCopy->execute([
                    $scoreSeason, $pilot['first_name'], $pilot['last_name'], $pilot['bib_number'],
                    $pilot['club_id'], $pilot['email'], $pilot['phone'], $pilot['model_type_id'],
                    $pilot['model_name'], $pilot['notes'],
                ]);
                if ($findCopy->fetchColumn()) {
                    throw new RuntimeException('Mehrdeutige Pilotkopie für Quelle ' . $originalId
                        . ' und Wettbewerb ' . $scoreSeason . ' gefunden; bitte manuell prüfen.');
                }
                $pdo->beginTransaction();
                try {
                    $copyPilot->execute([$scoreSeason, $originalId]);
                    $copyId = (int) $pdo->lastInsertId();
                    $mapInsert->execute([$originalId, $scoreSeason, $copyId]);
                    $repoint->execute([$copyId, $originalId, $scoreSeason]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
                $duplicated++;
            } else {
                $repoint->execute([$copyId, $originalId, $scoreSeason]);
            }
        }
    }
    if ($duplicated) {
        $log[] = "$duplicated Pilotendatensätze wurden für weitere Wettbewerbe dupliziert.";
    }
    $pdo->prepare('UPDATE pilots SET season_id = ? WHERE season_id IS NULL')->execute([$seasonId]);
    $pdo->exec('ALTER TABLE pilots MODIFY COLUMN season_id INT NOT NULL');
    migration_add_index($pdo, 'pilots', 'idx_pilot_season', 'INDEX idx_pilot_season (season_id)');
    migration_add_fk($pdo, 'pilots', 'fk_pilot_season', 'FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE');

    return $log;
}

/** Ergänzt die Datenbank-Constraints für Wettbewerb, Startnummer und Score-Zuordnung. */
function migration_score_constraints(PDO $pdo): array
{
    $log = [];

    foreach (migration_single_column_unique_indexes($pdo, 'pilots', 'bib_number') as $index) {
        $pdo->exec("ALTER TABLE pilots DROP INDEX `$index`");
        $log[] = "Altes globales Startnummern-Index $index wurde entfernt.";
    }

    // Leere Startnummern werden wie NULL behandelt.
    $pdo->exec("UPDATE pilots SET bib_number = NULL WHERE bib_number = ''");
    $duplicates = $pdo->query("SELECT season_id, bib_number, COUNT(*) AS n
                               FROM pilots WHERE bib_number IS NOT NULL
                               GROUP BY season_id, bib_number HAVING n > 1 LIMIT 1")->fetch();
    if ($duplicates) {
        throw new RuntimeException('Doppelte Startnummern in Wettbewerb ' . $duplicates['season_id']
            . ' (' . $duplicates['bib_number'] . ') müssen vor der Migration manuell bereinigt werden.');
    }

    migration_add_index($pdo, 'pilots', 'uq_pilot_id_season', 'UNIQUE KEY uq_pilot_id_season (id, season_id)');
    migration_add_index($pdo, 'pilots', 'uq_pilot_season_bib', 'UNIQUE KEY uq_pilot_season_bib (season_id, bib_number)');
    migration_add_index($pdo, 'rounds', 'uq_round_id_season', 'UNIQUE KEY uq_round_id_season (id, season_id)');

    if (!migration_column_exists($pdo, 'scores', 'season_id')) {
        $pdo->exec('ALTER TABLE scores ADD COLUMN season_id INT NULL AFTER round_id');
    }
    $pdo->exec('UPDATE scores s JOIN rounds r ON r.id = s.round_id SET s.season_id = r.season_id WHERE s.season_id IS NULL');
    $orphaned = $pdo->query('SELECT COUNT(*) FROM scores s
                              LEFT JOIN pilots p ON p.id = s.pilot_id
                              LEFT JOIN rounds r ON r.id = s.round_id
                              WHERE p.id IS NULL OR r.id IS NULL')->fetchColumn();
    if ((int) $orphaned > 0) {
        throw new RuntimeException('Es gibt Scores ohne zugehörigen Piloten oder Durchgang. '
            . 'Diese Daten müssen vor der Migration bereinigt werden.');
    }
    $mismatch = $pdo->query('SELECT COUNT(*) FROM scores s
                              JOIN pilots p ON p.id = s.pilot_id
                              JOIN rounds r ON r.id = s.round_id
                              WHERE p.season_id <> s.season_id OR r.season_id <> s.season_id')->fetchColumn();
    if ((int) $mismatch > 0) {
        throw new RuntimeException('Es gibt Scores, deren Pilot und Durchgang nicht zum selben Wettbewerb gehören. '
            . 'Diese Daten müssen vor der Migration bereinigt werden.');
    }
    $pdo->exec('ALTER TABLE scores MODIFY COLUMN season_id INT NOT NULL');
    foreach (['fk_score_pilot', 'fk_score_round'] as $oldConstraint) {
        if (migration_fk_exists($pdo, 'scores', $oldConstraint)) {
            $pdo->exec("ALTER TABLE scores DROP FOREIGN KEY `$oldConstraint`");
        }
    }
    migration_add_index($pdo, 'scores', 'idx_score_season', 'INDEX idx_score_season (season_id)');
    migration_add_index($pdo, 'scores', 'idx_score_pilot_season', 'INDEX idx_score_pilot_season (pilot_id, season_id)');
    migration_add_index($pdo, 'scores', 'idx_score_round_season', 'INDEX idx_score_round_season (round_id, season_id)');
    migration_add_fk($pdo, 'scores', 'fk_score_pilot_season',
        'FOREIGN KEY (pilot_id, season_id) REFERENCES pilots(id, season_id) ON DELETE CASCADE');
    migration_add_fk($pdo, 'scores', 'fk_score_round_season',
        'FOREIGN KEY (round_id, season_id) REFERENCES rounds(id, season_id) ON DELETE CASCADE');
    $log[] = 'Startnummern und Score-Zuordnungen sind jetzt Datenbank-seitig je Wettbewerb geschützt.';
    return $log;
}

function migration_foreign_keys_for_column(PDO $pdo, string $column): array
{
    $st = $pdo->prepare('SELECT DISTINCT k.TABLE_NAME, k.CONSTRAINT_NAME
                         FROM information_schema.KEY_COLUMN_USAGE k
                         WHERE k.CONSTRAINT_SCHEMA = DATABASE()
                           AND k.COLUMN_NAME = ?
                           AND k.REFERENCED_TABLE_NAME IS NOT NULL');
    $st->execute([$column]);
    return $st->fetchAll();
}

function migration_foreign_keys_referencing_table(PDO $pdo, string $table): array
{
    $st = $pdo->prepare('SELECT DISTINCT k.TABLE_NAME, k.CONSTRAINT_NAME
                         FROM information_schema.KEY_COLUMN_USAGE k
                         WHERE k.CONSTRAINT_SCHEMA = DATABASE()
                           AND k.REFERENCED_TABLE_NAME = ?');
    $st->execute([$table]);
    return $st->fetchAll();
}

function migration_foreign_keys_on_table(PDO $pdo, string $table): array
{
    $st = $pdo->prepare('SELECT DISTINCT TABLE_NAME, CONSTRAINT_NAME
                         FROM information_schema.REFERENTIAL_CONSTRAINTS
                         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$table]);
    return $st->fetchAll();
}

function migration_drop_foreign_key(PDO $pdo, string $table, string $constraint): void
{
    if (!preg_match('/^[A-Za-z0-9_$]+$/', $table) || !preg_match('/^[A-Za-z0-9_$]+$/', $constraint)) {
        throw new RuntimeException('Ungültiger Foreign-Key-Name in der Migration.');
    }
    if (migration_fk_exists($pdo, $table, $constraint)) {
        $pdo->exec("ALTER TABLE `$table` DROP FOREIGN KEY `$constraint`");
    }
}

function migration_drop_foreign_keys(PDO $pdo, array $constraints): void
{
    $seen = [];
    foreach ($constraints as $constraint) {
        $key = $constraint['TABLE_NAME'] . "\0" . $constraint['CONSTRAINT_NAME'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        migration_drop_foreign_key($pdo, $constraint['TABLE_NAME'], $constraint['CONSTRAINT_NAME']);
    }
}

function migration_normalize_index(PDO $pdo, string $table, string $oldName, string $newName, string $definition): void
{
    if ($oldName !== $newName && migration_index_exists($pdo, $table, $oldName)) {
        $pdo->exec("ALTER TABLE `$table` DROP INDEX `$oldName`");
    }
    if (!migration_index_exists($pdo, $table, $newName)) {
        $pdo->exec("ALTER TABLE `$table` ADD $definition");
    }
}

function migration_competitions_settings_shape(PDO $pdo): void
{
    if (!migration_table_exists($pdo, 'competition_settings')) {
        return;
    }
    foreach (['competition_id', 'skey', 'svalue'] as $column) {
        if (!migration_column_exists($pdo, 'competition_settings', $column)) {
            throw new RuntimeException('competition_settings hat eine unerwartete Struktur; bitte vor der Migration manuell prüfen.');
        }
    }
    if (migration_column_exists($pdo, 'competition_settings', 'season_id')) {
        throw new RuntimeException('competition_settings enthält noch season_id; bitte vor der Migration manuell prüfen.');
    }
    if (migration_index_columns($pdo, 'competition_settings', 'PRIMARY') !== ['competition_id', 'skey']) {
        throw new RuntimeException('competition_settings hat keinen kanonischen Primärschlüssel; bitte manuell prüfen.');
    }
}

function migration_ensure_completion_fields(PDO $pdo): array
{
    foreach (['rounds', 'scores'] as $table) {
        if (!migration_table_exists($pdo, $table)) {
            throw new RuntimeException("Die Tabelle $table fehlt; Abschlussstatus kann nicht vorbereitet werden.");
        }
    }

    $log = [];
    if (!migration_column_exists($pdo, 'rounds', 'is_included')) {
        $pdo->exec('ALTER TABLE rounds ADD COLUMN is_included TINYINT(1) NOT NULL DEFAULT 1');
        $log[] = 'Spalte rounds.is_included wurde ergänzt.';
    } else {
        $pdo->exec('UPDATE rounds SET is_included = 1 WHERE is_included IS NULL');
        $pdo->exec('ALTER TABLE rounds MODIFY COLUMN is_included TINYINT(1) NOT NULL DEFAULT 1');
    }

    if (!migration_column_exists($pdo, 'scores', 'status')) {
        $pdo->exec("ALTER TABLE scores ADD COLUMN status ENUM('flown','dnf','dns') NOT NULL DEFAULT 'flown'");
        $log[] = 'Spalte scores.status wurde ergänzt.';
    } else {
        $invalid = $pdo->query("SELECT DISTINCT status FROM scores
                                WHERE status IS NULL OR status NOT IN ('flown','dnf','dns')")->fetchAll(PDO::FETCH_COLUMN);
        if ($invalid) {
            throw new RuntimeException('scores.status enthält unbekannte Werte; bitte vor der Migration prüfen: '
                . implode(', ', array_map('strval', $invalid)));
        }
        $pdo->exec("ALTER TABLE scores MODIFY COLUMN status ENUM('flown','dnf','dns') NOT NULL DEFAULT 'flown'");
    }
    return $log;
}

function migration_competitions(PDO $pdo): array
{
    $log = [];
    migration_competitions_settings_shape($pdo);

    $hasCompetitions = migration_table_exists($pdo, 'competitions');
    $hasSeasons = migration_table_exists($pdo, 'seasons');
    if ($hasCompetitions && $hasSeasons) {
        throw new RuntimeException('Die Tabellen seasons und competitions existieren beide; bitte manuell prüfen.');
    }
    if (!$hasCompetitions && !$hasSeasons) {
        throw new RuntimeException('Weder seasons noch competitions sind vorhanden; bitte Datenbank prüfen.');
    }

    // MariaDB 10.3 kann eine an einem Foreign Key beteiligte Spalte nicht
    // immer per CHANGE umbenennen. Deshalb werden die alten Constraints vor
    // dem Umbau entfernt und anschließend mit neuen Namen neu angelegt.
    $constraints = array_merge(
        migration_foreign_keys_referencing_table($pdo, 'seasons'),
        migration_foreign_keys_on_table($pdo, 'scores'),
        migration_foreign_keys_on_table($pdo, 'competition_settings'),
        migration_foreign_keys_for_column($pdo, 'season_id'),
        migration_foreign_keys_for_column($pdo, 'competition_id'),
        migration_foreign_keys_for_column($pdo, 'pilot_id')
    );
    foreach ([
        ['pilots', 'fk_pilot_season'],
        ['rounds', 'fk_round_season'],
        ['registrations', 'fk_registration_season'],
        ['scores', 'fk_score_pilot_season'],
        ['scores', 'fk_score_round_season'],
        ['scores', 'fk_score_pilot'],
        ['scores', 'fk_score_round'],
    ] as [$table, $constraint]) {
        $constraints[] = ['TABLE_NAME' => $table, 'CONSTRAINT_NAME' => $constraint];
    }
    migration_drop_foreign_keys($pdo, $constraints);

    if ($hasSeasons) {
        $pdo->exec('RENAME TABLE seasons TO competitions');
        $log[] = 'Tabelle seasons wurde in competitions umbenannt.';
    }
    $pdo->exec('ALTER TABLE competitions MODIFY name VARCHAR(160) NOT NULL');
    if (!migration_column_exists($pdo, 'competitions', 'completed_at')) {
        $pdo->exec('ALTER TABLE competitions ADD COLUMN completed_at DATETIME NULL');
        $log[] = 'Spalte competitions.completed_at für den Abschlussstatus wurde ergänzt.';
    }

    foreach ([
        ['pilots', 'INT NOT NULL'],
        ['rounds', 'INT NOT NULL'],
        ['registrations', 'INT NULL'],
        ['scores', 'INT NOT NULL'],
        ['pilot_migration_map', 'INT NOT NULL'],
    ] as $definition) {
        $table = $definition[0];
        if (migration_column_exists($pdo, $table, 'season_id') && !migration_column_exists($pdo, $table, 'competition_id')) {
            $pdo->exec("ALTER TABLE `$table` CHANGE `season_id` `competition_id` {$definition[1]}");
            $log[] = "Spalte $table.season_id wurde in competition_id umbenannt.";
        } elseif (migration_column_exists($pdo, $table, 'season_id') && migration_column_exists($pdo, $table, 'competition_id')) {
            $mismatch = $pdo->query("SELECT COUNT(*) FROM `$table`
                                     WHERE season_id IS NOT NULL
                                       AND (competition_id IS NULL OR competition_id <> season_id)")->fetchColumn();
            if ((int) $mismatch > 0) {
                throw new RuntimeException("Die Spalten season_id und competition_id in $table enthalten unterschiedliche Werte.");
            }
            if ($table === 'pilot_migration_map') {
                $pdo->exec('ALTER TABLE pilot_migration_map DROP PRIMARY KEY');
            }
            $pdo->exec("UPDATE `$table` SET competition_id = season_id WHERE competition_id IS NULL");
            $pdo->exec("ALTER TABLE `$table` DROP COLUMN season_id");
            if ($table === 'pilot_migration_map') {
                $pdo->exec('ALTER TABLE pilot_migration_map ADD PRIMARY KEY (source_pilot_id, competition_id)');
            }
            $log[] = "Doppelte Spalte in $table wurde bereinigt.";
        }
    }

    $log = array_merge($log, migration_ensure_completion_fields($pdo));

    $activeId = $pdo->query('SELECT id FROM competitions WHERE is_current = 1 ORDER BY id DESC LIMIT 1')->fetchColumn();
    if (!$activeId) {
        $activeId = $pdo->query('SELECT id FROM competitions WHERE completed_at IS NULL ORDER BY id DESC LIMIT 1')->fetchColumn();
    }
    if ($activeId) {
        $pdo->exec('UPDATE competitions SET is_current = 0');
        $pdo->prepare('UPDATE competitions SET is_current = 1 WHERE id = ?')->execute([$activeId]);
    }

    // Alte Indexnamen werden normalisiert, damit die kanonische Struktur auch
    // nach einer Migration aus einer alten Installation eindeutig ist.
    migration_normalize_index($pdo, 'competitions', 'uq_season_name', 'uq_competition_name', 'UNIQUE KEY uq_competition_name (name)');
    migration_normalize_index($pdo, 'pilots', 'idx_pilot_season', 'idx_pilot_competition', 'INDEX idx_pilot_competition (competition_id)');
    migration_normalize_index($pdo, 'pilots', 'uq_pilot_id_season', 'uq_pilot_id_competition', 'UNIQUE KEY uq_pilot_id_competition (id, competition_id)');
    migration_normalize_index($pdo, 'pilots', 'uq_pilot_season_bib', 'uq_pilot_competition_bib', 'UNIQUE KEY uq_pilot_competition_bib (competition_id, bib_number)');
    migration_normalize_index($pdo, 'rounds', 'uq_round_id_season', 'uq_round_id_competition', 'UNIQUE KEY uq_round_id_competition (id, competition_id)');
    migration_normalize_index($pdo, 'scores', 'idx_score_season', 'idx_score_competition', 'INDEX idx_score_competition (competition_id)');
    migration_normalize_index($pdo, 'scores', 'idx_score_pilot_season', 'idx_score_pilot_competition', 'INDEX idx_score_pilot_competition (pilot_id, competition_id)');
    migration_normalize_index($pdo, 'scores', 'idx_score_round_season', 'idx_score_round_competition', 'INDEX idx_score_round_competition (round_id, competition_id)');
    if (!migration_index_exists($pdo, 'rounds', 'uq_round_number')) {
        $pdo->exec('ALTER TABLE rounds ADD UNIQUE KEY uq_round_number (competition_id, round_number)');
    }
    if (!migration_index_exists($pdo, 'scores', 'uq_pilot_round')) {
        $pdo->exec('ALTER TABLE scores ADD UNIQUE KEY uq_pilot_round (pilot_id, round_id)');
    }

    migration_add_fk($pdo, 'pilots', 'fk_pilot_competition', 'FOREIGN KEY (competition_id) REFERENCES competitions(id) ON DELETE CASCADE');
    migration_add_fk($pdo, 'rounds', 'fk_round_competition', 'FOREIGN KEY (competition_id) REFERENCES competitions(id) ON DELETE CASCADE');
    migration_add_fk($pdo, 'registrations', 'fk_registration_competition', 'FOREIGN KEY (competition_id) REFERENCES competitions(id) ON DELETE SET NULL');
    migration_add_fk($pdo, 'scores', 'fk_score_pilot_competition', 'FOREIGN KEY (pilot_id, competition_id) REFERENCES pilots(id, competition_id) ON DELETE CASCADE');
    migration_add_fk($pdo, 'scores', 'fk_score_round_competition', 'FOREIGN KEY (round_id, competition_id) REFERENCES rounds(id, competition_id) ON DELETE CASCADE');

    // Alte Anmeldungen dürfen nach dem Umbau nicht auf gelöschte oder
    // konkurrierende Piloten zeigen.
    $pdo->exec("UPDATE registrations r
                LEFT JOIN pilots p ON p.id = r.pilot_id
                SET r.pilot_id = NULL, r.status = 'pending', r.decided_at = NULL
                WHERE r.pilot_id IS NOT NULL
                  AND (p.id IS NULL OR r.competition_id IS NULL OR p.competition_id <> r.competition_id)");
    migration_normalize_index($pdo, 'registrations', 'idx_registration_competition', 'idx_registration_competition', 'INDEX idx_registration_competition (competition_id)');
    migration_normalize_index($pdo, 'registrations', 'idx_registration_pilot', 'idx_registration_pilot', 'INDEX idx_registration_pilot (pilot_id)');
    migration_add_fk($pdo, 'registrations', 'fk_registration_pilot', 'FOREIGN KEY (pilot_id) REFERENCES pilots(id) ON DELETE SET NULL');
    $log[] = 'Wettbewerbs-Constraints wurden auf competition_id umgestellt.';

    $pdo->exec('CREATE TABLE IF NOT EXISTS competition_settings (
        competition_id INT NOT NULL,
        skey VARCHAR(64) NOT NULL,
        svalue TEXT NOT NULL,
        PRIMARY KEY (competition_id, skey)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    migration_competitions_settings_shape($pdo);
    migration_add_fk($pdo, 'competition_settings', 'fk_competition_settings_competition', 'FOREIGN KEY (competition_id) REFERENCES competitions(id) ON DELETE CASCADE');
    $log[] = 'Tabelle competition_settings wurde angelegt bzw. geprüft.';

    $copy = $pdo->prepare('INSERT IGNORE INTO competition_settings (competition_id, skey, svalue)
                           SELECT c.id, s.skey, s.svalue
                           FROM competitions c CROSS JOIN settings s');
    $copy->execute();
    $defaults = $pdo->prepare('INSERT IGNORE INTO competition_settings (competition_id, skey, svalue)
                               SELECT c.id, ?, ?
                               FROM competitions c');
    foreach (setting_defaults() as $key => $value) {
        $defaults->execute([$key, $value]);
    }
    $name = $pdo->prepare('INSERT IGNORE INTO competition_settings (competition_id, skey, svalue)
                           SELECT id, ?, name FROM competitions');
    $name->execute(['competition_name']);
    $pdo->exec("UPDATE competition_settings cs
                JOIN competitions c ON c.id = cs.competition_id
                SET cs.svalue = c.name
                WHERE cs.skey = 'competition_name'");
    $log[] = 'Bestehende Einstellungen wurden pro Wettbewerb kopiert.';
    $missingSettingsStmt = $pdo->prepare('SELECT COUNT(*) FROM competitions c
                                           LEFT JOIN competition_settings cs
                                             ON cs.competition_id = c.id AND cs.skey = ?
                                           WHERE cs.competition_id IS NULL');
    $missingSettingsStmt->execute(['competition_name']);
    $missingSettings = $missingSettingsStmt->fetchColumn();
    if ((int) $missingSettings > 0) {
        throw new RuntimeException('Für mindestens einen Wettbewerb fehlen die initialen Einstellungen.');
    }

    // Die Ergebnis-Kennzeichen (scores.motor) kommen erst mit der späteren
    // Migration, deshalb wird hier ohne sie geprüft.
    if (!migration_schema_is_current($pdo, false)) {
        $issues = function_exists('competition_schema_diagnostics')
            ? competition_schema_diagnostics($pdo, false) : ['unbekannte Schemaabweichung'];
        throw new RuntimeException('Die Wettbewerbsstruktur ist nach der Migration nicht vollständig: '
            . implode('; ', $issues));
    }
    return $log;
}

function migration_completion_status(PDO $pdo): array
{
    if (!migration_table_exists($pdo, 'competitions')) {
        throw new RuntimeException('Die Tabelle competitions fehlt.');
    }
    $log = [];
    if (!migration_column_exists($pdo, 'competitions', 'completed_at')) {
        $pdo->exec('ALTER TABLE competitions ADD COLUMN completed_at DATETIME NULL');
        $log[] = 'Spalte competitions.completed_at für den Abschlussstatus wurde ergänzt.';
    } else {
        $log[] = 'Der Abschlussstatus für Wettbewerbe ist bereits vorhanden.';
    }
    return array_merge($log, migration_ensure_completion_fields($pdo));
}

/**
 * Führt die Strafpunktregeln je Wettbewerb auf das neue Format zurück:
 * ein Satz je Sekunde Abweichung statt getrennter Sätze für zu lang und zu kurz,
 * und je eine Feststrafe für Aussenlandung, Nichtantritt und Motorstart. Die
 * Obergrenzen für Zeit- und Landestrafe entfallen. Die bisherigen Werte werden
 * übernommen, damit erfasste Resultate gültig bleiben – die Obergrenzen standen
 * überall auf 0 und haben deshalb nie gewirkt.
 */
function migration_penalty_rules(PDO $pdo): array
{
    $log = [];
    if (!migration_column_exists($pdo, 'scores', 'motor')) {
        $pdo->exec('ALTER TABLE scores ADD COLUMN motor TINYINT(1) NOT NULL DEFAULT 0 AFTER status');
        $log[] = 'Spalte scores.motor für die Motorstrafe wurde ergänzt.';
    } else {
        $pdo->exec('UPDATE scores SET motor = 0 WHERE motor IS NULL OR motor NOT IN (0, 1)');
        $pdo->exec('ALTER TABLE scores MODIFY COLUMN motor TINYINT(1) NOT NULL DEFAULT 0');
    }
    $pdo->exec('UPDATE scores SET motor = 0 WHERE status <> \'flown\' AND status <> \'dnf\'');

    $legacyKeys = [
        'penalty_per_second_over', 'penalty_per_second_under', 'penalty_not_flown',
        'max_time_penalty', 'max_landing_penalty',
    ];
    $newKeys = ['penalty_per_second', 'penalty_outlanding', 'penalty_not_started', 'penalty_motor'];
    $allKeys = array_merge($legacyKeys, $newKeys);
    $placeholders = implode(', ', array_fill(0, count($allKeys), '?'));

    $sources = [0 => []];   // 0 = globale Vorlage
    if (migration_table_exists($pdo, 'settings')) {
        $st = $pdo->prepare("SELECT skey, svalue FROM settings WHERE skey IN ($placeholders)");
        $st->execute($allKeys);
        foreach ($st->fetchAll() as $row) {
            $sources[0][(string) $row['skey']] = (string) $row['svalue'];
        }
    }
    if (migration_table_exists($pdo, 'competition_settings')) {
        $st = $pdo->prepare("SELECT competition_id, skey, svalue FROM competition_settings
                             WHERE skey IN ($placeholders)");
        $st->execute($allKeys);
        foreach ($st->fetchAll() as $row) {
            $sources[(int) $row['competition_id']][(string) $row['skey']] = (string) $row['svalue'];
        }
    }
    if (migration_table_exists($pdo, 'competitions')) {
        foreach ($pdo->query('SELECT id FROM competitions') as $row) {
            $sources[(int) $row['id']] = $sources[(int) $row['id']] ?? [];
        }
    }

    // Nur endliche Werte im erlaubten Bereich übernehmen; alles andere gilt als
    // "nicht gesetzt" und wird durch den Standard ersetzt.
    $number = function ($value): ?float {
        if (!is_scalar($value)) {
            return null;
        }
        $value = (float) $value;
        return is_finite($value) && $value >= 0 && $value <= 999999.99 ? $value : null;
    };
    $pick = static function ($value, float $fallback) use ($number): float {
        $parsed = $number($value);
        return $parsed !== null ? $parsed : $fallback;
    };
    $globalRewrite = [];
    $usedOver = null;
    $usedUnder = null;
    $usedNotFlown = null;
    $differingSeconds = false;

    foreach ($sources as $competitionId => $values) {
        $overC = $number($values['penalty_per_second_over'] ?? null);
        $underC = $number($values['penalty_per_second_under'] ?? null);
        $notFlownC = $number($values['penalty_not_flown'] ?? null);

        // Für die neuen Schlüssel zählt jede bereits gesetzte Angabe: eine
        // Installation, die die Migration schon einmal teilweise sah, verliert
        // dadurch keine getroffene Entscheidung.
        $highest = max($overC ?? 0, $underC ?? 0);
        $seconds = $pick($values['penalty_per_second'] ?? null, $highest > 0 ? $highest : 1);
        $outlanding = $pick($values['penalty_outlanding'] ?? null, $notFlownC ?? 100);
        $notStarted = $pick($values['penalty_not_started'] ?? null, $notFlownC ?? 100);
        $motor = $pick($values['penalty_motor'] ?? null, $notFlownC ?? 100);

        if ($overC !== null && $underC !== null && $overC !== $underC && $seconds === max($overC, $underC)) {
            $differingSeconds = true;
            $usedOver = $overC;
            $usedUnder = $underC;
        }
        if ($notFlownC !== null && $usedNotFlown === null) {
            $usedNotFlown = $notFlownC;
        }

        if ($competitionId === 0) {
            $globalRewrite = [
                'penalty_per_second' => (string) $seconds,
                'penalty_outlanding' => (string) $outlanding,
                'penalty_not_started' => (string) $notStarted,
                'penalty_motor' => (string) $motor,
            ];
            continue;
        }
        $write = $pdo->prepare('INSERT INTO competition_settings (competition_id, skey, svalue) VALUES (?, ?, ?)
                                ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
        $write->execute([$competitionId, 'penalty_per_second', (string) $seconds]);
        $write->execute([$competitionId, 'penalty_outlanding', (string) $outlanding]);
        $write->execute([$competitionId, 'penalty_not_started', (string) $notStarted]);
        $write->execute([$competitionId, 'penalty_motor', (string) $motor]);
    }

    if ($globalRewrite && migration_table_exists($pdo, 'settings')) {
        $write = $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)
                                ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
        foreach ($globalRewrite as $key => $value) {
            $write->execute([$key, $value]);
        }
    }

    // Die bisherigen Schlüssel werden nicht mehr gelesen und nicht vererbt.
    $legacyPlaceholders = implode(', ', array_fill(0, count($legacyKeys), '?'));
    if (migration_table_exists($pdo, 'settings')) {
        $st = $pdo->prepare("DELETE FROM settings WHERE skey IN ($legacyPlaceholders)");
        $st->execute($legacyKeys);
    }
    if (migration_table_exists($pdo, 'competition_settings')) {
        $st = $pdo->prepare("DELETE FROM competition_settings WHERE skey IN ($legacyPlaceholders)");
        $st->execute($legacyKeys);
    }

    $log[] = 'Strafpunkte: ein Satz je Sekunde Abweichung statt getrennter Sätze für zu lang und zu kurz.';
    $log[] = 'Die Obergrenzen für Zeit- und Landestrafe sind entfallen; sie standen auf 0 und haben nie gewirkt.';
    if ($differingSeconds) {
        $log[] = "Achtung: bisher {$usedUnder} Punkte je Sekunde zu kurz und {$usedOver} zu lang; "
            . 'der höhere Wert wurde übernommen. Bitte unter Einstellungen prüfen.';
    }
    $log[] = $usedNotFlown !== null
        ? "Feste Strafen für Aussenlandung, Nichtantritt und Motorstart wurden mit {$usedNotFlown} Punkten aus dem bisherigen Sammelwert übernommen."
        : 'Feste Strafen für Aussenlandung, Nichtantritt und Motorstart wurden mit 100 Punkten angelegt.';
    return $log;
}

function migration_definitions(): array
{
    return [
        1 => [
            'description' => 'Legacy-Schema auf Vereine, Modelltypen und Wettbewerbe vorbereiten',
            'run' => function (PDO $pdo): array { return migration_legacy_schema($pdo); },
        ],
        2 => [
            'description' => 'Wettbewerbs- und Startnummern-Constraints für Scores und Piloten ergänzen',
            'run' => function (PDO $pdo): array { return migration_score_constraints($pdo); },
        ],
        3 => [
            'description' => 'Saisons in Wettbewerbe umbenennen und Einstellungen pro Wettbewerb speichern',
            'run' => function (PDO $pdo): array { return migration_competitions($pdo); },
        ],
        4 => [
            'description' => 'Abschlussstatus und Resultatfelder für Wettbewerbe ergänzen',
            'run' => function (PDO $pdo): array { return migration_completion_status($pdo); },
        ],
        5 => [
            'description' => 'Strafpunkte je Wettbewerb: eine Zeitabweichung, Feststrafen und Motor-Kennzeichen',
            'run' => function (PDO $pdo): array { return migration_penalty_rules($pdo); },
        ],
    ];
}

function run_pending_migrations(PDO $pdo): array
{
    $lockName = 'segelflug_schema_migration';
    $locked = false;
    try {
        if ((config()['db_driver'] ?? 'mysql') === 'mysql') {
            $lock = $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockName) . ", 30)")->fetchColumn();
            if ((int) $lock !== 1) {
                throw new RuntimeException('Ein anderer Migrationslauf ist bereits aktiv.');
            }
            $locked = true;
        }

        migration_ensure_table($pdo);
        if (migration_table_exists($pdo, 'competitions') && migration_table_exists($pdo, 'seasons')) {
            throw new RuntimeException('Die Tabellen seasons und competitions existieren gleichzeitig; bitte manuell prüfen.');
        }
        $hasCompetitions = migration_table_exists($pdo, 'competitions');
        if ($hasCompetitions && !migration_table_exists($pdo, 'seasons')) {
            foreach (['pilots', 'rounds', 'scores', 'registrations'] as $requiredTable) {
                if (!migration_table_exists($pdo, $requiredTable)) {
                    throw new RuntimeException('Die Wettbewerbsstruktur ist unvollständig; bitte vor dem Upgrade prüfen.');
                }
            }
        }
        $canonicalColumns = $hasCompetitions
            && migration_column_exists($pdo, 'pilots', 'competition_id')
            && migration_column_exists($pdo, 'rounds', 'competition_id')
            && migration_column_exists($pdo, 'scores', 'competition_id')
            && migration_column_exists($pdo, 'registrations', 'competition_id');
        $partialCompetitionRename = $hasCompetitions && !migration_table_exists($pdo, 'seasons')
            && (migration_column_exists($pdo, 'pilots', 'season_id')
                || migration_column_exists($pdo, 'rounds', 'season_id')
                || migration_column_exists($pdo, 'scores', 'season_id')
                || migration_column_exists($pdo, 'registrations', 'season_id'));
        if ($partialCompetitionRename) {
            $repairLog = migration_competitions($pdo);
            if (!migration_column_exists($pdo, 'scores', 'motor')) {
                $repairLog = array_merge($repairLog, migration_penalty_rules($pdo));
            }
            migration_mark_current($pdo);
            return array_merge(['Eine unterbrochene Wettbewerbsmigration wurde fortgesetzt.'], $repairLog);
        }
        if ($hasCompetitions && !$canonicalColumns) {
            throw new RuntimeException('Die Wettbewerbsstruktur ist unvollständig; bitte vor dem Upgrade prüfen.');
        }
        if ($canonicalColumns) {
            // Wettbewerbsstruktur steht schon, einzelne neuere Schritte fehlen
            // aber noch. Sie werden hier nachgeholt, statt als "bereits aktuell"
            // markiert zu werden – sonst bliebe die Datenbank dauerhaft unvollständig.
            $repairLog = [];
            if (!migration_schema_is_current($pdo, false)) {
                $repairLog = migration_competitions($pdo);
            }
            if (!migration_column_exists($pdo, 'scores', 'motor')) {
                $repairLog = array_merge($repairLog, migration_penalty_rules($pdo));
            }
            migration_mark_current($pdo);
            return array_merge([
                'Das kanonische Wettbewerbsschema war bereits vorhanden; die Migrationen wurden als aktuell markiert.',
            ], $repairLog);
        }
        $applied = array_fill_keys(migration_applied_versions($pdo), true);
        $log = [];
        foreach (migration_definitions() as $version => $definition) {
            if (isset($applied[$version])) {
                continue;
            }
            try {
                $messages = $definition['run']($pdo);
                $st = $pdo->prepare('INSERT INTO schema_migrations (version, description) VALUES (?, ?)');
                $st->execute([$version, $definition['description']]);
                $log[] = "Migration $version: " . $definition['description'];
                foreach ($messages as $message) {
                    $log[] = $message;
                }
            } catch (Throwable $e) {
                throw new RuntimeException("Migration $version fehlgeschlagen: " . $e->getMessage(), 0, $e);
            }
        }
        if (!migration_schema_is_current($pdo)) {
            $issues = function_exists('competition_schema_diagnostics')
                ? competition_schema_diagnostics($pdo) : ['unbekannte Schemaabweichung'];
            throw new RuntimeException('Die Schemaprüfung nach den Migrationen ist fehlgeschlagen: '
                . implode('; ', $issues));
        }
        return $log;
    } finally {
        if ($locked) {
            $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
        }
    }
}
