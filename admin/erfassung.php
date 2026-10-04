<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/scoring.php';
require_once __DIR__ . '/../lib/layout.php';
require_login();

$competition = resolve_competition_param(competition_request_param(), true);
// Startliste, Resultate und Einstellungen gehören genau diesem Wettbewerb.
// Die Prüfung steht hier und nicht versteckt in resolve_competition_param(),
// wo sie auch die öffentlichen Seiten betroffen hätte.
require_competition_access((int) $competition['id']);
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
// Die vier Ausgänge kommen aus derselben Liste wie der Laufzettel, damit
// Bildschirm, PDF und Datenbank niemals unterschiedliche Kästchen zeigen.
$boxes = runsheet_penalty_boxes();

/* ---------- Speichern ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($competitionCompleted) {
        flash('Dieser Wettbewerb ist abgeschlossen. Die Resultate können nicht mehr geändert werden.', 'err');
        redirect('wettbewerbe.php?competition=' . (int) $competition['id']);
    }

    $times  = is_array($_POST['time'] ?? null) ? $_POST['time'] : [];
    $dists  = is_array($_POST['dist'] ?? null) ? $_POST['dist'] : [];
    $clears = is_array($_POST['clear'] ?? null) ? $_POST['clear'] : [];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        lock_open_competition($pdo, (int) $competition['id']);
        $ins = $pdo->prepare('INSERT INTO scores (pilot_id, round_id, competition_id, not_started, outlanding, crash, motor, flight_time_seconds, landing_value, time_penalty, landing_penalty, penalty, note)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                              ON DUPLICATE KEY UPDATE competition_id=VALUES(competition_id),
                                not_started=VALUES(not_started), outlanding=VALUES(outlanding), crash=VALUES(crash), motor=VALUES(motor),
                                flight_time_seconds=VALUES(flight_time_seconds), landing_value=VALUES(landing_value), time_penalty=VALUES(time_penalty),
                                landing_penalty=VALUES(landing_penalty), penalty=VALUES(penalty), note=VALUES(note)');
        $del = $pdo->prepare('DELETE FROM scores WHERE pilot_id = ? AND round_id = ?');

        $saved = 0; $cleared = 0; $problems = [];

        foreach ($validPilotIds as $pid => $_) {
        $pid = (int) $pid;

        // Ein gesetztes „löschen“ hat Vorrang vor allen anderen Feldern.
        if (isset($clears[$pid]) && is_scalar($clears[$pid]) && $clears[$pid] === '1') {
            $del->execute([$pid, $roundId]);
            $cleared += $del->rowCount();
            continue;
        }

        $checked = [];
        foreach ($boxes as $box) {
            $field = str_replace('penalty_', '', $box['setting']);
            $checked[$field] = isset($_POST[$field][$pid]) && $_POST[$field][$pid] === '1';
        }
        $hasDns   = $checked['not_started'];
        $hasDnf   = $checked['outlanding'];
        $hasCrash = $checked['crash'];
        // Der Motor ist eine Zusatzstrafe und kann zu jedem Ausgang dazukommen.

        $rawTime = isset($times[$pid]) && is_scalar($times[$pid]) ? (string) $times[$pid] : '';
        $rawDist = isset($dists[$pid]) && is_scalar($dists[$pid])
            ? str_replace(',', '.', trim((string) $dists[$pid])) : '';

        // Die Kästchen sind unabhängig – eine Aussenlandung schliesst eine
        // Bruchlandung nicht aus. Einzige Ausnahme: „nicht angetreten" nimmt
        // alles andere mit, denn wer nicht angetreten ist, hat nicht geflogen.
        // Die Seite sperrt die anderen Felder schon, doch darauf ist hier nicht
        // zu vertrauen: das Formular kann von Hand gesendet werden.
        $flags = [
            'not_started' => $hasDns,
            'outlanding'  => $hasDnf,
            'crash'       => $hasCrash,
            'motor'       => $checked['motor'],
        ];
        $flags = score_flags($flags);
        $hasMotor = $flags['motor'];

        if (score_is_flown($flags) && !$hasMotor && $rawTime === '' && $rawDist === '') {
            $del->execute([$pid, $roundId]);
            $cleared += $del->rowCount();
            continue;
        }

        // Flugzeit und Landewert lassen sich immer eintragen, auch neben einem
        // festen Ausgang. Wer eine Aussenlandung nach 3:20 Landewert 15 hatte,
        // trägt beides ein – die Strafpunkte rechnen weiterhin nach der
        // festen Regel, aber der nachgemessene Flug geht nicht verloren.
        $time = $rawTime === '' ? null : parse_time($rawTime);
        if ($rawTime !== '' && ($time === null || !is_finite($time) || $time < 0 || $time > 999999.9)) {
            $problems[] = 'Pilot ' . $pid . ': Flugzeit muss eine Zahl zwischen 0 und 999999.9 sein.';
            continue;
        }
        if ($rawDist === '') {
            $dist = null;
        } elseif (!is_numeric($rawDist) || !is_finite((float) $rawDist)
            || (float) $rawDist < 0 || (float) $rawDist > 99999.9) {
            $problems[] = 'Pilot ' . $pid . ': Landewert muss eine Zahl zwischen 0 und 99999.9 sein.';
            continue;
        } else {
            $dist = (float) $rawDist;
        }

        // Beim freien Flug bleibt die Zeit Pflicht – ohne sie gibt es nichts zu
        // rechnen. Neben einem festen Ausgang ist sie freiwillig.
        if (score_is_flown($flags)) {
            if ($time === null) {
                $problems[] = 'Pilot ' . $pid . ': Für einen geflogenen Start wird die Flugzeit gebraucht.';
                continue;
            }
            // Wie bisher: ein leerer Landewert zählt als Null, nicht als unbekannt.
            $dist = $dist ?? 0.0;
        }

        [$tp, $lp, $total] = calc_penalty($flags, $time, $dist, $target);
        $ins->execute([$pid, $roundId, $competition['id'],
            $flags['not_started'] ? 1 : 0, $flags['outlanding'] ? 1 : 0, $flags['crash'] ? 1 : 0,
            $flags['motor'] ? 1 : 0, $time, $dist, $tp, $lp, $total, null]);
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
$sql = 'SELECT p.*, pr.first_name, pr.last_name, pr.smv_number,
                 t.name AS model_type_name, t.sort_order, c.name AS club_name,
               s.not_started, s.outlanding, s.crash, s.motor,
               s.flight_time_seconds, s.landing_value, s.penalty
        FROM pilots p
        JOIN pilot_profiles pr ON pr.id = p.profile_id
        LEFT JOIN model_types t ON t.id = p.model_type_id
        LEFT JOIN clubs c ON c.id = p.club_id
        LEFT JOIN scores s ON s.pilot_id = p.id AND s.round_id = ? AND s.competition_id = ?
        WHERE p.active = 1 AND p.competition_id = ?';
$args = [$roundId, $competition['id'], $competition['id']];
if ($typeFilter !== '') {
    $sql .= ' AND p.model_type_id = ?';
    $args[] = (int) $typeFilter;
}
$sql .= ' ORDER BY t.sort_order, t.name, p.bib_number + 0, p.bib_number, pr.last_name';
$st = db()->prepare($sql);
$st->execute($args);
$pilots = $st->fetchAll();

$types = all_model_types();
$leer = score_flags(null);
$done = count(array_filter($pilots, function ($p) use ($leer) { return score_flags($p) !== $leer; }));

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
        <p class="lead">Genau wie im Laufzettel: kein Kästchen angekreuzt heisst <b>geflogen</b> und wird nach Flugzeit und
            Landewert gewertet. <b>Aussenlandung</b> und <b>Bruchlandung</b> schlagen den Nichtantritt, und
            <b>Motor</b> kommt als Zusatzstrafe zu jedem Ausgang dazu. Eine Zeile ohne Zeit, Landewert und Kästchen
            bleibt ohne Resultat; das ✕ löscht ein gespeichertes Resultat.</p>
    </div>
    <div class="btn-row dense no-print">
        <a class="btn ghost" href="<?= h($runsheetPdfUrl) ?>">Laufzettel-PDF</a>
        <a class="btn ghost" href="../rangliste.php<?= (int) $competition['id'] !== current_competition_id() ? '?competition=' . (int) $competition['id'] : '' ?>">Rangliste ↗</a>
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
            <?php foreach ($boxes as $box): ?>
                <th class="mid penalty-head" title="<?= h(fmt_num(fixed_penalty($box['setting']))) ?> Punkte"><?= h($box['label']) ?></th>
            <?php endforeach; ?>
            <th class="num">Strafpunkte</th>
            <th class="no-print"></th>
        </tr>
        </thead>
        <tbody>
        <?php $lastGroup = null;
        $boxCount = count($boxes);
        foreach ($pilots as $p):
            $pid = (int) $p['id'];
            if ($typeFilter === '' && $p['model_type_name'] !== $lastGroup) {
                $lastGroup = $p['model_type_name'];
                echo '<tr class="group-head"><td colspan="' . ($boxCount + 6) . '">' . h($lastGroup ?: 'Ohne Modelltyp') . '</td></tr>';
            }
            $marked = score_flags($p);
            $has = $marked !== $leer;
            $timeVal = $p['flight_time_seconds'] !== null ? fmt_time((float) $p['flight_time_seconds']) : '';
            $distVal = $p['landing_value'] !== null ? fmt_num($p['landing_value']) : '';
            // $marked traegt die vier Kaestchen aus dem gespeicherten Datensatz.
            ?>
            <tr class="<?= $has ? 'saved' : '' ?>" data-row>
                <td><span class="bib"><?= h($p['bib_number'] ?: '–') ?></span></td>
                <td class="nowrap"><?= h(full_name($p)) ?><br><span class="small muted"><?= h($p['club_name'] ?: '') ?></span></td>
                <td class="mid">
                    <input type="text" class="w-time" name="time[<?= $pid ?>]" value="<?= h($timeVal) ?>"
                           inputmode="decimal" autocomplete="off" data-time placeholder="0:00">
                </td>
                <td class="mid">
                    <input type="text" class="w-dist" name="dist[<?= $pid ?>]" value="<?= h($distVal) ?>"
                           inputmode="decimal" autocomplete="off" data-dist placeholder="0">
                </td>
                <?php foreach ($boxes as $box):
                    $field = str_replace('penalty_', '', $box['setting']); ?>
                    <td class="mid">
                        <input type="checkbox" name="<?= h($field) ?>[<?= $pid ?>]" value="1"
                               data-box="<?= h($field) ?>"<?= !empty($marked[$field]) ? ' checked' : '' ?>
                               aria-label="<?= h(full_name($p)) ?>: <?= h($box['label']) ?>">
                    </td>
                <?php endforeach; ?>
                <td class="num live" data-live><?= $has ? h(fmt_num($p['penalty'])) : '–' ?></td>
                <td class="no-print">
                    <button class="btn ghost small" type="submit" name="clear[<?= $pid ?>]" value="1"
                            formnovalidate
                            data-confirm-click="Resultat von <?= h(full_name($p)) ?> löschen?"
                            <?= $has ? '' : 'disabled title="Nichts gespeichert"' ?>>✕</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>

    <div class="sticky-save no-print">
        <span class="muted small">Zeilen ohne Zeit, Landewert und Kästchen bleiben ohne Resultat.</span>
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
      'crash'       => fixed_penalty('penalty_crash'),
      'notStarted'  => fixed_penalty('penalty_not_started'),
      'motor'       => fixed_penalty('penalty_motor'),
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

  function box(row, name) { return row.querySelector('[data-box="' + name + '"]'); }

  // Strafpunkte aus den angekreuzten Kästchen, ohne den Server zu fragen.
  // Bildet calc_penalty() ab. Die Kästchen sind unabhängig: eine Aussenlandung
  // schliesst eine Bruchlandung nicht aus, ein Modell kann neben der Piste
  // gelandet sein und Teile verloren haben. Nur "nicht angetreten" nimmt die
  // anderen mit. Die Zeitabweichung zaehlt immer, der Landewert ausser bei der
  // Aussenlandung - dort aber wieder, wenn eine Bruchlandung dazukommt.
  function penaltyOf(flags, time, dist) {
    var nichtAngetreten = flags.not_started;
    var aussenlandung = !nichtAngetreten && flags.outlanding;
    var bruchlandung = !nichtAngetreten && flags.crash;
    var motor = !nichtAngetreten && flags.motor;

    var fest = 0;
    var teile = [];
    if (aussenlandung) { fest += cfg.outlanding; teile.push('Aussenlandung'); }
    if (bruchlandung) { fest += cfg.crash; teile.push('Bruchlandung'); }
    if (nichtAngetreten) { fest += cfg.notStarted; teile.push('nicht angetreten'); }
    if (motor) { fest += cfg.motor; teile.push('Motor'); }

    var zeitZaehlt = !nichtAngetreten;
    var landZaehlt = !nichtAngetreten && (!aussenlandung || bruchlandung);
    var angetreten = !nichtAngetreten && !aussenlandung && !bruchlandung;
    // Beim freien Flug braucht es die Zeit, sonst gibt es nichts zu rechnen.
    if (angetreten && time === null) {
      return { total: null, note: '' };
    }

    // Betrag statt Vorzeichen: zu lang und zu kurz zählen gleich.
    var tp = zeitZaehlt ? Math.abs((time || 0) - cfg.target) * cfg.perSecond : 0;
    var lp = landZaehlt ? Math.max(0, dist || 0) * cfg.meter : 0;
    tp = round2(tp); lp = round2(lp);

    if (teile.length === 0) {
      teile.push('Zeit ' + tp + ' + Landewert ' + lp);
    } else {
      if (zeitZaehlt) { teile.push('Zeit ' + tp); }
      if (landZaehlt && lp > 0) { teile.push('Landewert ' + lp); }
    }
    return { total: Math.min(999999.99, round2(tp + lp + fest)), note: teile.join(' + ') };
  }

  // "nicht angetreten" schliesst alles andere aus. Wer nicht angetreten ist, hat
  // nicht geflogen: es gibt keine Zeit und keinen Landewert, und eine
  // Aussenlandung kann man nicht auch noch Bruchlandung nennen. Die Zeit steht
  // deshalb auf 0:00 und ist nicht mehr zu aendern. Beim Abwaehlen kommt der
  // vorher eingetragene Wert zurueck, damit ein Fehlklick nichts vernichtet.
  function nichtAngetretenAnwenden(row, an) {
    var timeEl = row.querySelector('[data-time]');
    var distEl = row.querySelector('[data-dist]');
    var andere = [box(row, 'outlanding'), box(row, 'crash'), box(row, 'motor')];
    if (an) {
      if (!row.hasAttribute('data-zuvor')) {
        row.setAttribute('data-zuvor', timeEl.value + '\t' + distEl.value);
      }
      timeEl.value = '0:00';
      distEl.value = '0';
      // readOnly statt disabled: ein gesperrtes Feld wird nicht mitgeschickt,
      // und die 0:00 waeren nach dem Neuladen wieder weg.
      timeEl.readOnly = true;
      distEl.readOnly = true;
      for (var i = 0; i < andere.length; i++) {
        if (!andere[i]) { continue; }
        andere[i].checked = false;
        andere[i].disabled = true;
      }
    } else {
      var zuvor = row.getAttribute('data-zuvor');
      if (zuvor !== null) {
        var teile = zuvor.split('\t');
        timeEl.value = teile[0];
        distEl.value = teile[1] === undefined ? '' : teile[1];
        row.removeAttribute('data-zuvor');
      }
      timeEl.readOnly = false;
      distEl.readOnly = false;
      for (var j = 0; j < andere.length; j++) {
        if (andere[j]) { andere[j].disabled = false; }
      }
    }
  }

  function update(row) {
    var out = row.querySelector('[data-live]');
    var timeEl = row.querySelector('[data-time]');
    var distEl = row.querySelector('[data-dist]');
    var dnsEl = box(row, 'not_started');
    var dnfEl = box(row, 'outlanding');
    var crashEl = box(row, 'crash');
    var motorEl = box(row, 'motor');
    // Der Sperrbereich folgt dem Kästchen, und zwar nur beim Umschalten.
    var dnsAn = !!dnsEl && dnsEl.checked;
    if (row.getAttribute('data-dns') !== (dnsAn ? '1' : '0')) {
      row.setAttribute('data-dns', dnsAn ? '1' : '0');
      nichtAngetretenAnwenden(row, dnsAn);
    }
    var flags = {
      not_started: dnsAn,
      outlanding: !!dnfEl && dnfEl.checked,
      crash: !!crashEl && crashEl.checked,
      motor: !!motorEl && motorEl.checked
    };
    // Angetreten, solange kein fester Ausgang angekreuzt ist.
    var flew = !flags.not_started && !flags.outlanding && !flags.crash;
    // Zeit und Landewert bleiben immer bedienbar. Wer eine Aussenlandung nach
    // 3:20 Landewert 15 hatte, soll beides eintragen können – die Strafpunkte
    // rechnen weiterhin nach der festen Regel.
    var t = parseTime(timeEl.value);
    var d = parseFloat((distEl.value || '0').replace(',', '.'));
    if (isNaN(d)) d = 0;
    var result = penaltyOf(flags, t, d);
    if (result.total === null) {
      out.textContent = '–';
      out.style.color = '';
      out.removeAttribute('title');
      return;
    }
    out.textContent = result.total;
    out.style.color = flew ? '' : 'var(--rot)';
    out.title = result.note;
  }

  var rows = document.querySelectorAll('[data-row]');
  rows.forEach(function (row) {
    row.addEventListener('input', function () { update(row); });
    row.addEventListener('change', function () { update(row); });
    // Bereits gespeicherte Zeilen kommen mit gesetztem Kästchen daher. Ohne
    // diesen Aufruf stuende die Sperre erst nach dem ersten Klick.
    var dnsGespeichert = box(row, 'not_started');
    row.setAttribute('data-dns', (dnsGespeichert && dnsGespeichert.checked) ? '1' : '0');
    if (dnsGespeichert && dnsGespeichert.checked) {
      nichtAngetretenAnwenden(row, true);
    }
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
