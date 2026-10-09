<?php
// Self-check: builds a workbook and validates ZIP/OOXML structure. Run: php tests/xlsx_test.php
require_once __DIR__ . '/../includes/xlsx.php';

$bytes = xlsx_build([
    'Uji' => [
        ['No', 'Unit', 'Total'],
        [1, "A-01 & <B>\x01", 150000.0],
        [2, 'Ñama "q"', 0],
        [3, '', -5.5],
    ],
    'Dua' => [['x']],
]);

$tmp = sys_get_temp_dir() . '/xlsx_test_' . getmypid() . '.xlsx';
file_put_contents($tmp, $bytes);
assert(substr($bytes, 0, 4) === "PK\x03\x04", 'local header');
assert(str_contains($bytes, '[Content_Types].xml'), 'content types entry');
assert(str_contains($bytes, 'xl/worksheets/sheet2.xml'), 'second sheet');
assert(str_contains($bytes, 'A-01 &amp; &lt;B&gt;'), 'escaping');
assert(!str_contains($bytes, "A-01 \x01"), 'control char stripped');
assert(substr($bytes, -22, 4) === "PK\x05\x06", 'end of central dir');
unlink($tmp);
echo "xlsx_test OK\n";
