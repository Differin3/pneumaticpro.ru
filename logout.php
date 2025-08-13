<?php
ini_set('display_errors', 0); // Hide errors in production
ini_set('log_errors', 1);
error_log("Starting user logout.php at " . date('Y-m-d H:i:s'));

ob_start();
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// Define SITE_URL if not already defined
if (!defined('SITE_URL')) {
    define('SITE_URL', 'https://pnevmatpro.ru/');
}

// Check if session is active before logging
if (session_status() === PHP_SESSION_ACTIVE) {
    try {
        $user_id = $_SESSION['user_id'] ?? null;
        $username = $_SESSION['username'] ?? 'unknown';
        $description = "Деаутентификация пользователя $username";

        if ($user_id) {
            logActivity($pdo, 'user_logout', $description, null, $user_id);
        } else {
            logActivity($pdo, 'logout_attempt', "Деаутентификация неизвестного пользователя", null, null);
        }
    } catch (PDOException $e) {
        error_log("Ошибка записи лога выхода пользователя: " . $e->getMessage());
    }
} else {
    error_log("Session not active during logout attempt");
}

// Clear remember-me token in database
try {
    if (isset($user_id) && $user_id) {
        clear_remember_token($pdo, $user_id);
    }
} catch (PDOException $e) {
    error_log("Ошибка очистки remember_token для пользователя: " . $e->getMessage());
}

// Clear remember-me cookies
setcookie('remember_token', '', time() - 3600, '/', '', true, true);
setcookie('user_id', '', time() - 3600, '/', '', true, true);

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
error_log("Session destroyed and cookies cleared");

// Redirect to main index
$redirect_url = SITE_URL . '/index.php';
if (headers_sent()) {
    error_log("Headers already sent, using meta refresh as fallback");
    echo '<meta http-equiv="refresh" content="0;url=' . $redirect_url . '">';
} else {
    error_log("Redirecting to $redirect_url");
    header('Location: ' . $redirect_url);
}
ob_end_flush();
exit();
?>