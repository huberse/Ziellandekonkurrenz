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
    if ($action === 'delete' && post('id') === '') {
        $_POST['id'] = post('delete_id');
    }

    if ($action === 'add') {
        $name = text_limit(post('name'), 80);
        if ($name === '') {
            flash('Der Modelltyp braucht einen Namen.', 'err');
        } else {
            try {
                $st = db()->prepare('INSERT INTO model_types (name, info, sort_order, active) VALUES (?,?,?,1)');
                $st->execute([$name, text_limit(post('info'), 200) ?: null, (int) post('sort_order', '0')]);
                flash('Modelltyp angelegt.', 'ok');
            } catch (PDOException $e) {
                flash('Diesen Modelltyp gibt es schon.', 'err');
            }
        }
    } elseif ($action === 'save') {
        $st = db()->prepare('UPDATE model_types SET name = ?, info = ?, sort_order = ?, active = ? WHERE id = ?');
        foreach (is_array($_POST['name_by_id'] ?? null) ? $_POST['name_by_id'] : [] as $gid => $name) {
            $gid = (int) $gid;
            $name = is_scalar($name) ? text_limit((string) $name, 80) : '';
            if ($name === '') { continue; }
            $infoRaw = $_POST['info_by_id'][$gid] ?? '';
            $info = is_scalar($infoRaw) ? text_limit((string) $infoRaw, 200) : '';
            $st->execute([
                $name,
                $info ?: null,
                (int) ($_POST['sort_by_id'][$gid] ?? 0),
                isset($_POST['active_by_id'][$gid]) ? 1 : 0,
                $gid,
            ]);
        }
        flash('Modelltypen gespeichert.', 'ok');
    } elseif ($action === 'delete') {
        $gid = (int) post('id');
        $pdo = db();
        try {
            $pdo->beginTransaction();
            lock_all_competitions($pdo);
            if (global_category_used_in_completed('model_type', $gid)) {
                throw new DomainException('Dieser Modelltyp wird in abgeschlossenen Wettbewerben verwendet und kann nicht gelöscht werden.');
            }
            $st = $pdo->prepare('SELECT COUNT(*) FROM pilots WHERE model_type_id = ?');
            $st->execute([$gid]);
            $n = (int) $st->fetchColumn();
            $up = $pdo->prepare('UPDATE registrations SET model_type_id = NULL WHERE model_type_id = ?');
            $up->execute([$gid]);
            $d = $pdo->prepare('DELETE FROM model_types WHERE id = ?');
            $d->execute([$gid]);
            $pdo->commit();
            flash($n ? "Modelltyp gelöscht. $n Piloten sind jetzt ohne Typ." : 'Modelltyp gelöscht.', $n ? 'info' : 'ok');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash($e instanceof DomainException ? $e->getMessage() : 'Der Modelltyp konnte nicht gelöscht werden.', 'err');
        }
    }
    redirect('modelltypen.php' . $competitionQS);
}

$types = db()->prepare('SELECT t.*, (SELECT COUNT(*) FROM pilots p WHERE p.model_type_id = t.id AND p.competition_id = ?) AS pilot_count
                       FROM model_types t ORDER BY t.sort_order, t.name');
$types->execute([(int) $competition['id']]);
$types = $types->fetchAll();

page_start('Modelltypen', 'admin', 'modelltypen.php');
?>
<h2>Modelltypen<?= $notCurrent ? ' – Wettbewerb ' . h($competition['name']) : '' ?></h2>
<?php if ($notCurrent): ?>
    <div class="flash info">Du bearbeitest den Wettbewerb „<?= h($competition['name']) ?>“, nicht den aktiven Wettbewerb.</div>
<?php endif; ?>
<p class="lead">Modelltypen bleiben global. Ein Modelltyp, der in einem abgeschlossenen Wettbewerb verwendet wird,
    kann dort nicht gelöscht werden; die historische Zuordnung bleibt erhalten.
    Die Rangliste wird je Modelltyp ausgewertet. Die Sortierung bestimmt die Reihenfolge in Listen und Laufzetteln.</p>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="panel" style="padding:0">
        <div class="table-scroll">
        <table class="data dense">
            <thead><tr><th>Name</th><th>Erklärung</th><th>Reihenfolge</th><th class="mid">Zur Auswahl</th><th class="num">Piloten <?= h($competition['name']) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($types as $g): $gid = (int) $g['id']; ?>
                <tr>
                    <td><input type="text" name="name_by_id[<?= $gid ?>]" value="<?= h($g['name']) ?>"></td>
                    <td><input type="text" name="info_by_id[<?= $gid ?>]" value="<?= h($g['info'] ?? '') ?>" placeholder="z.B. Start am Schlepp"></td>
                    <td><input type="number" name="sort_by_id[<?= $gid ?>]" value="<?= (int) $g['sort_order'] ?>" style="width:5rem"></td>
                    <td class="mid"><input type="checkbox" name="active_by_id[<?= $gid ?>]" <?= $g['active'] ? 'checked' : '' ?>></td>
                    <td class="num"><?= (int) $g['pilot_count'] ?></td>
                    <td class="no-print">
                        <button class="btn danger" type="submit" name="delete_id" value="<?= $gid ?>"
                                data-confirm-click="Modelltyp <?= h($g['name']) ?> löschen?">Löschen</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php if ($types): ?><div class="btn-row"><button class="btn" type="submit">Speichern</button></div><?php endif; ?>
</form>

<div class="panel" style="margin-top:20px">
    <h3 style="margin-top:0">Modelltyp hinzufügen</h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="grid-2">
            <div class="field"><label for="n">Name</label><input type="text" id="n" name="name" placeholder="z.B. Elektrisch" required></div>
            <div class="field"><label for="i">Erklärung</label><input type="text" id="i" name="info"></div>
            <div class="field"><label for="s">Reihenfolge</label><input type="number" id="s" name="sort_order" value="<?= (count($types) + 1) * 10 ?>"></div>
        </div>
        <button class="btn ghost" type="submit">Hinzufügen</button>
    </form>
</div>
<?php page_end();
