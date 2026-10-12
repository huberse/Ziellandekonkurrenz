<?php
declare(strict_types=1);

/**
 * Bremse fuer die oeffentliche Anmeldung.
 *
 * Warum es sie braucht: ohne Bremse hat jede Anmeldung eine
 * Bestaetigungsmail an eine frei gewaehlte Adresse ausgeloest. Wer das
 * missbraucht, laedt das Postfach eines Menschen mit Muell und den
 * Mailserver des Hosters mit Ausgangsverkehr - und der Mailserver gehoert
 * dem Verein, nicht dem Missbraucher.
 *
 * Warum der Zaehler eine Datei ist und keine Tabelle: eine Tabelle waere
 * eine Datenbankaenderung und damit nach der Fassungsregel eine hoehere
 * Versionsziffer. Fuer zwei Zahlen jeIP ist eine Tabelle zu viel. Ausserdem
 * muss der Zaehler im Zweifel erreichbar sein, auch wenn die Datenbank gerade
 * klemmt.
 *
 * Warum der Zaehler nach der Eingabepruefung kommt: eine Person, die einen
 * Tippfehler korrigiert, ist keine Gefahr. Erst eine Anmeldung, die alle
 * Pruefungen besteht, wird gezaehlt.
 *
 * Grundsatz bei jedem Fehler: die Bremse gibt frei. Sie darf eine Anmeldung
 * im schlimmsten Fall durchlassen, aber niemals verhindern. Ein Wettbewerb,
 * zu dem sich niemand anmelden kann, ist ein waechsender Schaden; eine Mail,
 * die zu viel verschwindet, ist ein kleiner.
 */

/** Anmeldungen je IP im Zeitfenster. */
const RATE_LIMIT_ANMELDUNGEN = 2;

/** Länge des Zeitfensters in Sekunden. */
const RATE_LIMIT_FENSTER = 300;

/**
 * Verzeichnis fuer die Zaehlerdateien.
 *
 * Bewusst ausserhalb des ausgelieferten Programms: es sind Laufzeitdaten,
 * keine Dateien des Projekts, und die Bestandsliste soll davon nichts wissen.
 * sys_get_temp_dir() ist auf Shared Hosting ueblicherweise vorhanden und
 * wird vom System von allein wieder geleert. Fehlt er, entfaellt die Bremse
 * und die Anmeldung geht durch.
 */
function ratelimit_verzeichnis(): ?string
{
    $basis = sys_get_temp_dir() . '/segelflug-bremse';
    if (!is_dir($basis) && !@mkdir($basis, 0700, true) && !is_dir($basis)) {
        return null;
    }
    return is_writable($basis) ? $basis : null;
}

/**
 * Name der Zaehlerdatei zu einer IP.
 *
 * Der sha1 rechnet zwei Dinge auf einmal: die IP landet nicht im Dateinamen
 * auf der Platte, und weil die Ausgabe kurz und hexadezimal ist, kann sie
 * keine Sonderzeichen in den Pfad bringen.
 */
function ratelimit_datei(string $ip): ?string
{
    $basis = ratelimit_verzeichnis();
    if ($basis === null || $ip === '') {
        return null;
    }
    return $basis . '/' . substr(sha1('anmeldung|' . $ip), 0, 20) . '.txt';
}

/**
 * Prüft die Bremse und zählt den Versuch - oder lässt ihn durch, weil keine
 * IP zu ermitteln war.
 *
 * Ein Aufruf ohne erkennbare IP zählt nicht. Sonst würde eine Fehlkonfiguration
 * auf dem Server - Proxy ohne REMOTE_ADDR - sämtliche Anmeldungen des Vereins
 * unter einem einzigen Zähler sammeln und genau die Leute aussperren, für die
 * das Programm da ist.
 */
function ratelimit_registrierung_pruefen(string $ip): bool
{
    // Der SuperAdmin kann die Bremse abschalten, um selbst mehrere Anmeldungen
    // zu probieren. Voreingestellt ist sie an - das Umgekehrte waere der
    // Fehler: eine Bremse, die man erst einschalten muss, ist nach der
    // Installation eines Vereins aus und bleibt es.
    if (!setting_bool('ratelimit_an', true)) {
        return true;
    }
    if ($ip === '') {
        return true;
    }
    $datei = ratelimit_datei($ip);
    if ($datei === null) {
        return true;
    }
    $jetzt = time();
    $fensterBeginn = $jetzt - RATE_LIMIT_FENSTER;

    $offen = @file_get_contents($datei);
    $offen = is_string($offen) ? $offen : '';
    $zeiten = [];
    foreach (explode("\n", $offen) as $zeile) {
        $zahl = (int) trim($zeile);
        // Alles ausserhalb des Fensters fliegt raus. Der Zaehler bleibt damit
        // klein, ohne dass je eine Aufräumaufgabe laufen muss.
        if ($zahl > $fensterBeginn) {
            $zeiten[] = $zahl;
        }
    }

    if (count($zeiten) >= RATE_LIMIT_ANMELDUNGEN) {
        return false;
    }
    $zeiten[] = $jetzt;
    @file_put_contents($datei, implode("\n", $zeiten) . "\n", LOCK_EX);
    return true;
}

/**
 * Die IP des Aufrufers.
 *
 * Bewusst nur $_SERVER['REMOTE_ADDR'] und nichts weiter. Die üblichen
 * Auswege - X-Forwarded-For und Verwandte - werden von jedem Skript
 * mitgeschickt, das man in die Hand nimmt. Auf einem normalen Hosting ist
 * REMOTE_ADDR genau die Adresse, auf die es ankommt.
 */
function ratelimit_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
}

/**
 * Wie lange noch gewartet werden muss, wenn die Bremse gerade greift.
 *
 * Steht in der Meldung, weil "bitte später" ohne Zeitangabe nicht zu gebrauchen
 * ist - der Pilot steht mit offener Seite daneben und weiss sonst nicht, wie
 * lange.
 */
function ratelimit_wartezeit(string $ip): int
{
    $datei = ratelimit_datei($ip);
    if ($datei === null) {
        return 0;
    }
    $offen = @file_get_contents($datei);
    if (!is_string($offen) || trim($offen) === '') {
        return 0;
    }
    $zeiten = array_values(array_filter(array_map('intval', explode("\n", $offen)), static fn(int $z): bool => $z > 0));
    sort($zeiten);
    $gesamt = count($zeiten);
    if ($gesamt < RATE_LIMIT_ANMELDUNGEN) {
        return 0;
    }
    // Die älteste Eintragung im Fenster ist die, die als nächstes herausfällt.
    $frei = $zeiten[$gesamt - RATE_LIMIT_ANMELDUNGEN];
    return max(1, $frei + RATE_LIMIT_FENSTER - time());
}