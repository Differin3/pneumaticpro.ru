<?php
session_start();
require 'includes/config.php';
require 'includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

$response = [
    'status' => 'error',
    'message' => 'Неизвестная ошибка',
    'code' => 500
];

try {
    // Проверка авторизации
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Необходима авторизация', 401);
    }

    // Проверка метода запроса
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Метод не поддерживается', 405);
    }

    // Валидация CSRF-токена
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token($csrf_token)) {
        throw new Exception('Недействительный CSRF-токен', 403);
    }

    // Проверка корзины
    if (empty($_SESSION['cart'])) {
        throw new Exception('Корзина пуста', 400);
    }

    // Получение и очистка данных
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $delivery_company = trim($_POST['delivery_company'] ?? '');
    $pickup_point = trim($_POST['pickup_point'] ?? '');

    // Валидация данных
    if (empty($fullname) || strlen($fullname) < 3 || strlen($fullname) > 50 || !preg_match('/^[a-zA-Zа-яА-Я\s]+$/u', $fullname)) {
        throw new Exception('Неверный формат ФИО', 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Неверный формат email', 400);
    }
    if (!preg_match('/^\+?\d{10,15}$/', $phone)) {
        throw new Exception('Неверный формат телефона', 400);
    }
    if (!in_array($delivery_company, ['cdek', 'post', 'pickup'])) {
        throw new Exception('Неверная служба доставки', 400);
    }
    if (empty($pickup_point) || strlen($pickup_point) < 3) {
        throw new Exception('Укажите пункт выдачи', 400);
    }

    // Начинаем транзакцию
    $pdo->beginTransaction();

    // Рассчитываем общую сумму
    $total = 0;
    $cart = $_SESSION['cart'];
    foreach ($cart as $productId => $item) {
        $stmt = $pdo->prepare("SELECT price FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            throw new Exception("Товар с ID $productId не найден", 404);
        }
        $total += $product['price'] * $item['quantity'];
    }

    // Получаем ID клиента
    $stmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        throw new Exception('Профиль клиента не найден', 404);
    }
    $customer_id = $customer['id'];

    // Создаем заказ
    $order_number = 'ORD-' . date('Ymd') . '-' . substr(md5(uniqid()), 0, 6);
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
        ) VALUES (?, ?, ?, 'new', 'online', 'pending', ?, ?)
    ");
    $stmt->execute([
        $customer_id,
        $order_number,
        $total,
        $delivery_company,
        "Пункт выдачи: $pickup_point"
    ]);
    $order_id = $pdo->lastInsertId();

    // Добавляем товары в заказ
    foreach ($cart as $productId => $item) {
        $stmt = $pdo->prepare("
            INSERT INTO order_items (
                order_id, 
                product_id, 
                quantity, 
                price
            ) VALUES (?, ?, ?, (SELECT price FROM products WHERE id = ?))
        ");
        $stmt->execute([$order_id, $productId, $item['quantity'], $productId]);
    }

    // Обновляем данные клиента
    $stmt = $pdo->prepare("
        UPDATE customers SET
            full_name = ?,
            phone = ?,
            address = ?
        WHERE user_id = ?
    ");
    $stmt->execute([
        $fullname,
        $phone,
        $pickup_point,
        $_SESSION['user_id']
    ]);

    // Обновляем email пользователя
    $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
    $stmt->execute([$email, $_SESSION['user_id']]);

    // Фиксируем транзакцию
    $pdo->commit();

    // Очищаем корзину
    unset($_SESSION['cart']);

    // Обновляем данные в сессии
    $_SESSION['fullname'] = $fullname;
    $_SESSION['email'] = $email;
    $_SESSION['phone'] = $phone;
    $_SESSION['address'] = $pickup_point;

    $response = [
        'status' => 'success',
        'message' => "Заказ успешно оформлен! Номер вашего заказа: $order_number",
        'order_id' => $order_id,
        'code' => 200
    ];

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("submit_order.php: Database error: " . $e->getMessage());
    $response = [
        'status' => 'error',
        'message' => 'Ошибка базы данных',
        'code' => 500
    ];
} catch (Exception $e) {
    error_log("submit_order.php: Error: " . $e->getMessage());
    $response = [
        'status' => 'error',
        'message' => $e->getMessage(),
        'code' => $e->getCode() ?: 500
    ];
}

ob_end_clean();
http_response_code($response['code']);
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit();
?>