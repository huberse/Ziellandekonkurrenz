<?php
declare(strict_types=1);

require_once __DIR__ . '/pdf.php';

function build_runsheet_pdf(array $sheets, array $meta): string
{
    $pdf = new SimplePdf();
    $margin = 28.0;
    $contentWidth = $pdf->width() - (2 * $margin);
    // Die drei Ankreuzfelder bekommen zusammen gut ein Drittel der Breite, damit
    // ihre Beschriftung ohne Abschneiden in die Spalte passt (Summe 539 pt). Die
    // Titel kommen aus runsheet_penalty_boxes(), damit PDF, HTML und Legende
    // niemals unterschiedliche Bezeichnungen zeigen.
    $boxes = runsheet_penalty_boxes();
    $columns = [
        ['title' => 'Nr.', 'width' => 26.0],
        ['title' => 'Pilot / Verein', 'width' => 150.0],
        ['title' => 'Modell', 'width' => 78.0],
        ['title' => 'Flugzeit', 'width' => 62.0],
        ['title' => 'Landewert', 'width' => 52.0],
    ];
    foreach ($boxes as $box) {
        $columns[] = ['title' => $box['label'], 'width' => 57.0, 'check' => true, 'titleSize' => 7.0];
    }
    $legend = [];
    foreach ($boxes as $box) {
        $legend[] = $box['label'] . ' ' . fmt_num(fixed_penalty($box['setting']));
    }
    $penaltyNote = 'Abweichung vom Ziel ankreuzen – kein Feld = geflogen · '
        . implode(' · ', $legend)
        . ' · Aussenlandung und Motor zusammen ergeben die Summe';

    // Beschriftung so gross wie möglich, aber nie breiter als ihre Spalte: erst
    // die Schriftgrösse verkleinern, und wenn das nicht reicht, kürzen. Damit kann
    // keine Beschriftung über den Rand ragen, egal wie sie lautet. Die Grenzen
    // werden zur Laufzeit gemessen, nicht geschätzt.
    $fitTitle = static function (string $text, float $width, float $max, float $min = 5.4): array {
        $limit = $width - 4.0;
        for ($size = $max; $size > $min; $size -= 0.2) {
            if (SimplePdf::textWidth($text, $size, true) <= $limit) {
                return [$text, $size];
            }
        }
        $chars = strlen($text);
        while ($chars > 3 && SimplePdf::textWidth(substr($text, 0, $chars), $min, true) > $limit) {
            $chars--;
        }
        return [substr($text, 0, $chars), $min];
    };
    $tableBottom = 755.0;
    $headerHeight = 26.0;
    $footerTop = 790.0;
    $totalPages = count($sheets);
    $dark = [0.08, 0.18, 0.32];
    $accent = [0.12, 0.30, 0.52];
    $muted = [0.30, 0.35, 0.40];
    $grid = [0.62, 0.68, 0.73];

    foreach ($sheets as $pageIndex => $sheet) {
        $pdf->addPage();
        $pdf->rectTop(0, 0, $pdf->width(), 68, [0.95, 0.965, 0.98], null);

        $competition = simple_pdf_truncate((string) ($meta['competition_name'] ?? 'Wettbewerb'), 48);
        $date = trim((string) ($meta['date'] ?? ''));
        $place = trim((string) ($meta['place'] ?? ''));
        $datePlace = trim(($date !== '' ? $date . ' · ' : '') . $place, " ·");
        $pdf->textTop($margin, 20, 15, $competition, true, $dark);
        if ($datePlace !== '') {
            $pdf->textTop($margin, 43, 8.5, simple_pdf_truncate($datePlace, 70), false, $muted);
        }
        $pdf->textRight($pdf->width() - $margin, 20, 11, 'Durchgang ' . (int) $sheet['round']['round_number'], true, $dark);
        $pdf->textRight($pdf->width() - $margin, 41, 8.5, 'Zielzeit ' . fmt_time((float) $sheet['round']['target_time_seconds']), false, $muted);
        $pdf->lineTop($margin, 68, $pdf->width() - $margin, 68, 1.4, $accent);

        $sectionTop = 78.0;
        $pdf->rectTop(0, $sectionTop, $pdf->width(), 25, [0.90, 0.94, 0.98], null);
        $pdf->textTop($margin, $sectionTop + 7, 10.5, (string) $sheet['title'], true, $dark);
        $pilotLabel = count($sheet['pilots']) . ' Pilot' . (count($sheet['pilots']) === 1 ? '' : 'en');
        $pdf->textRight($pdf->width() - $margin, $sectionTop + 8, 8.5, $pilotLabel, false, $muted);

        $tableTop = $sectionTop + 32;
        $pdf->textTop($margin, $tableTop - 9, 6.8, simple_pdf_truncate($penaltyNote, 108), false, $muted);
        $hasNone = !empty($sheet['has_none']);
        if ($hasNone) {
            $pdf->rectTop($margin, $tableTop, $contentWidth, 14, [1.0, 0.96, 0.78], [0.78, 0.68, 0.35], 0.45);
            $pdf->textTop($margin + 6, $tableTop + 4, 7.2, 'Ohne Startnummer – bitte vor dem Wettbewerb zuweisen', true, [0.35, 0.28, 0.05]);
            $tableTop += 14;
        }

        $availableRows = $tableBottom - $tableTop - $headerHeight;
        $pilotCount = count($sheet['pilots']);
        if ($pilotCount > 0) {
            $rowHeight = min(56.0, max(15.0, floor($availableRows / $pilotCount)));
        } else {
            $rowHeight = 28.0;
        }

        // Tabellenkopf
        $x = $margin;
        foreach ($columns as $column) {
            $pdf->rectTop($x, $tableTop, $column['width'], $headerHeight, $accent, $accent, 0.5);
            $title = $column['title'];
            $titleSize = (float) ($column['titleSize'] ?? 7.4);
            if (!empty($column['check'])) {
                [$title, $titleSize] = $fitTitle($title, $column['width'], $titleSize);
            }
            $pdf->textCenter($x, $column['width'], $tableTop + 8, $titleSize, $title, true, [1, 1, 1]);
            $x += $column['width'];
        }

        if ($pilotCount === 0) {
            $pdf->rectTop($margin, $tableTop + $headerHeight, $contentWidth, 30, [1, 1, 1], $grid, 0.45);
            $pdf->textCenter($margin, $contentWidth, $tableTop + $headerHeight + 11, 8, 'Keine Piloten in dieser Gruppe.', false, $muted);
        }

        foreach ($sheet['pilots'] as $pilotIndex => $pilot) {
            $top = $tableTop + $headerHeight + ($pilotIndex * $rowHeight);
            $rowFill = $pilotIndex % 2 === 0 ? [1, 1, 1] : [0.975, 0.982, 0.992];
            $x = $margin;
            foreach ($columns as $column) {
                $pdf->rectTop($x, $top, $column['width'], $rowHeight, $rowFill, $grid, 0.4);
                $x += $column['width'];
            }

            $number = $pilot['bib_number'] ?: '–';
            $pdf->textCenter($margin, $columns[0]['width'], $top + ($rowHeight / 2) - 4, 8.3, simple_pdf_truncate((string) $number, 8), true, $dark);

            $nameX = $margin + $columns[0]['width'];
            $name = simple_pdf_truncate(full_name($pilot), $rowHeight >= 20 ? 34 : 25);
            $nameY = $top + ($rowHeight / 2) - ($rowHeight >= 22 && !empty($pilot['club_name']) ? 8 : 4);
            $pdf->textTop($nameX + 6, $nameY, 8.4, $name, true, $dark);
            if ($rowHeight >= 22 && !empty($pilot['club_name'])) {
                $pdf->textTop($nameX + 6, $top + ($rowHeight / 2) + 2, 6.8, simple_pdf_truncate($pilot['club_name'], 42), false, $muted);
            }

            $modelX = $nameX + $columns[1]['width'];
            $model = simple_pdf_truncate($pilot['model_name'] ?: ($pilot['model_type_name'] ?: '–'), 23);
            $pdf->textCenter($modelX, $columns[2]['width'], $top + ($rowHeight / 2) - 4, 7.8, $model, false, [0.12, 0.12, 0.12]);

            $timeX = $modelX + $columns[2]['width'];
            $landingX = $timeX + $columns[3]['width'];
            $writeY = $top + ($rowHeight / 2) + 3;
            $pdf->lineTop($timeX + 7, $writeY, $timeX + $columns[3]['width'] - 7, $writeY, 0.45, [0.55, 0.60, 0.64]);
            $pdf->lineTop($landingX + 7, $writeY, $landingX + $columns[4]['width'] - 7, $writeY, 0.45, [0.55, 0.60, 0.64]);

            $checkX = $landingX + $columns[4]['width'];
            $checkboxSize = 12.0;
            for ($c = 5; $c < count($columns); $c++) {
                $column = $columns[$c];
                $pdf->checkboxTop($checkX + ($column['width'] - $checkboxSize) / 2, $top + ($rowHeight - $checkboxSize) / 2, $checkboxSize);
                $checkX += $column['width'];
            }
        }

        // Übergabequittung
        $pdf->lineTop($margin, $footerTop, $pdf->width() - $margin, $footerTop, 0.65, [0.45, 0.50, 0.55]);
        $pdf->textTop($margin, $footerTop + 9, 7.5, 'Zeitnehmer: ____________________', false, $muted);
        $pdf->textTop($margin + 160, $footerTop + 9, 7.5, 'Übergeben an: ____________________', false, $muted);
        $pdf->textTop($margin + 350, $footerTop + 9, 7.5, 'Datum / Uhrzeit: ____________________', false, $muted);
        $pdf->textRight($pdf->width() - $margin, $footerTop + 9, 7, 'Blatt ' . ($pageIndex + 1) . '/' . $totalPages, true, $muted);
    }

    return $pdf->render();
}
