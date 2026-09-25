<?php
declare(strict_types=1);

/**
 * findet Aufrufe von Namen, die es als Funktion weder im Projekt noch in PHP
 * gibt.
 *
 * Warum das noetig ist: ein Aufruf wie $foo($x) sieht im Quelltext wie ein
 * Funktionsaufruf aus und faellt beim Lesen nicht auf. Er faellt erst zur
 * Laufzeit auf, wenn die Variable null ist - mit "Value of type null is not
 * callable". Genau dieser Fehler ist beim Aktualisierungs-Knopf passiert.
 *
 * Der PHP-Tokenizer trennt Schluesselwoerter (if, foreach, function) von
 * Namen, deshalb meldet diese Fassung nicht jedes Schluesselwort.
 *
 * Aufruf:
 *     php tools/aufrufe_pruefen.php lib/update.php admin/aktualisieren.php
 *
 * Rueckgabe 1 bei mindestens einem Fund.
 */

if ($argc < 2) {
    fwrite(STDERR, "Aufruf: php tools/aufrufe_pruefen.php <datei.php> [<datei.php> ...]\n");
    exit(2);
}

$wurzel = dirname(__DIR__);
foreach (glob($wurzel . '/lib/*.php') ?: [] as $datei) {
    require_once $datei;
}
require_once $wurzel . '/lib/scoring.php';
$bekannt = array_flip(array_merge(get_defined_functions()['user'], get_defined_functions()['internal']));

// Woerter, die im Quelltext wie ein Aufruf aussehen, aber keiner sind.
$keinAufruf = [
    T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_CONST,
    T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR,
];

$funde = 0;

for ($i = 1; $i < $argc; $i++) {
    $pfad = $argv[$i];
    $quelle = file_get_contents($pfad);
    if ($quelle === false) {
        fwrite(STDERR, "  $pfad nicht lesbar\n");
        $funde++;
        continue;
    }
    $marken = token_get_all($quelle);
    $n = count($marken);

    // Funktionen, die diese Datei selbst definiert, sind auch bekannt.
    $eigen = update_eigene_funktionen($marken);

    // Variablen, denen im Laufe der Datei etwas zugewiesen wird. Ein Aufruf
    // ueber eine andere Variable ist ein Fund: sie kann dann keine Closure
    // sein und wurde vermutlich mit einer Funktion verwechselt.
    $zugewiesen = update_zugewiesene_variablen($marken);

    for ($k = 0; $k < $n; $k++) {
        $marke = $marken[$k];

        // ---- Aufruf ueber eine Variable: $name(...)
        if (is_array($marke) && $marke[0] === T_VARIABLE) {
            $name = substr($marke[1], 1);
            if (isset($zugewiesen[$name])) {
                continue;
            }
            $j = update_naechstes($marken, $k + 1, $n);
            if ($j !== null && $marken[$j] === '(') {
                echo "  $pfad:" . $marke[2] . "  \$$name(...) - Aufruf ueber eine Variable, der nie etwas zugewiesen wird\n";
                $funde++;
            }
            continue;
        }

        // ---- Aufruf einer Funktion: name(...)
        if (!is_array($marke) || $marke[0] !== T_STRING) {
            continue;
        }
        $name = $marke[1];
        $j = update_naechstes($marken, $k + 1, $n);
        if ($j === null || $marken[$j] !== '(') {
            continue;
        }
        $vorher = update_vorheriges($marken, $k - 1, $n);
        if ($vorher !== null && is_array($vorher) && in_array($vorher[0], $keinAufruf, true)) {
            continue;
        }
        if (isset($bekannt[$name]) || isset($eigen[$name])) {
            continue;
        }
        echo "  $pfad:" . $marke[2] . "  $name(...) - weder Projekt- noch PHP-Funktion\n";
        $funde++;
    }
}

echo "  $funde Fundstelle(n)\n";
exit($funde > 0 ? 1 : 0);

/** Namen aller Funktionen, die in diesen Marken definiert werden. */
function update_eigene_funktionen(array $marken): array
{
    $namen = [];
    $n = count($marken);
    for ($i = 0; $i < $n; $i++) {
        if (is_array($marken[$i]) && $marken[$i][0] === T_FUNCTION) {
            $j = update_naechstes($marken, $i + 1, $n);
            if ($j !== null && is_array($marken[$j]) && $marken[$j][0] === T_STRING) {
                $namen[$marken[$j][1]] = true;
            }
        }
    }
    return $namen;
}

/** Namen aller Variablen, denen irgendwo etwas zugewiesen wird. */
function update_zugewiesene_variablen(array $marken): array
{
    $namen = [];
    $n = count($marken);
    for ($i = 0; $i < $n; $i++) {
        if (is_array($marken[$i]) && $marken[$i][0] === T_VARIABLE) {
            $name = substr($marken[$i][1], 1);
            $j = update_naechstes($marken, $i + 1, $n);
            // "=" und "=&" sind ausgeschlossen: dort wird nichts zugewiesen,
            // sondern verglichen.
            if ($j !== null && $marken[$j] === '=') {
                $namen[$name] = true;
            }
        }
        // foreach (... as $x) und while (... : $x)
        if (is_array($marken[$i]) && in_array($marken[$i][0], [T_AS, T_DOUBLE_ARROW], true)) {
            $j = update_naechstes($marken, $i + 1, $n);
            if ($j !== null && is_array($marken[$j]) && $marken[$j][0] === T_VARIABLE) {
                $namen[substr($marken[$j][1], 1)] = true;
            }
        }
    }
    // Uebergabeparameter einer Funktion: der Aufrufer weist sie zu.
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($marken[$i]) || $marken[$i][0] !== T_FUNCTION) {
            continue;
        }
        $klammer = null;
        for ($j = $i + 1; $j < $n; $j++) {
            if ($marken[$j] === '(') {
                $klammer = $j;
                break;
            }
            if ($marken[$j] === '{') {
                break;
            }
        }
        if ($klammer === null) {
            continue;
        }
        $tiefe = 0;
        for ($j = $klammer; $j < $n; $j++) {
            if ($marken[$j] === '(') {
                $tiefe++;
            } elseif ($marken[$j] === ')') {
                $tiefe--;
                if ($tiefe === 0) {
                    break;
                }
            } elseif (is_array($marken[$j]) && $marken[$j][0] === T_VARIABLE) {
                $namen[substr($marken[$j][1], 1)] = true;
            }
        }
    }
    return $namen;
}

/** Index des naechsten Markens ohne Leerraum, oder null. */
function update_naechstes(array $marken, int $ab, int $n): ?int
{
    for ($i = $ab; $i < $n; $i++) {
        if (is_array($marken[$i]) && in_array($marken[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return $i;
    }
    return null;
}

/** Die Marke unmittelbar vor einer Stelle, ohne Leerraum dazwischen. */
function update_vorheriges(array $marken, int $ab, int $n): mixed
{
    for ($i = $ab; $i >= 0; $i--) {
        if (is_array($marken[$i]) && in_array($marken[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return $marken[$i];
    }
    return null;
}
