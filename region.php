<?php
/**
 * Regiorangliste: die Punkte aller Wettbewerbe eines Jahres, die den
 * Regiocup-Knopf haben.
 *
 * Die Auswahl traegt keine Hand ein: `region_wettbewerbe()` nimmt jedes
 * Wettbewerb des Jahres mit gesetztem Kennzeichen. Wer den Knopf drueckt,
 * nimmt teil - unabhaengig vom Veranstalter.
 *
 * Wer sie sehen darf, entscheidet `region_darf_sehen()`: sind die Resultate
 * oeffentlich, darf es jeder; sonst nur der SuperAdmin und die Mitglieder des
 * Vereins aus `region_club_id`.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/scoring.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/region.php';

if (!is_file(__DIR__ . '/config.php')) {
    exit('config.php fehlt. Kopiere config.sample.php nach config.php.');
}
try {
    db()->query('SELECT 1 FROM rounds LIMIT 1');
} catch (PDOException $e) {
    redirect('install.php');
}
if (!schema_has_competitions()) {
    redirect('upgrade.php');
}

$jahre = region_jahre();
if (!$jahre) {
    page_start('Regiorangliste', 'public', 'region.php', false, false);
    echo '<div class="panel"><h2>Noch keine Regiorangliste</h2><p class="lead">Sobald ein Wettbewerb '
        . 'das Regiocup-Kennzeichen trägt, steht er hier.</p></div>';
    page_end();
    exit;
}

$jahr = (int) get('jahr', (string) $jahre[0]);
if (!in_array($jahr, array_map('intval', $jahre), true)) {
    $jahr = (int) $jahre[0];
}
$wettbewerbe = region_wettbewerbe($jahr);
$daten = region_rangliste(array_map(static function (array $w): int {
    return (int) $w['id'];
}, $wettbewerbe));

// ------------------------------------------------------------------ Export
if (get('csv') !== '') {
    if (!region_darf_sehen()) {
        flash('Die Regiorangliste ist hier nicht freigegeben.', 'err');
        redirect('region.php?jahr=' . $jahr);
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Regiorangliste_' . $jahr . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM, damit Excel die Umlaute richtig zeigt
    $kopf = ['Rang', 'Pilot', 'Verein', 'Punkte'];
    foreach ($wettbewerbe as $w) {
        $kopf[] = competition_kuerzel((string) $w['name']);
    }
    fputcsv($out, $kopf, ';');
    foreach ($daten['zeilen'] as $z) {
        $zeile = [$z['rang'] ?? '', $z['name'], $z['club'], $z['punkte']];
        foreach ($wettbewerbe as $w) {
            $rang = $z['plaetze'][(int) $w['id']] ?? null;
            $zeile[] = $rang === null ? '' : $rang . ' (' . region_fis_punkte((int) $rang) . ')';
        }
        fputcsv($out, $zeile, ';');
    }
    fclose($out);
    exit;
}

// ------------------------------------------------------------------ Anzeige
if (!region_darf_sehen()) {
    page_start('Regiorangliste', 'public', 'region.php', false, false);
    echo '<div class="panel"><h2>Regiorangliste</h2><p class="lead">Die Resultate dieses Jahres '
        . 'sind noch nicht freigegeben.</p><p class="small muted">Sobald der Verein sie '
        . 'freischaltet, steht die Regiorangliste hier und auf der Startseite.</p></div>';
    page_end();
    exit;
}

$clubId = region_club_id();
page_start('Regiorangliste', 'public', 'region.php', true, false);
?>
<div class="row-between no-print">
    <div>
        <h2>Regiorangliste <?= h((string) $jahr) ?></h2>
        <p class="lead">Aus allen Wettbewerben des Jahres, die zum Regiocup gehören.
            <?= region_anzahl_gewertet() ?> Starts zählen, der schlechteste nicht.
            <strong>Wenig Punkte sind gut.</strong></p>
    </div>
    <div class="btn-row dense">
        <?php foreach ($jahre as $j): ?>
            <a class="btn <?= (int) $j === $jahr ? '' : 'ghost' ?>" href="region.php?jahr=<?= (int) $j ?>"><?= (int) $j ?></a>
        <?php endforeach; ?>
        <a class="btn ghost" href="region.php?jahr=<?= $jahr ?>&amp;csv=1">CSV</a>
    </div>
</div>

<div class="panel">
    <p class="small muted">
        <?php if (!$wettbewerbe): ?>
            Kein Wettbewerb des Jahres <?= (int) $jahr ?> hat das Regiocup-Kennzeichen.
        <?php else: ?>
            <?= count($wettbewerbe) ?> Wettbewerb<?= count($wettbewerbe) === 1 ? '' : 'e' ?>:
            <?php foreach ($wettbewerbe as $i => $w): ?>
                <?= $i > 0 ? ' · ' : '' ?><?= h((string) $w['name']) ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($clubId !== null && !is_superadmin()): ?>
            <?php $vereinName = '';
            foreach (all_clubs() as $c) {
                if ((int) $c['id'] === $clubId) {
                    $vereinName = (string) $c['name'];
                }
            } ?>
            <br>Freigeschaltet für <?= h($vereinName !== '' ? $vereinName : 'den eingestellten Verein') ?>.
        <?php endif; ?>
    </p>

    <?php region_table($daten, $wettbewerbe); ?>
</div>
<?php page_end();