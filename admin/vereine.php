<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/scoring.php';
require_once __DIR__ . '/../lib/layout.php';
require_login();
$competition = resolve_competition_param(competition_request_param());
$notCurrent = (int) $competition['id'] !== current_competition_id();
$competitionQS = $notCurrent ? '?competition=' . (int) $competition['id'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('delete_id') !== '' ? 'delete' : post('action');

    if ($action === 'add') {
        $name = text_limit(post('name'), 120);
        if ($name === '') {
            flash('Der Verein braucht einen Namen.', 'err');
        } else {
            try {
                $st = db()->prepare('INSERT INTO clubs (name, short_name, place, sort_order) VALUES (?,?,?,?)');
                $st->execute([$name, text_limit(post('short_name'), 30) ?: null, text_limit(post('place'), 120) ?: null, (int) post('sort_order', '0')]);
                flash('Verein angelegt.', 'ok');
            } catch (PDOException $e) {
                flash('Diesen Verein gibt es schon.', 'err');
            }
        }

    } elseif ($action === 'save') {
        $st = db()->prepare('UPDATE clubs SET name = ?, short_name = ?, place = ?, sort_order = ?, active = ? WHERE id = ?');
        foreach (is_array($_POST['name_by_id'] ?? null) ? $_POST['name_by_id'] : [] as $cid => $name) {
            $cid = (int) $cid;
            $name = is_scalar($name) ? text_limit((string) $name, 120) : '';
            if ($name === '') { continue; }
            $shortRaw = $_POST['short_by_id'][$cid] ?? '';
            $placeRaw = $_POST['place_by_id'][$cid] ?? '';
            $short = is_scalar($shortRaw) ? text_limit((string) $shortRaw, 30) : '';
            $place = is_scalar($placeRaw) ? text_limit((string) $placeRaw, 120) : '';
            $st->execute([
                $name,
                $short ?: null,
                $place ?: null,
                (int) ($_POST['sort_by_id'][$cid] ?? 0),
                isset($_POST['active_by_id'][$cid]) ? 1 : 0,
                $cid,
            ]);
        }
        flash('Vereine gespeichert.', 'ok');

    } elseif ($action === 'delete') {
        $cid = (int) (post('delete_id') ?: post('id'));
        $pdo = db();
        try {
            $pdo->beginTransaction();
            lock_all_competitions($pdo);
            if (global_category_used_in_completed('club', $cid)) {
                throw new DomainException('Dieser Verein wird in abgeschlossenen Wettbewerben verwendet und kann nicht gelöscht werden.');
            }
            $st = $pdo->prepare('SELECT COUNT(*) FROM pilots WHERE club_id = ?');
            $st->execute([$cid]);
            $n = (int) $st->fetchColumn();
            $club = $pdo->prepare('SELECT name FROM clubs WHERE id = ?');
            $club->execute([$cid]);
            $clubName = (string) ($club->fetchColumn() ?: '');
            $up = $pdo->prepare('UPDATE registrations SET club_id = NULL, club = ? WHERE club_id = ?');
            $up->execute([$clubName ?: null, $cid]);
            $d = $pdo->prepare('DELETE FROM clubs WHERE id = ?');
            $d->execute([$cid]);
            $pdo->commit();
            flash($n ? "Verein gelöscht. $n Piloten sind jetzt ohne Verein." : 'Verein gelöscht.', $n ? 'info' : 'ok');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash($e instanceof DomainException ? $e->getMessage() : 'Der Verein konnte nicht gelöscht werden.', 'err');
        }

    } elseif ($action === 'merge') {
        $from = (int) post('from_id');
        $to   = (int) post('to_id');
        if ($from && $to && $from !== $to) {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                lock_all_competitions($pdo);
                if (global_category_used_in_completed('club', $from)
                    || global_category_used_in_completed('club', $to)) {
                    throw new DomainException('Die Vereine werden in abgeschlossenen Wettbewerben verwendet und können nicht zusammengeführt werden.');
                }
                $st = $pdo->prepare('UPDATE pilots SET club_id = ? WHERE club_id = ?');
                $st->execute([$to, $from]);
                $moved = $st->rowCount();
                $st = $pdo->prepare('UPDATE registrations SET club_id = ? WHERE club_id = ?');
                $st->execute([$to, $from]);
                $d = $pdo->prepare('DELETE FROM clubs WHERE id = ?');
                $d->execute([$from]);
                $pdo->commit();
                flash("$moved Piloten umgehängt, doppelter Verein entfernt.", 'ok');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash($e instanceof DomainException ? $e->getMessage() : 'Die Vereine konnten nicht zusammengeführt werden.', 'err');
            }
        } else {
            flash('Bitte zwei verschiedene Vereine wählen.', 'err');
        }
    }
    redirect('vereine.php' . $competitionQS);
}

