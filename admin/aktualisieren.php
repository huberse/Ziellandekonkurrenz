<?php
declare(strict_types=1);

/**
 * Aktualisierung aus dem Repository.
 *
 * Nur dem SuperAdmin vorbehalten, aus demselben Grund wie die Benutzerverwaltung:
 * wer das Programm austauschen darf, bestimmt, was auf dem Server laeuft.
 */

require_once __DIR__ . '/../lib/scoring.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/update.php';
require_once __DIR__ . '/../lib/migrations.php';
require_superadmin();

/** Hinterlaeuft die Datenbank hinter dem Programm? */
function schema_hinter_programm(): bool
{
    try {
        $soll = max(array_map('intval', array_keys(migration_definitions())));
        $ist = array_map('intval', migration_applied_versions(db()));
    } catch (Throwable $e) {
        return false;
    }
    return $ist !== [] && max($ist) < $soll;
}

$installiert = update_bestand_installiert();
$versionHier = $installiert !== null ? $installiert['version'] : null;

// Meldung eines gerade gelaufenen Updates. Sie wird hier gebildet und nicht im
// Absender, damit der Text von der Fassung stammt, die gerade eingespielt wurde.
$gemerkt = update_bericht_holen();
if ($gemerkt !== null) {
    $text = 'Fassung ' . $gemerkt['version'] . ' ist eingespielt, ' . $gemerkt['geschrieben'] . ' Datei(en) geschrieben.';
    if ((int) $gemerkt['stehen'] > 0) {
        $text .= ' ' . (int) $gemerkt['stehen'] . ' Datei(en) sind stehen geblieben, weil sie von Hand '
              . 'geaendert sind. Der Stand ist damit gemischt, siehe die Liste unten.';
    } elseif ((int) $gemerkt['geschuetzt'] > 0) {
        $text .= ' ' . (int) $gemerkt['geschuetzt'] . ' geschuetzte Datei(en) sind aelter als im Repository '
              . 'und wurden deshalb nicht mitgeschrieben; das ist so vorgesehen und kein Fehler.';
    }
    $link = [];
    if (!empty($gemerkt['migration'])) {
        // Kein blosses "bitte aufrufen": der Weg ist einen Klick entfernt.
        // upgrade.php liegt im Hauptverzeichnis, diese Seite in admin/.
        $link = ['text' => 'Jetzt upgrade.php aufrufen', 'href' => '../upgrade.php'];
    }
    flash($text, (int) $gemerkt['stehen'] > 0 ? 'err' : 'ok', $link);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (post('action') === 'update') {
        $remote = update_bestand_remote();
        if (!$remote['ok']) {
            flash('Die Bestandsliste von GitHub liess sich nicht holen: ' . $remote['error'], 'err');
        } else {
            $plan = update_plan($installiert, $remote['manifest']);
            if (update_plan_umfang($plan) === 0) {
                flash('Es gibt nichts zu aktualisieren.');
            } else {
                // Nur von Hand geaenderte und unbekannte Dateien sind ein
                // Problem. Geschuetzte Dateien sind es nicht: sie gehoeren dem
                // Server, und der Knopf darf sie grundsaetzlich nicht liefern.
                // Vorher standen beide in einer Summe, wodurch jede Installation
                // nach jedem Update eine rote Meldung bekam, die nichts
                // beanstandete.
                $stehen = count($plan['geaendert']) + count($plan['unbekannt']);
                $geschuetztAbweichend = 0;
                foreach ($plan['geschuetzt'] as $eintrag) {
                    if (!empty($eintrag['abweichend'])) {
                        $geschuetztAbweichend++;
                    }
                }
                $bericht = update_ausfuehren($plan, $remote['manifest']);
                if ($bericht['ok']) {
                    // Nur die Zahlen merken, den Text nicht. Diese Seite ist
                    // beim Programmstart in den Speicher geladen worden - der
                    // Text waere also der des Standes von VOR dem Einspielen.
                    // Genau das ist beim Sprung von 1.9.13 auf 1.9.16 passiert:
                    // Die Korrektur der Meldung steckte in 1.9.14, und 1.9.13
                    // hat sie noch mit dem alten Wortlaut versehen. Wer den Text
                    // erst beim naechsten Aufruf bildet, schreibt ihn mit dem
                    // Code, der gerade installiert wurde.
                    update_bericht_merken([
                        'version' => (string) $bericht['version'],
                        'geschrieben' => count($bericht['ersetzen']) + count($bericht['neu']),
                        'stehen' => $stehen,
                        'geschuetzt' => $geschuetztAbweichend,
                        'migration' => schema_hinter_programm(),
                    ]);
                } else {
                    flash('Nichts eingespielt. ' . implode(' ', $bericht['fehler']), 'err');
                }
            }
        }
        redirect('aktualisieren.php');
    }

    if (post('action') === 'undo') {
        $ergebnis = update_rueckgaengig();
        flash($ergebnis['text'], $ergebnis['ok'] ? 'ok' : 'err');
        redirect('aktualisieren.php');
    }
}

