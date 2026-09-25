<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

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
            $user = $st->fetch() ?: null;
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

function login(string $username, string $password): bool
{
    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$username]);
    $u = $st->fetch();
    if ($u && password_verify($password, $u['password_hash'])) {
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
