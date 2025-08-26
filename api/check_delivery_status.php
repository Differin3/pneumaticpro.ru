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

    // ИЗМЕНЕНО: Убрано условие проверки времени последней проверки
    $sql = "SELECT id, tracking_number, status, delivery_status
            FROM orders 
            WHERE delivery_service = 'cdek'
            AND status NOT IN ('completed', 'canceled')
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
    
    logToConsole("Найдено заказов для обработки: " . $order_count);

    if ($order_count === 0) {
        logToConsole("Нет заказов для обработки");
        exit(0);
    }

    foreach ($orders as $order) {
        $order_id = $order['id'];
        $tracking_number = trim($order['tracking_number']);
        $current_status = $order['status'];
        $current_delivery_status = $order['delivery_status'];
        
        logToConsole("Обработка заказа ID: $order_id, Трек: $tracking_number");
        
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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            logToConsole("Ошибка обработки заказа $order_id: HTTP код $httpCode");
            if ($error) {
                logToConsole("cURL ошибка: $error");
            }
        } else {
            $result = json_decode($response, true);
            if ($result['status'] === 'success') {
                $new_delivery_status = $result['data']['delivery_status'];
                $new_internal_status = $result['data']['internal_status'];
                
                // Проверяем, изменился ли статус
                $status_changed = ($new_delivery_status !== $current_delivery_status) || 
                                 ($new_internal_status && $new_internal_status !== $current_status);
                
                if ($status_changed) {
                    logToConsole("Статус изменился: $new_delivery_status (внутренний: $new_internal_status)");
                } else {
                    logToConsole("Статус не изменился: $new_delivery_status");
                }
            } else {
                logToConsole("API ошибка: " . $result['message']);
            }
        }
        
        // Пауза между запросами
        sleep(2);
    }
    
    logToConsole("Скрипт завершен");
    
} catch (Exception $e) {
    logToConsole("КРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage());
}