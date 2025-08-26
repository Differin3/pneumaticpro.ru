<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['admin'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['status' => 'error', 'message' => 'Доступ запрещен']);
    exit();
}

if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['status' => 'error', 'message' => 'Недействительный CSRF-токен']);
    exit();
}

$page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
$per_page = isset($_POST['per_page']) ? (int)$_POST['per_page'] : 10;
$offset = ($page - 1) * $per_page;

try {
    // Получаем общее количество записей
    $count_stmt = $pdo->query("SELECT COUNT(*) FROM user_activity_log");
    $total = $count_stmt->fetchColumn();
    $total_pages = ceil($total / $per_page);

    // Получаем записи логов с информацией о пользователях
    $sql = "SELECT ual.*, u.username 
            FROM user_activity_log ual 
            LEFT JOIN users u ON ual.user_id = u.id 
            ORDER BY ual.created_at DESC 
            LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'status' => 'success',
        'logs' => $logs,
        'total_pages' => $total_pages,
        'current_page' => $page
    ]);
    
} catch (PDOException $e) {
    error_log("Ошибка получения логов пользователей: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['status' => 'error', 'message' => 'Ошибка сервера: ' . $e->getMessage()]);
}