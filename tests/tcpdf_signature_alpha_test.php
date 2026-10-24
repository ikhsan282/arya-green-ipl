<?php
require_once __DIR__ . '/_cli_only.php';
require_once __DIR__ . '/../includes/functions.php';
$pdfSource = file_get_contents(__DIR__ . '/../pages/payments/download_receipt_pdf.php');
$assertSource = str_contains($pdfSource, 'tcpdf_signature_supported($signaturePath)');
if (!$assertSource) { fwrite(STDERR, "FAIL: PDF path does not guard alpha signatures\n"); exit(1); }
$chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
$png = static function (int $colorType) use ($chunk): string {
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 1, 1, 8, $colorType, 0, 0, 0))
        . $chunk('IDAT', gzcompress("\0" . str_repeat("\x80", 4))) . $chunk('IEND', '');
};
$opaque = tempnam(sys_get_temp_dir(), 'png-opaque-');
$alpha = tempnam(sys_get_temp_dir(), 'png-alpha-');
file_put_contents($opaque, $png(2));
file_put_contents($alpha, $png(6));
$assert = static function (bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$assert(tcpdf_signature_supported($opaque), 'opaque PNG remains supported');
$assert(tcpdf_signature_supported($alpha) === (extension_loaded('gd') || extension_loaded('imagick')), 'alpha PNG only used when TCPDF can read it');
unlink($opaque);
unlink($alpha);
echo "tcpdf_signature_alpha_test OK (2 assertions)\n";
