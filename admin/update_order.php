<?php
session_start();

// Убедимся, что никаких данных не выводится до JSON
ob_start();
header('Content-Type: application/json; charset=UTF-8');

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

$response = ['status' => 'error', 'message' => 'Неизвестная ошибка'];

try {
    // Проверка авторизации
    if (!isset($_SESSION['admin']) || !is_array($_SESSION['admin']) || !isset($_SESSION['admin']['id'])) {
        throw new Exception("Требуется авторизация", 401);
    }

    // Извлечение admin_id из $_SESSION['admin']['id']
    $adminId = $_SESSION['admin']['id'];
    if (!ctype_digit((string)$adminId)) {
        error_log("Invalid admin_id in session: " . print_r($_SESSION['admin'], true));
        throw new Exception("Неверный ID администратора в сессии. Проверьте авторизацию.", 500);
    }
    $adminId = (int)$adminId;

    // Проверка CSRF-токена
    if (!isset($_POST['csrf_token'])) {
        throw new Exception("CSRF-токен не предоставлен", 400);
    }

    if (!validate_csrf_token($_POST['csrf_token'])) {
        throw new Exception("Недействительный CSRF-токен", 403);
    }

    // Проверка данных
    if (!isset($_POST['order_id']) || !ctype_digit($_POST['order_id'])) {
        throw new Exception("Неверный ID заказа", 400);
    }
    
    $orderId = (int)$_POST['order_id'];
    $status = $_POST['status'] ?? null;
    $trackingNumber = $_POST['tracking_number'] ?? '';
    $deliveryService = $_POST['delivery_service'] ?? null; // Необязательное поле

    // Валидация статуса
    $allowedStatuses = ['new', 'processing', 'shipped', 'ready_for_pickup', 'completed', 'canceled'];
    if (!in_array($status, $allowedStatuses)) {
        throw new Exception("Неверный статус заказа", 400);
    }

    // Валидация трек-номера, если он передан и не пустой
    if (!empty($trackingNumber)) {
        if (strlen($trackingNumber) > 50 || !preg_match('/^[a-zA-Z0-9-]+$/', $trackingNumber)) {
            throw new Exception("Недействительный трек-номер: максимум 50 символов, только буквы, цифры и дефисы", 400);
        }
    } else {
        $trackingNumber = ''; // Убедимся, что trackingNumber - строка, а не null
    }

    // Получаем текущую службу доставки заказа
    $stmt = $pdo->prepare("SELECT delivery_service FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $currentOrder = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$currentOrder) {
        throw new Exception("Заказ не найден", 404);
    }
    $currentDeliveryService = $currentOrder['delivery_service'];

    // Логика изменения службы доставки
    if ($status === 'shipped' && $currentDeliveryService === 'post') {
        $deliveryService = 'cdek'; // Автоматически меняем на СДЭК
    } elseif (empty($deliveryService)) {
        $deliveryService = $currentDeliveryService ?? 'Не выбрано'; // Сохраняем текущее значение или устанавливаем дефолт
    }

    // Обновление заказа
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET status = ?, 
            tracking_number = ?,
            delivery_service = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$status, $trackingNumber, $deliveryService, $orderId]);

    // Запись в историю изменений
    try {
        $details = [
            'status' => $status,
            'tracking_number' => $trackingNumber,
            'delivery_service' => $deliveryService,
            'previous_delivery_service' => $currentDeliveryService
        ];
        $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE);
        if ($detailsJson === false) {
            throw new Exception("Ошибка при кодировании JSON: " . json_last_error_msg(), 500);
        }

        $stmt = $pdo->prepare("
            INSERT INTO order_history 
            (order_id, admin_id, action, details, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $orderId,
            $adminId,
            'status_update',
            $detailsJson
        ]);
    } catch (Exception $e) {
        error_log("Ошибка записи в историю изменений: " . $e->getMessage());
        throw new Exception("Не удалось записать историю изменений: " . $e->getMessage(), 500);
    }

    // Логирование действия
    $logDescription = "Обновлен заказ №{$orderId}: статус на '{$status}', трек-номер '{$trackingNumber}', служба доставки '{$deliveryService}'";
    logActivity($pdo, 'order_update', $logDescription, $adminId);

    $response = [
        'status' => 'success',
        'message' => 'Заказ успешно обновлен',
        'debug' => [
            'order_id' => $orderId,
            'admin_id' => $adminId,
            'status' => $status,
            'tracking_number' => $trackingNumber,
            'delivery_service' => $deliveryService,
            'previous_delivery_service' => $currentDeliveryService,
            'csrf_token_session' => $_SESSION['csrf_token'] ?? 'not set',
            'csrf_token_post' => $_POST['csrf_token'] ?? 'not set'
        ]
    ];

} catch (PDOException $e) {
    error_log("PDO Exception in update_order.php: " . $e->getMessage());
    $response = [
        'status' => 'error',
        'message' => 'Ошибка базы данных: ' . $e->getMessage(),
        'code' => 500
    ];
} catch (Exception $e) {
    error_log("Exception in update_order.php: " . $e->getMessage());
    $response = [
        'status' => 'error',
        'message' => $e->getMessage(),
        'code' => $e->getCode()
    ];
}

// Очищаем буфер вывода и отправляем JSON
ob_end_clean();
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
?>