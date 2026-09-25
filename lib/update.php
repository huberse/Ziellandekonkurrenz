<?php
declare(strict_types=1);

/**
 * Aktualisierung aus dem Repository.
 *
 * Der Ablauf ist immer derselbe:
 *   1. Die Bestandsliste von GitHub holen. Sie nennt jede Datei der neuen
 *      Version mit ihrer Pruefsumme.
 *   2. Vergleichen: Welche Dateien sind hier noch unveraendert?
 *   3. Nur diese ersetzen. Was jemand von Hand angefasst hat, bleibt stehen
 *      und wird gemeldet.
 *   4. Vorher eine Sicherung anlegen, damit ein Knopf zurueckholt.
 *
 * Grundsaetze:
 *   - Quelle und Ziel stehen fest in lib/version.php. Es gibt keine Adresse
 *     aus dem Formular, damit die Seite nicht als Bruecke zu beliebigen
 *     Zielen taugt.
 *   - Geschrieben wird nur, was in der Bestandsliste steht, und nur, wenn die
 *     Pruefsumme des Inhalts dazu passt. Ein Archiv kann also keine Datei
 *     unterbringen, die niemand angefordert hat.
 *   - Erst werden alle Inhalte geprueft, dann geschrieben. Stimmt eines nicht,
 *     wird gar nichts geschrieben.
 *   - config.php, .htaccess, .gitignore und die Logos werden nie ersetzt.
 *     Sie gehoeren dem Server, nicht dem Programm.
 */

require_once __DIR__ . '/version.php';

// Grenzen. Diese Anwendung ist klein; alles darueber deutet auf etwas anderes
// hin und wird abgewiesen.
const UPDATE_MAX_DOWNLOAD = 20971520;   // 20 MB
const UPDATE_MAX_DATEI    = 4194304;    // 4 MB je Datei
const UPDATE_MAX_GESAMT   = 20971520;   // 20 MB altogether im Speicher
const UPDATE_SPERR_ALTER  = 900;        // Sekunden, danach gilt die Sperre als verwaist

// ---------------------------------------------------------------------------
// Pfade
// ---------------------------------------------------------------------------

/** Wurzelverzeichnis der Anwendung. */
function update_root(): string
{
    return dirname(__DIR__);
}

/** Eigenes Verzeichnis fuer Sicherungen und Sperre. */
function update_dir(): string
{
    return update_root() . '/.update';
}

function update_manifest_pfad(): string
{
    return update_root() . '/manifest.json';
}

/**
 * Dateien, die der Knopf nie anfasst, mit dem Grund.
 *
 * config.php steht nicht im Repository, haette dort aber denselben Schutz
 * verdient. .htaccess und .gitignore gehoeren dem Server: der Hoster oder der
 * Betreiber koennen dort eigene Anweisungen hinterlegt haben, die ein
 * Ueberschreiben still verschwinden liesse. Die Logos sind Eigenheiten des
 * Vereins.
 */
function update_geschuetzt(): array
{
    return [
        'config.php'               => 'Zugangsdaten',
        '.htaccess'                => 'Serveranweisungen',
        '.gitignore'               => 'Repository-Regeln',
        'assets/logo.png'          => 'Vereinslogo',
        'assets/logo_nordwest.jpg' => 'Vereinslogo',
    ];
}

function update_ist_geschuetzt(string $pfad): bool
{
    return array_key_exists($pfad, update_geschuetzt());
}

// ---------------------------------------------------------------------------
// Holen
// ---------------------------------------------------------------------------

function update_url_raw(string $pfad): string
{
    $teile = array_map('rawurlencode', explode('/', $pfad));
    return 'https://raw.githubusercontent.com/' . APP_REPO . '/' . APP_BRANCH . '/' . implode('/', $teile);
}

function update_url_zip(): string
{
    return 'https://github.com/' . APP_REPO . '/archive/refs/heads/' . rawurlencode(APP_BRANCH) . '.zip';
}

