<?php
// /var/www/pneumaticpro.ru/api/check_delivery_status.php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

logToFile("CRON: Запуск проверки статусов доставки", 'INFO');

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
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            logToFile("CRON: Ошибка при проверке заказа $order_id: HTTP $httpCode", 'ERROR');
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
}