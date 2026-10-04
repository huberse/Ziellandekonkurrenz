<?php
/**
 * Regiocup: wer ihn sehen darf, und wie er gerade aussieht.
 *
 * Eine eigene Seite, weil zwei verschiedene Dinge getrennt werden müssen:
 * wer die Liste sehen darf (das entscheidet `region_darf_sehen()`) und wer
 * sie einstellen darf (das ist der SuperAdmin, und niemand sonst).
 *
 * Erreichbar ist die Seite für den SuperAdmin und für die Mitglieder des in
 * `region_club_id` eingestellten Vereins. Letzteres ist der Punkt: der Verein
 * soll die Regioliste seines eigenen Cups sehen können, ohne SuperAdmin zu
 * sein. Alle anderen bekommen die Sperre, die auch `region.php` zeigt – nicht
 * ein Formular, an dem sie ohnehin nichts ändern dürften.
 *
 * Der Verein muss dafür **nicht** für die Anmeldung freigeschaltet sein. Das
 * ist bewusst unabhängig: `clubs.active` steuert das Anmeldeformular, und ein
 * Verein, der nur zusieht, soll dafür nicht auftauchen müssen.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/scoring.php';
require_once __DIR__ . '/../lib/layout.php';

if (!is_file(__DIR__ . '/../config.php')) {
    exit('config.php fehlt. Kopiere config.sample.php nach config.php.');
}
if (!schema_has_competitions()) {
    redirect('../upgrade.php');
}

$me = require_login();

// Wer darf diese Seite sehen? SuperAdmin und der eingestellte Verein. Wer
// sonst ist, sieht dieselbe Sperre wie region.php - keine Liste, kein Export,
// und die Startseite zeigt ihm keine Kachel.
$regionClubId = region_club_id();
$istEingestellterVerein = $regionClubId !== null && user_club_id() === $regionClubId;

if (!is_superadmin() && !$istEingestellterVerein && !region_darf_sehen()) {
    page_start('Regiocup', 'admin', 'regiocup.php');
    echo '<div class="panel"><h2>Regiocup</h2><p class="lead">Die Regiorangliste ist hier nicht freigegeben.</p>'
        . '<p class="small muted">Sobald der SuperAdmin einen Verein freischaltet, steht die'
        . ' Regiorangliste hier und auf der Startseite.</p></div>';
    page_end();
    exit;
}

$action = post('action');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($action === 'regiocup') {
        if (!is_superadmin()) {
            flash('Das darf nur der SuperAdmin.', 'err');
        } else {
            $verein = (int) post('region_club_id', '0');
            global_setting_set('region_club_id', (string) ($verein > 0 ? $verein : 0));
            // Die alten Werte aus den Wettbewerbseinstellungen mit wegraeumen.
            // Blieben sie stehen, wuerde region_club_id() zurueckfallen, sobald
            // hier "niemand" gesetzt wird - und der Verein haette die Freigabe
            // wieder, die der SuperAdmin gerade entzogen hat.
            db()->exec('DELETE FROM competition_settings WHERE skey = \'region_club_id\'');
            flash('Freigabe für die Regiorangliste gespeichert.', 'ok');
        }
        redirect('regiocup.php?jahr=' . (int) post('jahr', '0'));
    }
}

// Die Vorschau zeigt die Liste auch dann, wenn sie sonst nirgends
// freigegeben ist. Darum ist das gerade der Zweck dieser Seite: ansehen,
// BEVOR sie veroeffentlicht wird.
$jahre = region_jahre();
$jahr = (int) get('jahr', $jahre ? (string) $jahre[0] : '0');
if ($jahre && !in_array($jahr, array_map('intval', $jahre), true)) {
    $jahr = (int) $jahre[0];
}
$wettbewerbe = $jahr > 0 ? region_wettbewerbe($jahr) : [];
$daten = $wettbewerbe
    ? region_rangliste(array_map(static function (array $w): int {
        return (int) $w['id'];
    }, $wettbewerbe))
    : ['zeilen' => []];

$vereinName = 'niemand';
foreach (all_clubs() as $c) {
    if ((int) $c['id'] === $regionClubId) {
        $vereinName = (string) $c['name'];
    }
}

page_start('Regiocup', 'admin', 'regiocup.php', true);
?>
<h2>Regiocup</h2>
<p class="lead">Ein Regiocup-Jahr ist die Summe aller Wettbewerbe, die den Regiocup-Knopf tragen.
    <?= region_anzahl_gewertet() ?> Starts zählen, der schlechteste nicht – <strong>wenig Punkte sind gut</strong>.</p>

<div class="panel">
    <div class="row-between no-print">
        <div>
            <h3 style="margin-top:0">Freigabe</h3>
            <p class="small muted" style="margin-bottom:0">
                <?php if ($regionClubId === null): ?>
                    Niemand sieht die Liste über diesen Weg. Sie ist nur für den SuperAdmin sichtbar –
                    und für alle, solange die Ergebnisse der Wettbewerbe ohnehin öffentlich sind.
                <?php else: ?>
                    Freigegeben für <strong><?= h($vereinName) ?></strong>. Deren Mitglieder sehen diese
                    Seite und die Regiorangliste, auch wenn die Ergebnisse sonst nirgends öffentlich sind.
                <?php endif; ?>
            </p>
        </div>
        <?php if (is_superadmin()): ?>
            <form method="post" class="btn-row">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="regiocup">
                <input type="hidden" name="jahr" value="<?= $jahr ?>">
                <label class="sr-only" for="rci">Verein, der die Regiorangliste sehen darf</label>
                <select id="rci" name="region_club_id">
                    <option value="0" <?= $regionClubId === null ? 'selected' : '' ?>>niemand</option>
                    <?php foreach (all_clubs() as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $regionClubId === (int) $c['id'] ? 'selected' : '' ?>><?= h((string) $c['name']) ?><?= (int) $c['active'] === 1 ? '' : ' (nicht in der Anmeldung)' ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn ghost" type="submit">Speichern</button>
            </form>
        <?php endif; ?>
    </div>
    <?php if (is_superadmin()): ?>
        <p class="hint" style="margin-bottom:0">Gilt für das ganze Programm, nicht für einen Wettbewerb:
            der Regiocup läuft über ein Jahr und damit über mehrere Wettbewerbe. Ein Verein muss dafür
            nicht für die Anmeldung freigeschaltet sein – „nur in der Anmeldung“ ist eine eigene Sache.</p>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="row-between no-print">
        <div>
            <h3 style="margin-top:0">Regiorangliste <?= $jahr > 0 ? h((string) $jahr) : '' ?></h3>
            <p class="small muted" style="margin-bottom:0">
                <?php if (!$wettbewerbe): ?>
                    Kein Wettbewerb des Jahres hat das Regiocup-Kennzeichen.
                <?php else: ?>
                    <?= count($wettbewerbe) ?> Wettbewerb<?= count($wettbewerbe) === 1 ? '' : 'e' ?>:
                    <?php foreach ($wettbewerbe as $i => $w): ?>
                        <?= $i > 0 ? ' · ' : '' ?><?= h((string) $w['name']) ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </p>
        </div>
        <div class="btn-row dense">
            <?php foreach ($jahre as $j): ?>
                <a class="btn <?= (int) $j === $jahr ? '' : 'ghost' ?>" href="regiocup.php?jahr=<?= (int) $j ?>"><?= (int) $j ?></a>
            <?php endforeach; ?>
            <?php if ($wettbewerbe): ?>
                <a class="btn ghost" href="../region.php?jahr=<?= $jahr ?>">Öffentliche Seite</a>
                <a class="btn ghost" href="../region.php?jahr=<?= $jahr ?>&amp;csv=1">CSV</a>
            <?php endif; ?>
        </div>
    </div>
    <?php region_table($daten, $wettbewerbe); ?>
</div>
<?php page_end();
