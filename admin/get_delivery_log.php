<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$per_page = 10;
$page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
$offset = ($page - 1) * $per_page;

try {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        throw new Exception('Недействительный CSRF-токен');
    }

    $query = "SELECT dl.*, o.order_number 
              FROM delivery_logs dl
              JOIN orders o ON dl.order_id = o.id
              ORDER BY dl.created_at DESC
              LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($query);
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM delivery_logs");
    $count_stmt->execute();
    $total_logs = $count_stmt->fetchColumn();
    $total_pages = ceil($total_logs / $per_page);

    echo json_encode([
        'status' => 'success',
        'logs' => $logs,
        'total_pages' => $total_pages
    ]);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}