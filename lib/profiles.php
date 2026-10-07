<?php
/**
 * Die Stammdaten der Piloten: eine Person, ein Satz.
 *
 * Wer hier arbeitet, fasst zwei verschiedene Dinge auseinander, die vorher
 * ineinander lagen:
 *
 *   pilot_profiles   Wer ist das?  SMV-Nummer und Name. Genau ein Satz je
 *                    Person, ueber alle Wettbewerbe hinweg.
 *   pilots           Wer fliegt in welchem Wettbewerb?  Startnummer, Verein,
 *                    Modell, Modelltyp. Alles, was je Wettbewerb verschieden
 *                    sein kann.
 *
 * Vorher stand der Name an jedem Startlisteneintrag. Wer in drei Jahren
 * dreimal flog, hatte dreimal denselben Namen im System und nichts darueber,
 * dass es dieselbe Person ist. Genau daran scheitert die Regiowertung:
 * `region_pilot_schluessel()` erkennt Piloten nur ueber den Namen, also
 * werden zwei Personen mit gleichem Namen zu einer, und wer seinen Namen
 * aendert, zu zweien.
 *
 * scores.pilot_id zeigt weiter auf pilots. An Resultaten, Auswertungen,
 * Exporten und am Papierlaufzettel aendert sich also nichts.
 *
 * Der Weg aus dem Bestand ist eine Namenssuche, weil noch niemand eine
 * SMV-Nummer eingetragen hat. Zwei verschiedene Menschen mit demselben Namen
 * landen dadurch in einem Stammsatz - dieselbe Schwachstelle wie vorher, nur
 * jetzt sichtbar und von Hand reparierbar statt still in der Regioliste.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * Die SMV-Nummer in die Form bringen, in der sie gespeichert wird.
 *
 * Leer ist ein "keine Nummer", nicht "0": NULL ist im eindeutigen Index
 * mehrfach erlaubt, 0 nicht. Genau deshalb steht hier kein 999999 - das waere
 * fuer zwei Piloten ohne Nummer derselbe Wert und wuerde den zweiten
 * zurueckweisen.
 *
 * @return string|null sechs Ziffern oder null
 */
function pilot_smv_normalisieren(string $roh): ?string
{
    $roh = trim($roh);
    if ($roh === '') {
        return null;
    }
    // Aus einem Textfeld kommen Leerzeichen und Bindestriche mit.
    $roh = str_replace([' ', '-', '–'], '', $roh);
    if (!preg_match('/^\d{1,6}$/', $roh)) {
        return null;
    }
    // Eine kuerzere Nummer wird NICHT auf sechs Stellen aufgefuellt. Damit
    // wuerde eine Ziffer erfunden, und zwar genau die, die eine echte Nummer
    // vom selben Piloten unterscheidet. "12345" bleibt "12345".
    return $roh;
}

/** Prüft, ob eine Nummer echt sein koennte, ohne sie umzuformen. */
function pilot_smv_ist_gueltig(string $roh): bool
{
    $roh = trim($roh);
    if ($roh === '') {
        return true;
    }
    return pilot_smv_normalisieren($roh) !== null;
}

/** Wie eine Nummer angezeigt wird. Ohne Nummer steht "999999" da. */
function pilot_smv_anzeige(?string $nummer): string
{
    $nummer = $nummer === null ? '' : trim($nummer);
    return $nummer !== '' ? $nummer : '999999';
}

/**
 * Der Schluessel, an dem zwei Eintraege derselbe Person sind.
 *
 * Nachname zuerst, weil "Ott, Nina" und "Zürcher, Zeno" so nicht
 * vertauscht werden koennen. Gross- und Kleinschreibung und Mehrfach-
 * leerzeichen sind egal, Umlaute aber nicht: mb_strtolower arbeitet auf
 * Zeichen, nicht auf Bytes.
 *
 * Leer, wenn beide Namen fehlen - dann ist nichts zuzuordnen.
 */
function pilot_name_schluessel(string $vorname, string $nachname): string
{
    $falten = static function (string $s): string {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)), 'UTF-8');
    };
    $v = $falten($vorname);
    $n = $falten($nachname);
    if ($v === '' && $n === '') {
        return '';
    }
    return $n . "\t" . $v;
}

/** Wie ein Stammsatz heisst. */
function profile_name(array $profil): string
{
    return trim((string) ($profil['first_name'] ?? '')) . ' ' . trim((string) ($profil['last_name'] ?? ''));
}

