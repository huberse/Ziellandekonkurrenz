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

    if ($action === 'oeffentlich') {
        if (!is_superadmin()) {
            flash('Das darf nur der SuperAdmin.', 'err');
        } else {
            try {
                foreach (region_jahr_freigeben((int) post('jahr', '0')) as $zeile) {
                    flash($zeile, 'ok');
                }
            } catch (Throwable $e) {
                flash($e->getMessage(), 'err');
            }
        }
        redirect('regiocup.php?jahr=' . (int) post('jahr_zeile', post('jahr', '0')));
    }

    if ($action === 'punkte') {
        if (!is_superadmin()) {
            flash('Das darf nur der SuperAdmin.', 'err');
        } else {
            $anzahl = (int) post('rang_anzahl', '0');
            if ($anzahl < 3 || $anzahl > 60) {
                flash('Die Zahl der Ränge muss zwischen 3 und 60 liegen.', 'err');
                redirect('regiocup.php?jahr=' . (int) post('jahr', '0'));
            }
            $roh = [];
            for ($rang = 1; $rang <= $anzahl; $rang++) {
                $roh[$rang] = post("punkte_$rang", '');
            }
            $liste = region_fis_pruefen(array_map('trim', $roh));
            if ($liste === []) {
                // region_fis_pruefen() sagt nur "brauchbar oder nicht". Welcher
                // Rang es war, muss die Meldung selbst nennen, sonst steht
                // da "abgelehnt" und der weiss nicht, wo er suchen soll.
                $grund = region_fis_mangel($roh);
                flash('Die Punkteliste wurde nicht gespeichert. ' . $grund, 'err');
            } else {
                region_fis_speichern($liste);
                flash('Punkteliste gespeichert. Die Regiorangliste rechnet sofort damit.',
                    'ok');
            }
        }
        redirect('regiocup.php?jahr=' . (int) post('jahr', '0'));
    }

    if ($action === 'punkte_zurueck') {
        if (!is_superadmin()) {
            flash('Das darf nur der SuperAdmin.', 'err');
        } else {
            region_fis_zuruecksetzen();
            flash('Punkteliste auf die FIS-Vorgabe zurückgesetzt.', 'ok');
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

// Die Punkteliste zum Bearbeiten. Angezeigt werden mindestens 30 Zeilen, damit
// die Vorgabe ohne Scrollen passt, und mindestens so viele, wie gerade gesetzt
// sind - eine Liste, die laenger ist als das, was man sieht, waere ein
// Versteck.
$punkteListe = region_fis_schema();
$rangAnzahl = max(3, count($punkteListe));
$zeilen = max(30, $rangAnzahl);

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

<?php if (is_superadmin()):
    // Der Block steht vor der Punkteliste, weil er das grosse Ereignis am Ende
    // einer Saison ist - und man danach sucht, wenn man mit der Saison fertig ist.
    $oeffentlichesJahr = region_jahr_oeffentlich();
    $bereit = region_freigabe_bereit((int) $jahr);
    $istFreigegeben = $oeffentlichesJahr === (int) $jahr;
?>
<div class="panel">
    <h3 style="margin-top:0">Öffentliche Freigabe</h3>
    <?php if ($oeffentlichesJahr > 0): ?>
        <p class="small">Die Regiorangliste <strong><?= (int) $oeffentlichesJahr ?></strong> steht
            öffentlich. Jeder kann sie ohne Konto ansehen.</p>
    <?php else: ?>
        <p class="small">Noch steht keine Regiorangliste öffentlich.</p>
    <?php endif; ?>
    <p class="small muted">Nach der Freigabe sieht ein Besucher <strong>nur dieses eine Jahr</strong>.
        Andere Jahre bleiben hier im Wettbewerbsbüro stehen, sind aber nicht öffentlich. Die Ranglisten
        der einzelnen Wettbewerbe sind davon nicht berührt – die gibst du je Wettbewerb frei.</p>

    <?php if ($istFreigegeben): ?>
        <form method="post" class="btn-row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="oeffentlich">
            <input type="hidden" name="jahr" value="0">
            <input type="hidden" name="jahr_zeile" value="<?= (int) $jahr ?>">
            <button class="btn ghost" type="submit"
                    data-confirm-click="Freigabe für <?= (int) $jahr ?> zurücknehmen? Danach sieht sie wieder nur der eingestellte Verein und der SuperAdmin.">Freigabe zurücknehmen</button>
        </form>
    <?php elseif ($bereit['offen'] > 0): ?>
        <?php // Der Knopf fehlt, statt ihn zu zeigen und dann zu verweigern. Wer
              // danach sucht, liest stattdessen die Zahl und weiss damit auch,
              // WESSER er zu tun hat. ?>
        <p class="small muted" style="margin-bottom:0">Noch nicht möglich: In <?= (int) $jahr ?> sind
            <?= (int) $bereit['offen'] ?> Wettbewerb<?= (int) $bereit['offen'] === 1 ? '' : 'e' ?> noch
            offen: <?= h(implode(', ', $bereit['jahre'])) ?>.
            Beende <?= (int) $bereit['offen'] === 1 ? 'ihn' : 'sie' ?>, oder markiere
            <?= (int) $bereit['offen'] === 1 ? 'ihn' : 'sie' ?> als abgesagt – ein abgesagter
            Wettbewerb gilt als abgeschlossen und zählt nicht zum Regiocup.</p>
    <?php else: ?>
        <form method="post" class="btn-row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="oeffentlich">
            <input type="hidden" name="jahr" value="<?= (int) $jahr ?>">
            <input type="hidden" name="jahr_zeile" value="<?= (int) $jahr ?>">
            <button class="btn" type="submit"
                    data-confirm-click="Regiorangliste <?= (int) $jahr ?> öffentlich machen? Jeder kann sie dann ohne Konto ansehen. Ein anderes Jahr ist danach nicht mehr öffentlich.">Für <?= (int) $jahr ?> öffentlich freigeben</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (is_superadmin()): ?>
<div class="panel">
    <h3 style="margin-top:0">Punkteliste</h3>
    <p class="small muted">
        Welcher Rang wie viele Punkte bekommt. <?= region_fis_ist_eingestellt()
            ? 'Gerade eingestellt.' : 'Zurzeit die FIS-Vorgabe.' ?>
        <strong>Der beste Platz muss die meisten Punkte bekommen</strong>, sonst wird die
        Liste nicht gespeichert. Gleichstand ist erlaubt.
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="punkte">
        <input type="hidden" name="jahr" value="<?= $jahr ?>">
        <div class="field" style="max-width:220px">
            <label for="ra">Ränge, für die es Punkte gibt</label>
            <input type="number" id="ra" name="rang_anzahl" min="3" max="60" value="<?= $rangAnzahl ?>">
            <p class="hint">Ab Rang <?= $rangAnzahl + 1 ?> gibt es keine Punkte mehr.</p>
        </div>
        <div class="punkte-raster">
            <?php for ($rang = 1; $rang <= $zeilen; $rang++): ?>
                <div class="punkte-paar">
                    <label for="p<?= $rang ?>"><?= $rang ?>.</label>
                    <input type="text" inputmode="decimal" id="p<?= $rang ?>" name="punkte_<?= $rang ?>"
                           maxlength="7" value="<?= h((string) ($punkteListe[$rang] ?? '')) ?>">
                </div>
            <?php endfor; ?>
        </div>
        <div class="btn-row" style="margin-top:14px">
            <button class="btn" type="submit">Punkteliste speichern</button>
        </div>
        <p class="hint">
            Es wird sofort neu gerechnet: die Regiorangliste wird bei jedem Aufruf frisch berechnet
            und steht nirgends gespeichert. Es ist also nichts nachzurechnen – die alte Wertung ist
            danach einfach die neue Wertung.
        </p>
    </form>
    <form method="post" class="btn-row" style="margin-top:10px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="punkte_zurueck">
        <input type="hidden" name="jahr" value="<?= $jahr ?>">
        <button class="btn ghost" type="submit" formnovalidate
            <?= region_fis_ist_eingestellt() ? '' : 'disabled title="Es ist schon die Vorgabe"' ?>>Auf die FIS-Vorgabe zurück</button>
        <?php if (region_fis_ist_eingestellt()): ?>
            <span class="small muted">Damit gelten wieder 100, 80, 60, 50, 45 … bis Platz 30.</span>
        <?php endif; ?>
    </form>
</div>
<?php endif; ?>

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
