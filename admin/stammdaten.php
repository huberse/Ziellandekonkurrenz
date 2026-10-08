<?php
/**
 * Die Stammdaten der Piloten: wer im Verein fliegt, einmal.
 *
 * Hier steht, was über die Jahre gleich bleibt: SMV-Nummer und Name. Alles,
 * was je Wettbewerb anders sein kann – Startnummer, Verein, Modell, Modelltyp –
 * steht am Eintrag in der Startliste. Wer den Verein wechselt, behaelt die
 * Historie beim alten; das ist der Grund fuer diese Trennung.
 *
 * Die Liste baut sich selbst: wer sich ueber das oeffentliche Anmeldeformular
 * mit einer noch unbekannten Nummer meldet, kommt hier herein.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/scoring.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/profiles.php';
// Das Wort 'Benutzerverwaltung' stand hier fest im Sperrtext - eine Meldung
// ueber Konten, wenn jemand auf die Stammliste wollte.
$me = require_superadmin('Die Stammdaten', true);

$suche = text_limit(get('q'), 120);
$action = post('action');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) post('id');

    if ($action === 'delete') {
        // Muss vor der Bearbeitung stehen. Das Formular des Loeschknopfes
        // schickt nur die Nummer des Stammsatzes, keine Namen - und die
        // Pruefung weiter unten wuerde ihn mit "Vor- und Nachname gehoeren
        // dazu" zurueckweisen, ohne dass man den Grund fuer das Scheitern
        // irgendwo sieht.
        try {
            $weg = profile_loeschen($id);
            $zusatz = $weg['eintraege'] > 0
                ? ' Dabei wurden ' . $weg['eintraege']
                    . ($weg['eintraege'] === 1 ? ' Startlisteneintrag' : ' Startlisteneintraege')
                    . ' mitgenommen.'
                : '';
            flash('Stammsatz gelöscht.' . $zusatz
                . ($weg['anmeldungen'] > 0
                    ? ' Eine zugehörige Anmeldung ist wieder offen und kann abgelehnt werden.'
                    : ''), 'ok');
        } catch (DomainException $e) {
            flash($e->getMessage(), 'err');
        } catch (Throwable $e) {
            flash($e->getMessage(), 'err');
        }
        redirect('stammdaten.php' . ($suche !== '' ? '?q=' . urlencode($suche) : ''));
    }

    $st = db()->prepare('SELECT * FROM pilot_profiles WHERE id = ?');
    $st->execute([$id]);
    $profil = $st->fetch();
    if (!$profil) {
        flash('Diesen Piloten gibt es nicht.', 'err');
        redirect('stammdaten.php');
    }
    $vorname = text_limit(post('first_name'), 80);
    $nachname = text_limit(post('last_name'), 80);
    $rohNummer = post('smv_number');

    if ($vorname === '' || $nachname === '') {
        flash('Vor- und Nachname gehören dazu.', 'err');
        redirect('stammdaten.php?bearbeiten=' . $id);
    }
    if (!pilot_smv_ist_gueltig($rohNummer)) {
        flash('Die SMV-Nummer besteht aus bis zu sechs Ziffern.', 'err');
        redirect('stammdaten.php?bearbeiten=' . $id);
    }
    $nummer = pilot_smv_normalisieren($rohNummer);
    if ($nummer !== null) {
        $anders = db()->prepare('SELECT id FROM pilot_profiles WHERE smv_number = ? AND id <> ?');
        $anders->execute([$nummer, $id]);
        if ($anders->fetchColumn()) {
            flash('Diese SMV-Nummer gehört schon einem anderen Piloten.', 'err');
            redirect('stammdaten.php?bearbeiten=' . $id);
        }
    }
    // Die Nummer wird nur geschrieben, wenn sie sich aendert. Sonst wuerde
    // jedes Speichern den Zeitstempel heben, ohne dass etwas passiert.
    $unveraendert = $vorname === (string) $profil['first_name']
        && $nachname === (string) $profil['last_name']
        && ($nummer ?? '') === trim((string) ($profil['smv_number'] ?? ''));
    if ($unveraendert) {
        flash('Nichts geändert.', 'info');
        redirect('stammdaten.php');
    }
    $setzen = db()->prepare('UPDATE pilot_profiles
                            SET smv_number = ?, first_name = ?, last_name = ?, updated_at = NOW()
                            WHERE id = ?');
    $setzen->execute([$nummer, $vorname, $nachname, $id]);

    // An den Startlisteneintraegen muss nichts mitgezogen werden: der Name
    // steht nur hier, und die lesen ihn ueber profile_id.

    $geaendert = [];
    if ($vorname !== (string) $profil['first_name'] || $nachname !== (string) $profil['last_name']) {
        $geaendert[] = 'Name';
    }
    if (($nummer ?? '') !== trim((string) ($profil['smv_number'] ?? ''))) {
        $geaendert[] = 'SMV-Nummer';
    }
    flash('Stammdaten gespeichert' . ($geaendert ? ': ' . implode(', ', $geaendert) : '') . '.', 'ok');
    redirect('stammdaten.php');
}

$profile = profiles_uebersicht($suche);

$editId = (int) get('bearbeiten', '0');
$edit = null;
if ($editId) {
    $st = db()->prepare('SELECT * FROM pilot_profiles WHERE id = ?');
    $st->execute([$editId]);
    $edit = $st->fetch() ?: null;
}

$mitNummer = count(array_filter($profile, static function (array $p): bool {
    return trim((string) ($p['smv_number'] ?? '')) !== '';
}));

page_start('Stammdaten', 'admin', 'stammdaten.php', true);
?>
<h2>Stammdaten der Piloten</h2>
<p class="lead">Wer im Verein fliegt, steht hier einmal – mit SMV-Nummer und Name. Das gilt über
    alle Jahre hinweg. Startnummer, Verein, Modell und Modelltyp stehen nicht hier, sondern am
    Eintrag in der jeweiligen <a href="piloten.php">Startliste</a>.</p>
<p class="small muted">Die Liste wächst von selbst: wer sich über das
    <a href="../anmeldung.php">Anmeldeformular</a> mit einer noch unbekannten Nummer meldet, wird
    hier eingetragen. Wer keine Nummer hat, steht hier mit 999999 – das ist keine Nummer, sondern
    nur die Anzeige, damit solche Piloten in der Regiowertung geführt werden.</p>

<div class="row-between no-print">
    <div class="field" style="max-width:320px;margin:0">
        <label class="sr-only" for="q">Stammdaten durchsuchen</label>
        <input type="search" id="q" name="q" value="<?= h($suche) ?>" placeholder="Nummer oder Name">
    </div>
    <span class="small muted"><?= count($profile) ?> <?= count($profile) === 1 ? 'Pilot' : 'Piloten' ?><?php
        if ($suche !== ''): ?>, gefiltert nach „<?= h($suche) ?>“<?php endif; ?></span>
</div>

<?php if ($suche !== ''): ?>
    <form method="get" class="no-print" style="margin-bottom:12px">
        <a class="btn ghost" href="stammdaten.php">Filter zurücksetzen</a>
    </form>
<?php endif; ?>

<?php if ($edit): ?>
<div class="panel">
    <h3 style="margin-top:0">Stammdaten ändern</h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
        <div class="grid-2">
            <div class="field">
                <label for="sm">SMV-Nummer</label>
                <input type="text" id="sm" name="smv_number" inputmode="numeric" maxlength="6"
                       value="<?= h($edit['smv_number'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="fn">Vorname</label>
                <input type="text" id="fn" name="first_name" maxlength="80"
                       value="<?= h($edit['first_name']) ?>" required>
            </div>
            <div class="field">
                <label for="ln">Name</label>
                <input type="text" id="ln" name="last_name" maxlength="80"
                       value="<?= h($edit['last_name']) ?>" required>
            </div>
        </div>
        <p class="hint">Der Name gilt für alle Wettbewerbe, in denen dieser Pilot geflogen ist.
            Korrigierst du ihn hier, stimmt er überall.</p>
        <div class="btn-row">
            <button class="btn" type="submit">Stammdaten speichern</button>
            <a class="btn ghost" href="stammdaten.php">Abbrechen</a>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="panel" style="padding:0">
    <div class="table-scroll">
    <table class="data">
        <thead>
            <tr>
                <th class="num">SMV</th>
                <th>Vorname</th>
                <th>Name</th>
                <th class="num">Starts</th>
                <th>zuletzt</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($profile as $p): ?>
            <tr>
                <td class="num<?= trim((string) ($p['smv_number'] ?? '')) === '' ? ' muted' : '' ?>">
                    <?= h(pilot_smv_anzeige($p['smv_number'] ?? null)) ?>
                </td>
                <td><?= h((string) $p['first_name']) ?></td>
                <td><?= h((string) $p['last_name']) ?></td>
                <td class="num"><?= (int) $p['starts'] ?></td>
                <td class="small muted nowrap">
                    <?= $p['zuletzt'] ? h(date('Y', strtotime((string) $p['zuletzt']))) : '–' ?>
                </td>
                <td class="nowrap no-print">
                    <a class="btn ghost small" href="stammdaten.php?bearbeiten=<?= (int) $p['id'] ?>">Ändern</a>
                    <?php if ((int) $p['ergebnisse'] === 0): ?>
                        <?php // Der Knopf fehlt, wo das Loeschen nicht moeglich ist.
                              // Er waere sonst da und wuerde beim Klick mit einem
                              // Satz zurueckkommen, den man nicht erwartet hat -
                              // und genau das hat der Nutzer hier zu tun. ?>
                        <form method="post" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <button class="btn danger small" type="submit"
                                    data-confirm-click="Stammsatz <?= h(profile_name($p)) ?> löschen?<?= (int) $p['starts'] > 0
                                        ? ' Dabei werden ' . (int) $p['starts']
                                          . ((int) $p['starts'] === 1 ? ' Startlisteneintrag' : ' Startlisteneinträge')
                                          . ' mitgenommen. Ergebnisse hat dieser Pilot keine, die behalten sich also keine.'
                                        : ' Dieser Pilot steht in keiner Startliste.' ?>">✕ Löschen</button>
                        </form>
                    <?php else: ?>
                        <span class="small muted nowrap"
                              title="<?= (int) $p['ergebnisse'] ?> Ergebnisse in <?= (int) $p['starts'] ?> Wettbewerb<?= (int) $p['starts'] === 1 ? '' : 'en' ?> – die kann man nicht wegwerfen">geschützt</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$profile): ?>
            <tr><td colspan="6" class="muted" style="padding:20px">
                <?= $suche !== ''
                    ? 'Zu dieser Suche gibt es niemanden.'
                    : 'Noch keine Piloten. Sie kommen herein, sobald sich jemand über das Anmeldeformular meldet.' ?>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php if ($profile): ?>
    <p class="hint" style="margin:10px 0 0"><?= $mitNummer ?> von <?= count($profile) ?>
        <?= count($profile) === 1 ? 'Pilot hat' : 'Piloten haben' ?> eine SMV-Nummer.
        Wer keine hat, steht in der Regiowertung unter 999999 – das ist eine Anzeige und
        keine Nummer, zwei solche Piloten sind über ihre Nummer also nicht zu unterscheiden.</p>
    <?php endif; ?>
</div>
<?php page_end();
