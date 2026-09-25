<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

date_default_timezone_set(config()['timezone'] ?? 'Europe/Zurich');

/* mbstring ist nicht auf jedem Hosting aktiv – diese Ersatzfunktionen springen ein. */
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $length = null, $enc = null) {
        return $length === null ? substr((string) $s, $start) : substr((string) $s, $start, $length);
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $enc = null) { return strtolower((string) $s); }
}
if (!function_exists('mb_strimwidth')) {
    function mb_strimwidth($s, $start, $width, $trim = '', $enc = null) {
        $s = (string) $s;
        return strlen($s) > $width ? substr($s, $start, $width) . $trim : $s;
    }
}

function start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_set_cookie_params([
            'httponly' => true,
            'secure' => $secure,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/** HTML-sicher ausgeben. */
function h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function post(string $key, $default = ''): string
{
    return isset($_POST[$key]) && is_scalar($_POST[$key]) ? trim((string) $_POST[$key]) : (string) $default;
}

function get(string $key, $default = ''): string
{
    return isset($_GET[$key]) && is_scalar($_GET[$key]) ? trim((string) $_GET[$key]) : (string) $default;
}

/** Text auf die Länge des jeweiligen Datenbankfelds begrenzen. */
function text_limit(string $value, int $length): string
{
    return mb_substr(trim($value), 0, $length);
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

/** Eine nichtnegative, endliche Zahl aus Formulartext lesen. */
function nonnegative_number(string $raw): ?float
{
    $raw = str_replace(',', '.', trim($raw));
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    $value = (float) $raw;
    return is_finite($value) && $value >= 0 ? $value : null;
}

/** Nur lokale Relative/absolute-Pfade für Redirects zulassen. */
function safe_local_redirect(string $target, string $fallback = 'index.php'): string
{
    $target = trim($target);
    if ($target === '' || preg_match('/[\x00-\x1F\x7F]/', $target)
        || strpos($target, '\\') !== false || strpos($target, '//') !== false) {
        return $fallback;
    }
    $parts = parse_url($target);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])
        || isset($parts['user']) || isset($parts['pass'])) {
        return $fallback;
    }
    return $target;
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    start_session();
    $expected = isset($_SESSION['csrf']) && is_string($_SESSION['csrf']) ? $_SESSION['csrf'] : '';
    $sent = $_POST['_csrf'] ?? '';
    // Ohne Token in der Sitzung wird nichts akzeptiert: sonst käme eine leere
    // Eingabe mit einer leeren Erwartung überein.
    if ($expected === '' || !is_string($sent) || !hash_equals($expected, $sent)) {
        http_response_code(400);
        exit('Sicherheitsprüfung fehlgeschlagen. Bitte Seite neu laden und nochmals senden.');
    }
}

/* ---------- Einmal-Token gegen doppeltes Absenden ---------- */

/**
 * Ein Token je Formular. Es wird beim Absenden verbraucht: ein erneutes
 * Absenden derselben Seite – Zurück im Browser, doppelter Klick, Reload –
 * kommt dann nicht mehr durch. Nach dem Verbrauch wird ein neues Token
 * erzeugt, sobald die Formularseite neu aufgebaut wird.
 */
function form_token(string $form = 'default'): string
{
    start_session();
    $tokens = isset($_SESSION['form_tokens']) && is_array($_SESSION['form_tokens'])
        ? $_SESSION['form_tokens'] : [];
    if (empty($tokens[$form]) || !is_string($tokens[$form])) {
        $tokens[$form] = bin2hex(random_bytes(16));
        $_SESSION['form_tokens'] = $tokens;
    }
    return $tokens[$form];
}

function form_token_field(string $form = 'default'): string
{
    return '<input type="hidden" name="_form_token" value="' . h(form_token($form)) . '">';
}

/** Verbraucht das Token und meldet zurück, ob es noch gültig war. */
function form_token_consume(string $form = 'default'): bool
{
    start_session();
    $tokens = isset($_SESSION['form_tokens']) && is_array($_SESSION['form_tokens'])
        ? $_SESSION['form_tokens'] : [];
    $expected = isset($tokens[$form]) && is_string($tokens[$form]) ? $tokens[$form] : '';
    unset($tokens[$form]);
    $_SESSION['form_tokens'] = $tokens;
    $sent = $_POST['_form_token'] ?? '';
    return $expected !== '' && is_string($sent) && hash_equals($expected, $sent);
}

