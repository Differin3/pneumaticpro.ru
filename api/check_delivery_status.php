<?php
// /var/www/pneumaticpro.ru/api/check_delivery_status.php

// Диагностический лог
$debug_log = "/tmp/cron_debug_" . date('Ymd') . ".txt";
file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] Script started\n", FILE_APPEND);

try {
    // Загрузка конфигурации
    if (!@include __DIR__ . '/../includes/config.php') {
        throw new Exception("Failed to load config file");
    }
    file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] Config loaded\n", FILE_APPEND);
    
    if (!@include __DIR__ . '/../includes/functions.php') {
        throw new Exception("Failed to load functions file");
    }
    file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] Functions loaded\n", FILE_APPEND);

    // Проверка подключения к БД
    file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] DB host: " . DB_HOST . "\n", FILE_APPEND);
    
    $test_stmt = $pdo->query("SELECT 1");
    if (!$test_stmt) {
        throw new Exception("Database connection test failed");
    }
    file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] DB connection OK\n", FILE_APPEND);

    // SQL запрос
    $sql = "SELECT id, tracking_number 
            FROM orders 
            WHERE delivery_service = 'cdek'
            AND status NOT IN ('completed', 'canceled')
            AND (last_status_check IS NULL OR last_status_check < NOW() - INTERVAL 1 HOUR)
            ORDER BY last_status_check ASC
            LIMIT 20";

    file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] SQL: $sql\n", FILE_APPEND);
    
    $stmt = $pdo->prepare($sql);
    if (!$stmt->execute()) {
        throw new Exception("SQL execution failed");
    }
    
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $order_count = count($orders);
    file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] Found orders: $order_count\n", FILE_APPEND);

    if ($order_count === 0) {
        file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] No orders to process\n", FILE_APPEND);
        exit(0);
    }

    foreach ($orders as $index => $order) {
        $order_id = $order['id'];
        $tracking_number = $order['tracking_number'];
        
        file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] Processing order #$index: ID $order_id, Track: $tracking_number\n", FILE_APPEND);
        
        $postData = [
            'action' => 'get_delivery_status',
            'csrf_token' => generate_csrf_token(),
            'tracking_number' => $tracking_number,
            'order_id' => $order_id
        ];
        
        file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] POST data: " . print_r($postData, true) . "\n", FILE_APPEND);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://pnevmatpro.ru/api/order_check_cdek.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Для отладки
        
        // Логирование cURL
        $curl_log = "/tmp/curl_debug_" . date('Ymd') . ".txt";
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        curl_setopt($ch, CURLOPT_STDERR, fopen($curl_log, 'a'));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] HTTP code: $httpCode\n", FILE_APPEND);
        file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] cURL error: $error\n", FILE_APPEND);
        file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] Response: " . substr($response, 0, 500) . "\n", FILE_APPEND);
        
        if ($httpCode !== 200) {
            file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] Error processing order $order_id\n", FILE_APPEND);
        } else {
            $result = json_decode($response, true);
            if ($result['status'] === 'success') {
                file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] Success: " . $result['data']['delivery_status'] . "\n", FILE_APPEND);
            } else {
                file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] API error: " . $result['message'] . "\n", FILE_APPEND);
            }
        }
        
        sleep(2);
    }
    
    file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] Script finished\n", FILE_APPEND);
    
} catch (Exception $e) {
    file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] CRITICAL ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
}