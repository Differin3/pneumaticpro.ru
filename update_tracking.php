<?php
session_start();
require 'includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin'])) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Доступ запрещен']));
}

$input = json_decode(file_get_contents('php://input'), true);

try {
    if (empty($input['order_id']) || !isset($input['tracking_number'])) {
        throw new Exception("Неверные параметры запроса");
    }

    $order_id = (int)$input['order_id'];
    $tracking_number = trim($input['tracking_number']);

    // Обновляем заказ
    $stmt = $pdo->prepare("
        UPDATE orders SET 
            tracking_number = ?,
            status = 'shipped',
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$tracking_number, $order_id]);

    echo json_encode([
        'status' => 'success',
        'message' => 'Трек-номер успешно обновлен',
        'data' => [
            'order_id' => $order_id,
            'tracking_number' => $tracking_number,
            'status' => 'shipped'
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}