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
            // Vor der Vereins-Migration gibt es users.club_id noch nicht. Dann
            // darf die Abfrage nicht scheitern: ein Fehler hier wuerde als
            // abgemeldet erscheinen und damit auch den Weg zu upgrade.php
            // versperren. Deshalb faellt die Abfrage auf die nackten
            // Kontodaten zurueck.
            $mitVerein = 'SELECT u.*, c.name AS club_name
                          FROM users u
                          LEFT JOIN clubs c ON c.id = u.club_id
                          WHERE u.id = ?';
            try {
                $st = db()->prepare($mitVerein);
                $st->execute([$_SESSION['uid']]);
            } catch (PDOException $e) {
                $st = db()->prepare('SELECT * FROM users WHERE id = ?');
                $st->execute([$_SESSION['uid']]);
            }
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
        redirect(upgrade_url());
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
 * Wächter für die Seiten, die dem SuperAdmin vorbehalten sind: Benutzer,
 * Vereine und Modelltypen. Alle anderen Seiten bleiben für jedes Konto
 * zugänglich.
 *
 * Der Gegenstand steht im Parameter, weil die Meldung sonst das Falsche sagt.
 * Vorher stand hier fest "Die Benutzerverwaltung ist dem SuperAdmin vorbehalten"
 * - und dieselbe Seite sperrt inzwischen auch die Vereine und die Modelltypen.
 * Wer als Vereinskonto auf die Vereinsseite ging, las einen Text über
 * Benutzerkonten und konnte sich nichts dabei denken.
 *
 * @param string $gegenstand  Was gesperrt wird, im Nominativ mit Artikel:
 *                           "Die Benutzerverwaltung", "Die Vereinsverwaltung"
 * @param bool   $mehrzahl    true bei "Die Stammdaten" und "Die Modelltypen" -
 *                           sonst stimmt das Verb nicht: "die Modelltypen ist"
 *                           ist ein Fehler, den man in einer Meldung sofort sieht.
 */
function require_superadmin(string $gegenstand = 'Die Benutzerverwaltung', bool $mehrzahl = false): array
{
    $u = require_login();
    if (!is_superadmin()) {
        flash($gegenstand . ($mehrzahl ? ' sind' : ' ist') . ' dem SuperAdmin vorbehalten.', 'err');
        redirect('index.php');
    }
    return $u;
}

/**
 * Wohin es nach dem Anmelden geht, wenn niemand einen bestimmten Wunsch
 * mitgebracht hat.
 *
 * Ein Konto, dessen Verein noch keinen Wettbewerb hat, kommt nicht in das
 * Wettkampfsbuero, sondern auf die Wettbewerbsseite. Dort steht "Es ist noch
 * kein Wettbewerb angelegt" - und das ist die Wahrheit fuer DIESES Konto, nicht
 * fuer die ganze Datenbank. Das Wettkampfsbuero zeigt dagegen Startliste,
 * Durchgaenge und Resultate eines Wettbewerbs, den es fuer dieses Konto gar
 * nicht gibt.
 *
 * Vorher landete alles auf index.php. Dort versuchte die Seite, einen
 * Wettbewerb zu zeigen, den das Konto nicht steuern darf, und wurde von der
 * Zugriffspruefung auf index.php zurueckgeschickt - also auf sich selbst. Das
 * war eine Umleitungsschleife: der Browser gab nach zwanzig Runden auf.
 *
 * @param string $wunsch  Das "?next=" des Aufrufers, wenn es eines gab
 */
function login_landing_page(string $wunsch = ''): string
{
    $ziel = safe_local_redirect($wunsch, '');
    if ($ziel !== '') {
        return $ziel;
    }
    if (current_user() && !is_superadmin() && function_exists('accessible_competitions')
        && accessible_competitions() === []) {
        return 'wettbewerbe.php';
    }
    return 'index.php';
}

/** Anzahl aktiver SuperAdmins. Darf nie 0 werden, sonst ist niemand mehr zuständig. */
function superadmin_count(): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM users WHERE is_superadmin = 1 AND active = 1');
    $st->execute();
    return (int) $st->fetchColumn();
}

/**
 * Vereins-ID des aktuellen Kontos. NULL bedeutet: keinem Verein zugeordnet.
 * Solange das Konto nicht einem Verein angehört, darf es keine Wettbewerbe
 * anlegen oder steuern – es sei denn, es ist SuperAdmin.
 */