/**
 * Der Stammsatz zu einer SMV-Nummer, oder null.
 *
 * @param string|null $nummer roh oder normalisiert
 */
function profile_finden(?string $nummer, ?PDO $pdo = null): ?array
{
    $nummer = pilot_smv_normalisieren((string) $nummer);
    if ($nummer === null) {
        return null;
    }
    $pdo = $pdo ?? db();
    $st = $pdo->prepare('SELECT * FROM pilot_profiles WHERE smv_number = ? LIMIT 1');
    $st->execute([$nummer]);
    return $st->fetch() ?: null;
}

/**
 * Den Stammsatz zu einer Nummer holen, sonst einen anlegen.
 *
 * Genau das braucht das Anmeldeformular: wer eine bekannte Nummer eintippt,
 * bekommt seinen Namen zurueck; wer eine unbekannte eintippt, baut die Liste
 * mit auf. Das ist der ganze Zweck der Stammdaten.
 *
 * Achtung bei gleicher Nummer und anderem Namen: der vorhandene Satz gewinnt
 * und wird nicht ueberschrieben. Sonst koennte jeder durch wiederholtes
 * Anmelden mit derselben Nummer den Namen eines anderen aendern.
 *
 * @return array{id:int, neu:bool}
 */
function profile_oder_anlegen(?string $nummer, string $vorname, string $nachname, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $nummer = pilot_smv_normalisieren((string) $nummer);
    if ($nummer !== null) {
        $vorhanden = profile_finden($nummer, $pdo);
        if ($vorhanden !== null) {
            return ['id' => (int) $vorhanden['id'], 'neu' => false];
        }
    }
    $st = $pdo->prepare('INSERT INTO pilot_profiles (smv_number, first_name, last_name) VALUES (?, ?, ?)');
    $st->execute([$nummer, trim($vorname), trim($nachname)]);
    return ['id' => (int) $pdo->lastInsertId(), 'neu' => true];
}