/* ---------- Angaben der Installation ---------- */

/**
 * Feste Bezeichnung oben links im Kopf. Sie kommt aus config.php und nicht aus
 * den Wettbewerbseinstellungen: Der Kopf nennt die Plattform, der Wettbewerb
 * steht daneben im Abzeichen.
 */
function site_name(): string
{
    $name = trim((string) (config()['site_name'] ?? ''));
    return $name !== '' ? $name : 'Ziellandekonkurrenz';
}

/** Logo in assets. Der Name wird geprüft, damit nichts aus config.php in den Pfad gelangt. */
function site_logo(): string
{
    $logo = trim((string) (config()['logo'] ?? ''));
    return preg_match('/^[A-Za-z0-9._-]+\.(png|jpe?g|svg|webp|gif)$/i', $logo) ? $logo : 'logo_nordwest.jpg';
}

/* ---------- Adressen und Links ---------- */

/** Gültige E-Mail-Adresse ohne Zeilenumbrüche, damit nichts in Kopfzeilen landet. */
function is_valid_email(string $email): bool
{
    $email = trim($email);
    if ($email === '' || text_length($email) > 160 || preg_match('/[\r\n]/', $email)) {
        return false;
    }
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Adresse einer Seite dieser Installation, zum Beispiel für Links in E-Mails.
 * Host und Verzeichnis stammen aus dem Aufruf; ohne erkennbaren Host oder mit
 * unerwarteten Zeichen wird ein leerer String geliefert.
 */
function site_url(string $path = ''): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host)) {
        return '';
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = rtrim(preg_replace('#[^A-Za-z0-9/._-]#', '', dirname($script)), '/');
    if (!preg_match('#^[A-Za-z0-9/._?=&%-]*$#', $path)) {
        return '';
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https://' : 'http://') . $host . $dir . '/' . ltrim($path, '/');
}

/* ---------- Flash-Meldungen ---------- */

function flash(string $text, string $type = 'ok'): void
{
    start_session();
    $_SESSION['flash'][] = ['text' => $text, 'type' => $type];
}

function flash_take(): array
{
    start_session();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- Zeit ---------- */

/**
 * Nimmt "178", "178.4", "2:58", "2:58.4" entgegen und liefert Sekunden.
 * Leere Eingabe ergibt null.
 */
function parse_time(?string $raw): ?float
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    $raw = str_replace(',', '.', $raw);
    if (strpos($raw, ':') !== false) {
        [$m, $s] = array_pad(explode(':', $raw, 2), 2, '0');
        if (!is_numeric($m) || !is_numeric($s)) {
            return null;
        }
        $minutes = (float) $m;
        $seconds = (float) $s;
        if (!is_finite($minutes) || !is_finite($seconds) || $minutes < 0 || $seconds < 0) {
            return null;
        }
        return $minutes * 60 + $seconds;
    }
    if (!is_numeric($raw)) {
        return null;
    }
    $seconds = (float) $raw;
    return is_finite($seconds) && $seconds >= 0 ? $seconds : null;
}

/** Sekunden als m:ss darstellen. */
function fmt_time(?float $sec): string
{
    if ($sec === null) {
        return '–';
    }
    $neg    = $sec < 0;
    $tenths = (int) round(abs($sec) * 10);
    $m      = intdiv($tenths, 600);
    $rest   = $tenths - $m * 600;              // Zehntelsekunden innerhalb der Minute

    $str = $rest % 10 === 0
        ? sprintf('%d:%02d', $m, intdiv($rest, 10))
        : sprintf('%d:%04.1f', $m, $rest / 10);

    return ($neg ? '−' : '') . $str;
}

/** Zahl ohne unnötige Nachkommastellen. */
function fmt_num($n, int $dec = 1): string
{
    if ($n === null || $n === '') {
        return '–';
    }
    $n = (float) $n;
    return rtrim(rtrim(number_format($n, $dec, '.', ''), '0'), '.') ?: '0';
}

function full_name(array $p): string
{
    return trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
}
