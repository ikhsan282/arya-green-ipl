<?php
require_once __DIR__ . '/_cli_only.php';
$form = file_get_contents(__DIR__ . '/../pages/users/form.php');
$assert = static function (bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$assert(str_contains($form, 'if ($editing):') && str_contains($form, 'name="signature_file"'), 'signature upload form is available for every edited account');
$assert(!str_contains($form, 'in_array($targetRole, [\'ketua\', \'bendahara\']'), 'signature upload does not restrict role');
echo "signature_all_roles_test OK (2 assertions)\n";
