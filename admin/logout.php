<?php
ini_set('display_errors', 0); // Hide errors in production
ini_set('log_errors', 1);
error_log("Starting admin logout.php");

ob_start();
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Define SITE_URL if not already defined
if (!defined('SITE_URL')) {
    define('SITE_URL', 'https://pnevmatpro.ru/');
}

// Log logout activity
try {
    $admin_id = $_SESSION['admin']['id'] ?? null;
    $username = $_SESSION['admin']['username'] ?? 'unknown';
    $description = "Деаутентификация администратора $username";

    if ($admin_id) {
        logActivity($pdo, 'admin_logout', $description, $admin_id, null);
    } else {
        logActivity($pdo, 'logout_attempt', "Деаутентификация неизвестного администратора", null, null);
    }
} catch (PDOException $e) {
    error_log("Ошибка записи лога выхода администратора: " . $e->getMessage());
}

// Clear remember-me token in database
try {
    if ($admin_id) {
        clear_admin_remember_token($pdo, $admin_id);
    }
} catch (PDOException $e) {
    error_log("Ошибка очистки remember_token для администратора: " . $e->getMessage());
}

// Clear remember-me cookies
setcookie('admin_remember_token', '', time() - 3600, '/', '', true, true);
setcookie('admin_id', '', time() - 3600, '/', '', true, true);

// Clear session cookies
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Clear session
$_SESSION = [];
session_unset();
session_destroy();

// Redirect to admin login
$redirect_url = SITE_URL . '/admin/login.php';
error_log("Redirecting to $redirect_url");
header('Location: ' . $redirect_url);
ob_end_flush();
exit();
?>