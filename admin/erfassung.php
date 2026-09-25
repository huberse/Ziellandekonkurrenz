<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/scoring.php';
require_once __DIR__ . '/../lib/layout.php';
require_login();

$competition = resolve_competition_param(competition_request_param());
$competitionCompleted = competition_is_completed((int) $competition['id']);
$rounds = all_rounds($competition['id']);
if (!$rounds) {
    flash('Für diesen Wettbewerb sind noch keine Durchgänge angelegt.', 'info');
    redirect('durchgaenge.php?competition=' . $competition['id']);
}

// Durchgang bestimmen: Parameter, sonst der freigegebene, sonst der erste
$roundId = (int) get('dg', '0');
$round = null;
foreach ($rounds as $r) {
    if ((int) $r['id'] === $roundId) { $round = $r; }
}
if (!$round) {
    foreach ($rounds as $r) {
        if ($r['is_active']) { $round = $r; break; }
    }
}
$round = $round ?: $rounds[0];
$roundId = (int) $round['id'];
$target = (int) $round['target_time_seconds'];

$validPilotIds = [];
$pilotIdStmt = db()->prepare('SELECT id FROM pilots WHERE competition_id = ? AND active = 1');
$pilotIdStmt->execute([(int) $competition['id']]);
foreach ($pilotIdStmt as $pilotRow) {
    $validPilotIds[(int) $pilotRow['id']] = true;
}

$typeFilter = get('typ', '');
$competitionQS = (int) $competition['id'] !== current_competition_id() ? '&competition=' . (int) $competition['id'] : '';
$runsheetPdfUrl = 'laufzettel.php?format=pdf' . ($competitionQS !== '' ? '&competition=' . $competition['id'] : '');

/* ---------- Speichern ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($competitionCompleted) {
        flash('Dieser Wettbewerb ist abgeschlossen. Die Resultate können nicht mehr geändert werden.', 'err');
        redirect('wettbewerbe.php?competition=' . (int) $competition['id']);
    }

    $status = is_array($_POST['status'] ?? null) ? $_POST['status'] : [];
    $times  = is_array($_POST['time'] ?? null) ? $_POST['time'] : [];
    $dists  = is_array($_POST['dist'] ?? null) ? $_POST['dist'] : [];
    $notes  = is_array($_POST['note'] ?? null) ? $_POST['note'] : [];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        lock_open_competition($pdo, (int) $competition['id']);
        $ins = $pdo->prepare('INSERT INTO scores (pilot_id, round_id, competition_id, status, motor, flight_time_seconds, landing_value, time_penalty, landing_penalty, penalty, note)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?)
                              ON DUPLICATE KEY UPDATE competition_id=VALUES(competition_id), status=VALUES(status), motor=VALUES(motor),
                                flight_time_seconds=VALUES(flight_time_seconds), landing_value=VALUES(landing_value), time_penalty=VALUES(time_penalty),
                                landing_penalty=VALUES(landing_penalty), penalty=VALUES(penalty), note=VALUES(note)');
        $del = $pdo->prepare('DELETE FROM scores WHERE pilot_id = ? AND round_id = ?');

        $saved = 0; $cleared = 0; $problems = [];
        $outcomes = score_outcomes();

        foreach ($status as $pid => $raw) {
        $pid = (int) $pid;
        if (!isset($validPilotIds[$pid])) {
            $problems[] = "Pilot $pid: nicht in diesem Wettbewerb.";
            continue;
        }
        $outcome = is_scalar($raw) && isset($outcomes[(string) $raw]) ? (string) $raw : '';
        $motor = $outcome !== '' ? (bool) $outcomes[$outcome]['motor'] : false;
        $st = $outcome !== '' ? (string) $outcomes[$outcome]['status'] : '';
        $rawTime = isset($times[$pid]) && is_scalar($times[$pid]) ? (string) $times[$pid] : '';
        $rawDist = isset($dists[$pid]) && is_scalar($dists[$pid])
            ? str_replace(',', '.', trim((string) $dists[$pid])) : '';
        $note = isset($notes[$pid]) && is_scalar($notes[$pid]) ? text_limit((string) $notes[$pid], 160) : '';

        if ($outcome === '' || ($outcome === 'flown' && $rawTime === '' && $rawDist === '' && $note === '')) {
            $del->execute([$pid, $roundId]);
            $cleared += $del->rowCount();
            continue;
        }

        if ($outcome === 'flown') {
            $time = parse_time($rawTime);
            if ($time === null || !is_finite($time) || $time < 0 || $time > 999999.9) {
                $problems[] = 'Pilot ' . $pid . ': Flugzeit muss eine Zahl zwischen 0 und 999999.9 sein.';
                continue;
            }
            if ($rawDist === '') {
                $dist = 0.0;
            } elseif (!is_numeric($rawDist) || !is_finite((float) $rawDist)
                || (float) $rawDist < 0 || (float) $rawDist > 99999.9) {
                $problems[] = 'Pilot ' . $pid . ': Landewert muss eine Zahl zwischen 0 und 99999.9 sein.';
                continue;
            } else {
                $dist = (float) $rawDist;
            }
        } else {
            $time = null;
            $dist = null;
        }

        [$tp, $lp, $total] = calc_penalty($st, $time, $dist, $target, $motor);
        $ins->execute([$pid, $roundId, $competition['id'], $st, $motor ? 1 : 0, $time, $dist, $tp, $lp, $total, $note ?: null]);
        $saved++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash($e instanceof DomainException
            ? $e->getMessage()
            : 'Der Durchgang konnte nicht gespeichert werden. Bitte Eingaben prüfen und erneut versuchen.', 'err');
        redirect('erfassung.php?dg=' . $roundId . ($typeFilter !== '' ? '&typ=' . urlencode($typeFilter) : '') . $competitionQS);
    }

    if ($problems) {
        flash(implode(' ', array_slice($problems, 0, 3)), 'err');
    }
    flash("Durchgang {$round['round_number']}: $saved Resultate gespeichert"
        . ($cleared ? ", $cleared gelöscht" : '') . '.', 'ok');
    redirect('erfassung.php?dg=' . $roundId . ($typeFilter !== '' ? '&typ=' . urlencode($typeFilter) : '') . $competitionQS);
}

/* ---------- Anzeige ---------- */
$sql = 'SELECT p.*, t.name AS model_type_name, t.sort_order, c.name AS club_name,
               s.status, s.motor, s.flight_time_seconds, s.landing_value, s.penalty, s.note AS score_note
        FROM pilots p
        LEFT JOIN model_types t ON t.id = p.model_type_id
        LEFT JOIN clubs c ON c.id = p.club_id
        LEFT JOIN scores s ON s.pilot_id = p.id AND s.round_id = ? AND s.competition_id = ?
        WHERE p.active = 1 AND p.competition_id = ?';
