<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

// Путь к файлу логов
$log_file = __DIR__ . '/logs/api_requests_' . date('Y-m-d') . '.log';

// Функция для вычисления расстояния Левенштейна (для fuzzy matching)
function levenshtein_distance($str1, $str2) {
    return levenshtein(mb_strtolower($str1, 'UTF-8'), mb_strtolower($str2, 'UTF-8'));
}

// Функция для получения городов из локального файла
function getLocalCities($query) {
    $jsonFile = __DIR__ . '/cities_cdek.json';
    if (file_exists($jsonFile)) {
        $cities_data = json_decode(file_get_contents($jsonFile), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $query = mb_strtolower(trim($query), 'UTF-8');
            $results = [];
            foreach ($cities_data as $city) {
                $city_name = mb_strtolower($city['city'], 'UTF-8');
                if (stripos($city_name, $query) === 0) { // Начинается с запроса
                    $results[] = [
                        'city' => $city['city'],
                        'code' => $city['code'] ?? '',
                        'distance' => 0
                    ];
                } elseif (levenshtein_distance($query, $city_name) <= 3) { // Допускаем до 3 ошибок
                    $results[] = [
                        'city' => $city['city'],
                        'code' => $city['code'] ?? '',
                        'distance' => levenshtein_distance($query, $city_name)
                    ];
                }
            }
            // Сортируем по расстоянию Левенштейна
            usort($results, function($a, $b) {
                return $a['distance'] <=> $b['distance'];
            });
            return array_slice($results, 0, 10); // Ограничиваем 10 результатами
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
        throw new Exception("Введите запрос для поиска города", 400);
    }

    // Нормализация запроса
    $query = mb_convert_case($query, MB_CASE_TITLE, 'UTF-8');

    // Попытка получить города из локального файла
    $cities = getLocalCities($query);
    if ($cities && is_array($cities)) {
        $response = [
            'status' => 'success',
            'data' => $cities,
            'source' => 'local'
        ];
        logToFile("Успешно получены города из локального файла для запроса: $query", 'INFO');
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

    // Получение городов через API СДЭК
    $url = 'https://api.cdek.ru/v2/location/cities';
    $params = ['city' => $query];

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
        $cities_data = json_decode($response_data, true);
        if (!$cities_data || !is_array($cities_data)) {
            throw new Exception("Города не найдены", 404);
        }

        $cities = array_map(function ($item) use ($query) {
            $city_name = $item['city'] ?? '';
            return [
                'city' => $city_name,
                'code' => $item['code'] ?? '',
                'distance' => levenshtein_distance($query, $city_name)
            ];
        }, $cities_data);

        // Фильтруем города, начинающиеся с запроса или с малым расстоянием Левенштейна
        $filtered_cities = array_filter($cities, function($city) use ($query) {
            $city_name = mb_strtolower($city['city'], 'UTF-8');
            $query_lower = mb_strtolower($query, 'UTF-8');
            return stripos($city_name, $query_lower) === 0 || $city['distance'] <= 3;
        });

        // Сортируем по расстоянию
        usort($filtered_cities, function($a, $b) {
            return $a['distance'] <=> $b['distance'];
        });

        $response = [
            'status' => 'success',
            'data' => array_slice($filtered_cities, 0, 10), // Ограничиваем 10 результатами
            'source' => 'api'
        ];

        logToFile("Сформированный список городов: " . json_encode($filtered_cities, JSON_UNESCAPED_UNICODE), 'INFO');
    } else {
        logToFile("Ошибка получения городов для запроса '$query': HTTP $httpCode, Ответ: $response_data", 'ERROR');
        throw new Exception("Города не найдены", 404);
    }

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    $response = [
        'status' => 'error',
        'message' => $e->getMessage(),
        'error_code' => $e->getCode()
    ];
    logToFile("Ошибка в get_cdek_cities.php: " . $e->getMessage() . ", Код: " . $e->getCode() . ", Запрос: $query", 'ERROR');
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
?>