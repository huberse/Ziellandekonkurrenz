<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/scoring.php';
require_once __DIR__ . '/../lib/layout.php';
require_login();

$types = all_model_types();
$clubs = all_clubs();
$competition = resolve_competition_param(competition_request_param(), true);
// Startliste, Resultate und Einstellungen gehören genau diesem Wettbewerb.
// Die Prüfung steht hier und nicht versteckt in resolve_competition_param(),
// wo sie auch die öffentlichen Seiten betroffen hätte.
require_competition_access((int) $competition['id']);
$competitionCompleted = competition_is_completed((int) $competition['id']);

// Die Stammdaten der Piloten dieses Wettbewerbs. Sie werden fuer das Formular
// gebraucht (Name und Nummer stehen dort nicht mehr am Eintrag) und fuer den
// Vergleich beim Speichern: ein Name, der unveraendert bleibt, soll keinen
// Zeitstempel bekommen.
$pilotProfil = [];
$st = db()->prepare('SELECT pr.* FROM pilots p
                     JOIN pilot_profiles pr ON pr.id = p.profile_id
                     WHERE p.competition_id = ?');
$st->execute([(int) $competition['id']]);
foreach ($st as $zeile) {
    $pilotProfil[(int) $zeile['id']] = $zeile;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($competitionCompleted) {
        flash('Dieser Wettbewerb ist abgeschlossen. Startliste und Resultate sind gesperrt.', 'err');
        redirect('wettbewerbe.php?competition=' . (int) $competition['id']);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Der Abschluss und alle konkurrierenden Schreibvorgänge serialisieren sich
        // über dieselbe competitions-Zeile.
        lock_open_competition($pdo, (int) $competition['id']);
        $action = post('action');
        $successMessage = '';
        $successType = 'ok';

        if ($action === 'save') {
            $id = (int) post('id');
            $data = [
                'bib_number' => text_limit(post('bib_number'), 10) ?: null,
                'club_id'    => post('club_id') !== '' ? (int) post('club_id') : club_id_for_name(text_limit(post('club_new'), 120)),
                'model_type_id' => post('model_type_id') !== '' ? (int) post('model_type_id') : null,
                'model_name' => text_limit(post('model_name'), 120) ?: null,
                'notes'      => text_limit(post('notes'), 255) ?: null,
                'active'     => isset($_POST['active']) ? 1 : 0,
                'competition_id'  => $competition['id'],
            ];
            $vorname = text_limit(post('first_name'), 80);
            $nachname = text_limit(post('last_name'), 80);
            $rohNummer = post('smv_number');
            $validClubIds = array_map('intval', array_column($clubs, 'id'));
            $validTypeIds = array_map('intval', array_column($types, 'id'));
            if ($vorname === '' || $nachname === '') {
                throw new DomainException('Ohne Namen geht es nicht.');
            }
            if (!pilot_smv_ist_gueltig($rohNummer)) {
                throw new DomainException('Die SMV-Nummer hat bis zu sechs Ziffern. Leer lassen, wenn es keine gibt.');
            }
            if ($data['club_id'] !== null && !in_array($data['club_id'], $validClubIds, true)) {
                throw new DomainException('Bitte einen gültigen Verein wählen.');
            }
            if ($data['model_type_id'] !== null && !in_array($data['model_type_id'], $validTypeIds, true)) {
                throw new DomainException('Bitte einen gültigen Modelltyp wählen.');
            }
            if (competition_bib_number_exists((int) $competition['id'], $data['bib_number'], $id ?: null)) {
                throw new DomainException('Diese Startnummer ist in diesem Wettbewerb bereits vergeben.');
            }

            $bestehenderProfileId = null;
            if ($id) {
                $holen = $pdo->prepare('SELECT profile_id FROM pilots WHERE id = ? AND competition_id = ?');
                $holen->execute([$id, $competition['id']]);
                $bestehenderProfileId = $holen->fetchColumn();
                if ($bestehenderProfileId === false) {
                    throw new DomainException('Der Pilot gehört nicht zu diesem Wettbewerb.');
                }
                $bestehenderProfileId = (int) $bestehenderProfileId;
            }

            // Die Nummer gehoert zum Stamm, nicht zum Eintrag. Beim Bearbeiten
            // wird sie am bestehenden Stammdatensatz geaendert - sonst entstuende
            // bei jedem Speichern ein zweiter Stammsatz fuer dieselbe Person.
            $nummer = pilot_smv_normalisieren($rohNummer);
            if ($bestehenderProfileId !== null) {
                $anders = pilot_smv_normalisieren((string) ($pilotProfil[$bestehenderProfileId]['smv_number'] ?? ''));
                if ($anders !== $nummer) {
                    $belegt = $pdo->prepare('SELECT id FROM pilot_profiles WHERE smv_number = ? AND id <> ?');
                    $belegt->execute([$nummer, $bestehenderProfileId]);
                    if ($nummer !== null && $belegt->fetchColumn()) {
                        throw new DomainException('Diese SMV-Nummer gehört bereits zu einem anderen Piloten.');
                    }
                    $setzen = $pdo->prepare('UPDATE pilot_profiles SET smv_number = ?, updated_at = NOW() WHERE id = ?');
                    $setzen->execute([$nummer, $bestehenderProfileId]);
                }
                // Der Name wird nur geschrieben, wenn er sich aendert. Sonst
                // wuerde ein Klick auf Speichern den Zeitstempel heben.
                $vorherName = trim((string) ($pilotProfil[$bestehenderProfileId]['first_name'] ?? ''))
                    . '|' . trim((string) ($pilotProfil[$bestehenderProfileId]['last_name'] ?? ''));
                if ($vorherName !== $vorname . '|' . $nachname) {
                    $umbenennen = $pdo->prepare('UPDATE pilot_profiles
                                                 SET first_name = ?, last_name = ?, updated_at = NOW() WHERE id = ?');
                    $umbenennen->execute([$vorname, $nachname, $bestehenderProfileId]);
                }
                $profileId = $bestehenderProfileId;
            } else {
                $angelegt = profile_oder_anlegen($nummer, $vorname, $nachname, $pdo);
                $profileId = $angelegt['id'];
            }

            if ($id) {
                $sql = 'UPDATE pilots SET bib_number=:bib_number, profile_id=:profile_id,
                        club_id=:club_id, model_type_id=:model_type_id, model_name=:model_name,
                        notes=:notes, active=:active WHERE id=:id AND competition_id=:competition_id';
                $st = $pdo->prepare($sql);
                $st->execute($data + ['profile_id' => $profileId, 'id' => $id]);
                if ($st->rowCount() === 0) {
                    $check = $pdo->prepare('SELECT id FROM pilots WHERE id = ? AND competition_id = ?');
                    $check->execute([$id, $competition['id']]);
                    if (!$check->fetchColumn()) {
                        throw new DomainException('Der Pilot gehört nicht zu diesem Wettbewerb.');
                    }
                }
                $successMessage = 'Pilot gespeichert.';
            } else {
                $st = $pdo->prepare('INSERT INTO pilots (bib_number, profile_id, club_id, model_type_id, model_name, notes, active, competition_id)
                                     VALUES (:bib_number,:profile_id,:club_id,:model_type_id,:model_name,:notes,:active,:competition_id)');
                $st->execute($data + ['profile_id' => $profileId]);
                $successMessage = 'Pilot für den Wettbewerb „' . $competition['name'] . '“ aufgenommen.';
            }

        } elseif ($action === 'delete') {
            $pilotId = (int) post('id');
            $check = $pdo->prepare('SELECT id FROM pilots WHERE id = ? AND competition_id = ?');
            $check->execute([$pilotId, $competition['id']]);
            if (!$check->fetchColumn()) {
                throw new DomainException('Pilot gehört nicht zu diesem Wettbewerb.');
            }
            $unlink = $pdo->prepare("UPDATE registrations SET pilot_id = NULL, status = 'pending', decided_at = NULL
                                      WHERE pilot_id = ? AND competition_id = ? AND status = 'approved'");
            $unlink->execute([$pilotId, $competition['id']]);
            $st = $pdo->prepare('DELETE FROM pilots WHERE id = ? AND competition_id = ?');
            $st->execute([$pilotId, $competition['id']]);
            if ($st->rowCount() !== 1) {
                throw new DomainException('Pilot wurde nicht gelöscht.');
            }
            $successMessage = 'Pilot und dessen Resultate gelöscht. Die zugehörige Anmeldung ist wieder offen.';

        } elseif ($action === 'autonumber') {
            $st = $pdo->prepare('SELECT p.id FROM pilots p
                                LEFT JOIN model_types t ON t.id = p.model_type_id
                                WHERE p.active = 1 AND p.competition_id = ? ORDER BY t.sort_order, t.name, RAND()');
            $st->execute([$competition['id']]);
            $pilotIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if (!$pilotIds) {
                $successMessage = 'Es sind keine aktiven Piloten in der Startliste – es wurde nichts geändert.';
            } else {
                // Nur die Nummern, die ausserhalb dieser Auswahl vergeben sind, sind
                // gesperrt. Die Piloten in der Auswahl bekommen neue Nummern und
                // dürfen einander deshalb nicht blockieren: sonst schiebt sich jede
                // alte Nummer eines noch nicht umnummerierten Piloten als Lücke in
                // die Reihe, und aus drei Piloten werden 02, 04 und 05.
                // Nummern inaktiver Piloten bleiben gesperrt, weil der eindeutige
                // Index über Wettbewerb und Startnummer Doppelungen nicht zulässt.
                $in = implode(',', array_fill(0, count($pilotIds), '?'));
                $gesperrt = $pdo->prepare("SELECT p.bib_number, pr.first_name, pr.last_name
                                           FROM pilots p
                                           JOIN pilot_profiles pr ON pr.id = p.profile_id
                                           WHERE p.competition_id = ? AND p.id NOT IN ($in)
                                             AND p.bib_number IS NOT NULL AND p.bib_number <> ''
                                           ORDER BY p.bib_number");
                $gesperrt->execute(array_merge([(int) $competition['id']], $pilotIds));
                $belegt = [];
                $halter = [];
                foreach ($gesperrt->fetchAll(PDO::FETCH_ASSOC) as $zeile) {
                    $nummer = trim((string) $zeile['bib_number']);
                    $belegt[$nummer] = true;
                    $halter[$nummer] = trim(($zeile['first_name'] ?? '') . ' ' . ($zeile['last_name'] ?? ''));
                }

                $neu = [];
                $n = 0;
                $uebersprungen = [];
                foreach ($pilotIds as $pilotId) {
                    while (true) {
                        $bib = str_pad((string) (++$n), 2, '0', STR_PAD_LEFT);
                        if (!isset($belegt[$bib])) {
                            break;
                        }
                        $uebersprungen[$bib] = $halter[$bib] ?? 'unbekannt';
                    }
                    $belegt[$bib] = true;
                    $neu[$pilotId] = $bib;
                }

                // Die neuen Nummern werden in zwei Schritten gesetzt. Zuerst werden
                // die alten der Piloten in dieser Auswahl geleert, danach bekommen
                // sie die neuen. Man kann sie nicht einzeln überschreiben: die
                // erste Vergabe wäre mitten in der Runde doppelt belegt, solange der
                // bisherige Inhaber dieser Nummer noch nicht umgestellt ist – der
                // eindeutige Index über Wettbewerb und Startnummer lässt das nicht
                // zu. Leere Werte sind mehrfach erlaubt, deshalb geht das.
                // Eine eigene Transaktion ist nicht nötig: der ganze Aufruf läuft
                // bereits in einer, und PDO kennt keine verschachtelten. Scheitert
                // etwas, rollt der äussere Lauf zurück und die Startnummer bleibt so,
                // wie sie war.
                $leeren = $pdo->prepare("UPDATE pilots SET bib_number = NULL
                                          WHERE competition_id = ? AND id IN ($in)");
                $leeren->execute(array_merge([(int) $competition['id']], $pilotIds));
                $up = $pdo->prepare('UPDATE pilots SET bib_number = ? WHERE id = ? AND competition_id = ?');
                foreach ($neu as $pilotId => $bib) {
                    $up->execute([$bib, $pilotId, $competition['id']]);
                }
                $successMessage = count($pilotIds) . ' Startnummern neu und zufällig vergeben, gruppiert nach Modelltyp.';
                if ($uebersprungen) {
                    $stueck = [];
                    foreach ($uebersprungen as $nummer => $name) {
                        $stueck[] = $nummer . ' (' . ($name !== '' ? $name : 'ohne Namen') . ')';
                    }
                    $successMessage .= ' Die Nummern ' . implode(', ', $stueck)
                        . ' sind gesperrt, weil sie an inaktiven Piloten hängen – deshalb entstehen Lücken.'
                        . ' Wer sie frei haben will, muss die Startnummer dieses Piloten ändern oder ihn löschen.';
                }
            }

        } elseif ($action === 'import') {
            $raw = post('csv');
            $lines = preg_split('/\r\n|\r|\n/', $raw);
            $st = $pdo->prepare('INSERT INTO pilots (profile_id, club_id, model_type_id, bib_number, competition_id) VALUES (?,?,?,?,?)');
            $typeByName = [];
            foreach ($types as $g) { $typeByName[mb_strtolower($g['name'])] = (int) $g['id']; }
            $n = 0; $skipped = 0;
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') { continue; }
                $cols = array_map('trim', preg_split('/[;\t]/', $line));
                $kopf = mb_strtolower((string) ($cols[0] ?? ''));
                if ($kopf === 'vorname' || $kopf === 'name' || $kopf === 'smv') { continue; }
                // Zwei Formen: mit SMV-Nummer in der ersten Spalte, oder ohne.
                // Erkannt an der Spaltenzahl: mit Nummer sind es sechs, ohne
                // fuenf (Vorname, Name, Verein, Modelltyp, Startnummer).
                $smv = null;
                if (count($cols) >= 6 && pilot_smv_normalisieren((string) $cols[0]) !== null) {
                    $smv = pilot_smv_normalisieren((string) array_shift($cols));
                }
                $first = text_limit($cols[0] ?? '', 80);
                $last  = text_limit($cols[1] ?? '', 80);
                if ($last === '' && strpos($first, ' ') !== false) {
                    [$first, $last] = explode(' ', $first, 2);
                    $first = text_limit($first, 80);
                    $last = text_limit($last, 80);
                }
                if ($first === '' || $last === '') { $skipped++; continue; }
                $clubId = club_id_for_name(text_limit($cols[2] ?? '', 120));
                $gid = isset($cols[3]) && $cols[3] !== '' ? ($typeByName[mb_strtolower($cols[3])] ?? null) : null;
                $bib = text_limit($cols[4] ?? '', 10) ?: null;
                if (competition_bib_number_exists((int) $competition['id'], $bib)) {
                    $skipped++;
                    continue;
                }
                // Der Stammsatz wird je Zeile gesucht, nicht je Zeile neu
                // angelegt: dieselbe Person steht in mehreren Jahren in
                // mehreren Dateien, und soll daraus nicht mehrere Stammsaetze
                // werden.
                $angelegt = profile_oder_anlegen($smv, $first, $last, $pdo);
                $st->execute([$angelegt['id'], $clubId, $gid, $bib, $competition['id']]);
                $n++;
            }
            $successMessage = "$n Piloten für den Wettbewerb „{$competition['name']}“ importiert."
                . ($skipped ? " $skipped Zeilen übersprungen." : '');
        }

        $pdo->commit();
        if ($successMessage !== '') {
            flash($successMessage, $successType);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash($e instanceof DomainException
            ? $e->getMessage()
            : 'Die Startliste konnte nicht gespeichert werden. Bitte Eingaben prüfen.', 'err');
    }
    redirect('piloten.php?competition=' . $competition['id']);
}

$editId = (int) get('bearbeiten', '0');
$edit = null;
if ($editId) {
    $st = db()->prepare('SELECT p.*, pr.first_name, pr.last_name, pr.smv_number
                        FROM pilots p
                        JOIN pilot_profiles pr ON pr.id = p.profile_id
                        WHERE p.id = ? AND p.competition_id = ?');
    $st->execute([$editId, $competition['id']]);
    $edit = $st->fetch() ?: null;
}

$st = db()->prepare('SELECT p.*, pr.first_name, pr.last_name, pr.smv_number,
                       t.name AS model_type_name, c.name AS club_name,
                       (SELECT COUNT(*) FROM scores s WHERE s.pilot_id = p.id) AS score_count
                       FROM pilots p
                       JOIN pilot_profiles pr ON pr.id = p.profile_id
                       LEFT JOIN model_types t ON t.id = p.model_type_id
                       LEFT JOIN clubs c ON c.id = p.club_id
                       WHERE p.competition_id = ?
                       ORDER BY t.sort_order, t.name, p.bib_number + 0, p.bib_number, pr.last_name');
$st->execute([$competition['id']]);
$pilots = $st->fetchAll();

$competitions = function_exists('accessible_competitions') ? accessible_competitions() : all_competitions();
$notCurrent = (int) $competition['id'] !== current_competition_id();
$inaktiv = count(array_filter($pilots, static function (array $p): bool { return empty($p['active']); }));

/* ---------- Startliste als CSV zum Aufkleber drucken ---------- */
if (get('action') === 'csv') {
    $nurAktive = get('nur_aktive', '1') !== '0';
    $datei = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $competition['name']) ?: 'Wettbewerb';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $datei . '_Startliste_'
        . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM, damit Excel die Umlaute richtig zeigt

    // Kopfzeile nennt zugleich den Wettbewerb: beim Ausdrucken mehrerer
    // Aufkleberbogen ist sonst nicht erkennbar, welcher zu welchem gehoert.
    fputcsv($out, ['Startliste', (string) $competition['name'], date('d.m.Y')], ';');
    fputcsv($out, ['Startnummer', 'SMV-Nummer', 'Vorname', 'Name', 'Verein', 'Modelltyp', 'Modell'], ';');
    foreach ($pilots as $p) {
        if ($nurAktive && empty($p['active'])) {
            continue;
        }
        fputcsv($out, [
            (string) $p['bib_number'],
            pilot_smv_anzeige($p['smv_number'] ?? null),
            (string) $p['first_name'],
            (string) $p['last_name'],
            (string) ($p['club_name'] ?: ''),
            (string) ($p['model_type_name'] ?: ''),
            (string) ($p['model_name'] ?: ''),
        ], ';');
    }
    fclose($out);
    exit;
}

page_start('Piloten', 'admin', 'piloten.php');
?>
<div class="row-between">
    <div>
        <h2>Piloten<?= $notCurrent ? ' – Wettbewerb ' . h($competition['name']) : '' ?></h2>
        <p class="lead"><?= count($pilots) ?> für den Wettbewerb „<?= h($competition['name']) ?>“ gemeldet.
            Für einen neuen Wettbewerb meldet sich jeder wieder an, entweder über
            <a href="../anmeldung.php?competition=<?= (int) $competition['id'] ?>">das Anmeldeformular</a> oder hier
            direkt. <?= is_superadmin()
                ? 'Name und SMV-Nummer stehen in den <a href="stammdaten.php?competition='
                  . (int) $competition['id'] . '">Stammdaten</a> und gelten für alle Wettbewerbe'
                : 'Name und SMV-Nummer gelten für alle Wettbewerbe und werden vom SuperAdmin gepflegt' ?>;
            hier ändert sich je Wettbewerb nur die Startnummer,
            der Verein und das Modell.</p>
    </div>
</div>

<?php if (count($competitions) > 1): ?>
    <div class="competition-switch-row">
        <?php competition_switch($competitions, $competition, 'piloten.php'); ?>
    </div>
<?php endif; ?>
<?php if ($notCurrent): ?>
    <div class="flash info">Achtung: Du bearbeitest den Wettbewerb „<?= h($competition['name']) ?>“, nicht den aktiven Wettbewerb.
        <a href="wettbewerbe.php">Wettbewerbe ansehen</a></div>
<?php endif; ?>
<?php if ($competitionCompleted): ?>
    <div class="flash info">Dieser Wettbewerb ist abgeschlossen. Die Startliste und die Resultate sind gesperrt.</div>
<?php endif; ?>

<div class="panel"><?php if ($competitionCompleted): ?>
    <h3 style="margin-top:0">Startliste abgeschlossen</h3>
    <p class="lead">Für diesen Wettbewerb können keine Piloten mehr hinzugefügt, geändert oder gelöscht werden.
        Die vorhandenen Piloten und Resultate bleiben vollständig einsehbar.</p>
<?php else: ?>
    <h3 style="margin-top:0"><?= $edit ? 'Pilot bearbeiten' : 'Pilot aufnehmen' ?></h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : 0 ?>">
        <input type="hidden" name="competition" value="<?= (int) $competition['id'] ?>">
        <div class="grid-2">
            <div class="field">
                <label for="bib">Startnummer</label>
                <input type="text" id="bib" name="bib_number" value="<?= h($edit['bib_number'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="sm">SMV-Nummer</label>
                <input type="text" id="sm" name="smv_number" inputmode="numeric" maxlength="6"
                       value="<?= h($edit['smv_number'] ?? '') ?>" placeholder="ohne">
                <p class="hint">Bis zu sechs Ziffern. Leer lassen, wenn es keine gibt – angezeigt
                    wird dann 999999. Steht die Nummer schon in den Stammdaten, gehört der Pilot
                    zu diesem Eintrag, auch in anderen Jahren.</p>
            </div>
            <div class="field">
                <label for="fn">Vorname</label>
                <input type="text" id="fn" name="first_name" value="<?= h($edit['first_name'] ?? '') ?>" required>
            </div>
            <div class="field">
                <label for="ln">Name</label>
                <input type="text" id="ln" name="last_name" value="<?= h($edit['last_name'] ?? '') ?>" required>
                <p class="hint">Steht in den Stammdaten und gilt für alle Wettbewerbe. Ein Tippfehler
                    hier ist also gleich in allen Jahren des Piloten behoben.</p>
            </div>
            <div class="field">
                <label for="cl">Verein</label>
                <select id="cl" name="club_id">
                    <option value="">– neuer Verein unten –</option>
                    <?php foreach ($clubs as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= ($edit['club_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="cn">Neuer Verein</label>
                <input type="text" id="cn" name="club_new" placeholder="nur nötig, wenn oben keiner passt">
            </div>
            <div class="field">
                <label for="gr">Modelltyp</label>
                <select id="gr" name="model_type_id">
                    <option value="">– keiner –</option>
                    <?php foreach ($types as $g): ?>
                        <option value="<?= (int) $g['id'] ?>" <?= ($edit['model_type_id'] ?? '') == $g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="mo">Modell</label>
                <input type="text" id="mo" name="model_name" value="<?= h($edit['model_name'] ?? '') ?>">
            </div>
        </div>
        <div class="field">
            <label for="no">Bemerkung</label>
            <input type="text" id="no" name="notes" value="<?= h($edit['notes'] ?? '') ?>">
        </div>
        <div class="check">
            <input type="checkbox" id="ac" name="active" <?= ($edit === null || $edit['active']) ? 'checked' : '' ?>>
            <label for="ac">Nimmt am Wettbewerb teil (erscheint in Erfassung und Rangliste)</label>
        </div>
        <div class="btn-row">
            <button class="btn" type="submit"><?= $edit ? 'Änderungen speichern' : 'Pilot aufnehmen' ?></button>
            <?php if ($edit): ?><a class="btn ghost" href="piloten.php?competition=<?= (int) $competition['id'] ?>">Abbrechen</a><?php endif; ?>
        </div>
    </form>
<?php endif; ?>
</div>

<?php // Werkzeuge fuer die Liste selbst: Startnummern und Aufkleber-Export.
      // Sie gehoeren an die Tabelle, nicht an den Seitenkopf – dort wirken sie
      // ohne Zusammenhang und werden leicht uebersehen. ?>
<div class="row-between no-print" style="margin:18px 0 8px">
    <span class="small muted"><?= count($pilots) ?> Pilot<?= count($pilots) === 1 ? '' : 'en' ?><?= $inaktiv ? ', davon ' . $inaktiv . ' inaktiv' : '' ?></span>
    <div class="btn-row dense">
        <?php if (!$competitionCompleted): ?>
        <form method="post" style="display:inline"
              data-confirm="Startnummern aller aktiven Piloten dieses Wettbewerbs neu und zufällig vergeben (gruppiert nach Modelltyp)">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="autonumber">
            <input type="hidden" name="competition" value="<?= (int) $competition['id'] ?>">
            <button class="btn ghost small" type="submit">↻ Startnummern neu vergeben</button>
        </form>
        <?php endif; ?>
        <a class="btn ghost small" href="piloten.php?action=csv&amp;competition=<?= (int) $competition['id'] ?>">⇩ Startliste als CSV</a>
    </div>
</div>

<div class="panel" style="padding:0">
    <div class="table-scroll">
    <table class="data">
        <thead><tr><th class="num">Nr.</th><th>Pilot</th><th class="num">SMV</th><th>Verein</th><th>Modelltyp</th><th>Modell</th><th class="num">Resultate</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pilots as $p): ?>
            <tr<?= $p['active'] ? '' : ' class="muted"' ?>>
                <td class="num"><?= h($p['bib_number'] ?: '') ?></td>
                <td>
                    <?= h(full_name($p)) ?>
                    <?php if (!$p['active']): ?> <span class="tag off">inaktiv</span><?php endif; ?>
                </td>
                <td class="num small muted"><?= h(pilot_smv_anzeige($p['smv_number'] ?? null)) ?></td>
                <td class="small"><?= h($p['club_name'] ?: '') ?></td>
                <td class="small"><?= h($p['model_type_name'] ?: '–') ?></td>
                <td class="small"><?= h($p['model_name'] ?: '') ?></td>
                <td class="num"><?= (int) $p['score_count'] ?></td>
                <td class="nowrap no-print">
                    <?php if ($competitionCompleted): ?>
                        <span class="muted small">gesperrt</span>
                    <?php else: ?>
                        <a class="btn ghost small" href="?bearbeiten=<?= (int) $p['id'] ?>&competition=<?= (int) $competition['id'] ?>">Bearbeiten</a>
                        <form method="post" style="display:inline"
                              data-confirm="<?= h(full_name($p)) ?> und <?= (int) $p['score_count'] ?> Resultate löschen?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <input type="hidden" name="competition" value="<?= (int) $competition['id'] ?>">
                            <button class="btn danger small" type="submit">Löschen</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$pilots): ?>
            <tr><td colspan="9" class="muted" style="padding:20px">Noch niemand für diesen Wettbewerb gemeldet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php if (!$competitionCompleted): ?>
<div class="panel no-print">
    <h3 style="margin-top:0">Liste einfügen</h3>
    <p class="lead">Fügt Piloten für den Wettbewerb „<?= h($competition['name']) ?>“ hinzu. Eine Zeile pro Pilot, Felder mit
       Strichpunkt getrennt: <code>SMV-Nummer;Vorname;Name;Verein;Modelltyp;Startnummer</code>.
       Die SMV-Nummer darf fehlen, dann sind es fünf Spalten. Aus Excel kopierte Spalten
       funktionieren auch.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import">
        <input type="hidden" name="competition" value="<?= (int) $competition['id'] ?>">
        <div class="field">
            <textarea name="csv" placeholder="123456;Vorname;Nachname;Vereinsname;Schlepp;01&#10;123457;Vorname;Nachname;Vereinsname;Schlepp;02"></textarea>
        </div>
        <button class="btn ghost" type="submit">Importieren</button>
    </form>
</div>
<?php endif; ?>
<?php page_end();
