<?php
/** Minimal PDF 1.4 writer: text lines + table rows, A4 landscape, Helvetica (WinAnsi). */

// Map a UTF-8 char to a WinAnsi byte. mbstring/iconv are unavailable, so decode by hand.
function pdf_char(string $ch): string {
    static $cp1252 = [0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84, 0x2026 => 0x85, 0x2020 => 0x86,
        0x2021 => 0x87, 0x02C6 => 0x88, 0x2030 => 0x89, 0x0160 => 0x8A, 0x2039 => 0x8B, 0x0152 => 0x8C, 0x017D => 0x8E,
        0x2018 => 0x91, 0x2019 => 0x92, 0x201C => 0x93, 0x201D => 0x94, 0x2022 => 0x95, 0x2013 => 0x96, 0x2014 => 0x97,
        0x02DC => 0x98, 0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B, 0x0153 => 0x9C, 0x017E => 0x9E, 0x0178 => 0x9F];
    $b = array_values(unpack('C*', $ch));
    $cp = match (count($b)) {
        1 => $b[0],
        2 => (($b[0] & 0x1F) << 6) | ($b[1] & 0x3F),
        3 => (($b[0] & 0x0F) << 12) | (($b[1] & 0x3F) << 6) | ($b[2] & 0x3F),
        default => (($b[0] & 0x07) << 18) | (($b[1] & 0x3F) << 12) | (($b[2] & 0x3F) << 6) | ($b[3] & 0x3F),
    };
    if ($cp < 0x20 || $cp === 0x7F) return ' ';
    if ($cp < 0x7F || ($cp >= 0xA0 && $cp <= 0xFF)) return chr($cp);
    return isset($cp1252[$cp]) ? chr($cp1252[$cp]) : '?';
}

// Encode a value as a PDF literal string: WinAnsi bytes, ( ) \ escaped.
function pdf_text(mixed $value): string {
    $out = '';
    foreach (preg_split('//u', (string)($value ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        $out .= pdf_char($ch);
    }
    return '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $out) . ')';
}

// Append one table row (shaded when $is_header) to $cur; returns the next y.
function pdf_row(string &$cur, array $cells, float $y, bool $is_header, int $left, int $width, int $row_h, int $size, float $col_w, int $max_chars): float {
    if ($is_header) $cur .= "0.9 g {$left} " . ($y - 4) . ' ' . ($width - 2 * $left) . " {$row_h} re f 0 g\n";
    foreach (array_values($cells) as $c => $value) {
        $text = (string)($value ?? '');
        if (mb_strlen_fallback($text) > $max_chars) $text = implode('', array_slice(preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, $max_chars - 2)) . '..';
        $x = round($left + $c * $col_w + 2, 2);
        $cur .= 'BT /F1 ' . $size . " Tf {$x} {$y} Td " . pdf_text($text) . " Tj ET\n";
    }
    return $y - $row_h;
}

function mb_strlen_fallback(string $s): int {
    return count(preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: []);
}

// rows[0] is the header (repeated on every page). $lines are printed above the table.
function pdf_build(string $title, array $rows, array $lines = []): string {
    $width = 842; $left = 28; $top = 560; $bottom = 40; $row_h = 14;
    $cols = max(1, count($rows[0] ?? [0]));
    $col_w = ($width - 2 * $left) / $cols;
    $size = $cols > 8 ? 6 : ($cols > 5 ? 7 : 9);
    $max_chars = max(4, (int)floor(($col_w - 4) / ($size * 0.5)));
    $header = $rows[0] ?? [];
    $body = array_slice($rows, 1);

    $pages = [];
    $cur = '';
    $y = 0;
    $start_page = function () use (&$pages, &$cur, &$y, $title, $lines, $header, $top, $left, $width, $row_h, $size, $col_w, $max_chars): void {
        if ($cur !== '') $pages[] = $cur;
        $cur = 'BT /F1 14 Tf ' . $left . ' ' . $top . ' Td ' . pdf_text($title) . " Tj ET\n";
        $y = $top - 20;
        foreach ($lines as $line) {
            $cur .= 'BT /F1 9 Tf ' . $left . ' ' . $y . ' Td ' . pdf_text($line) . " Tj ET\n";
            $y -= 13;
        }
        $y -= 4;
        if ($header) $y = pdf_row($cur, $header, $y, true, $left, $width, $row_h, $size, $col_w, $max_chars);
    };

    $start_page();
    foreach ($body as $row) {
        if ($y < $bottom + $row_h) $start_page();
        $y = pdf_row($cur, $row, $y, false, $left, $width, $row_h, $size, $col_w, $max_chars);
    }
    $pages[] = $cur;

    // Objects: 1 catalog, 2 pages, 3 font, then (page, content) pairs from 4.
    $n = count($pages);
    $kids = [];
    for ($i = 0; $i < $n; $i++) $kids[] = (4 + $i * 2) . ' 0 R';
    $objs = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
    ];
    foreach ($pages as $i => $stream) {
        $objs[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $width . ' 595] /Resources << /Font << /F1 3 0 R >> >> /Contents ' . (5 + $i * 2) . ' 0 R >>';
        $objs[] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream';
    }

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objs as $i => $obj) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $obj . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $count = count($objs) + 1;
    $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
    foreach ($offsets as $off) $pdf .= sprintf("%010d 00000 n \n", $off);
    return $pdf . "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
}

function pdf_download(string $filename, string $title, array $rows, array $lines = []): never {
    $ascii = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $filename);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo pdf_build($title, $rows, $lines);
    exit;
}
