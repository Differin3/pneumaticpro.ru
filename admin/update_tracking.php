<?php
session_start();
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

// Проверка авторизации
if (!isset($_SESSION['admin']) || !isset($_SESSION['admin']['id'])) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Доступ запрещен']));
}

// Получение данных
$orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$trackingNumber = isset($_POST['tracking_number']) ? trim($_POST['tracking_number']) : '';
$adminId = (int)$_SESSION['admin']['id'];

if ($orderId <= 0) {
    http_response_code(400);
    die(json_encode(['status' => 'error', 'message' => 'Неверный ID заказа']));
}

try {
    // Обновление трек-номера
    $stmt = $pdo->prepare("UPDATE orders SET tracking_number = ? WHERE id = ?");
    $stmt->execute([$trackingNumber, $orderId]);
    
    // Запись в историю
    $stmt = $pdo->prepare("
        INSERT INTO order_history (order_id, admin_id, action, details) 
        VALUES (?, ?, 'tracking_update', ?)
    ");
    $stmt->execute([
        $orderId,
        $adminId,
        $trackingNumber ? 'Установлен трек-номер: ' . $trackingNumber : 'Трек-номер удален'
    ]);
    
    // Логирование действия
    $logDescription = $trackingNumber ? "Установлен трек-номер '{$trackingNumber}' для заказа №{$orderId}" : "Удален трек-номер для заказа №{$orderId}";
    logActivity($pdo, 'tracking_update', $logDescription, $adminId);
    
    echo json_encode(['status' => 'success']);
    
} catch (PDOException $e) {
    error_log("PDO Exception in update_tracking.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Ошибка базы данных',
        'error' => $e->getMessage()
    ]);
}
?>