function user_club_id(): ?int
{
    $u = current_user();
    if (!$u) {
        return null;
    }
    return isset($u['club_id']) && $u['club_id'] !== null ? (int) $u['club_id'] : null;
}

/**
 * Darf das aktuelle Konto diesen Wettbewerb steuern und bearbeiten?
 *
 * SuperAdmins dürfen alles. Benutzer dürfen nur Wettbewerbe ihres eigenen
 * Vereins.
 *
 * Ein Wettbewerb ohne Vereinszuordnung gehört dem SuperAdmin und sonst
 * niemandem. Früher stand hier "für alle sichtbar, damit keine Installation
 * ausgesperrt wird" - das galt, als es noch Wettbewerbe ohne Veranstalter gab,
 * die von niemandem betreut werden konnten. Seit der siebten Datenbank-
 * aenderung legt nur noch der SuperAdmin einen Wettbewerb an, und er waehlt
 * dabei den Veranstalter. Ein Wettbewerb ohne Verein ist damit Altbestand,
 * dem noch niemand einen Veranstalter gegeben hat - und "gehoert allen" hiess
 * in der Praxis: jeder Verein im Netz darf Startliste, Resultate und Anmeldungen
 * eines fremden Wettbewerbs lesen und bearbeiten.
 *
 * diagnose.php weist jeden solchen Wettbewerb beim Namen aus. Wer ihn haben
 * will, weist ihm unter Wettbewerbe den Veranstalter zu.
 */
function can_manage_competition(int $competitionId): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    if ((int) ($u['is_superadmin'] ?? 0) === 1) {
        return true;
    }
    try {
        $st = db()->prepare('SELECT club_id FROM competitions WHERE id = ?');
        $st->execute([$competitionId]);
        $competitionClubId = $st->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
    if ($competitionClubId === false || $competitionClubId === null) {
        // Kein Veranstalter eingetragen: gehoert dem SuperAdmin, sonst niemandem.
        return false;
    }
    return user_club_id() === (int) $competitionClubId;
}

/**
 * Wächter für Admin-Seiten, die einen bestimmten Wettbewerb betreffen.
 * Ohne Zugriff wird eine Meldung ausgegeben und umgeleitet.
 */
/**
 * Der Zustand "dieses Konto hat gar keinen Wettbewerb".
 *
 * Bis 2.0.8 behandelte require_competition_access() das wie einen fremden
 * Wettbewerb und schickte auf 'index.php'. Die Seiten, die ohne Wettbewerb
 * nichts anzeigen koennen, rufen diese Funktion aber selbst auf - und eine
 * davon IST index.php. Also immer wieder, bis der Browser nach rund 20
 * Umleitungen aufgab. Gemeldet am 9. Oktober 2026, als sich ein Konto nicht
 * einmal anmelden
 * konnte.
 *
 * Die Seite sagt jetzt, was los ist, und geht nach drei Sekunden von selbst
 * zur Wettbewerbsseite. Der Knopf ist da, weil drei Sekunden Warten nicht
 * jedem passt; die Wartezeit ist da, weil sie beim Blättern stoert, wenn man
 * zehn Seiten nacheinander trifft.
 */
function kein_wettbewerb_ausgeben(): void
{
    if (!function_exists('page_start')) {
        // Ohne Layout gibt es nichts zu rendern. Dann ist die Umleitung nach
        // Wettbewerben wenigstens besser als eine Schleife.
        redirect('wettbewerbe.php');
    }
    seite_weiterleitung(3, 'wettbewerbe.php');
    page_start('Kein Wettbewerb', 'admin', 'wettbewerbe.php');
    echo '<div class="panel" style="max-width:720px">';
    echo '<h2 style="margin-top:0">Dein Verein hat noch keinen Wettbewerb.</h2>';
    echo '<p class="lead">Solange keiner da ist, gibt es für dich nichts zu erfassen, keine '
        . 'Startliste und keine Durchgänge. Der SuperAdmin legt unter <em>Wettbewerbe</em> einen an '
        . 'und setzt ihn auf deinen Verein; danach erscheint er hier.</p>';
    echo '<p class="small muted">In drei Sekunden geht es von selbst zu den Wettbewerben.</p>';
    echo '<p><a class="btn" href="wettbewerbe.php">Wettbewerbe ansehen</a></p>';
    echo '</div>';
    page_end();
    exit;
}