$args = [$roundId, $competition['id'], $competition['id']];
if ($typeFilter !== '') {
    $sql .= ' AND p.model_type_id = ?';
    $args[] = (int) $typeFilter;
}
$sql .= ' ORDER BY t.sort_order, t.name, p.bib_number + 0, p.bib_number, p.last_name';
$st = db()->prepare($sql);
$st->execute($args);
$pilots = $st->fetchAll();

$types = all_model_types();
$done = count(array_filter($pilots, function ($p) { return $p['status'] !== null; }));

page_start('Resultate erfassen', 'admin', 'erfassung.php', true);
if ((int) $competition['id'] !== current_competition_id()) {
    echo '<div class="flash info">Achtung: Du bearbeitest den Wettbewerb „' . h($competition['name'])
        . '“, nicht den aktiven Wettbewerb. <a href="wettbewerbe.php">Wettbewerbe ansehen</a></div>';
}
if ($competitionCompleted): ?>
    <div class="flash info">Dieser Wettbewerb ist abgeschlossen. Die Resultate bleiben sichtbar, sind aber gesperrt.
        <a href="wettbewerbe.php?competition=<?= (int) $competition['id'] ?>">Abschlussstatus ansehen</a></div>
<?php endif;
?>
<div class="row-between">
    <div>
        <h2>Resultate erfassen</h2>
        <p class="lead">Zielzeit <?= h(fmt_time((float) $target)) ?> · Zeit als <code>2:58</code> oder <code>178</code> eintragen, Tabulator springt weiter.
            Die festen Strafen stehen unter <a href="einstellungen.php<?= $competitionQS !== '' ? '&' : '?' ?>competition=<?= (int) $competition['id'] ?>">Einstellungen → Strafpunkte</a>.</p>
    </div>
    <div class="btn-row dense no-print">
        <a class="btn ghost" href="<?= h($runsheetPdfUrl) ?>">Laufzettel-PDF</a>
        <a class="btn ghost" href="../index.php<?= (int) $competition['id'] !== current_competition_id() ? '?competition=' . (int) $competition['id'] : '' ?>">Rangliste ↗</a>
    </div>
