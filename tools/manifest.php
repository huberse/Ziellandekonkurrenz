<?php
declare(strict_types=1);

/**
 * Erzeugt manifest.json: die Liste aller ausgelieferten Dateien mit ihrer
 * Pruefsumme. Damit weiss die Aktualisierung, welche Datei auf einem Server
 * noch unveraendert ist, und ersetzt nur dann eine Datei.
 *
 * Aufruf:
 *     php tools/manifest.php           manifest.json neu schreiben
 *     php tools/manifest.php --pruefen nur vergleichen, nichts schreiben
 *                                     (Rueckgabe 1 bei Abweichung)
 *
 * Erzeugt wird genau das, was im Repository steht. Deshalb zuerst die Ausgabe
 * von git, und nur wenn git fehlt der Dateisystemlauf.
 */

const MANIFEST_DATEI = 'manifest.json';

// Dieses Skript gehoert auf die Kommandozeile. Vom Browser aufgerufen wuerde es
// manifest.json neu schreiben - und zwar auf Verlangen jedes Besuchers, ohne
// Anmeldung. Der Zusatz .htaccess allein genuegt nicht, weil der Server
// Regelungen in Verzeichnissen je nach Hoster ignoriert.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Dieses Skript laeuft nur auf der Kommandozeile.\n");
}

$root = dirname(__DIR__);
$neu = manifest_bauen($root);
$alt = manifest_lesen($root);
$fehlt = manifest_nicht_erfasst($root, $neu['files']);

// Eine Datei, die im Verzeichnis liegt, aber nicht im Manifest, wuerde beim
// Update stillschweigend fehlen. Das ist der Grund fuer diese Meldung.
if ($fehlt) {
    echo "  Diese Dateien liegen im Verzeichnis, stehen aber nicht im Manifest:\n";
    foreach ($fehlt as $pfad) {
        echo "    $pfad\n";
    }
    echo "  Bitte erst \"git add\" und dann das Manifest neu erzeugen.\n";
    exit(1);
}

if (in_array('--pruefen', $argv, true)) {
    $gemerkte = [];
    if ($alt !== $neu) {
        $gemerkte[] = 'Die Datei auf der Platte stimmt nicht mit dem Stand im Repository ueberein.';
        if (!is_array($alt)) {
            $gemerkte[] = 'Es fehlt ganz oder ist unlesbar.';
        } else {
            $gemerkte[] = 'Version alt ' . $alt['version'] . ', neu ' . $neu['version'] . '.';
            foreach (manifest_vergleich($alt, $neu) as $zeile) {
                $gemerkte[] = '  ' . $zeile;
            }
        }
    }
    // Auch die Fassung pruefen, die git gerade vormerken wuerde. Sonst kann
    // ein "git add" vor dem Neuschreiben unbemerkt eine veraltete
    // manifest.json ins Repository bringen. Geprueft wird nur, wenn die Datei
    // auf der Platte in Ordnung ist - sonst lautet der Rat ohnehin "neu
    // erzeugen".
    if (!$gemerkte && is_dir($root . '/.git')) {
        $vorgemerkt = manifest_index_lesen($root);
        if ($vorgemerkt !== null && $vorgemerkt !== $neu) {
            $gemerkte[] = 'Die von git vorgemerkte manifest.json ist veraltet (Version ' . $vorgemerkt['version']
                        . ' statt ' . $neu['version'] . '). Ein "git add" wurde vor dem Neuschreiben ausgefuehrt.';
        }
    }
    if ($gemerkte) {
        echo "  manifest.json ist nicht aktuell.\n";
        foreach ($gemerkte as $zeile) {
            echo "    $zeile\n";
        }
        echo "  Reihenfolge: php tools/manifest.php, danach git add.\n";
        exit(1);
    }
    echo "  manifest.json ist aktuell (" . count($neu['files']) . " Dateien, Version {$neu['version']}).\n";
    exit(0);
}

if ($alt === $neu) {
    echo "  manifest.json war schon aktuell (" . count($neu['files']) . " Dateien).\n";
    exit(0);
}

if (file_put_contents($root . '/' . MANIFEST_DATEI, manifest_als_text($neu)) === false) {
    fwrite(STDERR, "  manifest.json liess sich nicht schreiben.\n");
    exit(1);
}
echo "  manifest.json geschrieben: Version {$neu['version']}, " . count($neu['files']) . " Dateien.\n";

// ---------------------------------------------------------------------------

/** Manifest aus dem aktuellen Dateistand bauen. */
function manifest_bauen(string $root): array
{
    require_once $root . '/lib/version.php';

    $dateien = manifest_dateiliste($root);
    $files = [];
    foreach ($dateien as $pfad) {
        $summe = @hash_file('sha256', $root . '/' . $pfad);
        if ($summe === false) {
            fwrite(STDERR, "  $pfad liess sich nicht lesen, uebersprungen.\n");
            continue;
        }
        $files[$pfad] = $summe;
    }
    ksort($files, SORT_STRING);

    return ['version' => APP_VERSION, 'files' => $files];
}

