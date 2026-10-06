<?php
require_once __DIR__ . '/includes/auth.php';
if (!empty($_SESSION['user_id'])) {
    redirect(APP_URL . '/pages/dashboard.php');
} else {
    redirect(APP_URL . '/auth/login.php');
}
