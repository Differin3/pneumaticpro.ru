<?php
header('Content-Type: application/json');
session_start();
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

// Логирование запроса
error_log("Запрос на /api/cart.php получен");

// Проверка подключения к базе данных
try {
    $pdo->query("SELECT 1");
    error_log("Подключение к базе данных работает");
} catch (PDOException $e) {
    error_log("Ошибка подключения к базе данных: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Ошибка подключения к базе данных.']);
    exit;
}

// Проверка сессии пользователя
if (!isset($_SESSION['user_id'])) {
    error_log("Пользователь не авторизован");
    echo json_encode(['status' => 'error', 'message' => 'Требуется авторизация.']);
    exit;
}
error_log("Пользователь авторизован, user_id: " . $_SESSION['user_id']);

// Проверка CSRF-токена
$csrf_token = $_POST['csrf_token'] ?? '';
if (!validate_csrf_token($csrf_token)) {
    error_log("Неверный CSRF-токен: " . $csrf_token);
    echo json_encode(['status' => 'error', 'message' => 'Неверный CSRF-токен.']);
    exit;
}
error_log("CSRF-токен проверен успешно");

// Получение данных формы
$fullname = trim($_POST['fullname'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$delivery_company = strtolower(trim($_POST['delivery_company'] ?? ''));
$pickup_point = trim($_POST['pickup_point'] ?? '');
$pickup_point_code = trim($_POST['pickup_point_code'] ?? '');
$pickup_city = trim($_POST['pickup_city'] ?? '');
$user_id = $_SESSION['user_id'];
$cart = $_SESSION['cart'] ?? [];
error_log("Получены данные формы: " . json_encode($_POST, JSON_UNESCAPED_UNICODE));

// Парсинг города из адреса, если pickup_city пустое
if ($delivery_company === 'cdek' && empty($pickup_city) && !empty($pickup_point)) {
    $parts = array_map('trim', explode(',', $pickup_point));
    $pickup_city = $parts[2] ?? $parts[3] ?? 'Москва';
    $pickup_city = preg_replace('/[^А-Яа-я\s-]/u', '', $pickup_city);
    error_log("Извлеченный pickup_city из адреса: '$pickup_city'");
}

// Дополнительное логирование для отладки
error_log("Перед валидацией: pickup_point='$pickup_point', pickup_point_code='$pickup_point_code', pickup_city='$pickup_city'");

// Валидация данных
if (empty($fullname) || empty($phone) || empty($delivery_company)) {
    error_log("Некоторые обязательные поля пусты: fullname='$fullname', phone='$phone', delivery_company='$delivery_company'");
    echo json_encode(['status' => 'error', 'message' => 'Все поля формы обязательны.']);
    exit;
}

if (!preg_match('/^[А-Яа-я\s]{3,50}$/u', $fullname)) {
    error_log("Неверный формат ФИО: " . $fullname);
    echo json_encode(['status' => 'error', 'message' => 'ФИО должно содержать 3-50 символов, только буквы и пробелы.']);
    exit;
}

if (!preg_match('/^\+?[0-9]{10,15}$/', $phone)) {
    error_log("Неверный формат телефона: " . $phone);
    echo json_encode(['status' => 'error', 'message' => 'Номер телефона должен содержать 10-15 цифр.']);
    exit;
}

if (!in_array($delivery_company, ['cdek', 'post'])) {
    error_log("Неверная служба доставки: " . $delivery_company);
    echo json_encode(['status' => 'error', 'message' => 'Неверная служба доставки.']);
    exit;
}

if (($delivery_company === 'cdek' || $delivery_company === 'post') && empty($pickup_point)) {
    error_log("Пункт выдачи не указан для доставки: " . $delivery_company);
    echo json_encode(['status' => 'error', 'message' => 'Укажите адрес пункта выдачи.']);
    exit;
}

// Для СДЭК проверяем наличие кода ПВЗ
//if ($delivery_company === 'cdek' && empty($pickup_point_code)) {
//    error_log("Отсутствует код пункта выдачи для СДЭК: pickup_point='$pickup_point'");
//    echo json_encode(['status' => 'error', 'message' => 'Укажите код пункта выдачи для СДЭК.']);
//    exit;
//}

if (empty($cart)) {
    error_log("Корзина пуста");
    echo json_encode(['status' => 'error', 'message' => 'Корзина пуста.']);
    exit;
}

// Получение списка продуктов
$product_ids = array_keys($cart);
$products = [];
$total = 0;
if (!empty($product_ids)) {
    try {
        $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
        $sql = "SELECT id, name, price, vendor_code, weight FROM products WHERE id IN ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($product_ids);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($products as $product) {
            if (isset($cart[$product['id']])) {
                $total += (float)($product['price'] ?? 0) * (int)($cart[$product['id']]['quantity'] ?? 0);
            }
        }
    } catch (PDOException $e) {
        error_log("Ошибка загрузки продуктов: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Ошибка загрузки данных продуктов.']);
        exit;
    }
}

// Создание заказа
$order_number = 'ORDER-' . time();
try {
    $pdo->beginTransaction();

    // Получение или создание customer_id
    $stmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = ? AND full_name = ? AND phone = ?");
    $stmt->execute([$user_id, $fullname, $phone]);
    $customer_id = $stmt->fetchColumn();

    if (!$customer_id) {
        $stmt = $pdo->prepare("INSERT INTO customers (user_id, full_name, phone, city, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([$user_id, $fullname, $phone, $pickup_city]);
        $customer_id = $pdo->lastInsertId();
    }

    // Сохранение заказа
    $stmt = $pdo->prepare("
        INSERT INTO orders (order_number, customer_id, status, delivery_service, pickup_city, pickup_address, pickup_point_code, total, created_at, updated_at)
        VALUES (?, ?, 'new', ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([$order_number, $customer_id, $delivery_company, $pickup_city, $pickup_point, $pickup_point_code, $total]);
    $order_id = $pdo->lastInsertId();

    // Сохранение товаров
    foreach ($cart as $product_id => $item) {
        $product_price = 0;
        foreach ($products as $product) {
            if ($product['id'] == $product_id) {
                $product_price = $product['price'];
                break;
            }
        }
        $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
        $stmt->execute([$order_id, $product_id, $item['quantity'], $product_price]);
    }

    $tracking_number = '';
    if ($delivery_company === 'cdek' && !empty($pickup_point_code)) {
        try {
            $account = CDEK_ACCOUNT;
            $securePassword = CDEK_SECURE_PASSWORD;
            $token = getCdekToken($account, $securePassword);
            if ($token) {
                $cdek_data = [
                    'type' => 1,
                    'tariff_code' => 139,
                    'shipment_point' => 'VLG132',
                    'delivery_point' => $pickup_point_code,
                    'recipient' => [
                        'name' => $fullname,
                        'phones' => [['number' => $phone]]
                    ],
                    'packages' => array_map(function ($product_id, $item) use ($products) {
                        $product = array_filter($products, fn($p) => $p['id'] == $product_id)[0] ?? [];
                        return [
                            'number' => 'PACK' . $product_id,
                            'weight' => ($product['weight'] ?? 0) * 1000 * $item['quantity'],
                            'items' => [[
                                'name' => $product['name'] ?? 'Товар',
                                'ware_key' => $product['vendor_code'] ?? 'UNKNOWN',
                                'payment' => ['value' => 0],
                                'weight' => ($product['weight'] ?? 0) * 1000,
                                'amount' => $item['quantity']
                            ]]
                        ];
                    }, array_keys($cart), $cart),
                    'number' => $order_number
                ];

                $ch = curl_init('https://api.cdek.ru/v2/orders');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json'
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cdek_data));
                $cdek_response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                error_log("Ответ СДЭК: HTTP $httpCode, " . $cdek_response);

                if ($httpCode === 202) {
                    $cdekResponse = json_decode($cdek_response, true);
                    if (isset($cdekResponse['entity']['uuid'])) {
                        $tracking_number = $cdekResponse['entity']['cdek_number'] ?? '';
                        $stmt = $pdo->prepare("UPDATE orders SET tracking_number = ? WHERE id = ?");
                        $stmt->execute([$tracking_number, $order_id]);
                    } else {
                        error_log("Ошибка создания заказа в СДЭК: " . json_encode($cdekResponse, JSON_UNESCAPED_UNICODE));
                    }
                } else {
                    error_log("Ошибка создания заказа в СДЭК: HTTP $httpCode, Ответ: $cdek_response");
                }
            } else {
                error_log("Не удалось получить токен СДЭК");
            }
        } catch (Exception $e) {
            error_log("Ошибка интеграции с СДЭК: " . $e->getMessage());
        }
    }

    $pdo->commit();

    // Отправка уведомления в Telegram
    try {
        $status_map = [
            'new' => 'Новый 🎉',
            'processing' => 'В обработке ⏳',
            'shipped' => 'Отправлен 🚚',
            'completed' => 'Завершён ✅',
            'canceled' => 'Отменён ❌'
        ];
        $delivery_map = [
            'cdek' => '🚛СДЭК ',
            'post' => '📬Почта России ',
            'pickup' => '🏪Самовывоз '
        ];
        $message = "📦 <b>Новый заказ #{$order_number}</b>\n\n";
        $message .= "👤 <b>Клиент:</b>\n";
        $message .= "👨‍💼Имя: {$fullname}\n";
        $message .= "📞Телефон: {$phone}\n\n";
        $message .= "💰 <b>Детали заказа:</b>\n";
        $message .= "💸Сумма: " . number_format($total, 2) . " ₽\n";
        $message .= "Статус: {$status_map['new']}\n";
        $message .= "🕒Дата: " . date('d.m.Y H:i') . "\n";
        $message .= "📍 <b>Доставка:</b>\n";
        $message .= "Служба: {$delivery_map[$delivery_company]}\n";
        $message .= "Город выдачи: {$pickup_city}\n";
        $message .= "Адрес выдачи: {$pickup_point}\n";
        if ($pickup_point_code) {
            $message .= "Код ПВЗ: {$pickup_point_code}\n";
        }
        if ($tracking_number) {
            $message .= "Трек-номер: {$tracking_number} 🔍\n";
        }
        $message .= "\n🛒 <b>Состав заказа:</b>\n";
        foreach ($cart as $product_id => $item) {
            $product_price = 0;
            foreach ($products as $product) {
                if ($product['id'] == $product_id) {
                    $product_price = $product['price'];
                    break;
                }
            }
            $message .= "- Товар: {$item['name']} (x{$item['quantity']}): " . 
                       number_format($product_price * $item['quantity'], 2) . " ₽\n";
        }
        sendTelegramMessage($pdo, $message);
        error_log("Уведомление отправлено в Telegram");
    } catch (Exception $e) {
        error_log("Ошибка Telegram: " . $e->getMessage());
    }

    // Очистка корзины и отправка ответа
    $_SESSION['cart'] = [];
    error_log("Корзина очищена");
    echo json_encode([
        'status' => 'success',
        'message' => 'Заказ успешно оформлен! Номер заказа: ' . $order_number
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Ошибка создания заказа: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Ошибка при оформлении заказа: ' . $e->getMessage()]);
}
?>