function require_competition_access(int $competitionId): void
{
    if (can_manage_competition($competitionId)) {
        return;
    }
    // Der Unterschied ist wichtig, und er ist der ganze Fehler: Ein fremder
    // Wettbewerb ist eine falsche Anzeige - dafuer hilft "Wettbewerbe ansehen".
    // Ein nicht vorhandener Wettbewerb ist kein Zugriffsproblem, sondern ein
    // leerer Betrieb, und darauf hat keine der Verwaltungsseiten eine Antwort.
    if (!is_superadmin()
        && function_exists('accessible_competitions')
        && accessible_competitions() === []) {
        kein_wettbewerb_ausgeben();
    }
    flash('Dieser Wettbewerb gehört einem anderen Verein. Du hast keinen Zugriff darauf.', 'err');
    redirect('index.php');
}

/** Fehlversuche, ab denen ein Konto bis zum Freigeben gesperrt bleibt. */
const LOGIN_SPERR_NACH = 10;

/**
 * Wie lange ein Fehlversuch gezaehlt wird.
 *
 * Zwei aehnlich uebliche Werte: nach fuenf Minuten oder nach einer halben
 * Stunde. Kurz genug, damit sich die Sperre von selbst erledigt, wer
 * zehnmal hintereinander danebenliegt; lang genug, damit ein Versuch, der
 * absichtlich ist, nicht ausfaellt, nur weil das Programm eine Pause macht.
 */
const LOGIN_SPERR_FENSTER_MINUTEN = 30;

/**
 * Ein Hash, gegen den verglichen wird, wenn es den Namen gar nicht gibt.
 *
 * Feste Zeichenkette, nicht jedes Mal neu erzeugen: der Hash wird beim Lesen
 * einmal berechnet und danach nur noch verglichen, und ein fester Wert ist
 * leichter zu pruefen.
 */
const LOGIN_UNRELEVANTER_HASH = '$2y$10$Y82WFwSqgWxJ2Owj9Nmql.ef6/vswAIlwqmTamEqDuishNfMkH5CO';

/**
 * Der Zaehlname zum Benutzernamen.
 *
 * Kleinbuchstaben, weil die Datenbank die Benutzernamen ohne Beachtung der
 * Gross- und Kleinschreibung vergleicht: "SUPERADMIN" und "superadmin" sind
 * dasselbe Konto. Der Zaehler muss es genauso behandeln, sonst waere die
 * Sperre mit einem einzigen Buchstaben zu umgehen.
 */
function login_schluessel(string $name): string
{
    return mb_strtolower(trim($name));
}

/**
 * Ist die Tabelle der Fehlversuche da?
 *
 * Sie kommt mit der Aktualisierung. Bis dahin darf sich nichts weigern, nur
 * weil die Tabelle fehlt - sonst waere nach einem Update nichts mehr
 * erreichbar.
 */
function login_zaehler_bereit(): bool
{
    static $bereit = null;
    if ($bereit === null) {
        try {
            db()->query('SELECT 1 FROM login_versuche LIMIT 1');
            $bereit = true;
        } catch (PDOException $e) {
            if (!settings_table_missing($e)) {
                throw $e;
            }
            $bereit = false;
        }
    }
    return $bereit;
}

/**
 * Wie viele Fehlversuche liegen im Fenster fuer diesen Namen.
 *
 * Das Fenster rechnet die Datenbank selbst, nicht PHP. Grund: der Zeitstempel
 * entsteht in der Datenbank (CURRENT_TIMESTAMP), und die beiden Uhren gehen
 * auseinander - die Datenbank schrieb hier UTC, PHP rechnete in
 * Europe/Zurich, zwei Stunden Unterschied. Verglich man beide, sah jede Zeile
 * sofort zwei Stunden alt aus, wurde nie gezaehlt und sofort wieder geloescht:
 * Die Sperre waere nie angegangen, ohne dass irgendetwas einen Fehler gemeldet
 * haette. Zwei Uhren zu mischen ist hier die schlimmere Variante von zwei
 * Uhren - man sieht es nicht, man sieht nur, dass es nicht wirkt.
 */
