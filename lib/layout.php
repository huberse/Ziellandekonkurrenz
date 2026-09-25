<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/competition.php';

/**
 * @param string $title
 * @param string $area  'public' oder 'admin'
 * @param string $here  Dateiname der aktiven Seite, für die Navigation
 */
function page_start(string $title, string $area = 'public', string $here = '', bool $wide = false, bool $nav = true): void
{
    ensure_competition_context();
    $base = $area === 'admin' ? '..' : '.';
    $activeCompetition = current_competition();
    $selected = selected_competition();
    $competitionName = (string) ($selected['name'] ?? '');
    $name = setting('competition_name', $competitionName ?: 'Segelflug-Wettbewerb');
    $date = setting('competition_date', '');
    $place = setting('competition_place', '');
    $user = current_user();

    $meta = array_filter([$place, $date ? date('d.m.Y', strtotime($date)) : '']);
    $contextId = competition_context_id();
    $contextQS = $contextId > 0 && (int) $activeCompetition['id'] !== $contextId
        ? '?competition=' . $contextId : '';
    $logo = $base . '/assets/' . site_logo();

    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title . ' · ' . $name) . '</title>';
    echo '<link rel="icon" href="' . h($logo) . '">';
    echo '<link rel="stylesheet" href="' . $base . '/assets/style.css">';
    echo '</head><body>';

    // Oben links steht die Plattform aus config.php, der Wettbewerb genau einmal
    // im Abzeichen daneben.
    echo '<header class="top"><div class="top-inner">';
    echo '<h1><a href="' . $base . '/index.php' . $contextQS . '"><img src="' . h($logo) . '" alt="" class="brand-logo">' . h(site_name()) . '</a></h1>';
    echo '<div class="meta"><span class="competition-badge">' . h($competitionName) . '</span>';
    if (!empty($selected['completed_at'])) {
        echo ' <span class="completion-badge">abgeschlossen</span>';
    }
    if ($meta) { echo ' &nbsp;·&nbsp; ' . h(implode(' · ', $meta)); }
    if ($user) {
        echo ($meta ? ' &nbsp;|&nbsp; ' : '') . h($user['display_name'] ?: $user['username']);
        echo ' <form class="logout-form" method="post" action="' . $base . '/admin/logout.php">';
        echo csrf_field();
        echo '<button class="logout-link" type="submit">Abmelden</button></form>';
    }
    echo '</div></div></header>';

    $links = $area === 'admin'
        ? [
            'index.php'       => 'Übersicht',
            'erfassung.php'   => 'Resultate erfassen',
            'wettbewerbe.php'     => 'Wettbewerbe',
            'durchgaenge.php' => 'Durchgänge',
            'piloten.php'     => 'Piloten',
            'vereine.php'     => 'Vereine',
            'modelltypen.php' => 'Modelltypen',
            'anmeldungen.php' => 'Anmeldungen',
            'einstellungen.php' => 'Einstellungen',
        ]
        : [
            'index.php'     => 'Rangliste',
            'vereinswertung.php' => 'Vereinswertung',
            'teilnehmer.php'=> 'Teilnehmer',
            'anmeldung.php' => 'Anmeldung',
        ];

    // Die Benutzerverwaltung ist dem SuperAdmin vorbehalten; für alle anderen
    // Konten gehört der Punkt nicht in die Leiste.
    if ($area === 'admin' && is_superadmin()) {
        $links['benutzer.php'] = 'Benutzer';
    }

    if ($area !== 'admin' && !setting_bool('club_ranking_enabled', true)) {
        unset($links['vereinswertung.php']);
    }

    if ($nav) {
        echo '<nav class="nav"><div class="nav-inner">';
        foreach ($links as $file => $label) {
            $on = ($file === $here) ? ' class="on"' : '';
            echo '<a href="' . $file . $contextQS . '"' . $on . '>' . h($label) . '</a>';
        }
        echo '<span class="spacer"></span>';
        if ($area === 'admin') {
            echo '<a href="../index.php' . $contextQS . '">Rangliste ↗</a>';
        } elseif ($user) {
            echo '<a href="admin/index.php' . $contextQS . '">Wettkampfbüro</a>';
        } else {
            echo '<a href="admin/login.php">Anmelden</a>';
        }
        echo '</div></nav>';
    }

    echo '<main' . ($wide ? ' class="wide"' : '') . '>';

    foreach (flash_take() as $f) {
        echo '<div class="flash ' . h($f['type']) . '">' . h($f['text']) . '</div>';
    }
}

