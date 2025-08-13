<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

// Проверка подключения к базе данных
try {
    $pdo->query("SELECT 1");
    error_log("Подключение к базе данных работает");
} catch (PDOException $e) {
    error_log("Ошибка подключения к базе данных: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Ошибка подключения к базе данных.']);
    exit;
}

// Логирование для отладки
error_log("Запрос на /api/order_service.php получен");

// Проверка, авторизован ли пользователь
if (!isset($_SESSION['user_id'])) {
    error_log("Пользователь не авторизован");
    echo json_encode([
        'status' => 'error',
        'message' => 'Требуется авторизация для оформления заказа.'
    ]);
    exit;
}
error_log("Пользователь авторизован, user_id: " . $_SESSION['user_id']);

// Проверка CSRF-токена
$csrf_token = $_POST['csrf_token'] ?? '';
if (!validate_csrf_token($csrf_token)) {
    error_log("Неверный CSRF-токен: " . $csrf_token);
    echo json_encode([
        'status' => 'error',
        'message' => 'Неверный CSRF-токен.'
    ]);
    exit;
}
error_log("CSRF-токен проверен успешно");

// Получение данных формы
$fullname = trim($_POST['fullname'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$delivery_company = strtolower(trim($_POST['delivery_company'] ?? ''));
$pickup_point = trim($_POST['pickup_point'] ?? '');
$service_id = (int)($_POST['service_id'] ?? 0);
$user_id = $_SESSION['user_id'];

error_log("Получены данные формы: " . print_r($_POST, true));

// Извлечение города из pickup_point
$pickup_city = '';
if (!empty($pickup_point)) {
    $lines = array_filter(array_map('trim', explode("\n", $pickup_point)));
    $address_line = '';
    foreach ($lines as $line) {
        if (preg_match('/\d{6}/', $line)) {
            $address_line = $line;
            break;
        }
    }
    if (empty($address_line)) {
        $address_line = end($lines);
    }
    $parts = array_map('trim', explode(',', $address_line));
    $city_index = 3;
    if (count($parts) > $city_index) {
        $pickup_city = $parts[$city_index];
    } elseif (count($parts) > 2) {
        $pickup_city = $parts[count($parts) - 2];
    }
    $pickup_city = preg_replace('/[^А-Яа-я\s-]/u', '', $pickup_city);
    if (empty($pickup_city) && count($parts) > 1) {
        $pickup_city = preg_replace('/[^А-Яа-я\s-]/u', '', $parts[1]);
    }
}
error_log("Извлеченный pickup_city: '$pickup_city' из pickup_point: '$pickup_point'");

// Валидация данных
if (empty($fullname) || empty($email) || empty($phone) || empty($delivery_company) || $service_id <= 0) {
    error_log("Некоторые обязательные поля пусты или service_id некорректен");
    echo json_encode([
        'status' => 'error',
        'message' => 'Все поля формы и услуга обязательны для заполнения.'
    ]);
    exit;
}

if (!preg_match('/^[А-Яа-я\s]{3,50}$/u', $fullname)) {
    error_log("Неверный формат ФИО: " . $fullname);
    echo json_encode([
        'status' => 'error',
        'message' => 'ФИО должно содержать 3-50 символов, только буквы и пробелы.'
    ]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    error_log("Неверный формат email: " . $email);
    echo json_encode([
        'status' => 'error',
        'message' => 'Неверный формат email.'
    ]);
    exit;
}

if (!preg_match('/^\+?[0-9]{10,15}$/', $phone)) {
    error_log("Неверный формат телефона: " . $phone);
    echo json_encode([
        'status' => 'error',
        'message' => 'Номер телефона должен содержать 10-15 цифр.'
    ]);
    exit;
}

// Валидация службы доставки
if (!in_array($delivery_company, ['cdek', 'post'])) {
    error_log("Неверная служба доставки: " . $delivery_company);
    echo json_encode([
        'status' => 'error',
        'message' => 'Неверная служба доставки. Доступны только "cdek" или "post".'
    ]);
    exit;
}

if (($delivery_company === 'cdek' || $delivery_company === 'post') && empty($pickup_point)) {
    error_log("Пункт выдачи не указан для доставки: " . $delivery_company);
    echo json_encode([
        'status' => 'error',
        'message' => 'Укажите адрес пункта выдачи.'
    ]);
    exit;
}

// Проверка услуги
$total = 0;
try {
    $sql = "SELECT id, price FROM services WHERE id = ?";
    error_log("Выполняется запрос: " . $sql . " с параметром: " . $service_id);
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$service_id]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$service) {
        error_log("Услуга не найдена: service_id=" . $service_id);
        echo json_encode([
            'status' => 'error',
            'message' => 'Услуга не найдена.'
        ]);
        exit;
    }
    $total = (float)$service['price'];
    error_log("Общая стоимость услуги: " . $total);
} catch (PDOException $e) {
    error_log("Ошибка загрузки данных об услуге: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Ошибка загрузки данных об услуге: ' . $e->getMessage()
    ]);
    exit;
}

// Получаем или создаём customer_id
$customer_id = null;
try {
    $sql = "SELECT id FROM customers WHERE user_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($customer) {
        $customer_id = $customer['id'];
        error_log("Найден существующий customer_id: " . $customer_id);
        $sql = "UPDATE customers SET full_name = ?, phone = ?, city = ?, address = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$fullname, $phone, $pickup_city ?: 'Не определен', $pickup_point, $customer_id]);
        error_log("Данные клиента обновлены: customer_id=$customer_id");
    } else {
        $sql = "INSERT INTO customers (user_id, full_name, phone, city, address) VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $fullname, $phone, $pickup_city ?: 'Не определен', $pickup_point]);
        $customer_id = $pdo->lastInsertId();
        error_log("Создан новый customer_id: " . $customer_id);
    }
} catch (PDOException $e) {
    error_log("Ошибка при работе с таблицей customers: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Ошибка при сохранении данных клиента: ' . $e->getMessage()
    ]);
    exit;
}