function login_fehlversuche(string $name): int
{
    if (!login_zaehler_bereit()) {
        return 0;
    }
    $st = db()->prepare('SELECT COUNT(*) FROM login_versuche
                         WHERE benutzername = ? AND versuch_am > (NOW() - INTERVAL ' . (int) LOGIN_SPERR_FENSTER_MINUTEN . ' MINUTE)');
    $st->execute([login_schluessel($name)]);
    return (int) $st->fetchColumn();
}

/**
 * Ist der Name gesperrt?
 *
 * Fuer jeden Namen, ob es das Konto gibt oder nicht. Andernfalls verriete
 * schon die Sperre, welche Benutzernamen es gibt.
 */
function login_ist_gesperrt(string $name): bool
{
    $name = trim($name);
    if ($name === '' || !login_zaehler_bereit()) {
        return false;
    }
    return login_fehlversuche($name) >= LOGIN_SPERR_NACH;
}

/**
 * Einen Fehlversuch festhalten und melden, ob das Konto dadurch gesperrt ist.
 *
 * Vor dem Sperren wird aufgeraeumt, sonst muesste man nur warten und nicht
 * koennen.
 */
function login_fehlversuch_verzeichnen(string $name): bool
{
    $name = trim($name);
    if ($name === '' || !login_zaehler_bereit()) {
        return false;
    }
    // Auch das Aufraeumen rechnet die Datenbank - dieselbe Uhr wie beim Zaehlen.
    db()->prepare('DELETE FROM login_versuche
                   WHERE versuch_am <= (NOW() - INTERVAL ' . (int) LOGIN_SPERR_FENSTER_MINUTEN . ' MINUTE)')->execute();
    $st = db()->prepare('INSERT INTO login_versuche (benutzername) VALUES (?)');
    $st->execute([login_schluessel($name)]);
    return login_fehlversuche($name) >= LOGIN_SPERR_NACH;
}

/** Die Sperre eines Namens aufheben; zugleich der Weg nach einem Erfolg. */
function login_sperre_heben(string $name): void
{
    if (!login_zaehler_bereit()) {
        return;
    }
    $st = db()->prepare('DELETE FROM login_versuche WHERE benutzername = ?');
    $st->execute([login_schluessel($name)]);
}

/** Alle gesperrten Namen - fuer die Liste im Kontoverwaltung und die Diagnose. */
function login_gesperrte_namen(int $minimum = LOGIN_SPERR_NACH): array
{
    if (!login_zaehler_bereit()) {
        return [];
    }
    $st = db()->prepare('SELECT benutzername, COUNT(*) AS anzahl FROM login_versuche
                         WHERE versuch_am > (NOW() - INTERVAL ' . (int) LOGIN_SPERR_FENSTER_MINUTEN . ' MINUTE)
                         GROUP BY benutzername HAVING anzahl >= ?
                         ORDER BY anzahl DESC');
    $st->execute([max(1, $minimum)]);
    $namen = [];
    foreach ($st as $zeile) {
        $namen[] = ['benutzername' => (string) $zeile['benutzername'],
                    'anzahl' => (int) $zeile['anzahl']];
    }
    return $namen;
}

/** Alte Zeilen weg, damit die Tabelle nicht unbegrenzt waechst. */
function login_versuche_aufraeumen(): void
{
    if (!login_zaehler_bereit()) {
        return;
    }
    db()->prepare('DELETE FROM login_versuche
                   WHERE versuch_am <= (NOW() - INTERVAL ' . (int) LOGIN_SPERR_FENSTER_MINUTEN . ' MINUTE)')->execute();
}

function login(string $username, string $password): bool
{
    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$username]);
    $u = $st->fetch();
    if (!$u) {
        // Auch ein Name, den es nicht gibt, wird gehasht. Ohne diesen Schritt
        // antwortet die Seite fuer einen erfundenen Namen rund 40 Millisekunden
        // schneller als fuer einen richtigen - genau um die Dauer des Hashens -
        // und verrät damit, welche Konten es überhaupt gibt. Gemessen: 0,4122
        // gegen 0,4553 Sekunden bei gleicher Fehlermeldung.
        password_verify($password, LOGIN_UNRELEVANTER_HASH);
        return false;
    }
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