function page_end(): void
{
    echo '</main>';
    echo '<script>
(function () {
    function closestWithData(target, attribute) {
        while (target && target !== document) {
            if (target.getAttribute && target.hasAttribute(attribute)) return target;
            target = target.parentNode;
        }
        return null;
    }
    document.addEventListener("click", function (event) {
        var el = closestWithData(event.target, "data-confirm-click");
        if (el && !window.confirm(el.getAttribute("data-confirm-click"))) event.preventDefault();
        if (closestWithData(event.target, "data-print")) window.print();
    });
    document.addEventListener("submit", function (event) {
        var form = event.target;
        if (!form || !form.getAttribute) return;
        var message = form.getAttribute("data-confirm");
        if (message && !window.confirm(message)) { event.preventDefault(); return; }
        // data-submit-once sperrt den Knopf nach dem ersten Absenden, damit ein
        // zweiter Klick keine zweite Anmeldung auslöst. Nicht für Formulare mit
        // benannten Absende-Knöpfen verwenden, die einen Wert mitsenden.
        if (form.getAttribute("data-submit-once") && form.getAttribute("data-submitting") !== "1") {
            form.setAttribute("data-submitting", "1");
            var buttons = form.querySelectorAll("button[type=submit], input[type=submit]");
            for (var i = 0; i < buttons.length; i++) buttons[i].disabled = true;
        }
    });
    document.addEventListener("change", function (event) {
        if (event.target.matches && event.target.matches("[data-auto-submit]") && event.target.form) {
            event.target.form.submit();
        }
    });
})();
</script></body></html>';
}

/** Anzahl offener Anmeldungen des aktuellen Wettbewerbs, für die Übersicht. */
function pending_registrations(?int $competitionId = null): int
{
    $competitionId = $competitionId ?? current_competition_id();
    $st = db()->prepare("SELECT COUNT(*) FROM registrations
                         WHERE status = 'pending' AND competition_id = ?");
    $st->execute([$competitionId]);
    return (int) $st->fetchColumn();
}

/**
 * Wettbewerbsauswahl als Dropdown. Offene und abgeschlossene Wettbewerbe stehen
 * in getrennten Gruppen, der aktive Wettbewerb zuerst; so bleibt die Auswahl auch
 * mit vielen Wettbewerben mehrerer Vereine übersichtlich.
 *
 * @param array  $competitions  Wettbewerbe, neueste zuerst
 * @param array  $current       der gerade bearbeitete Wettbewerb
 * @param string $query         Dateiname, auf den die Auswahl zeigt
 */
function competition_switch(array $competitions, array $current, string $query): void
{
    if (!$competitions) {
        return;
    }
    $currentId = (int) $current['id'];
    $activeId = current_competition_id();
    $counts = competition_pilot_counts();

    $groups = ['Offene Wettbewerbe' => [], 'Abgeschlossene Wettbewerbe' => []];
    foreach ($competitions as $competition) {
        $groups[empty($competition['completed_at']) ? 'Offene Wettbewerbe' : 'Abgeschlossene Wettbewerbe'][] = $competition;
    }

    echo '<form method="get" class="competition-switch no-print" action="' . h($query) . '">';
    echo '<label for="competition-switch" class="muted">Wettbewerb:</label>';
    echo '<select id="competition-switch" name="competition" data-auto-submit>';
    foreach ($groups as $label => $group) {
        if (!$group) {
            continue;
        }
        // Der aktive Wettbewerb steht oben, danach die neuesten.
        usort($group, static function (array $a, array $b) use ($activeId): int {
            $aActive = (int) $a['id'] === $activeId ? 0 : 1;
            $bActive = (int) $b['id'] === $activeId ? 0 : 1;
            return $aActive === $bActive ? (int) $b['id'] <=> (int) $a['id'] : $aActive <=> $bActive;
        });
        echo '<optgroup label="' . h($label) . '">';
        foreach ($group as $competition) {
            $id = (int) $competition['id'];
            $marks = [];
            if ($id === $activeId) {
                $marks[] = 'aktiv';
            }
            if (!empty($competition['completed_at'])) {
                $marks[] = 'beendet';
            }
            $total = $counts[$id] ?? 0;
            if ($total > 0) {
                $marks[] = $total . ($total === 1 ? ' Pilot' : ' Piloten');
            }
            $text = (string) $competition['name'] . ($marks ? ' · ' . implode(' · ', $marks) : '');
            echo '<option value="' . $id . '"' . ($id === $currentId ? ' selected' : '') . '>'
                . h($text) . '</option>';
        }
        echo '</optgroup>';
    }
    echo '</select></form>';
}