function update_kennung(): string
{
    return APP_REPO . '/' . APP_VERSION;
}

/**
 * Eine HTTPS-Adresse holen.
 *
 * Rueckgabe: ['ok' => bool, 'body' => string, 'error' => string, 'status' => int]
 */
function update_http(string $url, int $maxBytes): array
{
    if (!update_url_erlaubt($url)) {
        return ['ok' => false, 'body' => '', 'error' => 'Diese Adresse ist nicht freigegeben.', 'status' => 0];
    }

    if (function_exists('curl_init')) {
        $zu_viel = false;
        $gelesen = 0;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => update_kennung(),
            // Die Groesse wird unterwegs gezaehlt, damit eine riesige Antwort
            // nicht erst den Speicher fuellt.
            CURLOPT_WRITEFUNCTION  => function ($_c, $block) use (&$zu_viel, &$gelesen, $maxBytes) {
                $gelesen += strlen($block);
                if ($gelesen > $maxBytes) {
                    $zu_viel = true;
                    return 0;          // bricht die Uebertragung ab
                }
                return strlen($block);
            },
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $fehler = curl_error($ch);
        curl_close($ch);
        if ($zu_viel) {
            return ['ok' => false, 'body' => '', 'error' => 'Die Antwort ist grösser als ' . update_mb($maxBytes) . '.', 'status' => $status];
        }
        if ($body === false) {
            return ['ok' => false, 'body' => '', 'error' => $fehler ?: 'Die Verbindung ist fehlgeschlagen.', 'status' => $status];
        }
        if ($status !== 200) {
            return ['ok' => false, 'body' => '', 'error' => 'Der Server antwortet mit ' . $status . '.', 'status' => $status];
        }
        return ['ok' => true, 'body' => (string) $body, 'error' => '', 'status' => $status];
    }

    if (!ini_get('allow_url_fopen')) {
        return ['ok' => false, 'body' => '', 'error' => 'Weder cURL noch allow_url_fopen sind vorhanden.', 'status' => 0];
    }
    $kontext = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => 90,
            'follow_location' => 1,
            'max_redirects'   => 3,
            'user_agent'      => update_kennung(),
            'header'          => "Accept: */*\r\n",
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $kontext, 0, $maxBytes + 1);
    $status = 0;
    foreach ($http_response_header ?? [] as $zeile) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $zeile, $treffer)) {
            $status = (int) $treffer[1];
        }
    }
    if ($body === false) {
        return ['ok' => false, 'body' => '', 'error' => 'Die Verbindung ist fehlgeschlagen.', 'status' => $status];
    }
    if (strlen($body) > $maxBytes) {
        return ['ok' => false, 'body' => '', 'error' => 'Die Antwort ist grösser als ' . update_mb($maxBytes) . '.', 'status' => $status];
    }
    if ($status !== 200) {
        return ['ok' => false, 'body' => '', 'error' => 'Der Server antwortet mit ' . $status . '.', 'status' => $status];
    }
    return ['ok' => true, 'body' => $body, 'error' => '', 'status' => $status];
}

/** Nur die festen Hosts von GitHub. */
function update_url_erlaubt(string $url): bool
{
    $teile = parse_url($url);
    if (($teile['scheme'] ?? '') !== 'https' || empty($teile['host'])) {
        return false;
    }
    return in_array(strtolower((string) $teile['host']),
                    ['raw.githubusercontent.com', 'github.com', 'codeload.github.com'], true);
}

// ---------------------------------------------------------------------------
// Bestandslisten
// ---------------------------------------------------------------------------

