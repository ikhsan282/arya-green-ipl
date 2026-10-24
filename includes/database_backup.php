<?php
// Private, signed SQL backups built with the configured MySQLi connection.
function database_backup_dir(): string {
    $dir = getenv('IPL_BACKUP_DIR') ?: dirname(__DIR__, 3) . '/arya_ipl_private_backups';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Folder backup privat tidak dapat dibuat.');
    }
    if (!is_writable($dir)) throw new RuntimeException('Folder backup privat tidak dapat ditulis.');
    @chmod($dir, 0700);
    return $dir;
}

function database_backup_key(): string {
    $path = database_backup_dir() . '/.backup-key';
    $key = @file_get_contents($path);
    if (is_string($key) && strlen($key) === 32) return $key;
    $key = random_bytes(32);
    $fh = @fopen($path, 'xb');
    if ($fh) {
        fwrite($fh, $key);
        fclose($fh);
        @chmod($path, 0600);
        return $key;
    }
    $existing = @file_get_contents($path);
    if (is_string($existing) && strlen($existing) === 32) return $existing;
    throw new RuntimeException('Kunci backup privat tidak dapat dibuat atau dibaca.');
}

function database_backup_create(mysqli $db, string $prefix = 'backup'): string {
    $dir = database_backup_dir();
    $payload = tempnam($dir, '.dump-');
    if ($payload === false) throw new RuntimeException('Gagal membuat file sementara.');
    $out = fopen($payload, 'wb');
    if (!$out) { @unlink($payload); throw new RuntimeException('Gagal menulis file backup.'); }
    $write = static function (string $text) use ($out): void {
        if (fwrite($out, $text) !== strlen($text)) throw new RuntimeException('Penulisan backup tidak lengkap.');
    };
    try {
        $db->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $write("SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
        $tables = $db->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetch_all(MYSQLI_NUM);
        foreach ($tables as $item) {
            $table = (string)$item[0];
            $quoted = '`' . str_replace('`', '``', $table) . '`';
            $create = $db->query('SHOW CREATE TABLE ' . $quoted)->fetch_row()[1] ?? '';
            if ($create === '') throw new RuntimeException('Struktur tabel tidak dapat dibaca: ' . $table);
            $write("DROP TABLE IF EXISTS {$quoted};\n{$create};\n");
            $result = $db->query('SELECT * FROM ' . $quoted, MYSQLI_USE_RESULT);
            $fields = $result->fetch_fields();
            while ($row = $result->fetch_row()) {
                $values = [];
                foreach ($row as $i => $value) {
                    if ($value === null) { $values[] = 'NULL'; continue; }
                    $type = $fields[$i]->type;
                    if (in_array($type, [249, 250, 251, 252, 255], true)) {
                        $values[] = "X'" . bin2hex($value) . "'";
                    } else {
                        $values[] = "'" . $db->real_escape_string((string)$value) . "'";
                    }
                }
                $write('INSERT INTO ' . $quoted . ' VALUES (' . implode(',', $values) . ");\n");
            }
            $result->free();
        }
        $db->commit();
        $write("SET FOREIGN_KEY_CHECKS=1;\n");
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable) {}
        fclose($out); @unlink($payload); throw $e;
    }
    fclose($out);

    $sig = hash_hmac_file('sha256', $payload, database_backup_key());
    if ($sig === false) { @unlink($payload); throw new RuntimeException('Gagal menandatangani backup.'); }
    $name = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.sql';
    $final = $dir . '/' . $name;
    $dst = fopen($final, 'wb');
    $src = fopen($payload, 'rb');
    if (!$dst || !$src) { if ($dst) fclose($dst); if ($src) fclose($src); @unlink($payload); throw new RuntimeException('Gagal menyimpan backup.'); }
    fwrite($dst, "-- ARYA_GREEN_IPL_BACKUP_V1 {$sig}\n");
    if (stream_copy_to_stream($src, $dst) === false) { fclose($dst); fclose($src); @unlink($payload); @unlink($final); throw new RuntimeException('Gagal menyalin backup.'); }
    fclose($dst); fclose($src); @unlink($payload); @chmod($final, 0600);
    return $name;
}

function database_backup_verify(string $file): bool {
    $fh = @fopen($file, 'rb');
    if (!$fh) return false;
    $header = fgets($fh, 160);
    if (!is_string($header) || !preg_match('/^-- ARYA_GREEN_IPL_BACKUP_V1 ([a-f0-9]{64})\n$/D', $header, $m)) { fclose($fh); return false; }
    $ctx = hash_init('sha256', HASH_HMAC, database_backup_key());
    while (!feof($fh)) {
        $chunk = fread($fh, 1024 * 1024);
        if ($chunk === false) { fclose($fh); return false; }
        hash_update($ctx, $chunk);
    }
    fclose($fh);
    return hash_equals($m[1], hash_final($ctx));
}

function database_backup_restore(mysqli $db, string $file): void {
    if (!database_backup_verify($file)) throw new RuntimeException('File bukan backup aplikasi yang valid atau isinya berubah.');
    $sql = file_get_contents($file);
    if (!is_string($sql)) throw new RuntimeException('File backup tidak dapat dibaca.');
    $sql = preg_replace('/^-- ARYA_GREEN_IPL_BACKUP_V1 [a-f0-9]{64}\n/', '', $sql, 1);
    if (!is_string($sql) || !str_starts_with($sql, 'SET FOREIGN_KEY_CHECKS=0;')) throw new RuntimeException('Format backup tidak valid.');
    if (!$db->multi_query($sql)) throw new RuntimeException('Restore gagal dijalankan.');
    do {
        if ($result = $db->store_result()) $result->free();
        if (!$db->more_results()) break;
        if (!$db->next_result()) throw new RuntimeException('Restore terhenti saat mengeksekusi SQL backup.');
    } while (true);
}
