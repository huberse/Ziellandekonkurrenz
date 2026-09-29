<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/competition.php';

/**
 * @param string $title
 * @param string $area  'public' oder 'admin'
 * @param string $here  Dateiname der aktiven Seite, für die Navigation
 * @param bool   $wide  breiteres Layout für weite Tabellen
 * @param bool   $nav   Navigationsleiste zeigen
 * @param bool   $ohneAbzeichen  das Abzeichen mit dem Wettbewerbsnamen weglassen.
 *        Auf der Startseite steht es nicht, denn dort wird der Wettbewerb erst
 *        gewählt; ein Abzeichen würde eine Antwort vortäuschen, die es noch nicht gibt.
 */
function page_start(string $title, string $area = 'public', string $here = '', bool $wide = false, bool $nav = true, bool $ohneAbzeichen = false): void
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
    echo '<div class="meta">';
    if (!$ohneAbzeichen) {
        echo '<span class="competition-badge">' . h($competitionName) . '</span>';
        if (!empty($selected['completed_at'])) {
            echo ' <span class="completion-badge">abgeschlossen</span>';
        }
        if ($meta) { echo ' &nbsp;·&nbsp; ' . h(implode(' · ', $meta)); }
    }
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
            'index.php'     => 'Start',
            'rangliste.php' => 'Rangliste',
            'vereinswertung.php' => 'Vereinswertung',
            'teilnehmer.php'=> 'Teilnehmer',
            'anmeldung.php' => 'Anmeldung',
        ];

    // Die Benutzerverwaltung ist dem SuperAdmin vorbehalten; für alle anderen
    // Konten gehört der Punkt nicht in die Leiste. Dasselbe gilt für die
    // Aktualisierung, denn nur der SuperAdmin darf Programme Dateien austauschen.
    if ($area === 'admin' && is_superadmin()) {
        $links['benutzer.php'] = 'Benutzer';
        $links['aktualisieren.php'] = 'Aktualisierung';
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
            echo '<a href="../rangliste.php' . $contextQS . '">Rangliste ↗</a>';
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
    // Filtere nach Vereinszugehörigkeit für Nicht-Superadmins
    $u = current_user();
    if ($u && (int) ($u['is_superadmin'] ?? 0) !== 1) {
        $clubId = user_club_id();
        $competitions = array_filter($competitions, static function (array $c) use ($clubId): bool {
            // Altbestand ohne Vereinszuordnung bleibt für alle sichtbar
            if (empty($c['club_id'])) {
                return true;
            }
            return (int) $c['club_id'] === $clubId;
        });
        if (!$competitions) {
            return;
        }
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

/**
 * Die Wettbewerbe als grosse Karten für die Startseite.
 *
 * Die Auswahl ist das Hauptelement der Seite, nicht eine Ecke. Ein Besucher
 * soll einen Wettbewerb anklicken und direkt bei der Rangliste sein, ohne
 * vorher ein Feld zu bedienen. Deshalb ist die ganze Karte ein Ziel: der
 * Name trägt den Sprung, seine Fläche wird über die Karte gezogen.
 *
 * @param array $wettbewerbe  aus competitions_uebersicht(), neueste zuerst
 */
function competition_cards(array $wettbewerbe): void
{
    if (!$wettbewerbe) {
        echo '<p class="lead">Es ist noch kein Wettbewerb angelegt.</p>';
        return;
    }
    echo '<div class="picks">';
    foreach ($wettbewerbe as $w) {
        $id = (int) $w['id'];
        $oeffentlich = (string) ($w['public_results'] ?? '1') === '1';
        $beendet = !empty($w['completed_at']);
        $vergangen = competition_ist_vergangen($w);
        $nimmtAn = competition_nimmt_anmeldungen_an($w);
        $piloten = (int) ($w['pilots'] ?? 0);
        $runden  = (int) ($w['rounds'] ?? 0);
        $daten   = [];
        if (!empty($w['competition_date']) && $zeit = strtotime((string) $w['competition_date'])) {
            $daten[] = date('d.m.Y', $zeit);
        }
        if (!empty($w['competition_place'])) {
            $daten[] = (string) $w['competition_place'];
        }
        if (!empty($w['club_name'])) {
            $daten[] = (string) $w['club_name'];
        }

        echo '<div class="pick' . ($beendet ? ' past' : '') . '">';
        echo '<h3 class="pick-name">';
        if ($oeffentlich) {
            echo '<a href="rangliste.php?competition=' . $id . '">' . h((string) $w['name']) . '</a>';
        } else {
            echo h((string) $w['name']);
        }
        echo '</h3>';

        if ($daten) {
            echo '<p class="pick-when">' . h(implode(' · ', $daten)) . '</p>';
        }

        $zaehler = [];
        $zaehler[] = $piloten === 1 ? '1 Pilot' : $piloten . ' Piloten';
        if ($runden > 0) {
            $zaehler[] = $runden . ($runden === 1 ? ' Durchgang' : ' Durchgänge');
        }
        echo '<p class="pick-count">' . h(implode(' · ', $zaehler)) . '</p>';

        echo '<p class="pick-tags">';
        if ($beendet) {
            echo '<span class="tag">beendet</span>';
        } elseif ($vergangen) {
            echo '<span class="tag">vorbei</span>';
        }
        if (!$oeffentlich) {
            echo '<span class="tag">Rangliste noch nicht frei</span>';
        } elseif ($piloten === 0) {
            echo '<span class="tag">noch keine Anmeldungen</span>';
        }
        if (!$nimmtAn && !$beendet && !$vergangen) {
            echo '<span class="tag">Anmeldung zu</span>';
        }
        echo '</p>';

        // Die Knoepfe zuerst zusammensetzen und nur dann den Kasten ausgeben,
        // wenn etwas drinsteht. Sonst bleibt eine leere Trennlinie stehen, etwa
        // wenn die Rangliste noch nicht frei ist und keine Anmeldung offen ist.
        $knoepfe = '';
        if ($oeffentlich) {
            $knoepfe .= '<a class="btn" href="rangliste.php?competition=' . $id . '">Rangliste</a>';
        }
        // Nur Wettbewerbe, die wirklich noch annehmen. Die Rangliste gibt es
        // zu jedem, die Anmeldung nicht. Die Teilnehmerliste steht in der
        // Navigation und braucht hier keinen zweiten Knopf.
        if ($nimmtAn) {
            $knoepfe .= '<a class="btn" href="anmeldung.php?competition=' . $id . '">Anmelden</a>';
        }
        if ($knoepfe !== '') {
            echo '<div class="pick-go">' . $knoepfe . '</div>';
        }
        echo '</div>';
    }
    echo '</div>';
}

/**
 * Hinweis, für welche Wettbewerbe es noch eine Anmeldung gibt.
 *
 * Steht auf der Anmeldeseite, wenn der gerade gewählte Wettbewerb keine mehr
 * annimmt. Sonst sitzt man dort fest: die Seite sagt nur "zu", und man weiss
 * nicht, wohin. Die Liste ist dieselbe wie die Knöpfe darüber, damit beides
 * nicht auseinanderlaufen kann.
 *
 * @param array $offene     Wettbewerbe, für die eine Anmeldung möglich ist
 * @param bool  $rueckweg   true auf der Anmeldeseite: Ist nichts offen, führt
 *                          ein Knopf zurück zur Startseite mit allen Wettbewerben.
 *                          Auf der Startseite selbst ist das überflüssig, dort
 *                          stehen die Wettbewerbe ohnehin direkt darüber.
 */
function anmeldehinweis(array $offene, bool $rueckweg = false): void
{
    if (!$offene) {
        echo '<p class="lead">Zurzeit ist für keinen Wettbewerb die Anmeldung geöffnet.</p>';
        if ($rueckweg) {
            echo '<p class="small muted">Sobald ein Wettbewerb zur Anmeldung freigegeben wird, steht er hier.'
                . ' Die Ranglisten der früheren Wettbewerbe kannst du dir trotzdem ansehen.</p>';
            echo '<p><a class="btn ghost" href="index.php">Alle Wettbewerbe ansehen</a></p>';
        }
        return;
    }
    echo '<p class="lead">Anmelden kannst du nur für:</p>';
    echo '<ul class="pick-open">';
    foreach ($offene as $w) {
        $id = (int) $w['id'];
        $qs = $id === current_competition_id() ? '' : '?competition=' . $id;
        echo '<li><a href="anmeldung.php' . $qs . '">' . h((string) $w['name']) . '</a>';
        if (!empty($w['competition_date']) && ($zeit = strtotime((string) $w['competition_date'])) !== false) {
            echo ' <span class="small muted">· ' . date('d.m.Y', $zeit) . '</span>';
        }
        echo '</li>';
    }
    echo '</ul>';
}

/**
 * Wettbewerbsauswahl als Knöpfe statt als Formular, für die Unterseiten.
 *
 * Dieselbe Idee wie competition_cards(), nur kompakter: die Unterseite zeigt
 * schon, worum es geht, hier geht es nur darum, den Wettbewerb zu wechseln.
 * Ohne Formular und ohne JavaScript – ein Klick genügt.
 *
 * @param array  $competitions  Wettbewerbe, neueste zuerst
 * @param int    $currentId     der gerade gezeigte Wettbewerb
 * @param string $query         Seite, auf die die Knöpfe zeigen
 * @param array  $keep          weitere Parameter, die erhalten bleiben (z.B. typ)
 * @param bool   $erlaubeBeendet beendete Wettbewerbe mit anbieten
 */
function competition_choices(array $competitions, int $currentId, string $query, array $keep = [], bool $erlaubeBeendet = true): void
{
    $zeilen = [];
    foreach ($competitions as $c) {
        $id = (int) $c['id'];
        // Der gerade gezeigte Wettbewerb bleibt immer stehen. Auf der
        // Anmeldeseite sind beendete Wettbewerbe nicht in der Liste, und ohne
        // diese Ausnahme sähe man dort nicht, für welchen man sich gerade
        // entschieden hat.
        if (!$erlaubeBeendet && $id !== $currentId && !empty($c['completed_at'])) {
            continue;
        }
        $zeilen[$id] = $c;
    }
    if ($currentId > 0 && !isset($zeilen[$currentId])) {
        $zeilen[$currentId] = ['id' => $currentId, 'name' => 'Wettbewerb ' . $currentId, 'completed_at' => '1'];
    }

    $links = [];
    foreach ($zeilen as $id => $c) {
        $qs = http_build_query($keep + ['competition' => (int) $id]);
        $links[] = '<a class="pick-chip' . ((int) $id === $currentId ? ' on' : '') . '"'
            . ' href="' . h($query . '?' . $qs) . '"'
            . ((int) $id === $currentId ? ' aria-current="page"' : '') . '>'
            . h((string) $c['name'])
            . (empty($c['completed_at']) ? '' : ' <span class="muted">beendet</span>')
            . '</a>';
    }
    if (count($links) < 2) {
        return;
    }
    echo '<nav class="pick-row no-print" aria-label="Wettbewerb wählen">'
        . '<span class="pick-row-label">Wettbewerb</span>' . implode('', $links) . '</nav>';
}