/** Die hier installierte Bestandsliste, oder null wenn es keine gibt. */
function update_bestand_installiert(): ?array
{
    $pfad = update_manifest_pfad();
    if (!is_file($pfad) || !is_readable($pfad)) {
        return null;
    }
    $daten = json_decode((string) file_get_contents($pfad), true);
    if (!is_array($daten) || !isset($daten['files']) || !is_array($daten['files'])) {
        return null;
    }
    return ['version' => (string) ($daten['version'] ?? ''), 'files' => (array) $daten['files']];
}

/** Die Bestandsliste von GitHub holen und pruefen. */
function update_bestand_remote(): array
{
    $antwort = update_http(update_url_raw('manifest.json'), 2097152);
    if (!$antwort['ok']) {
        return ['ok' => false, 'manifest' => null, 'error' => $antwort['error']];
    }
    $daten = json_decode($antwort['body'], true);
    if (!is_array($daten) || !is_array($daten['files'] ?? null) || trim((string) ($daten['version'] ?? '')) === '') {
        return ['ok' => false, 'manifest' => null, 'error' => 'Die Bestandsliste von GitHub ist unbrauchbar.'];
    }
    $dateien = update_datein_pruefen($daten['files']);
    if ($dateien === null || !$dateien) {
        return ['ok' => false, 'manifest' => null, 'error' => 'Die Bestandsliste von GitHub enthaelt einen unzulaessigen Pfad.'];
    }
    return ['ok' => true, 'manifest' => ['version' => (string) $daten['version'], 'files' => $dateien], 'error' => ''];
}

/**
 * Pfade und Pruefsummen pruefen.
 *
 * Kommt null zurueck, sobald ein einziger Eintrag unbrauchbar ist. Dann wird
 * die ganze Liste verworfen, nicht nur der schlechte Eintrag.
 */
function update_datein_pruefen(array $roh): ?array
{
    $sauber = [];
    foreach ($roh as $pfad => $summe) {
        $pfad = (string) $pfad;
        if (!is_string($summe) || !preg_match('/^[0-9a-f]{64}$/', (string) $summe)) {
            return null;
        }
        if (!update_pfad_erlaubt($pfad) || update_ist_geschuetzt($pfad)) {
            return null;
        }
        $sauber[$pfad] = (string) $summe;
    }
    return $sauber;
}

/** Ein Pfad aus einer fremden Liste: nur relative Pfade ohne Auswuche. */
function update_pfad_erlaubt(string $pfad): bool
{
    if ($pfad === '' || strlen($pfad) > 200 || strpos($pfad, "\0") !== false) {
        return false;
    }
    if ($pfad[0] === '/' || strpos($pfad, '\\') !== false) {
        return false;
    }
    if (preg_match('#^[A-Za-z]:#', $pfad)) {          // Laufwerksbuchstabe
        return false;
    }
    foreach (explode('/', $pfad) as $teil) {
        if ($teil === '' || $teil === '.' || $teil === '..') {
            return false;
        }
    }
    return strncmp($pfad, '.update', 7) !== 0 && $pfad !== 'manifest.json';
}

// ---------------------------------------------------------------------------
// Vergleich
// ---------------------------------------------------------------------------

/**
 * Was beim Update passieren wuerde.
 *
 * Schluessel:
 *   ersetzen  Datei ist hier unveraendert und bekommt den neuen Inhalt
 *   neu       Datei gibt es hier noch nicht
 *   gleich    Datei ist bereits auf dem neuen Stand
 *   geaendert Datei weicht vom installierten Stand ab: bleibt stehen
 *   unbekannt Datei gibt es hier, stand aber in keiner Bestandsliste
 *   weg       Datei wird von der neuen Version nicht mehr gebraucht
 *   von_hand  geschuetzte Datei, die sich geaendert hat: selbst uebernehmen
 */
