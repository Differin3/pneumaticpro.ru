<?php
// api/order_check_cdek.php

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

// Важно: очистить буфер вывода перед установкой заголовков
if (ob_get_length()) ob_clean();

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Недопустимый метод запроса');
    }

    $action = clean_input($_POST['action'] ?? '');
    if ($action !== 'get_delivery_status') {
        throw new Exception('Недопустимое действие');
    }

    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        throw new Exception('Недопустимый CSRF-токен');
    }

    $tracking_number = clean_input($_POST['tracking_number'] ?? '');
    if (empty($tracking_number)) {
        throw new Exception('Трек-номер обязателен');
    }

    // Проверка формата трек-номера
    if (!preg_match('/^[a-zA-Z0-9-]{1,50}$/', $tracking_number)) {
        throw new Exception('Трек-номер должен содержать только буквы, цифры и дефисы, максимум 50 символов');
    }

    $order_id = clean_input($_POST['order_id'] ?? '');

    $token = getCdekToken(CDEK_ACCOUNT, CDEK_SECURE_PASSWORD);
    if (!$token) {
        throw new Exception('Не удалось получить токен СДЭК');
    }

    // Логируем токен для отладки
    logToFile("Токен СДЭК: $token", 'DEBUG');

    // Формируем запрос для cdek_number
    $url = 'https://api.cdek.ru/v2/orders?cdek_number=' . urlencode($tracking_number);
    $is_cdek_number = is_numeric($tracking_number);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception("Ошибка cURL: $curlError");
    }

    // Логируем полный ответ для отладки
    logToFile("Ответ API СДЭК для трек-номера $tracking_number (cdek_number): HTTP $httpCode, Ответ: $response", 'DEBUG');

    if ($httpCode !== 200) {
        $error_data = json_decode($response, true);
        $error_message = '';
        if (isset($error_data['errors']) && is_array($error_data['errors']) && !empty($error_data['errors'])) {
            $error_message = 'Ошибки API: ' . implode('; ', array_map(function($err) {
                return isset($err['message']) ? $err['message'] . (isset($err['code']) ? " (код: {$err['code']})" : '') : 'Неизвестная ошибка';
            }, $error_data['errors']));
        } else {
            $error_message = $error_data['message'] ?? 'Неизвестная ошибка API СДЭК';
        }

        // Если запрос с cdek_number не удался, пробуем im_number
        if ($httpCode === 400 && !empty($error_data['errors']) && $error_data['errors'][0]['code'] === 'v2_entity_forbidden') {
            $url = 'https://api.cdek.ru/v2/orders?im_number=' . urlencode($tracking_number);
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new Exception("Ошибка cURL (im_number): $curlError");
            }

            logToFile("Ответ API СДЭК для трек-номера $tracking_number (im_number): HTTP $httpCode, Ответ: $response", 'DEBUG');

            if ($httpCode !== 200) {
                $error_data = json_decode($response, true);
                $error_message = isset($error_data['errors']) && is_array($error_data['errors']) && !empty($error_data['errors'])
                    ? 'Ошибки API: ' . implode('; ', array_map(function($err) {
                        return isset($err['message']) ? $err['message'] . (isset($err['code']) ? " (код: {$err['code']})" : '') : 'Неизвестная ошибка';
                    }, $error_data['errors']))
                    : ($error_data['message'] ?? 'Неизвестная ошибка API СДЭК');
                throw new Exception("Ошибка API СДЭК (im_number) (HTTP $httpCode): $error_message");
            }
        } else {
            throw new Exception("Ошибка API СДЭК (cdek_number) (HTTP $httpCode): $error_message");
        }
    }

    $result = json_decode($response, true);

    if (empty($result['entity'])) {
        throw new Exception('Неверный формат ответа от API или заказ не найден');
    }

    $entity = $result['entity'];
    $statuses = $entity['statuses'] ?? [];

    if (empty($statuses)) {
        $delivery_status = 'Неизвестно';
        $internal_status = null;
    } else {
        $current_status = end($statuses);
        $delivery_status = $current_status['name'] ?? 'Неизвестно';
        $status_code = $current_status['code'] ?? '';

        // Маппинг кодов статусов СДЭК на внутренние статусы заказа
        $internal_status_map = [
            'CREATED' => 'processing',      // Создан -> Готовится к отправке
            'ACCEPTED' => 'processing',     // Принят -> Готовится к отправке
            'IN_TRANSIT' => 'shipped',      // В пути -> Отправлен
            'READY_FOR_PICKUP' => 'ready_for_pickup', // Готов к выдаче -> Новый статус
            'DELIVERED' => 'completed',     // Вручен -> Завершен
            'PICKED_UP' => 'completed',     // Получен -> Завершен
            'NOT_DELIVERED' => 'canceled',  // Не доставлен -> Отменен
            'RETURNED' => 'canceled',       // Возврат -> Отменен
        ];

        $internal_status = $internal_status_map[$status_code] ?? null;
    }

    // Обновляем статус заказа в базе данных, если получен internal_status и order_id
    if ($internal_status && !empty($order_id)) {
        try {
            $stmt = $pdo->prepare("UPDATE orders SET status = :status, updated_at = NOW() WHERE id = :order_id");
            $stmt->execute([
                ':status' => $internal_status,
                ':order_id' => $order_id
            ]);
            logToFile("Статус заказа ID $order_id обновлен на $internal_status для трек-номера $tracking_number", 'INFO');
        } catch (PDOException $e) {
            logToFile("Ошибка обновления статуса заказа ID $order_id: " . $e->getMessage(), 'ERROR');
        }
    }

    echo json_encode([
        'status' => 'success',
        'data' => [
            'delivery_status' => $delivery_status,
            'internal_status' => $internal_status,
            'raw_response' => $result // Для отладки
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
    logToFile("Ошибка в order_check_cdek.php для трек-номера $tracking_number: " . $e->getMessage(), 'ERROR');
}