<?php
declare(strict_types=1);

/** @deprecated Alte Include-Pfade bleiben für Erweiterungen kompatibel. */
require_once __DIR__ . '/competition.php';

function all_seasons(): array { return all_competitions(); }
function current_season(): array { return current_competition(); }
function current_season_id(): int { return current_competition_id(); }
function schema_has_seasons(): bool { return schema_has_competitions(); }
function find_season(int $id): ?array { return find_competition($id); }
function resolve_season_param(string $raw): array { return resolve_competition_param($raw); }
function set_current_season(int $id): void { set_current_competition($id); }
function create_season(string $name, int $roundsCount, int $targetTime, bool $makeCurrent = false): int {
    return create_competition($name, $roundsCount, $targetTime, $makeCurrent);
}
function ensure_season_rounds(int $seasonId, int $roundsCount, int $targetTime): void {
    ensure_competition_rounds($seasonId, $roundsCount, $targetTime);
}
function season_stats(int $seasonId): array { return competition_stats($seasonId); }