function update_plan(?array $alt, array $neu): array
{
    $wurzel = update_root();
    $plan = ['ersetzen' => [], 'neu' => [], 'gleich' => [], 'geaendert' => [],
             'unbekannt' => [], 'weg' => [], 'von_hand' => []];

    foreach ($neu['files'] as $pfad => $summe) {
        $da = is_file($wurzel . '/' . $pfad);
        $ist = $da ? @hash_file('sha256', $wurzel . '/' . $pfad) : null;

        if (update_ist_geschuetzt($pfad)) {
            if (!$da || $ist !== $summe) {
                $plan['von_hand'][$pfad] = ['server' => $ist, 'grund' => $da ? 'geaendert' : 'fehlt'];
            }
            continue;
        }
        if ($da && $ist === $summe) {
            $plan['gleich'][$pfad] = $summe;
        } elseif (!$da) {
            $plan['neu'][$pfad] = $summe;
        } elseif ($alt === null) {
            // Keine Bestandsliste: dieser Server ist aelter als die erste
            // versionierte Fassung. Es gibt nichts, wogegen sich pruefen liesse.
            $plan['ersetzen'][$pfad] = ['server' => (string) $ist, 'neu' => $summe, 'geprueft' => false];
        } elseif (!isset($alt['files'][$pfad])) {
            $plan['unbekannt'][$pfad] = (string) $ist;
        } elseif (hash_equals((string) $alt['files'][$pfad], (string) $ist)) {
            $plan['ersetzen'][$pfad] = ['server' => (string) $ist, 'neu' => $summe, 'geprueft' => true];
        } else {
            $plan['geaendert'][$pfad] = ['server' => (string) $ist, 'erwartet' => (string) $alt['files'][$pfad]];
        }
    }

    if ($alt !== null) {
        foreach ($alt['files'] as $pfad => $summe) {
            if (!isset($neu['files'][$pfad]) && is_file($wurzel . '/' . $pfad)) {
                $plan['weg'][$pfad] = (string) @hash_file('sha256', $wurzel . '/' . $pfad);
            }
        }
    }
    return $plan;
}

/** Wie viele Dateien tatsaechlich geschrieben wuerden. */
function update_plan_umfang(array $plan): int
{
    return count($plan['ersetzen']) + count($plan['neu']);
}

/** Kurz gesagt, ob es etwas zu tun gibt. */
function update_plan_beschreibung(array $plan): string
{
    $teile = [];
    if ($plan['ersetzen']) {
        $teile[] = count($plan['ersetzen']) . ' Datei(en) ersetzen';
    }
    if ($plan['neu']) {
        $teile[] = count($plan['neu']) . ' neu';
    }
    if ($plan['geaendert']) {
        $teile[] = count($plan['geaendert']) . ' von Hand geaendert';
    }
    if ($plan['unbekannt']) {
        $teile[] = count($plan['unbekannt']) . ' ohne Eintrag';
    }
    if ($plan['weg']) {
        $teile[] = count($plan['weg']) . ' nicht mehr gebraucht';
    }
    if ($plan['von_hand']) {
        $teile[] = count($plan['von_hand']) . ' geschuetzt';
    }
    return $teile ? implode(', ', $teile) : 'nichts zu tun';
}

// ---------------------------------------------------------------------------
// Ausfuehren
// ---------------------------------------------------------------------------

/**
 * Den Plan ausfuehren: erst alles lesen und pruefen, dann alles schreiben.
 *
 * Rueckgabe:
 *   ['ok' => bool, 'ersetzen' => [], 'neu' => [], 'abgelehnt' => [pfad => Grund],
 *    'fehler' => [], 'sicherung' => string, 'version' => string]
 */