</div>

<div class="rounds-strip no-print">
    <?php foreach ($rounds as $r):
        $cls = 'round-chip' . ((int) $r['id'] === $roundId ? ' on' : '') . ($r['is_active'] ? ' active' : '') . ($r['is_included'] ? '' : ' excluded'); ?>
        <a class="<?= $cls ?>" href="?dg=<?= (int) $r['id'] ?><?= $typeFilter !== '' ? '&typ=' . urlencode($typeFilter) : '' ?><?= $competitionQS ?>">
            <b>Durchgang <?= (int) $r['round_number'] ?></b>
            <span><?= h(fmt_time((float) $r['target_time_seconds'])) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($types): ?>
<div class="btn-row dense no-print" style="margin-bottom:16px">
    <a class="btn <?= $typeFilter === '' ? '' : 'ghost' ?>" href="?dg=<?= $roundId ?><?= $competitionQS ?>">Alle Typen</a>
    <?php foreach ($types as $g): ?>
        <a class="btn <?= $typeFilter === (string) $g['id'] ? '' : 'ghost' ?>" href="?dg=<?= $roundId ?>&typ=<?= (int) $g['id'] ?><?= $competitionQS ?>"><?= h($g['name']) ?></a>
    <?php endforeach; ?>
    <span class="muted small"><?= $done ?> von <?= count($pilots) ?> erfasst</span>
</div>
<?php endif; ?>

<?php if (!$pilots): ?>
    <div class="panel"><p class="lead">Keine Piloten in dieser Auswahl. <a href="piloten.php?competition=<?= (int) $competition['id'] ?>">Piloten erfassen</a>.</p></div>
<?php else: ?>
<form method="post" id="entry">
    <?= csrf_field() ?>
    <input type="hidden" name="competition" value="<?= (int) $competition['id'] ?>">
    <fieldset class="entry-controls"<?= $competitionCompleted ? ' disabled' : '' ?>>
    <div class="panel" style="padding:0;overflow:hidden">
    <div class="table-scroll">
    <table class="data entry">
        <thead>
        <tr>
            <th>Nr.</th>
            <th>Pilot</th>
            <th class="mid">Flugzeit</th>
            <th class="mid">Landewert</th>
            <th class="mid">Wertung</th>
            <th>Bemerkung</th>
            <th class="num">Strafpunkte</th>
        </tr>
        </thead>
        <tbody>
        <?php $lastGroup = null;
        foreach ($pilots as $p):
            $pid = (int) $p['id'];
            if ($typeFilter === '' && $p['model_type_name'] !== $lastGroup) {
                $lastGroup = $p['model_type_name'];
                echo '<tr class="group-head"><td colspan="7">' . h($lastGroup ?: 'Ohne Modelltyp') . '</td></tr>';
            }
            $has = $p['status'] !== null;
            $stVal = score_outcome_of((string) $p['status'], (bool) ($p['motor'] ?? 0));
            $timeVal = $p['flight_time_seconds'] !== null ? fmt_time((float) $p['flight_time_seconds']) : '';
            $distVal = $p['landing_value'] !== null ? fmt_num($p['landing_value']) : '';
            ?>
            <tr class="<?= $has ? 'saved' : '' ?>" data-row>
                <td><span class="bib"><?= h($p['bib_number'] ?: '–') ?></span></td>
                <td class="nowrap"><?= h(full_name($p)) ?><br><span class="small muted"><?= h($p['club_name'] ?: '') ?></span></td>
                <td class="mid">
                    <input type="text" class="w-time" name="time[<?= $pid ?>]" value="<?= h($timeVal) ?>"
                           inputmode="decimal" autocomplete="off" data-time placeholder="2:58">
                </td>
                <td class="mid">
                    <input type="text" class="w-dist" name="dist[<?= $pid ?>]" value="<?= h($distVal) ?>"
                           inputmode="decimal" autocomplete="off" data-dist placeholder="0">
                </td>
                <td class="mid">
                    <select name="status[<?= $pid ?>]" data-status>
                        <?php foreach (score_outcomes() as $key => $outcome): ?>
                            <option value="<?= h($key) ?>"<?= $stVal === $key ? ' selected' : '' ?>><?= h($outcome['label']) ?></option>
                        <?php endforeach; ?>
                        <option value="">kein Eintrag</option>
                    </select>
                </td>
                <td><input type="text" name="note[<?= $pid ?>]" value="<?= h($p['score_note'] ?? '') ?>" autocomplete="off"></td>
                <td class="live" data-live><?= $has ? h(fmt_num($p['penalty'])) : '–' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>

    <div class="sticky-save no-print">
        <span class="muted small">Leere Zeilen bleiben ohne Resultat.</span>
        <button class="btn big" type="submit">Durchgang <?= (int) $round['round_number'] ?> speichern</button>
    </div>
    </fieldset>
