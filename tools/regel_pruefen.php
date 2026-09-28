<?php
/**
 * Vergleicht die JavaScript-Funktion penaltyOf() aus admin/erfassung.php mit
 * calc_penalty() aus lib/scoring.php.
 *
 * Aufruf:  php tools/regel_pruefen.php [http://127.0.0.1:PORT]
 *
 * Ohne Adresse wird ein eigener Server gestartet, angemeldet und die Seite
 * geholt. Es ist ein Wegwerf-Konto noetig: dafuer wird das Passwort des ersten
 * SuperAdmins auf ein bekanntes gesetzt und danach nicht zurueckgesetzt – die
 * Klartextfassung gibt es nicht, nur den Hash. Also vorher ein eigenes Konto
 * anlegen und dessen Passwort eintragen.
 *
 * Beide Rechnungen bekommen dieselbe Falltabelle. Zaehlt jeder Fall, stimmen
 * Bildschirm und Datenbank ueberein.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/scoring.php';
require_once __DIR__ . '/../lib/competition.php';

const PW_TEST = 'Zwischenstand2026!';

// Dieses Skript gehoert auf die Kommandozeile. Vom Browser aufgerufen wuerde es
// das Passwort des ersten SuperAdmins aendern und einen Wettbewerb anlegen –
// ohne Anmeldung, auf Verlangen jedes Besuchers. Der Zusatz .htaccess allein
// genuegt nicht, weil der Server Regelungen in Verzeichnissen je nach Hoster
// ignoriert.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Dieses Skript laeuft nur auf der Kommandozeile.\n");
}

$port = (int) (explode(':', $argv[1] ?? '')[1] ?? 0);
$server = null;
if ($port === 0) {
    $port = 8500 + (getmypid() % 400);
    $GLOBALS['regel_server'] = proc_open(
        sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(dirname(__DIR__))),
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes
    );
    if (!is_resource($GLOBALS['regel_server'])) {
        fwrite(STDERR, "Der Testserver liess sich nicht starten.\n");
        exit(2);
    }
    // Auf warten, bis der Port antwortet
    for ($i = 0; $i < 60; $i++) {
        $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($s) {
            fclose($s);
            break;
        }
        usleep(100000);
    }
}
$basis = 'http://127.0.0.1:' . $port;

$pdo = db();
$admin = $pdo->query('SELECT id, username, password_hash FROM users
                      WHERE is_superadmin = 1 AND active = 1 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$admin) {
    fwrite(STDERR, "Es gibt kein aktives SuperAdmin-Konto.\n");
    exit(2);
}
$altHash = $admin['password_hash'];
$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
    ->execute([password_hash(PW_TEST, PASSWORD_DEFAULT), $admin['id']]);

/** Einen Wettbewerb mit fester Zielzeit und festen Strafpunkten anlegen. */
function wettbewerb_anlegen(PDO $pdo): int
{
    $club = (int) $pdo->query('SELECT id FROM clubs ORDER BY id LIMIT 1')->fetchColumn();
    $typ  = (int) $pdo->query('SELECT id FROM model_types ORDER BY id LIMIT 1')->fetchColumn();
    $name = 'ZZ Regelpruefung ' . substr(md5((string) mt_rand()), 0, 8);
    $pdo->prepare('INSERT INTO competitions (name, club_id, region) VALUES (?,?,0)')->execute([$name, $club]);
    $w = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO competition_settings (competition_id,skey,svalue) VALUES (?,?,?)')
        ->execute([$w, 'competition_date', '2026-06-19']);
    foreach (['penalty_per_second' => '1', 'penalty_per_meter' => '1',
              'penalty_outlanding' => '100', 'penalty_crash' => '100',
              'penalty_not_started' => '100', 'penalty_motor' => '100',
              'competition_name' => $name, 'drop_enabled' => '0',
              'ranking_scope' => 'group', 'public_results' => '1'] as $k => $v) {
        $pdo->prepare('INSERT INTO competition_settings (competition_id,skey,svalue) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')->execute([$w, $k, $v]);
    }
    for ($r = 1; $r <= 2; $r++) {
        $pdo->prepare('INSERT INTO rounds (competition_id, round_number, target_time_seconds, is_active, is_included)
                       VALUES (?,?,?,?,1)')->execute([$w, $r, 180, $r === 1 ? 1 : 0]);
    }
    // Mindestens ein Pilot: ohne Startliste gibt die Erfassungsseite kein
    // Formular und damit auch kein JavaScript aus.
    $pdo->prepare('INSERT INTO pilots (competition_id, bib_number, first_name, last_name, club_id, model_type_id, active)
                   VALUES (?,?,?,?,?,?,1)')->execute([$w, '01', 'Testpilot', 'Regel', $club, $typ]);
    $pdo->prepare('UPDATE competitions SET is_current = 0')->execute();
    $pdo->prepare('UPDATE competitions SET is_current = 1 WHERE id = ?')->execute([$w]);
    return $w;
}

function wettbewerb_raeumen(PDO $pdo, int $w): void
{
    foreach ($pdo->query("SELECT id FROM rounds WHERE competition_id = $w")->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        $pdo->prepare('DELETE FROM scores WHERE round_id = ?')->execute([$rid]);
    }
    foreach (['rounds', 'pilots', 'competition_settings'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE competition_id = ?")->execute([$w]);
    }
    $pdo->prepare('DELETE FROM competitions WHERE id = ?')->execute([$w]);
    $pdo->prepare('UPDATE competitions SET is_current = 0')->execute();
    $pdo->prepare('UPDATE competitions SET is_current = 1 WHERE id = 1')->execute();
}

/**
 * Alles zurueck: Testwettbewerb, gesetztes Passwort, laufender Server und
 * Cookie-Datei. Wird bei jedem Abbruch aufgerufen, damit kein halber Zustand
 * liegen bleibt.
 */
function aufraeumen(PDO $pdo, int $w, int $userId, string $altHash): void
{
    wettbewerb_raeumen($pdo, $w);
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$altHash, $userId]);
    if (($GLOBALS['regel_jar'] ?? '') !== '') {
        @unlink($GLOBALS['regel_jar']);
    }
    if (is_resource($GLOBALS['regel_server'] ?? null)) {
        proc_terminate($GLOBALS['regel_server']);
        proc_close($GLOBALS['regel_server']);
    }
}

$w = wettbewerb_anlegen($pdo);
$runde = (int) $pdo->query("SELECT id FROM rounds WHERE competition_id = $w ORDER BY round_number LIMIT 1")->fetchColumn();

$GLOBALS['regel_jar'] = tempnam(sys_get_temp_dir(), 'regeljar');
function http(string $u, ?array $post = null): string
{
    $jar = $GLOBALS['regel_jar'];
    $ch = curl_init($u);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $o = curl_exec($ch);
    if ($o === false) {
        fwrite(STDERR, "Abruf fehlgeschlagen: $u\n");
        exit(2);
    }
    curl_close($ch);
    return (string) $o;
}
function token(string $h): string
{
    return preg_match('/name="_csrf" value="([^"]*)"/', $h, $m) ? $m[1] : '';
}

$t = token(http("$basis/admin/login.php"));
http("$basis/admin/login.php", [
    'username' => (string) $admin['username'], 'password' => PW_TEST, '_csrf' => $t,
]);
$seite = http("$basis/admin/erfassung.php?competition=$w&dg=$runde");
if (!str_contains($seite, 'function penaltyOf(')) {
    fwrite(STDERR, "Die Seite liess sich nicht als angemeldeter Benutzer holen.\n");
    aufraeumen($pdo, $w, $admin['id'], $altHash);
    exit(2);
}

// ------------------------------------------------------ Falltabelle
// status, Zeit, Landewert, Motor, Kästchen, Beschreibung
$faelle = [
    ['flown', 180.0,   0.0, false, [],                 'genau auf Ziel'],
    ['flown', 200.0,  20.0, false, [],                 '20 s lang, Lande 20'],
    ['flown', 160.0,  10.0, false, [],                 '20 s kurz, Lande 10'],
    ['flown', 180.0,  20.0, true,  [],                 'Motor, auf Ziel'],
    ['flown', 200.0,  20.0, true,  [],                 'Motor, 20 s lang'],
    ['flown', 180.0,   0.0, true,  [],                 'Motor, Lande 0'],
    ['dnf',   190.0,  15.0, false, ['outlanding'],     'Aussenlandung, Lande zaehlt nicht'],
    ['dnf',   180.0,   0.0, false, ['outlanding'],     'Aussenlandung ohne Abweichung'],
    ['dnf',   190.0,  15.0, true,  ['outlanding'],     'Aussenlandung + Motor'],
    ['crash', 190.0,  15.0, false, ['crash'],          'Bruchlandung, Lande zaehlt'],
    ['crash', 200.0,   0.0, false, ['crash'],          'Bruchlandung ohne Lande'],
    ['crash', 190.0,  15.0, true,  ['crash'],          'Bruchlandung + Motor'],
    ['dns',   null,   null, false, ['not_started'],    'nicht angetreten'],
    ['dns',   300.0,  50.0, false, ['not_started'],    'nicht angetreten, Zeit eingetragen'],
    ['dns',   null,   null, true,  ['not_started'],    'nicht angetreten + Motor'],
    ['crash', 180.0,   5.0, false, ['crash', 'not_started'], 'Bruchlandung + nicht angetreten'],
    ['dnf',   190.0,  15.0, false, ['outlanding', 'not_started'], 'Aussenlandung + nicht angetreten'],
];

// ------------------------------------------------------ PHP rechnen
set_competition_context($w);
settings(true);
$php = [];
foreach ($faelle as $f) {
    $php[] = calc_penalty($f[0], $f[1], $f[2], 180, $f[3])[2];
}

// ------------------------------------------------------ Seite auswerten
if (!preg_match('/function penaltyOf\(flags, time, dist\) \{.*?\n  \}/s', $seite, $fn)
    || !preg_match('/var cfg = (\{.*?\});/s', $seite, $cf)) {
    fwrite(STDERR, "Die Funktion penaltyOf() oder die Konfiguration fehlt in der Seite.\n");
    aufraeumen($pdo, $w, $admin['id'], $altHash);
    exit(2);
}
$cfg = json_decode($cf[1], true);
if (!is_array($cfg)) {
    fwrite(STDERR, "Die Konfiguration der Seite liess sich nicht lesen.\n");
    aufraeumen($pdo, $w, $admin['id'], $altHash);
    exit(2);
}

$flagNames = [
    'not_started' => 'dns', 'outlanding' => 'dnf', 'crash' => 'crash', 'motor' => 'motor',
];
$jsEingabe = [];
foreach ($faelle as $f) {
    $flags = ['not_started' => $f[0] === 'dns', 'outlanding' => $f[0] === 'dnf',
              'crash' => $f[0] === 'crash', 'motor' => $f[3]];
    $jsEingabe[] = [$flags, $f[1], $f[2]];
}

$js = "var cfg = " . json_encode($cfg, JSON_THROW_ON_ERROR) . ";\n"
    . "function round2(v) { return Math.round(v * 100) / 100; }\n"
    . $fn[0] . "\n"
    . 'var eingabe = ' . json_encode($jsEingabe, JSON_THROW_ON_ERROR) . ";\n"
    . "var out = [];\n"
    . "for (var i = 0; i < eingabe.length; i++) {\n"
    . "  out.push(penaltyOf(eingabe[i][0], eingabe[i][1], eingabe[i][2]));\n"
    . "}\n"
    . "process.stdout.write(JSON.stringify(out));\n";

$jsDatei = tempnam(sys_get_temp_dir(), 'regel') . '.js';
file_put_contents($jsDatei, $js);
exec('node ' . escapeshellarg($jsDatei) . ' 2>&1', $outLines, $code);
@unlink($jsDatei);
$roher = trim(implode("\n", $outLines));
if ($code !== 0 || $roher === '') {
    fwrite(STDERR, "node liess die Funktion nicht aus:\n$roher\n");
    aufraeumen($pdo, $w, $admin['id'], $altHash);
    exit(2);
}
$jsErgebnis = json_decode($roher, true);
if (!is_array($jsErgebnis)) {
    fwrite(STDERR, "Die Ausgabe von node war unlesbar: $roher\n");
    aufraeumen($pdo, $w, $admin['id'], $altHash);
    exit(2);
}

// ------------------------------------------------------ Vergleich
echo "Zielzeit 180 s, je Sekunde {$cfg['perSecond']}, je Landewert-Einheit {$cfg['meter']}, ";
echo "Feststrafen {$cfg['outlanding']}/{$cfg['crash']}/{$cfg['notStarted']}, Motor {$cfg['motor']}\n\n";
printf("  %-9s %-7s %-7s %-6s %-34s %-9s %-9s %s\n", 'Status', 'Zeit', 'Lande', 'Motor', 'Fall', 'PHP', 'Browser', '');
echo '  ' . str_repeat('-', 96) . "\n";

$abweichungen = 0;
foreach ($faelle as $i => $f) {
    [$status, $zeit, $lande, $motor, , $besch] = $f;
    $p = $php[$i];
    $j = $jsErgebnis[$i]['total'] ?? null;
    $ok = $j !== null && abs((float) $j - (float) $p) < 0.005;
    $abweichungen += $ok ? 0 : 1;
    printf("  %-9s %-7s %-7s %-6s %-34s %-9s %-9s %s\n",
        $status,
        $zeit === null ? '-' : (string) $zeit,
        $lande === null ? '-' : (string) $lande,
        $motor ? 'ja' : 'nein',
        $besch,
        sprintf('%.2f', $p),
        $j === null ? '–' : sprintf('%.2f', (float) $j),
        $ok ? 'OK' : 'ABWEICHUNG');
}

aufraeumen($pdo, $w, $admin['id'], $altHash);

echo "\n", $abweichungen === 0
    ? "  Bildschirm und Datenbank rechnen gleich.\n"
    : "  $abweichungen Abweichungen. Der Bildschirm zeigt etwas anderes, als gespeichert wird.\n";
exit($abweichungen === 0 ? 0 : 1);
