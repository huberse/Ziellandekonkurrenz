<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Das angemeldete Konto. Ein gesperrtes Konto gilt sofort als abgemeldet, auch
 * wenn die Sitzung noch läuft – sonst könnte ein Konto nach dem Sperren noch
 * weiterarbeiten, bis sich jemand abmeldet.
 */
function current_user(): ?array
{
    start_session();
    if (empty($_SESSION['uid'])) {
        return null;
    }
    static $user = null;
    if ($user === null) {
        try {
            $st = db()->prepare('SELECT * FROM users WHERE id = ?');
            $st->execute([$_SESSION['uid']]);
            $found = $st->fetch() ?: null;
            $user = ($found && (int) ($found['active'] ?? 1) === 1) ? $found : null;
        } catch (PDOException $e) {
            $user = null;
        }
    }
    return $user ?: null;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        $target = $_SERVER['REQUEST_URI'] ?? 'index.php';
        redirect('login.php?next=' . urlencode($target));
    }
    require_once __DIR__ . '/competition.php';
    if (!schema_has_competitions()) {
        redirect('../upgrade.php');
    }
    $requestedCompetition = competition_request_param();
    if ($requestedCompetition !== '') {
        resolve_competition_param($requestedCompetition);
    } else {
        ensure_competition_context();
    }
    return $u;
}

/** Darf das Konto die Benutzer verwalten? Nur der SuperAdmin. */
function is_superadmin(): bool
{
    $u = current_user();
    return $u !== null && (int) ($u['is_superadmin'] ?? 0) === 1;
}

/**
 * Wächter für die Benutzerverwaltung. Alle anderen Seiten bleiben für jedes
 * Konto zugänglich; nur das Anlegen, Ändern und Löschen von Konten ist dem
 * SuperAdmin vorbehalten.
 */
function require_superadmin(): array
{
    $u = require_login();
    if (!is_superadmin()) {
        flash('Die Benutzerverwaltung ist dem SuperAdmin vorbehalten.', 'err');
        redirect('index.php');
    }
    return $u;
}

/** Anzahl aktiver SuperAdmins. Darf nie 0 werden, sonst ist niemand mehr zuständig. */
function superadmin_count(): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM users WHERE is_superadmin = 1 AND active = 1');
    $st->execute();
    return (int) $st->fetchColumn();
}

function login(string $username, string $password): bool
{
    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$username]);
    $u = $st->fetch();
    if ($u && (int) ($u['active'] ?? 1) === 1 && password_verify($password, $u['password_hash'])) {
        start_session();
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        return true;
    }
    return false;
}

function logout(): void
{
    start_session();
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    $_SESSION = [];
    session_destroy();
}