// Stand von GitHub. Der Aufruf kann bei schlechtem Netz dauern, deshalb nur
// hier und nicht auf jeder Seite.
$remote = update_bestand_remote();
$plan = $remote['ok'] ? update_plan($installiert, $remote['manifest']) : null;

$neuDa = $remote['ok'] && $versionHier !== null && $remote['manifest']['version'] !== $versionHier;
$zuSchreiben = $plan !== null ? update_plan_umfang($plan) : 0;
$sicherungen = update_sicherungen();

// Die Aenderungsliste sagt mehr als eine Aufzaehlung der ausgetauschten
// Dateien. Sie wird nur geholt, wenn es etwas zu tun gibt, und die Seite
// faellt sonst auf die Dateiliste zurueck.
$changelog = ['ok' => false, 'abschnitte' => [], 'error' => ''];
$zeigeAenderungen = false;
if ($plan !== null && $zuSchreiben > 0) {
    $changelog = update_changelog();
    $zeigeAenderungen = $changelog['ok']
        && update_changelog_vorhanden($changelog['abschnitte'], $versionHier, $remote['manifest']['version']);
}
$aenderungen = $zeigeAenderungen
    ? update_changelog_seit($changelog['abschnitte'], $versionHier, $remote['manifest']['version'])
    : [];

/** Was mit einer Datei passiert, in einer Zeile: Text und Begruendung. */
function update_zeile_text(string $art, string $pfad, $wert, ?string $altVersion): array
{
    switch ($art) {
        case 'ersetzen':
            return ['wird ersetzt', $wert['geprueft']
                ? 'unveraendert seit ' . $altVersion
                : 'keine Bestandsliste vorhanden, deshalb ohne Vergleich'];
        case 'neu':
            return ['kommt neu dazu', 'gibt es hier noch nicht'];
        case 'geaendert':
            return ['bleibt stehen', 'von Hand geaendert, letzte Fassung waere ' . substr($wert['erwartet'], 0, 8)];
        case 'unbekannt':
            return ['bleibt stehen', 'stand in keiner Bestandsliste, letzte Fassung waere ' . substr($wert, 0, 8)];
        case 'geschuetzt':
            $schutz = update_geschuetzt()[$pfad] ?? '';
            if (empty($wert['abweichend'])) {
                return ['bleibt stehen', $schutz . ', stimmt mit dem Repository ueberein'];
            }
            // Kein "von Hand geaendert": der Knopf liefert diese Datei nie.
            // Ehrlicher ist, woran sie liegt und was daraus folgt.
            return ['bleibt stehen', $schutz . ', aelter als im Repository - vom Update nicht erreichbar'];
        case 'weg':
            return ['bleibt stehen', 'die neue Fassung braucht sie nicht mehr'];
    }
    return ['', ''];
}