</form>

<script>
(function () {
  var cfg = <?= json_encode([
      'target'      => $target,
      'perSecond'   => max(0.0, setting_num('penalty_per_second', 1)),
      'meter'       => max(0.0, setting_num('penalty_per_meter', 1)),
      'outlanding'  => fixed_penalty('penalty_outlanding'),
      'notStarted'  => fixed_penalty('penalty_not_started'),
      'motor'       => fixed_penalty('penalty_motor'),
      'labels'      => array_map(static function (array $outcome): string { return $outcome['label']; }, score_outcomes()),
  ], JSON_THROW_ON_ERROR) ?>;

  function parseTime(raw) {
    raw = (raw || '').trim().replace(',', '.');
    if (!raw) return null;
    if (raw.indexOf(':') !== -1) {
      var p = raw.split(':');
      var m = parseFloat(p[0]), s = parseFloat(p[1] || '0');
      if (isNaN(m) || isNaN(s)) return null;
      return m * 60 + s;
    }
    var v = parseFloat(raw);
    return isNaN(v) ? null : v;
  }

  function round2(v) { return Math.round(v * 100) / 100; }

  // Strafpunkte je Ausgang, ohne den Server zu fragen. Bildet calc_penalty() ab:
  // ein sauberer Flug aus Zeitabweichung und Landewert, sonst eine feste Strafe.
  function penaltyOf(outcome, time, dist) {
    if (outcome === 'flown') {
      if (time === null) return { total: null, note: '' };
      // Betrag: zu lang und zu kurz zählen gleich.
      var tp = Math.abs(time - cfg.target) * cfg.perSecond;
      var lp = Math.max(0, dist) * cfg.meter;
      return { total: round2(tp + lp), note: 'Zeit ' + round2(tp) + ' + Landewert ' + round2(lp) };
    }
    if (outcome === 'motor') {
      return { total: cfg.motor, note: 'nur Motorstrafe, ohne Zeit und Landewert' };
    }
    if (outcome === 'motor_dnf') {
      return {
        total: round2(cfg.outlanding + cfg.motor),
        note: 'Aussenlandung ' + round2(cfg.outlanding) + ' + Motor ' + round2(cfg.motor)
      };
    }
    return {
      total: outcome === 'dnf' ? cfg.outlanding : cfg.notStarted,
      note: 'feste Strafe'
    };
  }

  function update(row) {
    var outcome = row.querySelector('[data-status]').value;
    var out = row.querySelector('[data-live]');
    var timeEl = row.querySelector('[data-time]');
    var distEl = row.querySelector('[data-dist]');
    // Flugzeit und Landewert werden nur bei einem sauberen Flug gewertet.
    var disabled = outcome !== 'flown';
    timeEl.disabled = disabled;
    distEl.disabled = disabled;

    if (outcome === '') {
      out.textContent = '–';
      out.style.color = '';
      out.removeAttribute('title');
      return;
    }

    var t = parseTime(timeEl.value);
    var d = parseFloat((distEl.value || '0').replace(',', '.'));
    if (isNaN(d)) d = 0;
    var result = penaltyOf(outcome, t, d);
    if (result.total === null) {
      out.textContent = '–';
      out.style.color = '';
      out.removeAttribute('title');
      return;
    }
    out.textContent = result.total;
    out.style.color = disabled ? 'var(--rot)' : '';
    out.title = (cfg.labels[outcome] || outcome) + ': ' + result.note;
  }

  var rows = document.querySelectorAll('[data-row]');
  rows.forEach(function (row) {
    row.addEventListener('input', function () { update(row); });
    row.addEventListener('change', function () { update(row); });
    update(row);
  });

  // Enter springt in die nächste Zeile statt das Formular zu senden
  document.getElementById('entry').addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || e.target.tagName !== 'INPUT') return;
    e.preventDefault();
    var fields = Array.prototype.slice.call(document.querySelectorAll('#entry input:not([type=hidden]):not([disabled])'));
    var i = fields.indexOf(e.target);
    if (i > -1 && fields[i + 1]) fields[i + 1].focus();
  });
})();
</script>
<?php endif; ?>
<?php page_end();
