<?php
// One-time migration runner — DELETE THIS FILE after use
define('DIRECT_RUN', true);
require_once __DIR__ . '/config/database.php';

$db = db();

$sqls = [
    "INSERT IGNORE INTO `permissions` (`name`, `label`, `module`) VALUES ('environments.view','Lihat Lingkungan','environments')",
    "INSERT IGNORE INTO `permissions` (`name`, `label`, `module`) VALUES ('environments.create','Tambah Lingkungan','environments')",
    "INSERT IGNORE INTO `permissions` (`name`, `label`, `module`) VALUES ('environments.edit','Edit Lingkungan','environments')",
    "INSERT IGNORE INTO `permissions` (`name`, `label`, `module`) VALUES ('environments.delete','Hapus Lingkungan','environments')",
    // Beri ke role superadmin (id=1)
    "INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`) SELECT 1, id FROM `permissions` WHERE `name` IN ('environments.view','environments.create','environments.edit','environments.delete')",
];

echo '<pre>';
foreach ($sqls as $sql) {
    try {
        $db->query($sql);
        echo "OK: " . $sql . "\n";
    } catch (Exception $e) {
        echo "ERR: " . $e->getMessage() . "\n  on: " . $sql . "\n";
    }
}
echo "\nDone. Delete this file: unlink(__FILE__) or rm migrate.php\n";
echo '</pre>';
