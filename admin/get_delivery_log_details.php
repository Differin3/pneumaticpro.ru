<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['admin'])) {
    echo json_encode(['status' => 'error', 'message' => 'Неавторизованный доступ']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Метод не разрешен']);
    exit();
}

if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['status' => 'error', 'message' => 'Недействительный CSRF-токен']);
    exit();
}

$log_id = (int)($_POST['log_id'] ?? 0);
if ($log_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Неверный ID записи']);
    exit();
}

try {
    // Получаем данные о логе доставки, включая номер заказа и информацию о заказе
    $stmt = $pdo->prepare("
        SELECT dl.*, o.order_number, o.tracking_number, o.delivery_service,
               c.full_name as customer_name, c.phone as customer_phone
        FROM delivery_logs dl 
        JOIN orders o ON dl.order_id = o.id 
        JOIN customers c ON o.customer_id = c.id
        WHERE dl.id = ?
    ");
    $stmt->execute([$log_id]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$log) {
        echo json_encode(['status' => 'error', 'message' => 'Запись не найдена']);
        exit();
    }
    
    // Декодируем JSON ответ от API
    $api_response = json_decode($log['api_response'], true);
    
    echo json_encode([
        'status' => 'success',
        'log' => $log,
        'api_response' => $api_response
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных: ' . $e->getMessage()]);
}