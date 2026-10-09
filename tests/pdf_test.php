<?php
// Self-check: builds a multi-page PDF and validates structure. Run: php tests/pdf_test.php
require_once __DIR__ . '/../includes/pdf.php';

$rows = [['No', 'Unit', 'Warga']];
for ($i = 1; $i <= 90; $i++) {
    $rows[] = [$i, 'A-' . $i, $i === 1 ? 'A (B) \\ C' : 'Warga ' . $i];
}
$pdf = pdf_build('Laporan (Uji)', $rows, ['Periode: Oktober 2026']);

assert(str_starts_with($pdf, "%PDF-1.4\n"), 'PDF header');
assert(str_ends_with($pdf, "%%EOF\n"), 'PDF EOF');
assert(str_contains($pdf, 'Laporan \\(Uji\\)'), 'title escaping');
assert(str_contains($pdf, 'A \\(B\\) \\\\ C'), 'row escaping');
assert(substr_count($pdf, '/Type /Page ') >= 2, 'automatic page break');

assert(preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $match) === 1, 'startxref');
$xref_offset = (int)$match[1];
assert(substr($pdf, $xref_offset, 5) === "xref\n", 'xref offset');
assert(preg_match('/xref\n0 (\d+)\n(.*?)trailer/s', substr($pdf, $xref_offset), $xref) === 1, 'xref table');
$entries = explode("\n", trim($xref[2]));
foreach (array_slice($entries, 1) as $object => $entry) {
    $offset = (int)substr($entry, 0, 10);
    assert(substr($pdf, $offset, strlen(($object + 1) . ' 0 obj')) === ($object + 1) . ' 0 obj', 'object xref ' . ($object + 1));
}

echo "pdf_test OK\n";
