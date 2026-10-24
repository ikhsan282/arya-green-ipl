<?php
require_once __DIR__ . '/_cli_only.php';
$source = file_get_contents(__DIR__ . '/../pages/roles/index.php');
if (!str_contains($source, '$sel_role_id===(int)$r[\'id\']') || !str_contains($source, '(int)$r[\'id\']===$sel_role_id')) {
    fwrite(STDERR, "FAIL: role selection must normalize database IDs to integers\n");
    exit(1);
}
echo "roles_selected_role_test OK\n";
