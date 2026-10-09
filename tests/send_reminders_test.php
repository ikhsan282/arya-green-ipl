<?php
// Regression self-check. Run: php -d zend.assertions=1 tests/send_reminders_test.php
$page = file_get_contents(__DIR__ . '/../pages/billing/send_reminders.php');
assert($page !== false, 'reminder page readable');

assert(str_contains($page, "require_permission('billing.send_reminder')"), 'permission remains enforced');
assert(str_contains($page, 'csrf_verify()'), 'POST remains CSRF protected');
assert(str_contains($page, "name=\"action\" value=\"test_email\""), 'test action is isolated');
assert(str_contains($page, 'FILTER_VALIDATE_EMAIL'), 'server-side email validation');
assert(str_contains($page, "'[TEST] Verifikasi Konfigurasi Email"), 'clear test subject');
assert(str_contains($page, "mail_template(\n            'Test Konfigurasi Email'"), 'safe mail template used');
assert(str_contains($page, 'send_mail($test_email, $subject, $body)'), 'mail helper used');
assert(str_contains($page, 'log_email(\'test_email\', $test_email, \'\', $subject, 0, $ok)'), 'test result logged with ref_id 0');
assert(str_contains($page, '$counts = run_email_reminders()'), 'batch reminder flow remains available');

assert(filter_var('admin@example.com', FILTER_VALIDATE_EMAIL) !== false, 'valid email accepted');
assert(filter_var("admin@example.com\r\nBcc: attacker@example.com", FILTER_VALIDATE_EMAIL) === false, 'header injection rejected');
assert(filter_var('bukan-email', FILTER_VALIDATE_EMAIL) === false, 'invalid email rejected');

echo "send_reminders_test OK\n";