// Генерируем уникальный order_number
$order_number = 'SRV-' . date('Ymd') . '-' . substr(md5(uniqid('', true)), 0, 6);
error_log("Сгенерирован order_number: " . $order_number);

// Сохранение заказа в таблицу orders
$tracking_number = null;
try {
    $sql = "INSERT INTO orders (customer_id, order_number, total, status, payment_method, payment_status, delivery_service, pickup_city, pickup_address) 
            VALUES (?, ?, ?, 'new', 'online', 'pending', ?, ?, ?)";
    error_log("Выполняется запрос на создание заказа: " . $sql);
    error_log("Параметры запроса: customer_id=$customer_id, order_number=$order_number, total=$total, delivery_service=$delivery_company, pickup_city=$pickup_city, pickup_address=$pickup_point");
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$customer_id, $order_number, $total, $delivery_company, $pickup_city ?: 'Не определен', $pickup_point]);
    $order_id = $pdo->lastInsertId();
    error_log("Заказ создан, order_id: " . $order_id);
} catch (PDOException $e) {
    error_log("Ошибка сохранения в таблицу orders: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Ошибка при сохранении заказа: ' . $e->getMessage()
    ]);
    exit;
}

// Сохранение услуги в таблицу order_items
try {
    $pdo->beginTransaction();
    $sql = "INSERT INTO order_items (order_id, service_id, quantity, price) VALUES (?, ?, ?, ?)";
    error_log("Выполняется запрос на сохранение услуги: " . $sql);
    $stmt = $pdo->prepare($sql);
    $quantity = 1;
    $stmt->execute([$order_id, $service_id, $quantity, $total]);
    error_log("Услуга добавлена: service_id=$service_id, quantity=$quantity, price=$total");
    $pdo->commit();
    error_log("Услуга успешно сохранена");
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Ошибка сохранения в таблицу order_items: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Ошибка при сохранении услуги: ' . $e->getMessage()
    ]);
    try {
        $pdo->exec("DELETE FROM orders WHERE id = $order_id");
        error_log("Заказ с order_id=$order_id удалён из-за ошибки в order_items");
    } catch (PDOException $e) {
        error_log("Ошибка при удалении заказа: " . $e->getMessage());
    }
    exit;
}

// Интеграция с API СДЭК
if ($delivery_company === 'cdek') {
    if (!function_exists('curl_init')) {
        error_log("Модуль cURL не установлен, заказ не отправлен в СДЭК");
    } else {
        $account = CDEK_ACCOUNT;
        $securePassword = CDEK_SECURE_PASSWORD;
        $token = getCdekToken($account, $securePassword);
        if (!$token) {
            error_log("Не удалось получить токен СДЭК");
        } else {
            $orderData = [
                'number' => $order_number,
                'tariff_code' => '1',
                'recipient' => [
                    'name' => $fullname,
                    'phones' => [['number' => $phone]],
                    'email' => $email
                ],
                'delivery_point' => [
                    'city' => $pickup_city ?: 'Не определен',
                    'address' => $pickup_point
                ],
                'packages' => [
                    [
                        'number' => 'PACK-' . $order_number,
                        'weight' => 1000,
                        'length' => 30,
                        'width' => 20,
                        'height' => 10
                    ]
                ]
            ];

            $cdekResponse = createCdekOrder($token, $orderData);
            if (!$cdekResponse) {
                error_log("Ошибка при создании заказа в СДЭК");
            } else {
                error_log("Заказ успешно создан в СДЭК: " . print_r($cdekResponse, true));
                $tracking_number = $cdekResponse['entity']['cdek_number'] ?? null;
                if ($tracking_number) {
                    try {
                        $sql = "UPDATE orders SET tracking_number = ? WHERE id = ?";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([$tracking_number, $order_id]);
                        error_log("Трек-номер $tracking_number добавлен к заказу order_id=$order_id");
                    } catch (PDOException $e) {
                        error_log("Ошибка добавления трек-номера: " . $e->getMessage());
                    }
                }
            }
        }
    }
}

// Отправка уведомления в Telegram через API
sendTelegramOrderNotification($pdo, $order_id);

// Обновление данных в сессии
$_SESSION['fullname'] = $fullname;
$_SESSION['email'] = $email;
$_SESSION['phone'] = $phone;

// Успешный ответ
echo json_encode([
    'status' => 'success',
    'message' => 'Заказ услуги успешно оформлен! Номер заказа: ' . $order_number,
    'order_id' => $order_id
]);
?>