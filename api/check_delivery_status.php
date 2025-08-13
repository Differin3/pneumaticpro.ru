<?php
// /var/www/pneumaticpro.ru/api/check_delivery_status.php

// 1. Настройки подключения к базе данных
define('DB_HOST', 'localhost');
define('DB_NAME', 'airgun_service');
define('DB_USER', 'pnevmatpro.ru'); // Замените на реальные данные
define('DB_PASS', 'pnevmatpro.ru');       // Замените на реальные данные

// 2. Создание подключения к БД
try {
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
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    exit(1);
}

// 3. Загрузка функций
require '/var/www/pneumaticpro.ru/includes/functions.php';

// 4. Основная логика
try {
    // Получаем заказы для проверки
    $sql = "SELECT id, tracking_number 
            FROM orders 
            WHERE delivery_service = 'cdek'
            AND status NOT IN ('completed', 'canceled')
            AND (last_status_check IS NULL OR last_status_check < NOW() - INTERVAL 1 HOUR)
            ORDER BY last_status_check ASC
            LIMIT 20";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $orders = $stmt->fetchAll();

    if (empty($orders)) {
        logToFile("CRON: Нет заказов для проверки", 'INFO');
        exit(0);
    }

    foreach ($orders as $order) {
        $order_id = $order['id'];
        $tracking_number = $order['tracking_number'];
        
        logToFile("CRON: Проверка заказа ID: $order_id, трек: $tracking_number", 'DEBUG');
        
        // Формируем POST-данные
        $postData = [
            'action' => 'get_delivery_status',
            'csrf_token' => generate_csrf_token(),
            'tracking_number' => $tracking_number,
            'order_id' => $order_id
        ];
        
        // Выполняем запрос к API
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://pnevmatpro.ru/api/order_check_cdek.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            logToFile("CRON: Ошибка при проверке заказа $order_id: HTTP $httpCode - $error", 'ERROR');
        } else {
            $result = json_decode($response, true);
            if ($result['status'] === 'success') {
                logToFile("CRON: Статус заказа $order_id обновлен: " . $result['data']['delivery_status'], 'INFO');
            } else {
                logToFile("CRON: Ошибка обновления $order_id: " . $result['message'], 'ERROR');
            }
        }
        
        // Пауза между запросами
        sleep(2);
    }
    
    logToFile("CRON: Проверка завершена, обработано заказов: " . count($orders), 'INFO');
    
} catch (Exception $e) {
    logToFile("CRON: Критическая ошибка: " . $e->getMessage(), 'CRITICAL');
    error_log("CRON error: " . $e->getMessage());
    exit(1);
}