page_start('Aktualisierung', 'admin', 'aktualisieren.php');
?>

<h2>Aktualisierung</h2>
<p class="lead">Neue Fassungen kommen direkt von GitHub. Die Datenbank und die Zugangsdaten
bleiben unberuehrt; ersetzt werden nur Programmdateien.</p>

<div class="panel">
    <div class="row-between">
        <div>
            <p>Hier installiert: <b><?= $versionHier !== null ? h($versionHier) : 'unbekannt' ?></b>
               <?php if ($versionHier === null): ?>
                   &ndash; auf diesem Server liegt keine Bestandsliste
               <?php endif; ?>
            </p>
            <p>Auf GitHub: <b><?= $remote['ok'] ? h($remote['manifest']['version']) : 'nicht erreichbar' ?></b></p>
        </div>
        <a class="btn ghost" href="aktualisieren.php">Neu pruefen</a>
    </div>

    <?php if (!$remote['ok']): ?>
        <p class="hint">Gemeldet wurde: <?= h($remote['error']) ?> Solange das so bleibt, laesst sich
            nichts aktualisieren. Die Seite selbst laeuft normal.</p>
    <?php elseif ($versionHier === null): ?>
        <p class="hint">Dieser Server ist aelter als die erste Fassung mit Bestandsliste. Das erste
            Update ersetzt deshalb alle Dateien ausser den geschuetzten. Ab dem zweiten Update wird
            jede Datei vorher ueber ihre Pruefsumme geprueft.</p>
    <?php elseif ($neuDa): ?>
        <p class="hint">Auf GitHub liegt eine neuere Fassung.</p>
    <?php else: ?>
        <p class="hint">Diese Fassung ist aktuell<?= count($plan['gleich']) > 0 ? ', ' . count($plan['gleich']) . ' Datei(en) stimmen' : '' ?>.</p>
    <?php endif; ?>
</div>

<?php if ($plan !== null && $zuSchreiben > 0): ?>
<div class="panel">
    <h3>Was sich aendert</h3>

    <?php if ($zeigeAenderungen): ?>
        <?php foreach ($aenderungen as $abschnitt): ?>
            <div style="margin-bottom:18px">
                <h4 style="margin:0 0 6px">
                    Fassung <?= h($abschnitt['version']) ?>
                    <span class="tag live">neu</span>
                    <?php if ($abschnitt['datum'] !== ''): ?>
                        <span class="small muted"><?= h($abschnitt['datum']) ?></span>
                    <?php endif; ?>
                </h4>
                <ul style="margin:0;padding-left:20px">
                    <?php foreach ($abschnitt['punkte'] as $punkt):
                        if (!empty($punkt['absatz'])) { ?>
                            <li style="margin-bottom:4px;list-style:none;margin-left:-14px"><?= h($punkt['text']) ?></li>
                        <?php } else { ?>
                            <li style="margin-bottom:4px"><?= h($punkt['text']) ?></li>
                        <?php }
                    endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p class="hint">Fuer diesen Sprung liegt keine Aenderungsliste vor – daher die
            Aufzaehlung der Dateien: <?= h(update_plan_beschreibung($plan)) ?></p>
    <?php endif; ?>

    <p class="hint"><?= h(update_plan_beschreibung($plan)) ?></p>

    <form method="post" action="aktualisieren.php"
          onsubmit="return confirm('Jetzt <?= $zuSchreiben ?> Datei(en) einspielen?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <button class="btn" type="submit">Auf Fassung <?= h($remote['manifest']['version']) ?> aktualisieren</button>
        <span class="hint">Vorher wird eine Sicherung angelegt. Die Datenbank aendert sich nicht.</span>
    </form>
</div>
<?php endif; ?>

<?php // Dateien, die der Knopf nicht anfasst. Das bleibt eine Liste mit
      // Dateinamen: hier muss der SuperAdmin wissen, welche Datei er von Hand
      // uebernehmen muss. ?>
