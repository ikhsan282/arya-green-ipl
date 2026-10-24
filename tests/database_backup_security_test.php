<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/database_backup.php';
function check(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
$tmp = tempnam(sys_get_temp_dir(), 'ipl-backup-test-');
file_put_contents($tmp, "-- ARYA_GREEN_IPL_BACKUP_V1 " . str_repeat('0', 64) . "\nSET FOREIGN_KEY_CHECKS=0;\n");
check(!database_backup_verify($tmp), 'tampered backup must be rejected');
file_put_contents($tmp, "SELECT 1;\n");
check(!database_backup_verify($tmp), 'unsigned SQL must be rejected');
unlink($tmp);
$source = file_get_contents(__DIR__ . '/../pages/database_backup/index.php');
check(str_contains($source, "auth_role() !== 'super_admin'"), 'server authorization must restrict endpoint to super_admin');
check(str_contains($source, "csrf_verify()"), 'state changes must require CSRF protection');
check(str_contains($source, 'database_backup_create($db, \'pre_restore\')'), 'restore must snapshot before applying backup');
echo "database_backup_security_test OK (5 checks)\n";