function update_ausfuehren(array $plan, array $neu): array
{
    $bericht = ['ok' => false, 'ersetzen' => [], 'neu' => [], 'abgelehnt' => [], 'fehler' => [],
                'sicherung' => '', 'version' => $neu['version']];

    $zu = update_plan_umfang($plan);
    if ($zu === 0) {
        $bericht['fehler'][] = 'Es gibt nichts zu schreiben.';
        return $bericht;
    }
    if (!update_verzeichnis_anlegen(update_dir())) {
        $bericht['fehler'][] = 'Das Verzeichnis .update liess sich nicht anlegen.';
        return $bericht;
    }
    if (!update_sperre_setzen()) {
        $bericht['fehler'][] = 'Es laeuft bereits ein Update, oder die Sperre ist nicht schreibbar.';
        return $bericht;
    }

    try {
        $antwort = update_http(update_url_zip(), UPDATE_MAX_DOWNLOAD);
        if (!$antwort['ok']) {
            $bericht['fehler'][] = 'Die Datei mit der neuen Version liess sich nicht holen: ' . $antwort['error'];
            return $bericht;
        }
        $zipPfad = update_dir() . '/update.zip';
        if (@file_put_contents($zipPfad, $antwort['body']) === false) {
            $bericht['fehler'][] = 'Die geholte Datei liess sich nicht ablegen.';
            return $bericht;
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPfad) !== true) {
            @unlink($zipPfad);
            $bericht['fehler'][] = 'Die geholte Datei liess sich nicht oeffnen.';
            return $bericht;
        }
        $praefix = update_zip_praefix($zip);
        if ($praefix === null) {
            $zip->close();
            @unlink($zipPfad);
            $bericht['fehler'][] = 'Der Aufbau der geholten Datei ist unerwartet.';
            return $bericht;
        }
        $eintraege = update_zip_namen($zip);

        // ----_phase 1: alles lesen und gegen die Bestandsliste pruefen --------
        $inhalte = [];
        $gesamt = 0;
        foreach ($zu_pfade($plan) as $pfad) {
            $name = $praefix . $pfad;
            if (!isset($eintraege[$name])) {
                $bericht['abgelehnt'][$pfad] = 'im Archiv fehlt die Datei';
                continue;
            }
            $inhalt = update_zip_lesen($zip, $name, UPDATE_MAX_DATEI);
            if ($inhalt === null) {
                $bericht['abgelehnt'][$pfad] = 'der Eintrag liess sich nicht lesen';
                continue;
            }
            $gesamt += strlen($inhalt);
            if ($gesamt > UPDATE_MAX_GESAMT) {
                $bericht['abgelehnt'][$pfad] = 'die Auslieferung ist zu gross';
                break;
            }
            if (!hash_equals($neu['files'][$pfad], hash('sha256', $inhalt))) {
                $bericht['abgelehnt'][$pfad] = 'der Inhalt passt nicht zur Bestandsliste';
                continue;
            }
            if (substr($pfad, -4) === '.php' && !update_php_gueltig($inhalt)) {
                $bericht['abgelehnt'][$pfad] = 'der Inhalt ist kein gueltiges PHP';
                continue;
            }
            $inhalte[$pfad] = $inhalt;
        }
        $zip->close();
        @unlink($zipPfad);

        if ($bericht['abgelehnt']) {
            $bericht['fehler'][] = 'Nichts geschrieben, weil ' . count($bericht['abgelehnt'])
                                 . ' Datei(en) nicht zur Bestandsliste passen.';
            return $bericht;
        }

        // ----_phase 2: sichern, dann schreiben ---------------------------------
        $ersetzen = array_keys($plan['ersetzen']);
        $sicherung = update_sicherung_anlegen(array_merge($ersetzen, ['manifest.json']));
        if ($sicherung === null) {
            $bericht['fehler'][] = 'Die Sicherung liess sich nicht anlegen.';
            return $bericht;
        }
        $bericht['sicherung'] = $sicherung;

        $geschrieben = [];
        foreach ($inhalte as $pfad => $inhalt) {
            if (update_datei_schreiben($pfad, $inhalt)) {
                $geschrieben[] = $pfad;
                if (isset($plan['ersetzen'][$pfad])) {
                    $bericht['ersetzen'][] = $pfad;
                } else {
                    $bericht['neu'][] = $pfad;
                }
            } else {
                $bericht['abgelehnt'][$pfad] = 'die Datei liess sich nicht einsetzen';
            }
        }
        if ($bericht['abgelehnt']) {
            // Etwas fehlt: alles wieder zurueckholen, damit kein halber Stand bleibt.
            $zurueck = update_wiederherstellen($sicherung, $geschrieben);
            $bericht['fehler'][] = $zurueck . ' Datei(en) wurden zurueckgeholt.';
            return $bericht;
        }

        // Die neue Bestandsliste zuletzt. Bricht etwas davor ab, gilt weiter die
        // alte, und das Update ist beim naechsten Versuch wieder da.
        $text = json_encode($neu, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        if (@file_put_contents(update_manifest_pfad(), $text) === false) {
            $bericht['fehler'][] = 'Die neue Bestandsliste liess sich nicht schreiben.';
            return $bericht;
        }

        $bericht['ok'] = true;
        return $bericht;
    } finally {
        update_sperre_loeschen();
    }
}

