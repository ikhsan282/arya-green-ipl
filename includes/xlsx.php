<?php
/** Minimal OOXML writer. It uses ZIP "store" entries so ZipArchive is not required. */
function xlsx_xml(string $value): string {
    // XML 1.0 forbids control chars; strip them so one bad name can't corrupt the sheet.
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsx_zip(string $name, string $data, int $offset): array {
    $crc = crc32($data);
    if ($crc < 0) $crc += 4294967296;
    $size = strlen($data);
    $name_len = strlen($name);
    $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $name_len, 0) . $name . $data;
    $central = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, $name_len, 0, 0, 0, 0, 0, $offset) . $name;
    return [$local, $central];
}

function xlsx_build(array $sheets): string {
    $files = [];
    $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    foreach (array_keys($sheets) as $i => $_) {
        $files['[Content_Types].xml'] .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $files['[Content_Types].xml'] .= '</Types>';
    $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach (array_keys($sheets) as $i => $name) {
        $sheet_id = $i + 1;
        $workbook .= '<sheet name="' . xlsx_xml((string)$name) . '" sheetId="' . $sheet_id . '" r:id="rId' . $sheet_id . '"/>';
        $rels .= '<Relationship Id="rId' . $sheet_id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheet_id . '.xml"/>';
    }
    $workbook .= '</sheets></workbook>';
    $rels .= '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $files['xl/workbook.xml'] = $workbook;
    $files['xl/_rels/workbook.xml.rels'] = $rels;
    $files['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    foreach (array_values($sheets) as $i => $rows) {
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $row_no => $row) {
            $excel_row = $row_no + 1;
            $sheet .= '<row r="' . $excel_row . '">';
            foreach (array_values($row) as $col_no => $value) {
                $column = '';
                $n = $col_no + 1;
                while ($n > 0) {
                    $n--;
                    $column = chr(65 + ($n % 26)) . $column;
                    $n = intdiv($n, 26);
                }
                $ref = $column . $excel_row;
                if (is_int($value) || is_float($value)) {
                    $sheet .= '<c r="' . $ref . '"><v>' . xlsx_xml((string)$value) . '</v></c>';
                } else {
                    $text = xlsx_xml((string)($value ?? ''));
                    $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $text . '</t></is></c>';
                }
            }
            $sheet .= '</row>';
        }
        $files['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $sheet . '</sheetData></worksheet>';
    }

    $body = $central = '';
    $offset = 0;
    foreach ($files as $name => $data) {
        [$local, $entry] = xlsx_zip($name, $data, $offset);
        $body .= $local;
        $central .= $entry;
        $offset += strlen($local);
    }
    $count = count($files);
    return $body . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), $offset, 0);
}

function xlsx_download(string $filename, array $sheets): never {
    $ascii = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $filename);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo xlsx_build($sheets);
    exit;
}
