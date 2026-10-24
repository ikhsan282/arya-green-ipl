<?php
require_once __DIR__ . '/_cli_only.php';
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$users = file_get_contents(__DIR__ . '/../pages/users/form.php');
$print = file_get_contents(__DIR__ . '/../pages/payments/print_receipt.php');
$pdf = file_get_contents(__DIR__ . '/../pages/payments/download_receipt_pdf.php');
$letter = file_get_contents(__DIR__ . '/../pages/letters/print.php');
$assert = static function (bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$assert(str_contains($schema, '`signature_file`') && str_contains($schema, 'VARCHAR(255) DEFAULT NULL'), 'users schema stores signature filename');
$assert(str_contains($users, 'save_signature_upload') && str_contains($users, 'signature_file'), 'user form uploads and saves signature');
$assert(str_contains($print, 'signature_image_data_uri') && str_contains($print, 'bendahara'), 'print receipt shows treasurer signature');
$assert(str_contains($pdf, 'Image($signaturePath'), 'PDF receipt embeds treasurer signature');
$assert(str_contains($letter, 'signature_image_data_uri') && str_contains($letter, 'ketua'), 'letter print shows chair signature');
echo "signature_workflow_test OK (5 assertions)\n";
