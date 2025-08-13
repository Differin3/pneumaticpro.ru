<?php
session_start();
require __DIR__ . '/../../includes/config.php';
require __DIR__ . '/../../includes/functions.php';

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

// Получение и валидация ID пользователя
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($userId <= 0) {
    http_response_code(400);
    die(json_encode(['status' => 'error', 'message' => 'Неверный ID пользователя']));
}

try {
    // Получаем данные пользователя и профиля
    $stmt = $pdo->prepare("
        SELECT 
            u.*,
            c.full_name,
            c.phone,
            IFNULL(c.address, 'Не указан') as address,
            c.birth_date,
            c.preferred_communication
        FROM users u
        LEFT JOIN customers c ON u.id = c.user_id
        WHERE u.id = ?
    ");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userData) {
        http_response_code(404);
        die(json_encode(['status' => 'error', 'message' => 'Пользователь не найден']));
    }

    // Получаем заказы пользователя через таблицу customers
    $stmt = $pdo->prepare("
        SELECT o.id AS order_id, o.order_number, o.created_at
        FROM orders o
        INNER JOIN customers c ON o.customer_id = c.id
        WHERE c.user_id = ?
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$userId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Формируем данные пользователя
    $user = [
        'id' => $userData['id'] ?? '—',
        'username' => $userData['username'] ?? '—',
        'email' => $userData['email'] ?? '—',
        'email_verified_at' => $userData['email_verified_at'] ?? null,
        'created_at' => $userData['created_at'] ?? '—',
        'updated_at' => $userData['updated_at'] ?? '—'
    ];

    // Формируем данные профиля
    $customer = [
        'full_name' => $userData['full_name'] ?? 'Не указано',
        'phone' => $userData['phone'] ?? 'Не указан',
        'address' => $userData['address'] ?? 'Не указан',
        'birth_date' => $userData['birth_date'] ?? null,
        'preferred_communication' => $userData['preferred_communication'] ?? 'email'
    ];

    // Формируем данные заказов
    $ordersData = array_map(function($order) {
        return [
            'order_id' => $order['order_id'] ?? '—',
            'order_number' => $order['order_number'] ?? '—',
            'created_at' => $order['created_at'] ?? '—'
        ];
    }, $orders);

    // Формируем финальный ответ
    echo json_encode([
        'status' => 'success',
        'data' => [
            'user' => $user,
            'customer' => $customer,
            'orders' => $ordersData
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