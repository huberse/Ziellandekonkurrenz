<?php
declare(strict_types=1);

/**
 * Kleiner, abhängigkeitsfreier PDF-Generator für Text und Linien.
 * Verwendet die eingebauten Helvetica-Fonts mit WinAnsiEncoding.
 */
class SimplePdf
{
    private $width = 595.28;
    private $height = 841.89;
    private $pages = [];
    private $current = -1;

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->current = count($this->pages) - 1;
    }

    public function width(): float
    {
        return $this->width;
    }

    public function height(): float
    {
        return $this->height;
    }

    public function textTop(float $x, float $top, float $size, string $text, bool $bold = false, array $rgb = [0, 0, 0]): void
    {
        $text = self::cleanText($text);
        if ($text === '') {
            return;
        }
        $font = $bold ? '/F2' : '/F1';
        $y = $this->height - $top - $size;
        $r = (float) ($rgb[0] ?? 0);
        $g = (float) ($rgb[1] ?? 0);
        $b = (float) ($rgb[2] ?? 0);
        $this->command("BT {$font} {$size} Tf {$r} {$g} {$b} rg 1 0 0 1 {$x} {$y} Tm (" . self::escape($text) . ") Tj ET");
    }

    /** Text an einer festen rechten Kante ausrichten. */
    public function textRight(float $right, float $top, float $size, string $text, bool $bold = false, array $rgb = [0, 0, 0]): void
    {
        $this->textTop($right - self::textWidth($text, $size, $bold), $top, $size, $text, $bold, $rgb);
    }

    /** Text in einer definierten Breite zentrieren. */
    public function textCenter(float $left, float $boxWidth, float $top, float $size, string $text, bool $bold = false, array $rgb = [0, 0, 0]): void
    {
        $this->textTop($left + max(0, ($boxWidth - self::textWidth($text, $size, $bold)) / 2), $top, $size, $text, $bold, $rgb);
    }

    public function lineTop(float $x1, float $top1, float $x2, float $top2, float $width = 0.5, array $rgb = [0.25, 0.25, 0.25]): void
    {
        $y1 = $this->height - $top1;
        $y2 = $this->height - $top2;
        $this->command(sprintf(
            "q %.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S Q",
            $rgb[0], $rgb[1], $rgb[2], $width, $x1, $y1, $x2, $y2
        ));
    }

    public function rectTop(float $x, float $top, float $w, float $h, ?array $fill = null, ?array $stroke = [0.25, 0.25, 0.25], float $lineWidth = 0.5): void
    {
        $y = $this->height - $top - $h;
        $parts = ['q'];
        if ($fill !== null) {
            $parts[] = sprintf('%.3F %.3F %.3F rg', $fill[0], $fill[1], $fill[2]);
        }
        if ($stroke !== null) {
            $parts[] = sprintf('%.3F %.3F %.3F RG %.2F w', $stroke[0], $stroke[1], $stroke[2], $lineWidth);
        }
        $parts[] = sprintf('%.2F %.2F %.2F %.2F re', $x, $y, $w, $h);
        $parts[] = ($fill !== null && $stroke !== null) ? 'B' : (($fill !== null) ? 'f' : 'S');
        $parts[] = 'Q';
        $this->command(implode(' ', $parts));
    }

    public function checkboxTop(float $x, float $top, float $size = 10): void
    {
        $this->rectTop($x, $top, $size, $size, null, [0.15, 0.15, 0.15], 0.8);
    }

    public function render(): string
    {
        if (!$this->pages) {
            $this->addPage();
        }

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $pageIds = [];
        $nextId = 5;
        foreach ($this->pages as $content) {
            $contentId = $nextId++;
            $pageId = $nextId++;
            $pageIds[] = $pageId;
            $objects[$contentId] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '
                . $this->width . ' ' . $this->height . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '
                . $contentId . ' 0 R >>';
        }
        $kids = implode(' ', array_map(static function (int $id): string {
            return $id . ' 0 R';
        }, $pageIds));
        $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pageIds) . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        ksort($objects);
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= count($objects); $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
        return $pdf;
    }

    public static function textWidth(string $text, float $size, bool $bold = false): float
    {
        // Für die Breite die UTF-8-Zeichen verwenden; cleanText würde sie
        // vorher in ein einByte-Encoding umwandeln.
        $text = strtr($text, [
            "\u{2013}" => '-', "\u{2014}" => '-', "\u{2011}" => '-',
            "\u{2212}" => '-', "\u{2026}" => '...', "\u{00A0}" => ' ',
        ]);
        $text = preg_replace('/[\r\n\t]+/', ' ', $text) ?? $text;
        $factor = $bold ? 1.04 : 1.0;
        $units = 0;
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = function_exists('mb_substr') ? mb_substr($text, $i, 1, 'UTF-8') : $text[$i];
            $units += self::charWidth($char);
        }
        return ($units * $size / 1000) * $factor;
    }

    private function command(string $command): void
    {
        if ($this->current < 0) {
            $this->addPage();
        }
        $this->pages[$this->current] .= $command . "\n";
    }

    private static function cleanText(string $text): string
    {
        // WinAnsiEncoding kann einige typografische Unicode-Zeichen nicht sauber
        // darstellen. Sie werden vor der Konvertierung in sichere PDF-Zeichen
        // überführt.
        $text = strtr($text, [
            "\u{2013}" => '-',   // en dash
            "\u{2014}" => '-',   // em dash
            "\u{2011}" => '-',   // non-breaking hyphen
            "\u{2212}" => '-',   // minus
            "\u{2026}" => '...', // ellipsis
            "\u{00A0}" => ' ',   // no-break space
        ]);
        $text = preg_replace('/[\r\n\t]+/', ' ', $text) ?? $text;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                return $converted;
            }
        }
        return strtr($text, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
        ]);
    }

    private static function charWidth(string $char): int
    {
        static $widths = [
            ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667,
            "'" => 191, '(' => 333, ')' => 333, '*' => 389, '+' => 584, ',' => 278, '-' => 333,
            '.' => 278, '/' => 278, '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556,
            '5' => 556, '6' => 556, '7' => 556, '8' => 556, '9' => 556, ':' => 278, ';' => 278,
            '<' => 584, '=' => 584, '>' => 584, '?' => 556, '@' => 1015, 'A' => 667, 'B' => 667,
            'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778, 'H' => 722, 'I' => 278,
            'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778, 'P' => 667,
            'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944,
            'X' => 667, 'Y' => 667, 'Z' => 611, '[' => 278, '\\' => 278, ']' => 278, '^' => 469,
            '_' => 556, '`' => 333, 'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556,
            'f' => 278, 'g' => 556, 'h' => 556, 'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222,
            'm' => 833, 'n' => 556, 'o' => 556, 'p' => 556, 'q' => 556, 'r' => 333, 's' => 500,
            't' => 278, 'u' => 556, 'v' => 500, 'w' => 722, 'x' => 500, 'y' => 500, 'z' => 500,
            'Ä' => 667, 'Ö' => 778, 'Ü' => 722, 'ä' => 556, 'ö' => 556, 'ü' => 556, 'ß' => 611,
            '·' => 333,
        ];
        return $widths[$char] ?? 556;
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $text);
    }
}

function simple_pdf_text_length(string $text): int
{
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

function simple_pdf_truncate(string $text, int $maxChars): string
{
    if (simple_pdf_text_length($text) <= $maxChars) {
        return $text;
    }
    $cut = function_exists('mb_substr') ? mb_substr($text, 0, max(0, $maxChars - 3), 'UTF-8') : substr($text, 0, max(0, $maxChars - 3));
    return $cut . '...';
}
