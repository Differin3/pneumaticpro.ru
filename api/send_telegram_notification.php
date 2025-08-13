<?php
// Инициализация сессии
try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['status' => 'error', 'message' => 'Ошибка инициализации сессии: ' . $e->getMessage()]);
    exit;
}

// Подключение минимальных зависимостей
try {
    require __DIR__ . '/../includes/config.php';
    require __DIR__ . '/../includes/functions.php';
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['status' => 'error', 'message' => 'Ошибка подключения зависимостей: ' . $e->getMessage()]);
    exit;
}

// Установка заголовка
header('Content-Type: application/json; charset=utf-8');

// Логирование получения запроса
file_put_contents('/var/www/pneumaticpro.ru/logs/api_requests_' . date('Y-m-d') . '.log', 
    '[' . date('Y-m-d H:i:s') . '] [DEBUG] Запрос на /api/send_telegram_notification.php получен' . "\n", 
    FILE_APPEND | LOCK_EX);

try {
    // Извлечение order_id
    $order_id = (int)($_POST['order_id'] ?? 0);
    if ($order_id <= 0) {
        throw new Exception('Некорректный ID заказа');
    }

    // Получение данных заказа
    $stmt = $pdo->prepare("
        SELECT order_number, total, status, pickup_city, pickup_address, tracking_number, created_at, customer_id
        FROM orders 
        WHERE id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new Exception("Заказ с ID $order_id не найден в базе данных");
    }

    // Получение данных клиента (без email)
    $stmt = $pdo->prepare("SELECT full_name, phone FROM customers WHERE id = ?");
    $stmt->execute([$order['customer_id']]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    $full_name = $customer['full_name'] ?? 'Не указан';
    $phone = $customer['phone'] ?? 'Не указан';

    // Получение элементов заказа
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(s.name, p.name) AS name, 
            oi.quantity, 
            oi.price,
            CASE 
                WHEN p.id IS NOT NULL THEN 'Товар'
                WHEN s.id IS NOT NULL THEN 'Услуга'
                ELSE 'Неизвестно'
            END AS type
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        LEFT JOIN services s ON oi.service_id = s.id
        WHERE oi.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Получение настроек Telegram
    $stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('telegram_bot_token', 'telegram_chat_id')");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    $bot_token = $settings['telegram_bot_token'] ?? '';
    $chat_id = $settings['telegram_chat_id'] ?? '7071144296';

    if (empty($bot_token)) {
        throw new Exception('Токен Telegram-бота не настроен');
    }

    // Формирование улучшенного сообщения с эмодзи (без email)
    $status_map = [
        'new' => 'Новый 🎉',
        'processing' => 'В обработке ⏳',
        'shipped' => 'Отправлен 🚚',
        'completed' => 'Завершён ✅',
        'canceled' => 'Отменён ❌'
    ];
    $delivery_map = [
        'cdek' => 'СДЭК 🚛',
        'post' => 'Почта России 📬',
        'pickup' => 'Самовывоз 🏪'
    ];

    $message = "📦 <b>Новый заказ #{$order['order_number']}</b> 📦\n\n";
    $message .= "👤 <b>Клиент:</b>\n";
    $message .= "👨‍💼Имя: {$full_name} \n";
    $message .= "📞Телефон: {$phone} \n\n";
    $message .= "💰 <b>Детали заказа:</b>\n";
    $message .= "💸Сумма: " . number_format($order['total'], 2) . " ₽ \n";
    $message .= "Статус: {$status_map[$order['status']]}\n";
    $message .= "🕒Дата: " . date('d.m.Y H:i', strtotime($order['created_at'])) . " \n";
    $message .= "📍 <b>Доставка:</b>\n";
    $message .= "Город выдачи: " . ($order['pickup_city'] ?: 'Не указан') . " \n";
    $message .= "Адрес выдачи: " . ($order['pickup_address'] ?: 'Не указан') . " \n";
    if ($order['tracking_number']) {
        $message .= "🔍Трек-номер: {$order['tracking_number']} \n";
    }
    $message .= "\n🛒 <b>Состав заказа:</b>\n";
    if (empty($items)) {
        $message .= "Нет элементов в заказе 😕\n";
    } else {
        foreach ($items as $item) {
            $message .= "- {$item['type']}: {$item['name']} (x{$item['quantity']}): " . 
                       number_format($item['price'] * $item['quantity'], 2) . " ₽ 💰\n";
        }
    }

    // Отправка сообщения
    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception("Ошибка cURL: $curlError");
    }
    if ($httpCode !== 200) {
        $result = json_decode($response, true);
        throw new Exception("Ошибка API Telegram (HTTP $httpCode): " . ($result['description'] ?? 'Неизвестная ошибка'));
    }

    file_put_contents('/var/www/pneumaticpro.ru/logs/api_requests_' . date('Y-m-d') . '.log', 
        '[' . date('Y-m-d H:i:s') . '] [INFO] Уведомление о заказе $order_id успешно отправлено' . "\n", 
        FILE_APPEND | LOCK_EX);
    echo json_encode(['status' => 'success', 'message' => 'Уведомление отправлено']);

} catch (Exception $e) {
    file_put_contents('/var/www/pneumaticpro.ru/logs/api_requests_' . date('Y-m-d') . '.log', 
        '[' . date('Y-m-d H:i:s') . '] [ERROR] Ошибка отправки уведомления для order_id ' . ($order_id > 0 ? $order_id : 'неизвестно') . ': ' . $e->getMessage() . "\n", 
        FILE_APPEND | LOCK_EX);
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>