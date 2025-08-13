<?php
session_start();
require 'includes/config.php';
require 'includes/functions.php';

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    die(json_encode(['status' => 'error', 'message' => 'Требуется авторизация']));
}

// Проверка корзины
if (empty($_SESSION['cart'])) {
    die(json_encode(['status' => 'error', 'message' => 'Корзина пуста']));
}

// Получаем данные из формы
$data = json_decode(file_get_contents('php://input'), true);

// Валидация данных
$requiredFields = ['fullname', 'email', 'phone', 'city', 'address'];
foreach ($requiredFields as $field) {
    if (empty($data[$field])) {
        die(json_encode(['status' => 'error', 'message' => 'Заполните все обязательные поля']));
    }
}

try {
    // Начинаем транзакцию
    $pdo->beginTransaction();

    // 1. Создаем заказ
    $orderNumber = 'ORD-' . date('Ymd') . '-' . substr(md5(uniqid()), 0, 6);
    $total = 0;

    // Рассчитываем общую сумму
    foreach ($_SESSION['cart'] as $productId => $item) {
        $stmt = $pdo->prepare("SELECT price FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        $total += $product['price'] * $item['quantity'];
    }

    // Вставляем заказ
    $stmt = $pdo->prepare("
        INSERT INTO orders (
            customer_id, 
            order_number, 
            total, 
            status, 
            payment_method, 
            payment_status,
            delivery_service,
            notes
        ) VALUES (
            (SELECT id FROM customers WHERE user_id = ?),
            ?, ?, 'new', 'online', 'pending', 'cdek', ?
        )
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        $orderNumber,
        $total,
        $data['comment'] ?? null
    ]);
    $orderId = $pdo->lastInsertId();

    // 2. Добавляем товары в заказ
    foreach ($_SESSION['cart'] as $productId => $item) {
        $stmt = $pdo->prepare("
            INSERT INTO order_items (
                order_id, 
                product_id, 
                quantity, 
                price
            ) VALUES (
                ?, ?, ?, 
                (SELECT price FROM products WHERE id = ?)
            )
        ");
        $stmt->execute([$orderId, $productId, $item['quantity'], $productId]);
    }

    // 3. Обновляем данные клиента
    $stmt = $pdo->prepare("
        UPDATE customers SET
            full_name = ?,
            phone = ?,
            address = ?
        WHERE user_id = ?
    ");
    $stmt->execute([
        $data['fullname'],
        $data['phone'],
        $data['city'] . ', ' . $data['address'],
        $_SESSION['user_id']
    ]);

    // 4. Обновляем email пользователя
    $stmt = $pdo->prepare("
        UPDATE users SET
            email = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $data['email'],
        $_SESSION['user_id']
    ]);

    // Фиксируем транзакцию
    $pdo->commit();

    // Очищаем корзину
    unset($_SESSION['cart']);

    // Сохраняем данные в сессию
    $_SESSION['fullname'] = $data['fullname'];
    $_SESSION['email'] = $data['email'];
    $_SESSION['phone'] = $data['phone'];
    $_SESSION['address'] = $data['city'] . ', ' . $data['address'];

    echo json_encode([
        'status' => 'success',
        'message' => 'Заказ успешно оформлен! Номер вашего заказа: ' . $orderNumber,
        'order_id' => $orderId
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Order error: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Ошибка при оформлении заказа: ' . $e->getMessage()
    ]);
}