/**
 * Die ausgelieferten Dateien.
 *
 * Ohne git zaehlt der Dateisystemlauf, mit git die Ausgabe von git. Das Manifest
 * darf sich nicht selbst enthalten, sonst haette es zwei verschiedene
 * Pruefsummen fuer dieselbe Datei.
 */
function manifest_dateiliste(string $root): array
{
    $alle = [];
    if (is_dir($root . '/.git')) {
        $ausgabe = [];
        exec('git -C ' . escapeshellarg($root) . ' ls-files -z 2>/dev/null', $ausgabe);
        $roh = implode("\0", $ausgabe);
        if (trim($roh, "\0") !== '') {
            foreach (explode("\0", $roh) as $pfad) {
                if ($pfad !== '' && is_file($root . '/' . $pfad)) {
                    $alle[] = $pfad;
                }
            }
        }
    }
    if (!$alle) {
        $alle = manifest_dateisystemlauf($root);
    }
    $alle = array_values(array_filter($alle, static fn($p) => $p !== MANIFEST_DATEI));
    sort($alle, SORT_STRING);
    return $alle;
}

/**
 * Alles im Verzeichnis, ausgenommen was nicht ins Projekt gehoert.
 *
 * Dient zugleich als Gegenprobe: eine Datei, die hier auftaucht, aber im
 * Manifest fehlt, waere beim Update nicht vorhanden.
 */
function manifest_dateisystemlauf(string $root): array
{
    $weg = ['.git', '.update', 'config.php', MANIFEST_DATEI, 'review.txt',
            'wettbewerb.sql', 'node_modules'];
    $alle = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $eintrag) {
        if (!$eintrag->isFile()) {
            continue;
        }
        $pfad = str_replace('\\', '/', substr($eintrag->getPathname(), strlen($root) + 1));
        $verzeichnis = strstr($pfad, '/', true);
        if ($verzeichnis !== false && in_array($verzeichnis, $weg, true)) {
            continue;
        }
        if (basename($pfad) === MANIFEST_DATEI || in_array(basename($pfad), $weg, true)) {
            continue;
        }
        if (preg_match('/\.(pdf|sql|log|swp)$/', $pfad) || substr($pfad, -1) === '~') {
            continue;
        }
        $alle[] = $pfad;
    }
    return $alle;
}

/** Die Fassung von manifest.json, die git gerade vorgemerkt hat. */
function manifest_index_lesen(string $root): ?array
{
    if (!is_dir($root . '/.git')) {
        return null;
    }
    $roh = [];
    exec('git -C ' . escapeshellarg($root) . ' show :' . MANIFEST_DATEI . ' 2>/dev/null', $roh);
    if (!$roh) {
        return null;                                // noch nichts vorgemerkt
    }
    $daten = json_decode(implode("\n", $roh), true);
    if (!is_array($daten) || !isset($daten['files']) || !is_array($daten['files'])) {
        return null;
    }
    return ['version' => (string) ($daten['version'] ?? ''), 'files' => $daten['files']];
}

/** Dateien im Verzeichnis, die das Manifest nicht kennt. */
function manifest_nicht_erfasst(string $root, array $files): array
{
    $fehlend = [];
    foreach (manifest_dateisystemlauf($root) as $pfad) {
        if (!isset($files[$pfad])) {
            $fehlend[] = $pfad;
        }
    }
    return $fehlend;
}

/** Manifest von der Platte lesen, oder null wenn es keines gibt. */
function manifest_lesen(string $root): ?array
{
    $datei = $root . '/' . MANIFEST_DATEI;
    if (!is_file($datei) || !is_readable($datei)) {
        return null;
    }
    $daten = json_decode((string) file_get_contents($datei), true);
    if (!is_array($daten) || !isset($daten['files']) || !is_array($daten['files'])) {
        return null;
    }
    $daten['version'] = (string) ($daten['version'] ?? '');
    return $daten;
}

function manifest_als_text(array $m): string
{
    return json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

/** Unterschiede zwischen zwei Manifesten, lesbar aufgeschrieben. */
function manifest_vergleich(array $alt, array $neu): array
{
    $zeilen = [];
    foreach ($neu['files'] as $pfad => $summe) {
        if (!isset($alt['files'][$pfad])) {
            $zeilen[] = "neu:            $pfad";
        } elseif ($alt['files'][$pfad] !== $summe) {
            $zeilen[] = "geaendert:      $pfad";
        }
    }
    foreach ($alt['files'] as $pfad => $summe) {
        if (!isset($neu['files'][$pfad])) {
            $zeilen[] = "weg:            $pfad";
        }
    }
    return $zeilen;
}
