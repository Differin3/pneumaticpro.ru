<?php
// /var/www/pneumaticpro.ru/api/check_delivery_status.php

// Настройки подключения к базе данных
define('DB_HOST', 'localhost');
define('DB_NAME', 'airgun_service');
define('DB_USER', 'pnevmatpro.ru');
define('DB_PASS', 'pnevmatpro.ru');

// Логирование в консоль
function logToConsole($message) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

// Путь к файлу лога
$debug_log = '/tmp/delivery_status_check.log';

try {
    logToConsole("Создание подключения к БД...");
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    logToConsole("Подключение к БД успешно");
} catch (PDOException $e) {
    logToConsole("Ошибка подключения к БД: " . $e->getMessage());
    exit(1);
}

require '/var/www/pneumaticpro.ru/includes/functions.php';

try {
    logToConsole("Поиск заказов для проверки...");
    // ИЗМЕНЕНО: Добавлены условия для фильтрации пустых трек-номеров
    $sql = "SELECT id, tracking_number 
            FROM orders 
            WHERE delivery_service = 'cdek'
            AND status NOT IN ('completed', 'canceled')
            AND (last_status_check IS NULL OR last_status_check < NOW() - INTERVAL 1 HOUR)
            AND tracking_number IS NOT NULL
            AND tracking_number != ''
            ORDER BY last_status_check ASC
            LIMIT 20";

    $stmt = $pdo->prepare($sql);
    if (!$stmt->execute()) {
        throw new Exception("SQL execution failed");
    }
    
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $order_count = count($orders);
    logToConsole("Found orders: $order_count");

    if ($order_count === 0) {
        logToConsole("No orders to process");
        exit(0);
    }

    foreach ($orders as $order) {
        $order_id = $order['id'];
        $tracking_number = trim($order['tracking_number']);
        
        logToConsole("Processing order: ID $order_id, Track: $tracking_number");
        
        $postData = [
            'action' => 'get_delivery_status',
            'tracking_number' => $tracking_number,
            'order_id' => $order_id,
            'internal' => 1  // Флаг внутреннего запроса
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://pnevmatpro.ru/api/order_check_cdek.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Для отладки
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        logToConsole("HTTP code: $httpCode");
        
        if ($httpCode !== 200) {
            logToConsole("Error processing order $order_id: $error");
        } else {
            $result = json_decode($response, true);
            if ($result['status'] === 'success') {
                logToConsole("Success: " . $result['data']['delivery_status']);
            } else {
                logToConsole("API error: " . $result['message']);
            }
        }
        
        // Пауза между запросами
        sleep(2);
    }
    
    logToConsole("Script finished");
    
} catch (Exception $e) {
    logToConsole("CRITICAL ERROR: " . $e->getMessage());
}