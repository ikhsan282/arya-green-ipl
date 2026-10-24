<?php
$host = getenv('IPL_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('IPL_TEST_DB_PORT') ?: 33061);
$user = getenv('IPL_TEST_DB_USER') ?: 'root';
$pass = getenv('IPL_TEST_DB_PASS') ?: '';
$name = 'ipl_backup_test_' . bin2hex(random_bytes(4));
$backupDir = sys_get_temp_dir() . '/' . $name;
putenv('IPL_BACKUP_DIR=' . $backupDir);
define('DB_HOST', $host); define('DB_USER', $user); define('DB_PASS', $pass); define('DB_NAME', $name);
require_once __DIR__ . '/../includes/database_backup.php';
function assert_backup(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$server = new mysqli($host, $user, $pass, '', $port);
$server->query('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$db = new mysqli($host, $user, $pass, $name, $port);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db->query('CREATE TABLE parent (id INT UNSIGNED PRIMARY KEY, note VARCHAR(100) NOT NULL) ENGINE=InnoDB');
    $db->query('CREATE TABLE child (id INT UNSIGNED PRIMARY KEY, parent_id INT UNSIGNED NOT NULL, payload BLOB NULL, FOREIGN KEY(parent_id) REFERENCES parent(id)) ENGINE=InnoDB');
    $db->query("INSERT INTO parent VALUES (0, 'quoted \\' phrase')");
    $db->query("INSERT INTO child VALUES (1, 0, X'00FF5C')");
    $file = database_backup_create($db);
    $path = $backupDir . '/' . $file;
    assert_backup(database_backup_verify($path), 'created backup signature should pass');
    $db->query('DROP TABLE child'); $db->query('DROP TABLE parent');
    database_backup_restore($db, $path);
    assert_backup($db->query('SELECT note FROM parent WHERE id=0')->fetch_row()[0] === "quoted ' phrase", 'quote and zero primary key should roundtrip');
    assert_backup(bin2hex($db->query('SELECT payload FROM child WHERE id=1')->fetch_row()[0]) === '00ff5c', 'binary blob should roundtrip');
    file_put_contents($path, file_get_contents($path) . "-- altered\n");
    assert_backup(!database_backup_verify($path), 'tampered file must fail verification');
    echo "database_backup_roundtrip_test OK (backup, restore, FK, zero ID, quoted text, binary, tamper)\n";
} finally {
    $db->query('SET FOREIGN_KEY_CHECKS=0');
    $db->query('DROP TABLE IF EXISTS child'); $db->query('DROP TABLE IF EXISTS parent');
    $db->query('SET FOREIGN_KEY_CHECKS=1');
    $server->query('DROP DATABASE `' . $name . '`');
    foreach (glob($backupDir . '/*') ?: [] as $f) unlink($f);
    foreach (glob($backupDir . '/.*') ?: [] as $f) if (is_file($f)) unlink($f);
    @rmdir($backupDir);
}
