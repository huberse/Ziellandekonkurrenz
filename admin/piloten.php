<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/scoring.php';
require_once __DIR__ . '/../lib/layout.php';
require_login();

$types = all_model_types();
$clubs = all_clubs();
$competition = resolve_competition_param(competition_request_param());
$competitionCompleted = competition_is_completed((int) $competition['id']);

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
                'first_name' => text_limit(post('first_name'), 80),
                'last_name'  => text_limit(post('last_name'), 80),
                'club_id'    => post('club_id') !== '' ? (int) post('club_id') : club_id_for_name(text_limit(post('club_new'), 120)),
                'email'      => text_limit(post('email'), 160) ?: null,
                'phone'      => text_limit(post('phone'), 40) ?: null,
                'model_type_id' => post('model_type_id') !== '' ? (int) post('model_type_id') : null,
                'model_name' => text_limit(post('model_name'), 120) ?: null,
                'notes'      => text_limit(post('notes'), 255) ?: null,
                'active'     => isset($_POST['active']) ? 1 : 0,
                'competition_id'  => $competition['id'],
            ];
            $validClubIds = array_map('intval', array_column($clubs, 'id'));
            $validTypeIds = array_map('intval', array_column($types, 'id'));
            if ($data['first_name'] === '' || $data['last_name'] === '') {
                throw new DomainException('Ohne Namen geht es nicht.');
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
            if ($id) {
                $sql = 'UPDATE pilots SET bib_number=:bib_number, first_name=:first_name, last_name=:last_name,
                        club_id=:club_id, email=:email, phone=:phone, model_type_id=:model_type_id, model_name=:model_name,
                        notes=:notes, active=:active WHERE id=:id AND competition_id=:competition_id';
                $st = $pdo->prepare($sql);
                $st->execute($data + ['id' => $id]);
                if ($st->rowCount() === 0) {
                    $check = $pdo->prepare('SELECT id FROM pilots WHERE id = ? AND competition_id = ?');
                    $check->execute([$id, $competition['id']]);
                    if (!$check->fetchColumn()) {
                        throw new DomainException('Der Pilot gehört nicht zu diesem Wettbewerb.');
                    }
                }
                $successMessage = 'Pilot gespeichert.';
            } else {
                $st = $pdo->prepare('INSERT INTO pilots (bib_number, first_name, last_name, club_id, email, phone, model_type_id, model_name, notes, active, competition_id)
                                     VALUES (:bib_number,:first_name,:last_name,:club_id,:email,:phone,:model_type_id,:model_name,:notes,:active,:competition_id)');
                $st->execute($data);
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
            $up = $pdo->prepare('UPDATE pilots SET bib_number = ? WHERE id = ? AND competition_id = ?');
            $n = 0;
            foreach ($st as $row) {
                $pilotId = (int) $row['id'];
                do {
                    $bib = str_pad((string) (++$n), 2, '0', STR_PAD_LEFT);
                } while (competition_bib_number_exists((int) $competition['id'], $bib, $pilotId));
                $up->execute([$bib, $pilotId, $competition['id']]);
            }
            $successMessage = "$n Startnummern neu und zufällig vergeben, gruppiert nach Modelltyp.";

        } elseif ($action === 'import') {
            $raw = post('csv');
            $lines = preg_split('/\r\n|\r|\n/', $raw);
            $st = $pdo->prepare('INSERT INTO pilots (first_name, last_name, club_id, model_type_id, bib_number, competition_id) VALUES (?,?,?,?,?,?)');
            $typeByName = [];
            foreach ($types as $g) { $typeByName[mb_strtolower($g['name'])] = (int) $g['id']; }
            $n = 0; $skipped = 0;
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') { continue; }
                $cols = array_map('trim', preg_split('/[;\t]/', $line));
                if (mb_strtolower($cols[0]) === 'vorname' || mb_strtolower($cols[0]) === 'name') { continue; }
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
                $st->execute([$first, $last, $clubId, $gid, $bib, $competition['id']]);
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
    $st = db()->prepare('SELECT * FROM pilots WHERE id = ? AND competition_id = ?');
    $st->execute([$editId, $competition['id']]);
    $edit = $st->fetch() ?: null;
}

$st = db()->prepare('SELECT p.*, t.name AS model_type_name, c.name AS club_name,
                       (SELECT COUNT(*) FROM scores s WHERE s.pilot_id = p.id) AS score_count
                       FROM pilots p
                       LEFT JOIN model_types t ON t.id = p.model_type_id
                       LEFT JOIN clubs c ON c.id = p.club_id
                       WHERE p.competition_id = ?
                       ORDER BY t.sort_order, t.name, p.bib_number + 0, p.bib_number, p.last_name');
$st->execute([$competition['id']]);
$pilots = $st->fetchAll();

$competitions = all_competitions();
$notCurrent = (int) $competition['id'] !== current_competition_id();

page_start('Piloten', 'admin', 'piloten.php');
?>
<div class="row-between">
    <div>
        <h2>Piloten<?= $notCurrent ? ' – Wettbewerb ' . h($competition['name']) : '' ?></h2>
        <p class="lead"><?= count($pilots) ?> für den Wettbewerb „<?= h($competition['name']) ?>“ gemeldet.
            Piloten gelten nur für diesen Wettbewerb – für einen neuen Wettbewerb meldet sich jeder wieder neu an,
            entweder über <a href="../anmeldung.php?competition=<?= (int) $competition['id'] ?>">das Anmeldeformular</a> oder hier direkt.</p>
    </div>
    <?php if (!$competitionCompleted): ?>
    <form method="post" class="no-print" data-confirm="Startnummern aller aktiven Piloten dieses Wettbewerbs neu und zufällig vergeben (gruppiert nach Modelltyp)?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="autonumber">
        <input type="hidden" name="competition" value="<?= (int) $competition['id'] ?>">
        <button class="btn ghost small" type="submit">Startnummern zufällig neu vergeben</button>
    </form>
    <?php endif; ?>
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
                <label for="fn">Vorname</label>
                <input type="text" id="fn" name="first_name" value="<?= h($edit['first_name'] ?? '') ?>" required>
            </div>
            <div class="field">
                <label for="ln">Name</label>
                <input type="text" id="ln" name="last_name" value="<?= h($edit['last_name'] ?? '') ?>" required>
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
            <div class="field">
                <label for="em">E-Mail</label>
                <input type="email" id="em" name="email" value="<?= h($edit['email'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="ph">Telefon</label>
                <input type="tel" id="ph" name="phone" value="<?= h($edit['phone'] ?? '') ?>">
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

<div class="panel" style="padding:0">
    <div class="table-scroll">
    <table class="data">
        <thead><tr><th class="num">Nr.</th><th>Pilot</th><th>Verein</th><th>Modelltyp</th><th>Modell</th><th>Kontakt</th><th class="num">Resultate</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pilots as $p): ?>
            <tr<?= $p['active'] ? '' : ' class="muted"' ?>>
                <td class="num"><?= h($p['bib_number'] ?: '') ?></td>
                <td>
                    <?= h(full_name($p)) ?>
                    <?php if (!$p['active']): ?> <span class="tag off">inaktiv</span><?php endif; ?>
                </td>
                <td class="small"><?= h($p['club_name'] ?: '') ?></td>
                <td class="small"><?= h($p['model_type_name'] ?: '–') ?></td>
                <td class="small"><?= h($p['model_name'] ?: '') ?></td>
                <td class="small muted"><?= h($p['email'] ?: $p['phone'] ?: '') ?></td>
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
            <tr><td colspan="8" class="muted" style="padding:20px">Noch niemand für diesen Wettbewerb gemeldet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php if (!$competitionCompleted): ?>
<div class="panel no-print">
    <h3 style="margin-top:0">Liste einfügen</h3>
    <p class="lead">Fügt Piloten für den Wettbewerb „<?= h($competition['name']) ?>“ hinzu. Eine Zeile pro Pilot, Felder mit
       Strichpunkt getrennt: <code>Vorname;Name;Verein;Modelltyp;Startnummer</code>.
       Aus Excel kopierte Spalten funktionieren auch.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import">
        <input type="hidden" name="competition" value="<?= (int) $competition['id'] ?>">
        <div class="field">
            <textarea name="csv" placeholder="Serge;Huber;MFV Brislach;Schlepp;01"></textarea>
        </div>
        <button class="btn ghost" type="submit">Importieren</button>
    </form>
</div>
<?php endif; ?>
<?php page_end();
