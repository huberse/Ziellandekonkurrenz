<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/scoring.php';
require_once __DIR__ . '/../lib/layout.php';
require_login();
$competition = resolve_competition_param(competition_request_param());
$competitionCompleted = competition_is_completed((int) $competition['id']);
$competitions = all_competitions();
$completedCompetitionIds = [];
foreach ($competitions as $knownCompetition) {
    if ($knownCompetition['completed_at'] !== null) {
        $completedCompetitionIds[(int) $knownCompetition['id']] = true;
    }
}
$showAll = get('alle', '') === '1';
$competitionQS = (int) $competition['id'] !== current_competition_id() ? '?competition=' . (int) $competition['id'] : '';
$alleQS = $showAll ? ($competitionQS !== '' ? '&alle=1' : '?alle=1') : '';
$listQS = $competitionQS . $alleQS;

function run_registration_mutation(int $competitionId, callable $mutation): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        lock_open_competition($pdo, $competitionId);
        $mutation($pdo);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) post('id');
    $action = post('action');

    $st = db()->prepare('SELECT * FROM registrations WHERE id = ?');
    $st->execute([$id]);
    $reg = $st->fetch();
    $regCompetitionId = $reg ? ($reg['competition_id'] !== null ? (int) $reg['competition_id'] : (int) $competition['id']) : 0;
    if (($competitionCompleted && (!$showAll || ($reg && $regCompetitionId === (int) $competition['id'])))
        || ($reg && $regCompetitionId > 0 && isset($completedCompetitionIds[$regCompetitionId]))) {
        flash('Dieser Wettbewerb ist abgeschlossen. Anmeldungen können nicht mehr geändert werden.', 'err');
        redirect('anmeldungen.php' . $listQS);
    }

    if ($reg && $action === 'approve') {
        if ($reg['status'] !== 'pending') {
            flash('Diese Anmeldung ist nicht mehr offen.', 'err');
            redirect('anmeldungen.php' . $listQS);
        }

        $pilotCompetitionId = $reg['competition_id'] !== null ? (int) $reg['competition_id'] : (int) $competition['id'];
        if (!find_competition($pilotCompetitionId)) {
            flash('Der Wettbewerb dieser Anmeldung existiert nicht mehr.', 'err');
            redirect('anmeldungen.php' . $listQS);
        }

        $firstName = text_limit((string) $reg['first_name'], 80);
        $lastName = text_limit((string) $reg['last_name'], 80);
        if ($firstName === '' || $lastName === '') {
            flash('Die Anmeldung braucht einen Vor- und Nachnamen.', 'err');
            redirect('anmeldungen.php' . $listQS);
        }

        $clubId = null;
        if ($reg['club_id'] !== null && $reg['club_id'] !== '') {
            $clubId = (int) $reg['club_id'];
            $clubCheck = db()->prepare('SELECT id FROM clubs WHERE id = ?');
            $clubCheck->execute([$clubId]);
            if (!$clubCheck->fetchColumn()) {
                flash('Der Verein dieser Anmeldung existiert nicht mehr.', 'err');
                redirect('anmeldungen.php' . $listQS);
            }
        } else {
            $clubId = club_id_for_name(text_limit((string) $reg['club'], 120));
        }

        $modelTypeId = null;
        if ($reg['model_type_id'] !== null && $reg['model_type_id'] !== '') {
            $modelTypeId = (int) $reg['model_type_id'];
            $typeCheck = db()->prepare('SELECT id FROM model_types WHERE id = ?');
            $typeCheck->execute([$modelTypeId]);
            if (!$typeCheck->fetchColumn()) {
                flash('Der Modelltyp dieser Anmeldung existiert nicht mehr.', 'err');
                redirect('anmeldungen.php' . $listQS);
            }
        }

        $pdo = db();
        $bib = null;
        try {
            $pdo->beginTransaction();
            lock_open_competition($pdo, $pilotCompetitionId);
            // Nächste freie Startnummer innerhalb dieses Wettbewerbs.
            $nextSt = $pdo->prepare("SELECT bib_number FROM pilots
                                     WHERE competition_id = ? AND bib_number REGEXP '^[0-9]+$'
                                     ORDER BY bib_number + 0 DESC LIMIT 1 FOR UPDATE");
            $nextSt->execute([$pilotCompetitionId]);
            $lastBib = $nextSt->fetchColumn();
            $next = $lastBib !== false ? ((int) $lastBib + 1) : 1;
            $bibInput = text_limit(post('bib_number'), 10);
            $bib = $bibInput !== '' ? $bibInput : str_pad((string) $next, 2, '0', STR_PAD_LEFT);
            if (competition_bib_number_exists($pilotCompetitionId, $bib)) {
                throw new RuntimeException('Diese Startnummer ist in diesem Wettbewerb bereits vergeben.');
            }

            $ins = $pdo->prepare('INSERT INTO pilots (bib_number, first_name, last_name, club_id, email, phone, model_type_id, model_name, notes, competition_id)
                                  VALUES (?,?,?,?,?,?,?,?,?,?)');
            $ins->execute([$bib, $firstName, $lastName, $clubId, text_limit((string) $reg['email'], 160) ?: null,
                text_limit((string) $reg['phone'], 40) ?: null, $modelTypeId, text_limit((string) $reg['model_name'], 120) ?: null,
                text_limit((string) $reg['notes'], 255) ?: null, $pilotCompetitionId]);
            $pid = (int) $pdo->lastInsertId();

            $up = $pdo->prepare("UPDATE registrations SET status='approved', pilot_id=?, competition_id=?, decided_at=NOW()
                                  WHERE id=? AND status='pending'");
            $up->execute([$pid, $pilotCompetitionId, $id]);
            if ($up->rowCount() !== 1) {
                throw new RuntimeException('Anmeldung wurde parallel bearbeitet.');
            }
            $pdo->commit();
            flash(trim($firstName . ' ' . $lastName) . " ist mit Startnummer $bib in der Startliste.", 'ok');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash($e instanceof DomainException
                ? $e->getMessage()
                : 'Die Anmeldung konnte nicht freigegeben werden.', 'err');
        }

    } elseif ($reg && $action === 'reject') {
        if ($reg['status'] !== 'pending') {
            flash('Nur offene Anmeldungen können abgelehnt werden.', 'err');
        } else {
            try {
                run_registration_mutation($regCompetitionId, function (PDO $pdo) use ($id): void {
                    $up = $pdo->prepare("UPDATE registrations SET status='rejected', decided_at=NOW() WHERE id=? AND status='pending'");
                    $up->execute([$id]);
                    if ($up->rowCount() !== 1) {
                        throw new DomainException('Die Anmeldung wurde parallel bearbeitet.');
                    }
                });
                flash('Anmeldung abgelehnt.', 'ok');
            } catch (Throwable $e) {
                flash($e instanceof DomainException ? $e->getMessage() : 'Die Anmeldung konnte nicht abgelehnt werden.', 'err');
            }
        }

    } elseif ($reg && $action === 'reopen') {
        if ($reg['status'] !== 'rejected') {
            flash('Nur abgelehnte Anmeldungen können wieder geöffnet werden.', 'err');
        } else {
            try {
                run_registration_mutation($regCompetitionId, function (PDO $pdo) use ($id): void {
                    $up = $pdo->prepare("UPDATE registrations SET status='pending', pilot_id=NULL, decided_at=NULL WHERE id=? AND status='rejected'");
                    $up->execute([$id]);
                    if ($up->rowCount() !== 1) {
                        throw new DomainException('Die Anmeldung wurde parallel bearbeitet.');
                    }
                });
                flash('Anmeldung wieder offen.', 'ok');
            } catch (Throwable $e) {
                flash($e instanceof DomainException ? $e->getMessage() : 'Die Anmeldung konnte nicht geöffnet werden.', 'err');
            }
        }

    } elseif ($reg && $action === 'delete') {
        if ($reg['status'] === 'approved') {
            flash('Freigegebene Anmeldungen können erst nach dem Löschen des Piloten entfernt werden.', 'err');
        } else {
            try {
                run_registration_mutation($regCompetitionId, function (PDO $pdo) use ($id): void {
                    $d = $pdo->prepare('DELETE FROM registrations WHERE id = ?');
                    $d->execute([$id]);
                    if ($d->rowCount() !== 1) {
                        throw new DomainException('Die Anmeldung wurde parallel bearbeitet.');
                    }
                });
                flash('Anmeldung gelöscht.', 'ok');
            } catch (Throwable $e) {
                flash($e instanceof DomainException ? $e->getMessage() : 'Die Anmeldung konnte nicht gelöscht werden.', 'err');
            }
        }
    }
    redirect('anmeldungen.php' . $listQS);
}

$sql = 'SELECT r.*, t.name AS model_type_name, c.name AS club_name, sea.name AS competition_name FROM registrations r
        LEFT JOIN model_types t ON t.id = r.model_type_id
        LEFT JOIN clubs c ON c.id = r.club_id
        LEFT JOIN competitions sea ON sea.id = r.competition_id';
$args = [];
if (!$showAll) {
    $sql .= ' WHERE r.competition_id = ?';
    $args[] = $competition['id'];
}
$sql .= " ORDER BY CASE r.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END, r.created_at DESC";
$st = db()->prepare($sql);
$st->execute($args);
$regs = $st->fetchAll();

$open = !$competitionCompleted && setting_bool('registration_open', true);

page_start('Anmeldungen', 'admin', 'anmeldungen.php');
?>
<div class="row-between">
    <div>
        <h2>Anmeldungen<?= $showAll ? '' : ' – Wettbewerb ' . h($competition['name']) ?></h2>
        <p class="lead">Freigegebene Anmeldungen wandern in die Startliste.
            Das Formular liegt unter <code>anmeldung.php</code>. Das Formular speichert keine Kontaktdaten:
            die E-Mail-Adresse dient nur der Bestätigung.</p>
    </div>
    <div class="btn-row">
        <span class="tag <?= $competitionCompleted ? 'off' : ($open ? 'on' : 'off') ?>">
            <?= $competitionCompleted ? 'Wettbewerb abgeschlossen' : ($open ? 'Anmeldung offen' : 'Anmeldung geschlossen') ?>
        </span>
        <a class="btn ghost small" href="einstellungen.php<?= $competitionQS ?>#anmeldung">Ändern</a>
        <a class="btn ghost small" href="../anmeldung.php<?= $competitionQS ?>">Formular ansehen ↗</a>
        <a class="btn <?= $showAll ? '' : 'ghost' ?> small" href="<?= $competitionQS ?><?= $showAll ? ($competitionQS !== '' ? '&' : '?') . 'alle=0' : ($competitionQS !== '' ? '&' : '?') . 'alle=1' ?>">
            <?= $showAll ? 'Nur den aktiven Wettbewerb' : 'Alle Wettbewerbe anzeigen' ?>
        </a>
    </div>
</div>
<?php if ($competitionCompleted): ?>
    <div class="flash info">Für diesen Wettbewerb werden keine Anmeldungen mehr angenommen oder geändert.</div>
<?php endif; ?>

<div class="panel" style="padding:0">
<?php if (!$regs): ?>
    <p class="lead" style="padding:20px">Noch keine Anmeldungen eingegangen.</p>
<?php else: ?>
    <div class="table-scroll">
    <table class="data">
        <thead><tr><th>Eingegangen</th><?php if ($showAll): ?><th>Wettbewerb</th><?php endif; ?><th>Pilot</th><th>Verein</th><th>Modelltyp</th><th>Modell</th><th>Kontakt</th><th>Bemerkung</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($regs as $r):
            $regCompetitionId = $r['competition_id'] !== null ? (int) $r['competition_id'] : (int) $competition['id'];
            $regCompleted = $regCompetitionId > 0 && isset($completedCompetitionIds[$regCompetitionId]);
            if ($competitionCompleted && $regCompetitionId === (int) $competition['id']) {
                $regCompleted = true;
            }
        ?>
            <tr>
                <td class="small nowrap"><?= h(date('d.m.Y H:i', strtotime($r['created_at']))) ?></td>
                <?php if ($showAll): ?><td class="small muted"><?= h($r['competition_name'] ?? 'Nicht zugeordnet') ?></td><?php endif; ?>
                <td class="nowrap"><?= h(trim($r['first_name'] . ' ' . $r['last_name'])) ?></td>
                <td class="small"><?= h($r['club_name'] ?: $r['club'] ?: '') ?><?= $r['club_id'] === null && $r['club'] ? ' <span class="tag live">neu</span>' : '' ?></td>
                <td class="small"><?= h($r['model_type_name'] ?: '–') ?></td>
                <td class="small"><?= h($r['model_name'] ?: '') ?></td>
                <td class="small muted"><?= ($r['email'] || $r['phone'])
                        ? h(trim((string) $r['email'] . ' ' . (string) $r['phone']))
                        : '<span class="muted">–</span>' ?></td>
                <td class="small muted"><?= h(mb_strimwidth((string) $r['notes'], 0, 60, '…')) ?></td>
                <td>
                    <?php if ($regCompleted): ?><span class="tag off">abgeschlossen</span>
                    <?php elseif ($r['status'] === 'pending'): ?><span class="tag live">offen</span>
                    <?php elseif ($r['status'] === 'approved'): ?><span class="tag on">aufgenommen</span>
                    <?php else: ?><span class="tag off">abgelehnt</span><?php endif; ?>
                </td>
                <td class="nowrap no-print">
                    <?php if ($regCompleted): ?>
                        <span class="tag off">gesperrt</span>
                    <?php else: ?>
                    <form method="post" class="dense" style="display:flex;gap:4px;align-items:center">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <input type="hidden" name="competition" value="<?= (int) $competition['id'] ?>">
                        <?php if ($r['status'] === 'pending'): ?>
                            <input type="text" name="bib_number" placeholder="Nr." style="width:4.5rem" title="Startnummer, leer = automatisch">
                            <button class="btn" type="submit" name="action" value="approve">Aufnehmen</button>
                            <button class="btn ghost" type="submit" name="action" value="reject">Ablehnen</button>
                        <?php elseif ($r['status'] === 'rejected'): ?>
                            <button class="btn ghost" type="submit" name="action" value="reopen">Zurücksetzen</button>
                            <button class="btn danger" type="submit" name="action" value="delete"
                                    data-confirm-click="Anmeldung löschen?">Löschen</button>
                        <?php else: ?>
                            <span class="muted small">bereits aufgenommen</span>
                        <?php endif; ?>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</div>
<?php page_end();
