<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/competition.php';
// region_card() rechnet die Regiorangliste. region.php holt sich selbst,
// was es braucht (Scoring, Wettbewerb, Rechte), deshalb genuegt das hier.
require_once __DIR__ . '/region.php';

/**
 * Das Benutzersymbol oben rechts in der Kopfzeile.
 *
 * Ohne Konto ist es ein schlichter Verweis auf die Anmeldung. Mit Konto oeffnet
 * es ein Menue mit dem Profil und dem Abmelden. Umgesetzt mit <details>, damit
 * es ohne JavaScript aufgeht und mit der Tastatur bedienbar bleibt.
 *
 * Vorher stand hier der Name mit einem "Abmelden"-Knopf daneben, und in der
 * Navigationsleiste zusaetzlich ein "Anmelden". Zwoerter Woerter, ein Buchstabe
 * Unterschied, beide an einem Fleck - das war die Verwirrung.
 *
 * @param string $base  '.' im oeffentlichen Teil, '..' in der Verwaltung
 * @param bool   $mitTrenner  ein Strich vor dem Symbol, wenn links Text steht
 */
function user_menu(string $base, bool $mitTrenner = false): void
{
    $symbol = '<svg class="user-icon" viewBox="0 0 24 24" width="22" height="22" '
        . 'fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" '
        . 'stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>'
        . '<circle cx="12" cy="7.5" r="4"></circle></svg>';
    $u = current_user();

    if (!$u) {
        echo '<a class="user-icon-link" href="' . h($base) . '/admin/login.php" '
            . 'title="Wettkampfbüro" aria-label="Für das Wettkampfbüro anmelden">'
            . ($mitTrenner ? '<span class="user-divider" aria-hidden="true"></span>' : '')
            . $symbol . '</a>';
        return;
    }

    $anzeige = (string) ($u['display_name'] ?: $u['username']);
    echo '<details class="user-menu">';
    echo '<summary class="user-icon-link" title="Angemeldet als ' . h($anzeige) . '" '
        . 'aria-label="Konto von ' . h($anzeige) . '">'
        . ($mitTrenner ? '<span class="user-divider" aria-hidden="true"></span>' : '')
        . $symbol
        . '<span class="sr-only">Konto</span>'
        . '</summary>';
    echo '<div class="user-pop">';
    echo '<p class="user-who">' . h($anzeige) . '</p>';
    echo '<p class="user-role small">'
        . ((int) ($u['is_superadmin'] ?? 0) === 1 ? 'SuperAdmin' : 'Wettkampfleitung')
        . (!empty($u['club_name']) ? ' · ' . h((string) $u['club_name']) : '')
        . '</p>';
    echo '<a class="user-item" href="' . h($base) . '/admin/profil.php">Profil</a>';
    // Das Wettkampfbuero gehoert hierher, seit die oeffentliche Seite keine
    // Navigationsleiste mehr hat. Wer angemeldet ist und im oeffentlichen
    // Teil blättert, findet sonst keinen Weg zurueck in die Verwaltung.
    echo '<a class="user-item" href="' . h($base) . '/admin/index.php">Wettkampfbüro</a>';
    // Der Regiocup steht hier und nicht in der Leiste: er betrifft den
    // SuperAdmin und den einen eingestellten Verein, und keinen sonst. Wer ihn
    // sehen darf, soll ihn finden - ohne ihn allen anderen aufzudraengen.
    // Die Stammdaten der Piloten sind Programmsache, nicht Vereinssache:
    // ein Verein sieht seine Startliste, die Stammliste aller Piloten nicht.
    if ((int) ($u['is_superadmin'] ?? 0) === 1) {
        echo '<a class="user-item" href="' . h($base) . '/admin/stammdaten.php">Stammdaten</a>';
    }
    if (function_exists('region_club_id') && (is_superadmin() || region_darf_sehen())) {
        echo '<a class="user-item" href="' . h($base) . '/admin/regiocup.php">Regiocup</a>';
    }
    if ((int) ($u['is_superadmin'] ?? 0) === 1) {
        echo '<a class="user-item" href="' . h($base) . '/admin/benutzer.php">Benutzer verwalten</a>';
        // Beide Punkte stehen hier und nicht mehr in der Navigationsleiste. Sie
        // betreffen nur den SuperAdmin und gehoeren deshalb nicht in eine Leiste,
        // die alle Konten sehen - dort nahmen sie zwei Plaetze fuer einen Bruchteil
        // der Benutzer ein.
        echo '<a class="user-item" href="' . h($base) . '/admin/aktualisieren.php">Aktualisierung</a>';
    }
    echo '<form class="user-logout" method="post" action="' . h($base) . '/admin/logout.php">';
    echo csrf_field();
    echo '<button class="user-item" type="submit">Abmelden</button>';
    echo '</form>';
    echo '</div>';
    echo '</details>';
}

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
        // "abgesagt" vor "abgeschlossen": ein abgesagter Wettbewerb ist auch
        // abgeschlossen, und wer nur "abgeschlossen" laese, wuerde ihn fuer
        // einen durchgefuehrten Wettbewerb halten.
        if (!empty($selected['cancelled_at'])) {
            echo ' <span class="completion-badge">abgesagt</span>';
        } elseif (!empty($selected['completed_at'])) {
            echo ' <span class="completion-badge">abgeschlossen</span>';
        }
        if ($meta) { echo ' &nbsp;·&nbsp; ' . h(implode(' · ', $meta)); }
    }
    user_menu($base, $meta !== [] && !$ohneAbzeichen);
    echo '</div></div></header>';

    $links = $area === 'admin'
        ? [
            'index.php'       => 'Übersicht',
            'erfassung.php'   => 'Resultate erfassen',
            'wettbewerbe.php'     => 'Wettbewerbe',
            'durchgaenge.php' => 'Durchgänge',
            'piloten.php'     => 'Piloten',
            'stammdaten.php'  => 'Stammdaten',
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

    // Benutzerverwaltung und Aktualisierung sind dem SuperAdmin vorbehalten und
    // stehen deshalb im Benutzermenü oben rechts, nicht hier. In der Leiste
    // sassen sie nur bei einem Teil der Konten und nahmen zwei Plaetze ein.

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
        }
        // Ohne Konto steht hier nichts mehr. "Anmelden" direkt neben dem Punkt
        // "Anmeldung" sah aus wie ein Tippfehler und wurde dauernd verwechselt;
        // das Benutzersymbol oben rechts macht beides klar auseinander.
        echo '</div></nav>';
    }

    echo '<main' . ($wide ? ' class="wide"' : '') . '>';

    foreach (flash_take() as $f) {
        echo '<div class="flash ' . h($f['type']) . '">' . h($f['text']);
        if (!empty($f['link']['text']) && !empty($f['link']['href'])) {
            // Beide Felder einzeln maskiert. So bleibt die Meldung frei von
            // HTML, bekommt aber einen klickbaren Weg.
            echo ' <a class="flash-link" href="' . h((string) $f['link']['href']) . '">'
                . h((string) $f['link']['text']) . '</a>';
        }
        echo '</div>';
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

    $groups = ['Offene Wettbewerbe' => [], 'Abgeschlossene Wettbewerbe' => [], 'Abgesagte Wettbewerbe' => []];
    foreach ($competitions as $competition) {
        // Drei Gruppen statt zwei. Sonst stuende ein abgesagter Wettbewerb
        // unter den abgeschlossenen und damit so, als haette er stattgefunden.
        if (!empty($competition['cancelled_at'])) {
            $groups['Abgesagte Wettbewerbe'][] = $competition;
        } elseif (empty($competition['completed_at'])) {
            $groups['Offene Wettbewerbe'][] = $competition;
        } else {
            $groups['Abgeschlossene Wettbewerbe'][] = $competition;
        }
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
            if (!empty($competition['cancelled_at'])) {
                $marks[] = 'abgesagt';
            } elseif (!empty($competition['completed_at'])) {
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
 * @param bool  $mitRegion    die Regiorangliste als Kachel in derselben Reihe
 *                            anhängen, wenn sie wer sehen darf
 */
/**
 * Der Hinweis, den eine abgesagte Competition oben auf der Seite braucht.
 *
 * Ohne ihn laesst sich die Tabelle fuer ein vollstaendiges Ergebnis halten.
 * Genau das ist der Schaden: jemand zieht aus unvollstaendigen Werten eine
 * Schlussfolgerung ueber die eigene Leistung.
 *
 * Rueckgabe: der Satz als Text, oder '' wenn der Wettbewerb nicht abgesagt ist.
 * Das Ausgeben bleibt der Seite, weil sie es an der richtigen Stelle tun muss.
 */
function wettbewerb_abgesagt_hinweis(array $competition): string
{
    if (empty($competition['cancelled_at'])) {
        return '';
    }
    $stand = competition_result_progress((int) $competition['id']);
    if ($stand['total'] === 0 || $stand['completed'] === 0) {
        return 'Dieser Wettbewerb fand nicht statt und wurde abgesagt.';
    }
    return sprintf(
        'Dieser Wettbewerb wurde abgebrochen und fand nicht statt. '
        . 'Die %d von %d Ergebnissen sind unvollständig und zählen nicht für den Regiocup.',
        (int) $stand['completed'],
        (int) $stand['total']
    );
}

function competition_cards(array $wettbewerbe, bool $mitRegion = false): void
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
        // Die Kachel ist bei einem abgesagten Wettbewerb ausgegraut, wie bei
        // einem beendeten - er ist ja genauso nicht mehr zu aendern.
        $abgesagt = !empty($w['cancelled_at']);
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
        if ($abgesagt) {
            echo '<span class="tag">abgesagt</span>';
        } elseif ($beendet) {
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
        if ($abgesagt && $piloten > 0) {
            // Nur wenn wirklich Leute dranstanden: dann lohnt der Hinweis,
            // dass deren Ergebnisse nicht gewertet werden.
            echo '<span class="tag">nicht für den Regiocup gewertet</span>';
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
    if ($mitRegion) {
        region_card();
    }
    echo '</div>';
}

/**
 * Die Regiorangliste als Tabelle.
 *
 * Ausgelagert, weil sie an zwei Stellen steht: auf der oeffentlichen Seite und
 * in der Vorschau auf der Profilseite des SuperAdmins. Zwei Abschriften
 * waeren zwei Wahrheiten - die Vorschau waere dann die, die man nicht pflegt.
 *
 * @param array $daten        aus region_rangliste()
 * @param array $wettbewerbe  aus region_wettbewerbe(), in Anzeigereihenfolge
 */
function region_table(array $daten, array $wettbewerbe): void
{
    if (!$daten['zeilen']) {
        echo '<p class="lead">Für dieses Jahr sind noch keine gewerteten Resultate vorhanden.</p>';
        return;
    }
    ?>
    <div class="table-scroll">
    <table class="data">
        <thead>
        <tr>
            <th>Rang</th>
            <th>Pilot</th>
            <th>Verein</th>
            <?php foreach ($wettbewerbe as $w): ?>
                <th class="num" title="<?= h((string) $w['name']) ?>"><?= h(competition_kuerzel((string) $w['name'])) ?></th>
            <?php endforeach; ?>
            <th class="num">Punkte</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($daten['zeilen'] as $z):
            $podium = (int) ($z['rang'] ?? 0) > 0 && (int) $z['rang'] <= 3 ? ' podium-' . (int) $z['rang'] : '';
            ?>
            <tr class="<?= $podium ?>">
                <td class="rank"><?= (int) ($z['rang'] ?? 0) > 0 ? (int) $z['rang'] : '&ndash;' ?></td>
                <td><?= h($z['name']) ?></td>
                <td class="small muted"><?= h($z['club']) ?></td>
                <?php foreach ($wettbewerbe as $w):
                    $cid = (int) $w['id'];
                    $rang = $z['plaetze'][$cid] ?? null;
                    if ($rang === null) {
                        echo '<td class="num cell-empty">·</td>';
                        continue;
                    }
                    $istGestreichen = $z['gestrichen'] === $rang;
                    $punkte = region_fis_punkte((int) $rang);
                    $titel = $z['name'] . ' im Wettbewerb ' . $w['name'] . ': Rang ' . $rang . ' = '
                        . fmt_num($punkte) . ' Punkte'
                        . ($istGestreichen ? ' - dieser Start hat nicht gezaehlt' : '');
                    echo '<td class="num' . ($istGestreichen ? ' dropped' : '') . '" title="' . h($titel) . '">'
                        . h(fmt_num($punkte)) . '</td>';
                endforeach; ?>
                <td class="num total"><?= h(fmt_num($z['punkte'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="small muted" style="margin-bottom:0">
        Ein durchgestrichener Start hat nicht gezählt. Ein Punkt entspricht dem
        Rang im jeweiligen Wettbewerb: <?= h(implode(', ', array_slice(
            array_map(static function (int $r): string {
                return $r . '. Platz = ' . fmt_num(region_fis_punkte($r)) . ' Punkte';
            }, [1, 2, 3, 4, 5]), 0, 3))) ?> und so weiter.
    </p>
    <?php
}

/**
 * Kachel der Regiorangliste auf der Startseite.
 *
 * Sie erscheint nur, wenn das Jahr ueberhaupt einen Wettbewerb mit
 * Regiocup-Kennzeichen hat UND wer davor steht sie sehen darf
 * (region_darf_sehen()). Sind die Resultate sonst nirgends freigegeben, ist
 * das nur der SuperAdmin und der eingestellte Verein. Eine Kachel, die den
 * Weg zu einer gesperrten Liste zeigt, waere eine Sackgasse.
 */
function region_card(): void
{
    if (!region_darf_sehen()) {
        return;
    }
    $jahre = region_jahre();
    $jahr = $jahre ? (int) $jahre[0] : 0;
    $wettbewerbe = $jahr > 0 ? region_wettbewerbe($jahr) : [];
    if (!$wettbewerbe) {
        return;
    }
    $daten = region_rangliste(array_map(static function (array $w): int {
        return (int) $w['id'];
    }, $wettbewerbe));
    $zeilen = $daten['zeilen'] ?? [];
    $besten = [];
    foreach ($zeilen as $z) {
        if ((int) ($z['rang'] ?? 0) > 0 && (int) $z['rang'] <= 3) {
            $besten[] = $z['name'] . ' (' . fmt_num($z['punkte']) . ')';
        }
    }

    echo '<div class="pick pick-region">';
    // Ohne Jahr im Namen. Das Jahr stand vorher im Kachelnamen und machte die
    // Kachel zu einer von vielen mit Jahreszahlen - dabei sagt die Zahl nichts
    // ueber den Inhalt, und sie steht jetzt eine Zeile tiefer, wo sie zusammen
    // mit den Wettbewerben steht, zu denen sie gehoert.
    echo '<h3 class="pick-name"><a href="region.php">Regiorangliste</a></h3>';
    echo '<p class="pick-when">' . h((string) $jahr) . ' · ' . count($wettbewerbe)
        . (count($wettbewerbe) === 1 ? ' Wettbewerb' : ' Wettbewerbe')
        . ' · ' . region_anzahl_gewertet() . ' Starts zählen</p>';
    echo '<p class="pick-count">' . count($zeilen)
        . (count($zeilen) === 1 ? ' Pilot' : ' Piloten') . ' in der Wertung</p>';
    echo '<p class="pick-tags"><span class="tag trophy">Regiocup</span></p>';
    if ($besten) {
        echo '<p class="pick-when small">'
            . h('Vorne: ' . implode(' · ', array_slice($besten, 0, 3))) . '</p>';
    }
    // Ohne Jahresangabe im Link: region.php nimmt ohne Parameter das neueste
    // Jahr, und der Kachel zeigt ohnehin das neueste. Das Jahr im Link waere
    // nur eine zweite Stelle, an der es veralten kann.
    echo '<div class="pick-go"><a class="btn" href="region.php">Ansehen</a></div>';
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
 * Die Wettbewerbsauswahl fuer Unterseiten ist weg (1.9.16).
 *
 * Sie stand als Knopfleiste ueber Rangliste, Teilnehmerliste und Anmeldung und
 * war damit die Navigation des oeffentlichen Teils. Mit dem Wegfall der
 * Navigationsleiste ist sie ueberfluessig geworden: zur Startseite fuehrt der
 * Titel in der Kopfzeile, und dort stehen die Kacheln aller Wettbewerbe. Von
 * dort geht es mit einem Klick zur Rangliste und mit dem Knopf "Anmelden" zur
 * passenden Anmeldung.
 */