/** Die Pfade, die geschrieben werden sollen: erst ersetzen, dann neu. */
function zu_pfade(array $plan): array
{
    return array_merge(array_keys($plan['ersetzen']), array_keys($plan['neu']));
}

/**
 * Ist der Inhalt gueltiges PHP?
 *
 * `php -l` waere der naehere Weg, braucht aber exec() und ist auf Hostern oft
 * abgeschaltet. Der Tokenizer mit TOKEN_PARSE parst dagegen vollstaendig und
 * wirft bei einem Syntaxfehler einen ParseError - ohne den Code auszufuehren.
 */
function update_php_gueltig(string $inhalt): bool
{
    if (!defined('TOKEN_PARSE')) {                 // aelteres PHP ohne diese Option
        return true;                               // dann eben nicht pruefen
    }
    try {
        token_get_all($inhalt, TOKEN_PARSE);
        return true;
    } catch (ParseError $e) {
        return false;
    }
}

/**
 * Eine Datei einsetzen.
 *
 * Es wird nicht entpackt, sondern nebenher geschrieben und dann umbenannt, so
 * steht nie eine halbe Datei da.
 */
function update_datei_schreiben(string $pfad, string $inhalt): bool
{
    $ziel = update_root() . '/' . $pfad;
    if (!update_verzeichnis_anlegen(dirname($ziel))) {
        return false;
    }
    $tmp = $ziel . '.neu-' . getmypid();
    if (@file_put_contents($tmp, $inhalt) === false) {
        return false;
    }
    $rechte = is_file($ziel) ? (@fileperms($ziel) & 0777) : 0644;
    @chmod($tmp, $rechte);
    if (!@rename($tmp, $ziel)) {
        @unlink($tmp);
        return false;
    }
    clearstatcache(true, $ziel);
    return true;
}

/** Das gemeinsame Hauptverzeichnis eines Archivs von GitHub. */
function update_zip_praefix(ZipArchive $zip): ?string
{
    $praefix = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if ($name === '' || substr($name, -1) === '/') {
            continue;                                   // Verzeichniseintrag
        }
        $teil = strstr($name, '/', true);
        if ($teil === false || $teil === '') {
            return null;                                // Datei ohne Hauptverzeichnis
        }
        if ($praefix === null) {
            $praefix = $teil . '/';
        } elseif ($praefix !== $teil . '/') {
            return null;                                // mehrere Hauptverzeichnisse
        }
    }
    return $praefix;
}

/** Index der Dateinamen eines Archivs. */
function update_zip_namen(ZipArchive $zip): array
{
    $namen = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $namen[(string) $zip->getNameIndex($i)] = true;
    }
    return $namen;
}

/** Einen Eintrag lesen, mit Groessengrenze. */
function update_zip_lesen(ZipArchive $zip, string $name, int $maxBytes): ?string
{
    $strom = $zip->getStream($name);
    if ($strom === false) {
        return null;
    }
    $inhalt = stream_get_contents($strom, $maxBytes + 1);
    fclose($strom);
    if ($inhalt === false || strlen($inhalt) > $maxBytes) {
        return null;
    }
    return $inhalt;
}

