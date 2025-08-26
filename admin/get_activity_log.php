<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Убедимся, что ничего не выводится до JSON
ob_start();
header('Content-Type: application/json; charset=utf-8');

// Проверка авторизации администратора
if (!isset($_SESSION['admin']) || !isset($_SESSION['admin']['id'])) {
    http_response_code(403);
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Доступ запрещен'], JSON_UNESCAPED_UNICODE);
    exit();
}

// Проверка CSRF-токена
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Недействительный CSRF-токен'], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    // Pagination parameters
    $page = max(1, (int)($_POST['page'] ?? 1));
    $per_page = max(1, (int)($_POST['per_page'] ?? 10));
    $offset = ($page - 1) * $per_page;

    // Total count of logs
    $count_stmt = $pdo->query("SELECT COUNT(*) FROM activity_log");
    $total_logs = $count_stmt->fetchColumn();
    $total_pages = ceil($total_logs / $per_page);

    // Fetch logs with pagination, including user_agent
    $stmt = $pdo->prepare("
        SELECT al.created_at, al.type, al.description, a.username AS admin_username, al.ip_address, al.user_agent
        FROM activity_log al
        LEFT JOIN admins a ON al.admin_id = a.id
        ORDER BY al.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Форматирование данных
    foreach ($logs as &$log) {
        $log['created_at'] = date('d.m.Y H:i:s', strtotime($log['created_at']));
        $log['type'] = htmlspecialchars($log['type']);
        $log['description'] = htmlspecialchars($log['description']);
        $log['admin_username'] = htmlspecialchars($log['admin_username'] ?? '-');
        $log['ip_address'] = htmlspecialchars($log['ip_address'] ?? '-');
        $log['user_agent'] = htmlspecialchars($log['user_agent'] ?? '');
    }

    // Формирование ответа
    $response = [
        'status' => 'success',
        'logs' => $logs,
        'total_pages' => $total_pages
    ];

    ob_end_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("Database error in get_activity_log.php: " . $e->getMessage());
    http_response_code(500);
    ob_end_clean();
    echo json_encode([
        'status' => 'error',
        'message' => 'Ошибка базы данных',
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("General error in get_activity_log.php: " . $e->getMessage());
    http_response_code(500);
    ob_end_clean();
    echo json_encode([
        'status' => 'error',
        'message' => 'Произошла ошибка при обработке запроса',
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

exit();
?>