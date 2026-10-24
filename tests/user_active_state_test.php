<?php
require_once __DIR__ . '/_cli_only.php';
$form = file_get_contents(__DIR__ . '/../pages/users/form.php');
$preserve = "isset(\$_POST['manage_active']) ? (isset(\$_POST['is_active']) ? 1 : 0) : (\$editing ? (int)\$user['is_active'] : 1)";
if (!str_contains($form, $preserve) || !str_contains($form, 'name="manage_active"')) {
    fwrite(STDERR, "FAIL: signature upload must preserve status unless the active control was submitted\n");
    exit(1);
}
echo "user_active_state_test OK\n";
