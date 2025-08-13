<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

// Путь к файлу логов
$log_file = __DIR__ . '/logs/api_requests_' . date('Y-m-d') . '.log';

// Функция для получения ПВЗ из локального файла
function getLocalPvz($city) {
    $jsonFile = __DIR__ . '/pvz_cdek.json';
    if (file_exists($jsonFile)) {
        $pvz_data = json_decode(file_get_contents($jsonFile), true);
        if (json_last_error() === JSON_ERROR_NONE && isset($pvz_data[$city])) {
            $pvz_list = array_map(function ($item) use ($city) {
                $code = $item['code'] ?? '';
                if (empty($code)) {
                    logToFile("Отсутствует code для ПВЗ в локальном файле: " . json_encode($item), 'WARNING');
                }
                return [
                    'code' => $code,
                    'address' => $item['address'] ?? 'Адрес не указан',
                    'city' => $item['city'] ?? $city // Используем city из файла, если доступно
                ];
            }, $pvz_data[$city]);
            // Фильтрация ПВЗ без кода
            $pvz_list = array_filter($pvz_list, function($item) {
                return !empty($item['code']);
            });
            // Проверка, есть ли хотя бы один ПВЗ с кодом
            $hasValidCode = count($pvz_list) > 0;
            if (!$hasValidCode) {
                logToFile("Все ПВЗ в локальном файле для города $city не содержат кодов", 'WARNING');
                return null; // Заставляем использовать API
            }
            return $pvz_list;
        }
    }
    return null;
}

$response = ['status' => 'error', 'message' => 'Неизвестная ошибка'];

try {
    // Проверка CSRF-токена
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        throw new Exception("Недействительный CSRF-токен", 403);
    }

    $query = clean_input($_POST['query'] ?? '');
    if (empty($query)) {
        throw new Exception("Введите город или индекс", 400);
    }

    // Нормализация города
    $query = mb_convert_case($query, MB_CASE_TITLE, 'UTF-8');

    // Попытка получить ПВЗ из локального файла
    $pvz_data = getLocalPvz($query);
    if ($pvz_data && is_array($pvz_data)) {
        $response = [
            'status' => 'success',
            'data' => array_values($pvz_data), // array_values для корректного JSON
            'source' => 'local'
        ];

        logToFile("Успешно получены ПВЗ из локального файла для города: $query", 'INFO');
        logToFile("Коды ПВЗ в ответе: " . json_encode(array_column($pvz_data, 'code')), 'INFO');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Получение токена
    $account = CDEK_ACCOUNT;
    $securePassword = CDEK_SECURE_PASSWORD;
    $token = getCdekToken($account, $securePassword);

    if (!$token) {
        throw new Exception("Не удалось получить токен СДЭК", 500);
    }

    // Получение city_code
    $city_code = getCdekCityCode($token, $query);
    if (!$city_code) {
        throw new Exception("Город '$query' не найден", 404);
    }

    // Получение ПВЗ
    $url = 'https://api.cdek.ru/v2/deliverypoints';
    $params = [
        'city_code' => $city_code,
        'type' => 'ALL'
    ];

    $ch = curl_init($url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);

    $response_data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $pvz_data = json_decode($response_data, true);
        if (!$pvz_data || !is_array($pvz_data)) {
            throw new Exception("Пункты выдачи не найдены", 404);
        }

        $pvz_list = [];
        foreach ($pvz_data as $item) {
            $code = $item['code'] ?? '';
            $address = $item['location']['address_full'] ?? $item['location']['address'] ?? 'Адрес не указан';
            $city = $item['location']['city'] ?? $query;
            
            // Фильтрация ПВЗ без кода
            if (empty($code)) {
                logToFile("Отсутствует code для ПВЗ: " . json_encode($item), 'WARNING');
                continue;
            }
            
            $pvz_list[] = [
                'code' => $code,
                'address' => $address,
                'city' => $city
            ];
        }

        if (empty($pvz_list)) {
            throw new Exception("Нет ПВЗ с действительными кодами для города '$query'", 404);
        }

        $response = [
            'status' => 'success',
            'data' => $pvz_list,
            'source' => 'api'
        ];

        logToFile("Сформированный pvz_list: " . json_encode($pvz_list), 'INFO');
        logToFile("Коды ПВЗ в ответе: " . json_encode(array_column($pvz_list, 'code')), 'INFO');
    } else {
        logToFile("Ошибка получения ПВЗ для city_code '$city_code': HTTP $httpCode, Ответ: $response_data", 'ERROR');
        throw new Exception("Пункты выдачи не найдены", 404);
    }

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    $response = [
        'status' => 'error',
        'message' => $e->getMessage(),
        'error_code' => $e->getCode()
    ];
    logToFile("Ошибка в get_cdek_pvz.php: " . $e->getMessage() . ", Код: " . $e->getCode() . ", Запрос: $query", 'ERROR');
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
?>