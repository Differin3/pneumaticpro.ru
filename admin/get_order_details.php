<?php
session_start();
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

// Убедимся, что ничего не выводится до JSON
ob_start();

header('Content-Type: application/json; charset=utf-8');

// Проверка авторизации администратора
if (!isset($_SESSION['admin'])) {
    http_response_code(403);
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Доступ запрещен'], JSON_UNESCAPED_UNICODE);
    exit();
}

// Проверка CSRF-токена
if (!validate_csrf_token($_GET['csrf_token'] ?? '')) {
    http_response_code(403);
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Недействительный CSRF-токен'], JSON_UNESCAPED_UNICODE);
    exit();
}

// Получение и валидация номера заказа
$orderNumber = $_GET['order_number'] ?? '';
if (empty($orderNumber)) {
    http_response_code(400);
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Номер заказа не указан'], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    // Получаем данные заказа и клиента
    $stmt = $pdo->prepare("
        SELECT 
            o.*,
            c.full_name,
            c.phone,
            IFNULL(c.address, 'Не указан') as customer_address,
            u.email,
            c.birth_date,
            c.preferred_communication
        FROM orders o
        JOIN customers c ON o.customer_id = c.id
        JOIN users u ON c.user_id = u.id
        WHERE o.order_number = ?
    ");
    $stmt->execute([$orderNumber]);
    $orderData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$orderData) {
        http_response_code(404);
        ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => 'Заказ не найден'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Формируем данные клиента
    $customer = [
        'full_name' => $orderData['full_name'] ?? 'Не указано',
        'phone' => $orderData['phone'] ?? 'Не указан',
        'email' => $orderData['email'] ?? 'Не указан',
        'address' => $orderData['customer_address'] ?? 'Не указан',
        'birth_date' => $orderData['birth_date'] ?? null,
        'preferred_communication' => $orderData['preferred_communication'] ?? 'email'
    ];

    // Удаляем данные клиента из массива заказа
    unset(
        $orderData['full_name'],
        $orderData['phone'],
        $orderData['email'],
        $orderData['customer_address'],
        $orderData['birth_date'],
        $orderData['preferred_communication']
    );

    // Получаем товары и услуги заказа
    $stmt = $pdo->prepare("
        SELECT 
            oi.*,
            p.name AS product_name,
            p.vendor_code AS product_vendor_code,
            p.image AS product_image,
            p.type AS product_type,
            p.diameter,
            p.weight,
            s.name AS service_name,
            s.vendor_code AS service_vendor_code,
            s.image AS service_image,
            s.service_type,
            s.duration
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        LEFT JOIN services s ON oi.service_id = s.id
        WHERE oi.order_id = ?
    ");
    $stmt->execute([$orderData['id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Форматируем элементы заказа
    $formattedItems = array_map(function($item) {
        if ($item['product_id']) {
            return [
                'type' => 'product',
                'name' => $item['product_name'],
                'vendor_code' => $item['product_vendor_code'],
                'image' => $item['product_image'],
                'product_type' => $item['product_type'],
                'diameter' => $item['diameter'],
                'weight' => $item['weight'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'product_id' => $item['product_id']
            ];
        } elseif ($item['service_id']) {
            return [
                'type' => 'service',
                'name' => $item['service_name'],
                'vendor_code' => $item['service_vendor_code'],
                'image' => $item['service_image'],
                'service_type' => $item['service_type'],
                'duration' => $item['duration'],
                'quantity' => $item['quantity'],
                'price' => $item['price']
            ];
        }
        return null;
    }, $items);

    // Удаляем null элементы
    $formattedItems = array_filter($formattedItems);

    // Получаем историю изменений заказа
    $stmt = $pdo->prepare("
        SELECT 
            oh.created_at,
            oh.action,
            oh.details
        FROM order_history oh
        WHERE oh.order_id = ?
        ORDER BY oh.created_at DESC
    ");
    $stmt->execute([$orderData['id']]);
    $history_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Обработка истории изменений с защитой от ошибок JSON
    $history = array_map(function($entry) {
        $details = $entry['details'];
        $formatted_details = '—';

        if ($details && is_string($details)) {
            $parsed_details = json_decode($details, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed_details)) {
                $status_map = [
                    'new' => 'Новый',
                    'processing' => 'В обработке',
                    'shipped' => 'Отправлен',
                    'completed' => 'Завершен',
                    'canceled' => 'Отменен'
                ];
                $delivery_service_map = [
                    'cdek' => 'CDEK',
                    'post' => 'Почта России',
                    'pickup' => 'Самовывоз'
                ];

                if ($entry['action'] === 'status_update') {
                    $status = $parsed_details['status'] ?? '';
                    $tracking_number = $parsed_details['tracking_number'] ?? '';
                    $delivery_service = $parsed_details['delivery_service'] ?? '';

                    $status_text = $status_map[$status] ?? $status;
                    $delivery_text = $delivery_service_map[$delivery_service] ?? $delivery_service;

                    $formatted_details = "Статус изменён на \"$status_text\". ";
                    $formatted_details .= "Служба доставки: " . ($delivery_text ?: 'Не выбрано') . ". ";
                    $formatted_details .= "Трекинг-номер: " . ($tracking_number ?: 'отсутствует') . ".";
                } else {
                    $formatted_details = $details;
                }
            } else {
                // Если JSON некорректен, логируем и используем исходные данные
                error_log("Некорректный JSON в order_history.details: " . $details);
                $formatted_details = $details;
            }
        }

        return [
            'created_at' => $entry['created_at'],
            'action' => $entry['action'],
            'details' => $formatted_details
        ];
    }, $history_raw);

    // Формируем финальный ответ
    $response = [
        'status' => 'success',
        'data' => [
            'order' => $orderData,
            'items' => array_values($formattedItems),
            'customer' => $customer,
            'history' => $history
        ]
    ];

    ob_end_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("Database error in get_order_details.php: " . $e->getMessage());
    http_response_code(500);
    ob_end_clean();
    echo json_encode([
        'status' => 'error',
        'message' => 'Ошибка базы данных',
        'error' => $e->getMessage(),
        'error_info' => $pdo->errorInfo()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("General error in get_order_details.php: " . $e->getMessage());
    http_response_code(500);
    ob_end_clean();
    echo json_encode([
        'status' => 'error',
        'message' => 'Произошла ошибка при обработке запроса',
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

exit();
?>