<?php if ($plan !== null && ($plan['geschuetzt'] || $plan['geaendert'] || $plan['unbekannt'] || $plan['weg'])): ?>
<div class="panel">
    <h3>Dateien, die stehen bleiben</h3>
    <p class="hint">Diese Dateien sind geschuetzt, von Hand geaendert oder werden nicht mehr
        gebraucht. Der Knopf fasst sie nicht an. <b>Geschuetzt heisst nicht „von Hand
        geaendert"</b>: bei install.php und config.sample.php waere das eine falsche
        Erklaerung - sie gehoeren dem Server und werden nie mitgeliefert.</p>
    <table class="data dense">
        <thead><tr><th>Datei</th><th>Grund</th></tr></thead>
        <tbody>
    <?php
    $arten = ['geschuetzt' => '', 'weg' => '', 'geaendert' => '', 'unbekannt' => ''];
    foreach (array_keys($arten) as $art):
        foreach ($plan[$art] as $pfad => $wert):
            [$text, $grund] = update_zeile_text($art, $pfad, $wert, $versionHier);
    ?>
            <tr>
                <td><code><?= h($pfad) ?></code></td>
                <td><span class="small"><?= h($text) ?></span> <span class="small muted"><?= h($grund) ?></span></td>
            </tr>
    <?php endforeach; endforeach; ?>
        </tbody>
    </table>
    <p class="hint">Jede Datei liegt einzeln auf GitHub unter
        <code>https://github.com/<?= h(APP_REPO) ?>/blob/<?= h(APP_BRANCH) ?>/&lt;Pfad&gt;</code>.</p>
</div>
<?php endif; ?>

<div class="panel">
    <h3>Selbsttest des Servers</h3>
    <p class="hint">Damit der Knopf arbeiten kann, braucht der Server ein paar Voraussetzungen.
        Fehlt eine, laesst sich die Seite nicht per Knopf aktualisieren. GitHub speichert rohe
        Dateien bis zu fünf Minuten zwischen: direkt nach dem Hochladen einer Fassung kann der
        Knopf noch den vorherigen Stand sehen.</p>
    <table class="data dense">
        <thead><tr><th>Voraussetzung</th><th class="mid">vorhanden</th><th>Wenn nicht</th></tr></thead>
        <tbody>
    <?php foreach (update_selbsttest() as $test): ?>
        <tr<?= $test['ok'] ? '' : ' class="cell-missing"' ?>>
            <td><?= h($test['name']) ?></td>
            <td class="mid"><?= $test['ok'] ? 'ja' : 'nein' ?></td>
            <td class="small muted"><?= $test['ok'] ? '–' : h($test['hinweis']) ?></td>
        </tr>
    <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="panel">
    <h3>Sicherungen</h3>
<?php if (!$sicherungen): ?>
    <p class="hint">Noch keine Sicherung. Beim ersten Update wird automatisch eine angelegt.</p>
<?php else: ?>
    <table class="data dense">
        <thead><tr><th>Sicherung</th><th class="num">Fassung davor</th><th class="num">angelegt</th></tr></thead>
        <tbody>
    <?php foreach ($sicherungen as $sicherung): ?>
        <tr>
            <td><code><?= h($sicherung['name']) ?></code></td>
            <td class="num"><?= h($sicherung['version'] ?: '–') ?></td>
            <td class="num"><?= h(date('d.m.Y H:i', $sicherung['zeit'])) ?></td>
        </tr>
    <?php endforeach; ?>
        </tbody>
    </table>
    <form method="post" action="aktualisieren.php"
          onsubmit="return confirm('Die neueste Sicherung zurueckholen? Der Stand von eben geht dabei verloren.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="undo">
        <button class="btn" type="submit">Neueste Sicherung zurueckholen</button>
        <span class="hint">Setzt die Dateien auf den Stand von <?= h($sicherungen[0]['name']) ?> zurueck.</span>
    </form>
<?php endif; ?>
</div>

<?php page_end(); ?>
