<?php
// update_cdek_statuses.php
session_start();
ob_start();
header('Content-Type: application/json; charset=UTF-8');

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/get_cdek_pvz.php';

// Путь к файлу логов
$log_file = __DIR__ . '/logs/cdek_status_updates_' . date('Y-m-d') . '.log';

// Функция для логирования
function logToFile($message, $level = 'INFO') {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] [$level] $message\n", FILE_APPEND);
}

// Функция для выполнения запроса с повторными попытками
function getCdekDeliveryStatusWithRetry($token, $tracking_number, $max_retries = 3, $retry_delay = 5) {
    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
        $result = getCdekDeliveryStatus($token, $tracking_number);
        if ($result !== null) {
            return $result;
        }
        if ($attempt < $max_retries) {
            logToFile("Попытка $attempt не удалась для трек-номера $tracking_number, повтор через $retry_delay секунд", 'WARNING');
            sleep($retry_delay);
        }
    }
    return null;
}

$response = ['status' => 'success', 'message' => 'Обновление статусов выполнено', 'updated_orders' => 0];

try {
    // Получаем токен СДЭК
    $account = CDEK_ACCOUNT;
    $securePassword = CDEK_SECURE_PASSWORD;
    $token = getCdekToken($account, $securePassword);

    if (!$token) {
        throw new Exception("Не удалось получить токен СДЭК", 500);
    }

    // Получаем заказы с delivery_service = 'cdek' и непустым tracking_number
    $stmt = $pdo->prepare("
        SELECT id, order_number, tracking_number, delivery_status, status
        FROM orders
        WHERE delivery_service = 'cdek' AND tracking_number IS NOT NULL AND tracking_number != ''
    ");
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updated_orders = 0;

    foreach ($orders as $order) {
        $order_id = $order['id'];
        $tracking_number = $order['tracking_number'];
        $current_delivery_status = $order['delivery_status'];
        $current_status = $order['status'];

        // Получаем статус с повторными попытками
        $delivery_status = getCdekDeliveryStatusWithRetry($token, $tracking_number);

        if ($delivery_status && isset($delivery_status['delivery_status'])) {
            $new_delivery_status = $delivery_status['delivery_status'];
            $status_description = $delivery_status['description'];
            $new_internal_status = $delivery_status['internal_status'];

            // Проверяем, изменился ли статус
            if ($new_delivery_status !== $current_delivery_status || $new_internal_status !== $current_status) {
                // Обновляем заказ
                $update_stmt = $pdo->prepare("
                    UPDATE orders
                    SET delivery_status = ?,
                        status = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $update_stmt->execute([$new_delivery_status, $new_internal_status, $order_id]);

                // Записываем в историю изменений
                $details = [
                    'delivery_status' => $new_delivery_status,
                    'status_description' => $status_description,
                    'internal_status' => $new_internal_status,
                    'tracking_number' => $tracking_number
                ];
                $details_json = json_encode($details, JSON_UNESCAPED_UNICODE);
                if ($details_json === false) {
                    throw new Exception("Ошибка кодирования JSON для истории изменений", 500);
                }

                $history_stmt = $pdo->prepare("
                    INSERT INTO order_history (order_id, admin_id, action, details, created_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $history_stmt->execute([$order_id, 0, 'delivery_status_update', $details_json]);

                // Логируем изменение
                logToFile("Обновлен статус доставки для заказа #{$order['order_number']}: $new_delivery_status (внутренний: $new_internal_status)", 'INFO');
                $updated_orders++;

                // Уведомление администраторов
                $notify_stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, message, created_at)
                    SELECT user_id, 'order', ?, NOW()
                    FROM admins WHERE notifications_enabled = 1
                ");
                $notify_stmt->execute(["Обновлен статус доставки для заказа #{$order['order_number']}: $new_delivery_status"]);
            }
        } else {
            logToFile("Не удалось получить статус для трек-номера $tracking_number (заказ #{$order['order_number']})", 'WARNING');
        }
    }

    $response['updated_orders'] = $updated_orders;
    $response['message'] = "Обновлено статусов: $updated_orders";

} catch (Exception $e) {
    $response = [
        'status' => 'error',
        'message' => $e->getMessage(),
        'code' => $e->getCode()
    ];
    logToFile("Ошибка в update_cdek_statuses.php: " . $e->getMessage(), 'ERROR');
}

ob_end_clean();
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
?>