// ---------------------------------------------------------------------------
// Sicherung
// ---------------------------------------------------------------------------

/** Die genannten Dateien in ein neues Sicherungsverzeichnis kopieren. */
function update_sicherung_anlegen(array $pfade): ?string
{
    $wurzel = update_root();
    $ziel = update_dir() . '/sicherung-' . date('Ymd-His');
    if (!update_verzeichnis_anlegen($ziel)) {
        return null;
    }
    foreach ($pfade as $pfad) {
        $quelle = $wurzel . '/' . $pfad;
        if (!is_file($quelle)) {
            continue;                                   // gab es vorher nicht
        }
        $zielPfad = $ziel . '/' . $pfad;
        if (!update_verzeichnis_anlegen(dirname($zielPfad))) {
            return null;
        }
        if (!@copy($quelle, $zielPfad)) {
            return null;
        }
    }
    $notiz = ['zeit' => date('c'), 'version' => APP_VERSION, 'dateien' => array_values($pfade)];
    @file_put_contents($ziel . '/sicherung.json',
                       json_encode($notiz, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    return $ziel;
}

/** Aus einer Sicherung die genannten Dateien zurueckholen. */
function update_wiederherstellen(string $sicherung, array $pfade): int
{
    $zaehler = 0;
    foreach ($pfade as $pfad) {
        $quelle = $sicherung . '/' . $pfad;
        if (is_file($quelle) && @copy($quelle, update_root() . '/' . $pfad)) {
            $zaehler++;
        }
    }
    return $zaehler;
}

/** Sicherungen, neueste zuerst. */
function update_sicherungen(): array
{
    $dir = update_dir();
    if (!is_dir($dir)) {
        return [];
    }
    $gefunden = glob($dir . '/sicherung-*', GLOB_ONLYDIR) ?: [];
    rsort($gefunden, SORT_STRING);
    $liste = [];
    foreach ($gefunden as $pfad) {
        $notiz = is_file($pfad . '/sicherung.json')
            ? json_decode((string) file_get_contents($pfad . '/sicherung.json'), true)
            : null;
        $liste[] = [
            'pfad'    => $pfad,
            'name'    => basename($pfad),
            'zeit'    => filemtime($pfad) ?: 0,
            'version' => is_array($notiz) ? (string) ($notiz['version'] ?? '') : '',
        ];
    }
    return $liste;
}

/** Die neueste Sicherung zurueckholen. */
function update_rueckgaengig(): array
{
    $sicherungen = update_sicherungen();
    if (!$sicherungen) {
        return ['ok' => false, 'text' => 'Es gibt keine Sicherung zum Zurueckholen.'];
    }
    $basis = update_sicherungen()[0]['pfad'];
    if (!update_sperre_setzen()) {
        return ['ok' => false, 'text' => 'Es laeuft bereits ein Update.'];
    }
    try {
        $zurueck = 0;
        $fehler = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basis, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $eintrag) {
            if ($eintrag->getFilename() === 'sicherung.json') {
                continue;
            }
            $pfad = str_replace('\\', '/', substr($eintrag->getPathname(), strlen($basis) + 1));
            if (!update_pfad_erlaubt($pfad) || !update_datei_schreiben($pfad, (string) file_get_contents($eintrag->getPathname()))) {
                $fehler[] = $pfad;
                continue;
            }
            $zurueck++;
        }
        return ['ok' => !$fehler, 'text' => $zurueck . ' Datei(en) zurueckgeholt.'
            . ($fehler ? ' Nicht geklappt: ' . implode(', ', $fehler) : '')];
    } finally {
        update_sperre_loeschen();
    }
}

// ---------------------------------------------------------------------------
// Sperre
// ---------------------------------------------------------------------------

function update_sperre_setzen(): bool
{
    $pfad = update_dir() . '/sperre';
    $griff = @fopen($pfad, 'x');
    if ($griff === false) {
        // Eine verwaiste Sperre, etwa von einem abgebrochenen Aufruf, nach
        // einer Viertelstunde raeumen.
        if (is_file($pfad) && (time() - (int) @filemtime($pfad)) > UPDATE_SPERR_ALTER) {
            @unlink($pfad);
            $griff = @fopen($pfad, 'x');
        }
        if ($griff === false) {
            return false;
        }
    }
    fwrite($griff, (string) getmypid());
    fclose($griff);
    return true;
}

function update_sperre_loeschen(): void
{
    @unlink(update_dir() . '/sperre');
}

// ---------------------------------------------------------------------------
// Selbsttest
// ---------------------------------------------------------------------------

/**
 * Was kann dieser Server ueberhaupt?
 *
 * Rueckgabe: Liste aus ['name' => string, 'ok' => bool, 'hinweis' => string]
 */
function update_selbsttest(bool $mitNetz = true): array
{
    $tests = [
        ['name'    => 'Archiv lesen (ZipArchive)',
         'ok'      => class_exists('ZipArchive'),
         'hinweis' => 'Ohne ZipArchive laesst sich die neue Version nicht entpacken. Beim Hoster nach der Erweiterung fragen.'],
        ['name'    => 'Verbindung nach aussen (cURL oder allow_url_fopen)',
         'ok'      => function_exists('curl_init') || (bool) ini_get('allow_url_fopen'),
         'hinweis' => 'Ohne diese Voraussetzung erreicht die Seite GitHub nicht.'],
        ['name'    => 'Verzeichnis .update anlegbar',
         'ok'      => update_verzeichnis_anlegen(update_dir()),
         'hinweis' => 'Ohne Schreibrecht gibt es weder Sicherung noch Sperre.'],
        ['name'    => 'Programmverzeichnis schreibbar',
         'ok'      => is_writable(update_root()),
         'hinweis' => 'Das Update ersetzt Dateien im Programmverzeichnis.'],
        ['name'    => 'Bestandsliste vorhanden',
         'ok'      => update_bestand_installiert() !== null,
         'hinweis' => 'Fehlt sie, ersetzt das erste Update alle Dateien ausser den geschuetzten. Ab dem zweiten Update gilt die Pruefsumme.'],
    ];
    if ($mitNetz) {
        $antwort = update_http(update_url_raw('manifest.json'), 1048576);
        $tests[] = ['name'    => 'GitHub erreichbar',
                    'ok'      => $antwort['ok'],
                    'hinweis' => $antwort['ok'] ? '' : 'Gemeldet wurde: ' . $antwort['error']];
    }
    return $tests;
}

/** Verzeichnis anlegen, mit einer Datei, die den Zugriff sperrt. */
function update_verzeichnis_anlegen(string $pfad): bool
{
    if (!is_dir($pfad) && !@mkdir($pfad, 0755, true) && !is_dir($pfad)) {
        return false;
    }
    if (!is_writable($pfad)) {
        return false;
    }
    // Das Verzeichnis sperrt sich selbst gegen direkten Abruf. Die Regel in
    // .htaccess greift fuer .update nur, wenn sie dort auch steht - deshalb
    // bekommt es eine eigene.
    $sperre = $pfad . '/.htaccess';
    if (!is_file($sperre)) {
        @file_put_contents($sperre,
            "# Von der Aktualisierung angelegt: niemand soll Sicherungen oder Sperren\n"
          . "# direkt abrufen koennen.\n"
          . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
          . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return true;
}

function update_mb(int $bytes): string
{
    if ($bytes >= 1048576) {
        return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), ',') . ' MB';
    }
    return round($bytes / 1024) . ' kB';
}