/** Ein Stammsatz nach seiner Nummer. */
function profile_by_id(int $id, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare('SELECT * FROM pilot_profiles WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Die Stammliste, optional nach einer Nummer oder einem Namen gesucht.
 *
 * @return array[] je Stammdatensatz mit 'starts' (Zahl der Eintraege)
 */
function profiles_uebersicht(string $suche = '', ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $where = '';
    $werte = [];
    $suche = trim($suche);
    if ($suche !== '') {
        $nummer = pilot_smv_normalisieren($suche);
        if ($nummer !== null) {
            $where = ' WHERE pr.smv_number = ?';
            $werte[] = $nummer;
        } else {
            $where = ' WHERE pr.first_name LIKE ? OR pr.last_name LIKE ? OR CONCAT(pr.first_name, " ", pr.last_name) LIKE ?';
            $like = '%' . $suche . '%';
            $werte = [$like, $like, $like];
        }
    }
    // Das Datum eines Wettbewerbs steht in competition_settings, nicht in der
    // competitions-Zeile - dort gibt es keine Spalte competition_date. "zuletzt"
    // holt deshalb den groessten Wert ueber alle Einstellungen dieses einen
    // Schluessels; competition_settings ist je (Wettbewerb, Schluessel) eindeutig,
    // also liefert das genau ein Datum.
    $st = $pdo->prepare(
        'SELECT pr.*, (SELECT COUNT(*) FROM pilots p WHERE p.profile_id = pr.id AND p.active = 1) AS starts,
                  (SELECT COUNT(*) FROM scores s
                     JOIN pilots p ON p.id = s.pilot_id
                    WHERE p.profile_id = pr.id) AS ergebnisse,
                (SELECT MAX(c.svalue) FROM pilots p
                   JOIN competition_settings c ON c.competition_id = p.competition_id AND c.skey = "competition_date"
                  WHERE p.profile_id = pr.id) AS zuletzt
         FROM pilot_profiles pr' . $where . '
         ORDER BY pr.last_name, pr.first_name');
    $st->execute($werte);
    return $st->fetchAll();
}

/**
 * Einen Stammsatz loeschen – mit allem, was an ihm haengt.
 *
 * Wofuer das da ist: wer sich zweimal angemeldet hat, einmal mit der echten
 * Nummer und einmal mit einer erfundenen, steht zweimal in dieser Liste. Der
 * zweite Satz ist Muell und muss weg.
 *
 * Der Vorgang ist mehr als ein Satz aus einer Tabelle, weil an einem Stammsatz
 * Startlisteneintraege haengen und an denen Resultate:
 *
 *   pilot_profiles   der Stammsatz selbst
 *     -> pilots      ein Eintrag je Wettbewerb, ON DELETE RESTRICT
 *          -> scores je Resultat, ON DELETE CASCADE
 *
 * Wegen RESTRICT lehnt die Datenbank das Loeschen von selbst ab, sobald ein
 * Eintrag dranhaengt – und zwar mit einer Meldung, die niemandem etwas sagt.
 * Deshalb wird vorher gezaehlt und der Grund genannt.
 *
 * **Geloescht wird nur, was nie geflogen ist.** Sobald ein Resultat dranhaengt,
 * wird abgelehnt und die Zahl genannt. Wer einen beendeten Wettbewerb von Grund
 * auf loeschen will, macht das an dem Wettbewerb - dort ist es eine einzige
 * Handlung mit einer klaren Bestandsangabe, hier waere es eine ueber mehrere
 * Jahre verteilte.
 *
 * Warum die zugehoerige Anmeldung wieder offen wird und nicht mit verschwindet:
 * `registrations` haelt Name, Nummer und Verein in eigenen Spalten, der Eintrag
 * ueberlebt das Loeschen also. Er gaenge nur stumm auf "angenommen" stehen
 * bleiben und niemand koennte ihn mehr zurueckweisen. Genau das macht
 * `piloten.php` beim Loeschen eines Startlisteneintrags auch so.
 *
 * @return array{eintraege:int,anmeldungen:int} Was wirklich entfernt wurde
 * @throws DomainException  mit dem Grund, wenn nicht geloescht wurde
 */
function profile_loeschen(int $id): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM pilot_profiles WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $profil = $st->fetch();
        if (!$profil) {
            throw new RuntimeException('Diesen Piloten gibt es nicht.');
        }

        $eintraege = $pdo->prepare('SELECT id FROM pilots WHERE profile_id = ?');
        $eintraege->execute([$id]);
        $ids = array_map('intval', $eintraege->fetchAll(PDO::FETCH_COLUMN));

        $ergebnisse = 0;
        $anmeldungen = 0;
        if ($ids) {
            // Zwei Abfragen statt einer. In einer Abfrage mit zwei
            // "IN (?,?...)" stuenden die Platzhalter zweimal drin, und es
            // muessten auch zweimal so viele Parameter gebunden werden.
            // Das ist genau die Sorte Fehler, die erst beim Aufruf auffaellt -
            // und dann als SQL-Fehler, nicht als Programmierfehler.
            $in = implode(',', array_fill(0, count($ids), '?'));
            $z1 = $pdo->prepare("SELECT COUNT(*) FROM scores WHERE pilot_id IN ($in)");
            $z1->execute($ids);
            $ergebnisse = (int) $z1->fetchColumn();

            $z2 = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE pilot_id IN ($in)");
            $z2->execute($ids);
            $anmeldungen = (int) $z2->fetchColumn();
        }

        if ($ergebnisse > 0) {
            $wettbewerbe = count($ids);
            throw new DomainException(sprintf(
                '%s hat %s in %d Wettbewerb%s. %s nicht mehr zu löschen – sonst '
                . 'verschwinden Ergebnisse ohne Spur. Soll der Wettbewerb weg, '
                . 'geschieht das an ihm.',
                profile_name($profil),
                $ergebnisse === 1 ? '1 Resultat' : $ergebnisse . ' Ergebnisse',
                $wettbewerbe,
                $wettbewerbe === 1 ? '' : 'en',
                $ergebnisse === 1 ? 'Dieses' : 'Diese'
            ));
        }

        if ($ids) {
            // Wie in piloten.php: eine freigegebene Anmeldung, deren Pilot
            // verschwindet, geht wieder auf offen. Sonst bliebe sie auf
            // "angenommen" stehen, ohne dass es den Piloten noch gaebe.
            $zurueck = $pdo->prepare("UPDATE registrations
                                       SET pilot_id = NULL, status = 'pending', decided_at = NULL
                                     WHERE pilot_id IN ($in)");
            $zurueck->execute($ids);

            $weg = $pdo->prepare('DELETE FROM pilots WHERE id IN (' . $in . ')');
            $weg->execute($ids);
        }

        $raus = $pdo->prepare('DELETE FROM pilot_profiles WHERE id = ?');
        $raus->execute([$id]);
        if ($raus->rowCount() !== 1) {
            throw new RuntimeException('Stammsatz wurde nicht gelöscht.');
        }

        $pdo->commit();
        return ['eintraege' => count($ids), 'anmeldungen' => $anmeldungen];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
