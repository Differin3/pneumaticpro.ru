<?php
// api/order_check_cdek.php

// Включение детального логгирования
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/cdek_api_errors.log');
error_reporting(E_ALL);

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

// Функция для консольного логирования
function logToConsole($message) {
    error_log('[' . date('Y-m-d H:i:s') . '] CDEK_API: ' . $message);
}

// Очистка буфера
if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

logToConsole("==== НАЧАЛО ОБРАБОТКИ ЗАПРОСА ====");

try {
    // Проверка внутреннего запроса
    $isInternal = isset($_POST['internal']) && $_POST['internal'] == 1;
    
    logToConsole("Метод запроса: " . $_SERVER['REQUEST_METHOD']);
    logToConsole("POST данные: " . json_encode($_POST, JSON_UNESCAPED_UNICODE));
    logToConsole("Тип запроса: " . ($isInternal ? "ВНУТРЕННИЙ" : "ВНЕШНИЙ"));

    // Проверка метода
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Недопустимый метод запроса');
    }

    // Проверка действия
    $action = clean_input($_POST['action'] ?? '');
    if ($action !== 'get_delivery_status') {
        throw new Exception('Недопустимое действие');
    }

    // Пропускаем проверку CSRF для внутренних запросов
    if (!$isInternal) {
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (!validate_csrf_token($csrf_token)) {
            throw new Exception('Недопустимый CSRF-токен');
        }
    }

    // Проверка трек-номера
    $tracking_number = clean_input($_POST['tracking_number'] ?? '');
    
    // ИЗМЕНЕНО: Улучшено сообщение об ошибке
    if (empty($tracking_number)) {
        throw new Exception('Трек-номер не может быть пустым');
    }
    if (!preg_match('/^[a-zA-Z0-9-]{1,50}$/', $tracking_number)) {
        throw new Exception('Недопустимый формат трек-номера');
    }

    // Получение ID заказа
    $order_id = clean_input($_POST['order_id'] ?? '');
    logToConsole("Параметры: action={$action}, track={$tracking_number}, order_id={$order_id}");

    // Обновление времени последней проверки
    if (!empty($order_id)) {
        try {
            $stmt = $pdo->prepare("UPDATE orders SET last_status_check = NOW() WHERE id = ?");
            $stmt->execute([$order_id]);
            logToConsole("Время проверки обновлено для заказа {$order_id}");
        } catch (PDOException $e) {
            logToConsole("Ошибка при обновлении времени проверки: " . $e->getMessage());
        }
    }

    // Получение токена CDEK
    logToConsole("Получение токена авторизации CDEK...");
    $token = getCdekToken(CDEK_ACCOUNT, CDEK_SECURE_PASSWORD);
    
    if (!$token) {
        throw new Exception('Не удалось получить токен авторизации CDEK');
    }
    logToConsole("Токен CDEK получен: " . substr($token, 0, 20) . "...");

    // Первый запрос: cdek_number
    $url_cdek = 'https://api.cdek.ru/v2/orders?cdek_number=' . urlencode($tracking_number);
    logToConsole("Запрос к CDEK API (cdek_number): {$url_cdek}");
    
    $response = sendCdekRequest($url_cdek, $token);
    $httpCode = $response['http_code'];
    $responseBody = $response['body'];
    
    logToConsole("Ответ CDEK (cdek_number): HTTP {$httpCode}, Тело: " . substr($responseBody, 0, 500));

    // Если первый запрос неудачен, пробуем im_number
    if ($httpCode !== 200) {
        $url_im = 'https://api.cdek.ru/v2/orders?im_number=' . urlencode($tracking_number);
        logToConsole("Повторный запрос (im_number): {$url_im}");
        
        $response = sendCdekRequest($url_im, $token);
        $httpCode = $response['http_code'];
        $responseBody = $response['body'];
        
        logToConsole("Ответ CDEK (im_number): HTTP {$httpCode}, Тело: " . substr($responseBody, 0, 500));
    }

    // Проверка успешности запроса
    if ($httpCode !== 200) {
        $errorData = json_decode($responseBody, true) ?? [];
        $errorMessage = 'Неизвестная ошибка API';
        
        if (!empty($errorData['errors'])) {
            $errorDetails = array_map(function($err) {
                return $err['code'] . ': ' . $err['message'];
            }, $errorData['errors']);
            $errorMessage = implode('; ', $errorDetails);
        } elseif (!empty($errorData['message'])) {
            $errorMessage = $errorData['message'];
        }
        
        throw new Exception("Ошибка API CDEK ({$httpCode}): {$errorMessage}");
    }

    // Декодирование JSON
    $result = json_decode($responseBody, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Ошибка декодирования JSON: " . json_last_error_msg());
    }

    // Проверка наличия данных
    if (empty($result['entity'])) {
        throw new Exception('Заказ не найден или неверный формат ответа');
    }

    // Обработка статуса
    $entity = $result['entity'];
    $statuses = $entity['statuses'] ?? [];
    
    if (empty($statuses)) {
        $delivery_status = 'Неизвестно';
        $internal_status = null;
        $status_code = null;
    } else {
        $current_status = end($statuses);
        $delivery_status = $current_status['name'] ?? 'Неизвестно';
        $status_code = $current_status['code'] ?? '';

        // Маппинг статусов CDEK на внутренние статусы
        $internal_status_map = [
            'CREATED' => 'processing',
            'ACCEPTED' => 'processing',
            'IN_TRANSIT' => 'shipped',
            'READY_FOR_PICKUP' => 'ready_for_pickup',
            'DELIVERED' => 'completed',
            'PICKED_UP' => 'completed',
            'NOT_DELIVERED' => 'canceled',
            'RETURNED' => 'canceled',
        ];

        $internal_status = $internal_status_map[$status_code] ?? null;
    }

    logToConsole("Статус получен: {$delivery_status} ({$status_code})");

    // Обновление статуса заказа
    if (!empty($order_id)) {
        try {
            // Получение текущего статуса
            $stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
            $stmt->execute([$order_id]);
            $current_local_status = $stmt->fetchColumn();
            
            // Подготовка данных для обновления
            $updateParams = [
                ':order_id' => $order_id,
                ':delivery_status' => $delivery_status,
                ':delivery_status_code' => $status_code,
                ':last_status_check' => date('Y-m-d H:i:s')
            ];
            
            $updateFields = [
                "delivery_status = :delivery_status",
                "delivery_status_code = :delivery_status_code",
                "last_status_check = :last_status_check"
            ];
            
            // Обновление основного статуса при наличии
            if ($internal_status) {
                $updateFields[] = "status = :status";
                $updateParams[':status'] = $internal_status;
                $current_local_status = $internal_status;
            }
            
            // Выполнение обновления
            $sql = "UPDATE orders SET " . implode(', ', $updateFields) . " WHERE id = :order_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($updateParams);
            logToConsole("Статус заказа {$order_id} обновлен");
            
            // Логирование в историю
            $logSql = "INSERT INTO delivery_logs 
                        (order_id, status_code, status_name, local_status, api_response) 
                       VALUES 
                        (:order_id, :status_code, :status_name, :local_status, :api_response)";
            
            $stmt = $pdo->prepare($logSql);
            $stmt->execute([
                ':order_id' => $order_id,
                ':status_code' => $status_code,
                ':status_name' => $delivery_status,
                ':local_status' => $current_local_status,
                ':api_response' => json_encode($result, JSON_UNESCAPED_UNICODE)
            ]);
            logToConsole("Лог доставки записан");
            
        } catch (PDOException $e) {
            logToConsole("Ошибка БД при обновлении статуса: " . $e->getMessage());
            throw new Exception("Ошибка обновления статуса заказа");
        }
    }

    // Формирование успешного ответа
    $response = [
        'status' => 'success',
        'data' => [
            'delivery_status' => $delivery_status,
            'internal_status' => $internal_status,
            'status_code' => $status_code
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    logToConsole("Успешный ответ сформирован");

} catch (Exception $e) {
    http_response_code(400);
    $errorResponse = [
        'status' => 'error',
        'message' => $e->getMessage()
    ];
    
    echo json_encode($errorResponse, JSON_UNESCAPED_UNICODE);
    logToConsole("ОШИБКА: " . $e->getMessage());
}

logToConsole("==== ЗАВЕРШЕНИЕ ОБРАБОТКИ ЗАПРОСА ====");

/**
 * Вспомогательная функция для запросов к API CDEK
 */
function sendCdekRequest($url, $token) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FAILONERROR => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return [
        'http_code' => $httpCode,
        'body' => $response,
        'error' => $error
    ];
}