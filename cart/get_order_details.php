<?php
session_start();
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

// Проверка авторизации администратора
if (!isset($_SESSION['admin'])) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Доступ запрещен']));
}

// Проверка CSRF-токена
if (!validate_csrf_token($_GET['csrf_token'] ?? '')) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Недействительный CSRF-токен']));
}

// Получение и валидация ID заказа
$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($orderId <= 0) {
    http_response_code(400);
    die(json_encode(['status' => 'error', 'message' => 'Неверный ID заказа']));
}

try {
    // Получаем данные заказа и клиента
    $stmt = $pdo->prepare("
        SELECT 
            o.*,
            c.full_name,
            c.phone,
            c.city,
            IFNULL(c.address, 'Не указан') as address,
            u.email,
            c.birth_date,
            c.preferred_communication
        FROM orders o
        JOIN customers c ON o.customer_id = c.id
        JOIN users u ON c.user_id = u.id
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    $orderData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$orderData) {
        http_response_code(404);
        die(json_encode(['status' => 'error', 'message' => 'Заказ не найден']));
    }

    // Формируем данные клиента
    $customer = [
        'full_name' => $orderData['full_name'] ?? 'Не указано',
        'phone' => $orderData['phone'] ?? 'Не указан',
        'city' => $orderData['city'] ?? 'Не указан',
        'email' => $orderData['email'] ?? 'Не указан',
        'address' => $orderData['address'] ?? 'Не указан',
        'birth_date' => $orderData['birth_date'] ?? null,
        'preferred_communication' => $orderData['preferred_communication'] ?? 'email'
    ];

    // Удаляем данные клиента из массива заказа
    unset(
        $orderData['full_name'],
        $orderData['phone'],
        $orderData['city'],
        $orderData['email'],
        $orderData['address'],
        $orderData['birth_date'],
        $orderData['preferred_communication']
    );

    // Получаем товары заказа
    $stmt = $pdo->prepare("
        SELECT 
            oi.*,
            p.name,
            p.vendor_code,
            p.image,
            p.type,
            p.diameter,
            p.weight,
            p.duration,
            p.service_type
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ?
    ");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Получаем историю изменений
    $stmt = $pdo->prepare("
        SELECT oh.*, a.username as admin_name
        FROM order_history oh
        LEFT JOIN admins a ON oh.admin_id = a.id
        WHERE oh.order_id = ?
        ORDER BY oh.created_at DESC
    ");
    $stmt->execute([$orderId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Формируем финальный ответ
    echo json_encode([
        'status' => 'success',
        'data' => [
            'order' => $orderData,
            'items' => $items,
            'customer' => $customer,
            'history' => $history
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Ошибка базы данных',
        'error' => $e->getMessage(),
        'error_info' => $pdo->errorInfo()
    ], JSON_UNESCAPED_UNICODE);
}
?>