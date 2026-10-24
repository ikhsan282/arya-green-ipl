<?php
$host=getenv('IPL_TEST_DB_HOST')?:'127.0.0.1'; $port=(int)(getenv('IPL_TEST_DB_PORT')?:33061); $user=getenv('IPL_TEST_DB_USER')?:'root'; $pass=getenv('IPL_TEST_DB_PASS')?:'';
$name='ipl_backup_rollback_'.bin2hex(random_bytes(4)); $dir=sys_get_temp_dir().'/'.$name; putenv('IPL_BACKUP_DIR='.$dir);
define('DB_HOST',$host); define('DB_USER',$user); define('DB_PASS',$pass); define('DB_NAME',$name); define('DB_PORT',$port); define('DB_CHARSET','utf8mb4');
require_once __DIR__.'/../includes/database_backup.php';
$server=new mysqli($host,$user,$pass,'',$port); $server->query('CREATE DATABASE `'.$name.'`');
$db=new mysqli($host,$user,$pass,$name,$port); mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
try {
 $db->query('CREATE TABLE record (id INT PRIMARY KEY, value_text VARCHAR(40)) ENGINE=InnoDB'); $db->query("INSERT INTO record VALUES (1,'before')");
 $snapshot=database_backup_create($db,'pre_restore');
 $bad=$dir.'/malformed.sql';
 // Signed, deliberately malformed import: drop existing data, then fail at the next statement.
 $payload="SET FOREIGN_KEY_CHECKS=0;\nDROP TABLE IF EXISTS `record`;\nTHIS IS NOT SQL;\n";
 $sig=hash_hmac('sha256',$payload,database_backup_key()); file_put_contents($bad,"-- ARYA_GREEN_IPL_BACKUP_V1 {$sig}\n".$payload);
 try { database_backup_restore($db,$bad); throw new RuntimeException('malformed import unexpectedly succeeded'); } catch (mysqli_sql_exception|RuntimeException $e) { if ($e->getMessage()==='malformed import unexpectedly succeeded') throw $e; }
 $rollback=new mysqli(DB_HOST,DB_USER,DB_PASS,DB_NAME,DB_PORT); $rollback->set_charset(DB_CHARSET); database_backup_restore($rollback,$dir.'/'.$snapshot);
 if ($rollback->query('SELECT value_text FROM record WHERE id=1')->fetch_row()[0] !== 'before') throw new RuntimeException('snapshot rollback did not restore original row');
 echo "database_backup_rollback_test OK (failed restore recovered from snapshot)\n";
} finally {
 $clean=new mysqli($host,$user,$pass,$name,$port); $clean->query('SET FOREIGN_KEY_CHECKS=0'); $clean->query('DROP TABLE IF EXISTS record'); $server->query('DROP DATABASE `'.$name.'`');
 foreach(glob($dir.'/*')?:[] as $f) @unlink($f); foreach(glob($dir.'/.*')?:[] as $f) if(is_file($f))@unlink($f); @rmdir($dir);
}
