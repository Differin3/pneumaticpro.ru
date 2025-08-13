<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Логируем начало выполнения скрипта
logToFile("send_order_notification.php script started", "DEBUG");

// Пример вызова функции
if (isset($_GET['order_id'])) {
    $order_id = (int)$_GET['order_id'];
    logToFile("Received order_id: $order_id", "INFO");
    sendTelegramOrderNotification($pdo, $order_id);
    echo "Уведомление отправлено (проверьте лог в /var/www/pneumaticpro.ru/logs/telegram_notifications.log)";
} else {
    logToFile("No order_id provided in request", "ERROR");
    echo "Не указан ID заказа";
}
?>