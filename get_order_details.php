<?php
session_start();
require 'includes/config.php';
require 'includes/functions.php';

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['error' => 'Не авторизован']);
    exit();
}

if (!isset($_POST['order_id']) || !is_numeric($_POST['order_id'])) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Неверный ID заказа']);
    exit();
}

try {
    // Получаем ID клиента текущего пользователя
    $stmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        throw new Exception("Профиль клиента не найден");
    }

    // Проверяем, принадлежит ли заказ этому клиенту
    $stmt = $pdo->prepare("SELECT order_number, total FROM orders WHERE id = ? AND customer_id = ?");
    $stmt->execute([$_POST['order_id'], $customer['id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        header('HTTP/1.1 403 Forbidden');
        echo json_encode(['error' => 'Заказ не найден или не принадлежит вам']);
        exit();
    }

    // Получаем элементы заказа
    $stmt = $pdo->prepare("
        SELECT 
            oi.quantity,
            oi.price,
            CASE 
                WHEN oi.product_id IS NOT NULL THEN 'Товар'
                WHEN oi.service_id IS NOT NULL THEN 'Услуга'
            END as type,
            COALESCE(p.name, s.name) as name
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        LEFT JOIN services s ON oi.service_id = s.id
        WHERE oi.order_id = ?
    ");
    $stmt->execute([$_POST['order_id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'order_number' => $order['order_number'],
        'total' => number_format($order['total'], 2),
        'items' => $items
    ]);

} catch (PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Ошибка базы данных']);
    exit();
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => $e->getMessage()]);
    exit();
}
?>