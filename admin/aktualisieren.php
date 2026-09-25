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
                $stehen = count($plan['geaendert']) + count($plan['unbekannt']) + count($plan['von_hand']);
                $bericht = update_ausfuehren($plan, $remote['manifest']);
                if ($bericht['ok']) {
                    $zahl = count($bericht['ersetzen']) + count($bericht['neu']);
                    $text = 'Fassung ' . $bericht['version'] . ' ist eingespielt, ' . $zahl . ' Datei(en) geschrieben.';
                    if ($stehen > 0) {
                        $text .= ' ' . $stehen . ' Datei(en) sind stehen geblieben, weil sie von Hand geaendert '
                              . 'oder geschuetzt sind. Der Stand ist damit gemischt, siehe die Liste unten.';
                    }
                    if (schema_hinter_programm()) {
                        $text .= ' Danach bitte upgrade.php aufrufen.';
                    }
                    flash($text, $stehen > 0 ? 'err' : 'ok');
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
        case 'von_hand':
            return ['bleibt stehen', (update_geschuetzt()[$pfad] ?? '') . ', ' . ($wert['grund'] === 'fehlt' ? 'fehlt hier' : 'von Hand geaendert')];
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
    <h3>Was beim Update passiert</h3>
    <p class="hint"><?= h(update_plan_beschreibung($plan)) ?></p>

    <table class="data dense">
        <thead>
            <tr>
                <th>Datei</th>
                <th>Was damit geschieht</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $arten = [
            'ersetzen'  => 'tag live',
            'neu'       => 'tag on',
            'geaendert' => 'tag off',
            'unbekannt' => 'tag off',
            'von_hand'  => 'tag',
            'weg'       => 'tag',
        ];
        foreach ($arten as $art => $klasse):
            foreach ($plan[$art] as $pfad => $wert):
                [$text, $grund] = update_zeile_text($art, $pfad, $wert, $versionHier);
        ?>
            <tr<?= in_array($art, ['ersetzen', 'neu'], true) ? '' : ' class="cell-missing"' ?>>
                <td><code><?= h($pfad) ?></code></td>
                <td>
                    <span class="<?= h($klasse) ?>"><?= h($text) ?></span>
                    <span class="small muted"><?= h($grund) ?></span>
                </td>
            </tr>
        <?php endforeach; endforeach; ?>
        </tbody>
    </table>

    <?php if ($plan['von_hand'] || $plan['geaendert'] || $plan['unbekannt'] || $plan['weg']): ?>
        <p class="hint">Was stehen bleibt, musst du selbst uebernehmen. Jede Datei liegt einzeln
            auf GitHub unter
            <code>https://github.com/<?= h(APP_REPO) ?>/blob/<?= h(APP_BRANCH) ?>/&lt;Pfad&gt;</code>.</p>
    <?php endif; ?>

    <form method="post" action="aktualisieren.php"
          onsubmit="return confirm('Jetzt <?= $zuSchreiben ?> Datei(en) einspielen?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <button class="btn" type="submit">Auf Fassung <?= h($remote['manifest']['version']) ?> aktualisieren</button>
        <span class="hint">Vorher wird eine Sicherung angelegt. Die Datenbank aendert sich nicht.</span>
    </form>
</div>
<?php endif; ?>

<?php if ($plan !== null && $zuSchreiben === 0 && ($plan['von_hand'] || $plan['weg'] || $plan['geaendert'] || $plan['unbekannt'])): ?>
<div class="panel">
    <h3>Dateien fuer Handarbeit</h3>
    <p class="hint">An den Programmdateien ist nichts zu tun. Diese Dateien wurden auf GitHub
        geaendert, der Knopf fasst sie aber nicht an.</p>
    <table class="data dense">
        <thead><tr><th>Datei</th><th>Grund</th></tr></thead>
        <tbody>
    <?php
    $arten = ['von_hand' => '', 'weg' => '', 'geaendert' => '', 'unbekannt' => ''];
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
</div>
<?php endif; ?>

<div class="panel">
    <h3>Selbsttest des Servers</h3>
    <p class="hint">Damit der Knopf arbeiten kann, braucht der Server ein paar Voraussetzungen.
        Fehlt eine, laesst sich die Seite nicht per Knopf aktualisieren.</p>
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