$clubs = db()->prepare('SELECT c.*,
                        (SELECT COUNT(*) FROM pilots p WHERE p.club_id = c.id AND p.active = 1 AND p.competition_id = ?) AS pilot_count
                      FROM clubs c ORDER BY c.sort_order, c.name');
$clubs->execute([(int) $competition['id']]);
$clubs = $clubs->fetchAll();

$count = (int) setting_num('club_scoring_count', 3);

page_start('Vereine', 'admin', 'vereine.php');
?>
<h2>Vereine<?= $notCurrent ? ' – Wettbewerb ' . h($competition['name']) : '' ?></h2>
<?php if ($notCurrent): ?>
    <div class="flash info">Du bearbeitest den Wettbewerb „<?= h($competition['name']) ?>“, nicht den aktiven Wettbewerb.</div>
<?php endif; ?>
<p class="lead">Vereine bleiben global. Ein Verein, der in einem abgeschlossenen Wettbewerb verwendet wird,
    kann dort nicht gelöscht oder zusammengeführt werden; die historische Zuordnung bleibt erhalten.
    Für die Vereinswertung zählen die <?= $count ?> besten Piloten eines Vereins.
    Ein Verein braucht also mindestens <?= $count ?> gewertete Piloten, sonst erscheint er ausser Konkurrenz.
    Die Anzahl änderst du unter <a href="einstellungen.php<?= $competitionQS ?>#vereinswertung">Einstellungen</a>.</p>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="panel" style="padding:0">
        <div class="table-scroll">
        <table class="data dense">
            <thead><tr><th>Name</th><th>Kürzel</th><th>Ort</th><th>Reihenfolge</th><th class="mid">Zur Auswahl</th><th class="num">Piloten <?= h($competition['name']) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($clubs as $c): $cid = (int) $c['id']; ?>
                <tr>
                    <td><input type="text" name="name_by_id[<?= $cid ?>]" value="<?= h($c['name']) ?>"></td>
                    <td><input type="text" name="short_by_id[<?= $cid ?>]" value="<?= h($c['short_name'] ?? '') ?>" style="width:7rem" placeholder="MFVB"></td>
                    <td><input type="text" name="place_by_id[<?= $cid ?>]" value="<?= h($c['place'] ?? '') ?>"></td>
                    <td><input type="number" name="sort_by_id[<?= $cid ?>]" value="<?= (int) $c['sort_order'] ?>" style="width:5rem"></td>
                    <td class="mid"><input type="checkbox" name="active_by_id[<?= $cid ?>]" <?= $c['active'] ? 'checked' : '' ?>></td>
                    <td class="num<?= (int) $c['pilot_count'] < $count ? ' cell-missing' : '' ?>"><?= (int) $c['pilot_count'] ?></td>
                    <td class="no-print">
                        <button class="btn danger" type="submit" name="delete_id" value="<?= $cid ?>"
                                data-confirm-click="<?= h($c['name']) ?> löschen? Die Piloten bleiben, aber ohne Verein.">Löschen</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$clubs): ?>
                <tr><td colspan="7" class="muted" style="padding:20px">Noch keine Vereine. Lege unten den ersten an,
                    oder importiere Piloten mit Vereinsnamen – dann entstehen die Vereine automatisch.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php if ($clubs): ?><div class="btn-row"><button class="btn" type="submit">Speichern</button></div><?php endif; ?>
</form>

<div class="split" style="margin-top:20px">
    <div class="panel">
        <h3 style="margin-top:0">Verein hinzufügen</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <div class="grid-2">
                <div class="field"><label for="n">Name</label><input type="text" id="n" name="name" placeholder="MFV Brislach" required></div>
                <div class="field"><label for="k">Kürzel</label><input type="text" id="k" name="short_name" placeholder="MFVB"></div>
                <div class="field"><label for="o">Ort</label><input type="text" id="o" name="place"></div>
                <div class="field"><label for="s">Reihenfolge</label><input type="number" id="s" name="sort_order" value="<?= (count($clubs) + 1) * 10 ?>"></div>
            </div>
            <button class="btn ghost" type="submit">Hinzufügen</button>
        </form>
    </div>

    <div class="panel">
        <h3 style="margin-top:0">Doppelten Verein zusammenlegen</h3>
        <p class="lead">Wenn derselbe Verein zweimal erfasst wurde, etwa mit Tippfehler aus einer Anmeldung.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="merge">
            <div class="grid-2">
                <div class="field">
                    <label for="f">Diesen Verein auflösen</label>
                    <select id="f" name="from_id">
                        <?php foreach ($clubs as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"><?= h($c['name']) ?> (<?= (int) $c['pilot_count'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="t">und die Piloten hierhin</label>
                    <select id="t" name="to_id">
                        <?php foreach ($clubs as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"><?= h($c['name']) ?> (<?= (int) $c['pilot_count'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button class="btn ghost" type="submit" data-confirm-click="Vereine zusammenlegen?">Zusammenlegen</button>
        </form>
    </div>
</div>
<?php page_end();
