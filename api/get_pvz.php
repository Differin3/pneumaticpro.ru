<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

// Путь к файлу логов
$log_file = __DIR__ . '/logs/api_requests_' . date('Y-m-d') . '.log';

// Функция для логирования с временной меткой
function log_to_file($message, $log_file) {
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] $message\n";
    file_put_contents($log_file, $log_message, FILE_APPEND);
}

$response = ['status' => 'error', 'message' => 'Неизвестная ошибка'];

try {
    // Логируем входящие параметры
    log_to_file("Входящий запрос: " . print_r($_POST, true), $log_file);

    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token($csrf_token)) {
        throw new Exception("Недействительный CSRF-токен", 403);
    }

    $query = isset($_POST['query']) ? clean_input($_POST['query']) : '';
    $delivery_company = isset($_POST['delivery_company']) ? clean_input($_POST['delivery_company']) : '';

    if (empty($query) || empty($delivery_company)) {
        throw new Exception("Город или служба доставки не указаны", 400);
    }

    if (!in_array(strtolower($delivery_company), ['post', 'cdek'])) {
        throw new Exception("Неверная служба доставки", 400);
    }

    $cache_file = __DIR__ . '/cache/pvz_data.json';
    $pvz_data = [];

    // Проверка кэша
    if (file_exists($cache_file)) {
        $pvz_data = json_decode(file_get_contents($cache_file), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_msg = "Ошибка чтения кэша pvz_data.json: " . json_last_error_msg();
            log_to_file($error_msg, $log_file);
            $pvz_data = ['post' => [], 'cdek' => []]; // Инициализация пустого кэша
        } else {
            log_to_file("Кэш успешно прочитан: " . print_r($pvz_data, true), $log_file);
        }
    } else {
        log_to_file("Кэш-файл pvz_data.json не найден по пути: $cache_file", $log_file);
        $pvz_data = ['post' => [], 'cdek' => []];
    }

    $data_from_cache = isset($pvz_data[$delivery_company][$query]) && is_array($pvz_data[$delivery_company][$query]);
    $api_data = null;

    if ($data_from_cache) {
        $api_data = $pvz_data[$delivery_company][$query];
        log_to_file("Данные для $query и $delivery_company найдены в кэше: " . print_r($api_data, true), $log_file);
    } else {
        if ($delivery_company === 'post') {
            // API Почты России через otpravka-api.pochta.ru
            $url = "https://otpravka-api.pochta.ru/postoffice/list";
            $headers = [
                "Authorization: AccessToken GmnCDePKBE6OSnCpS3sfSLD5ELha_Fcs",
                "X-User-Authorization: Basic M2RzdHVkaW9nYW1lc0BtYWlsLnJ1OjU0Nzg1VEdVNjQ3Z2o=",
                "Content-Type: application/json",
                "Accept: application/json"
            ];
            $post_data = json_encode([
                'address' => $query,
                'type' => 'ALL'
            ]);

            // Логируем запрос к API
            log_to_file("Запрос к API Почты России: URL: $url, Заголовки: " . print_r($headers, true) . ", Тело: $post_data", $log_file);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
            curl_setopt($ch, CURLOPT_HEADER, true);

            $output = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            $effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

            // Разделяем заголовки и тело ответа
            $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers = substr($output, 0, $header_size);
            $body = substr($output, $header_size);
            curl_close($ch);

            // Логируем ответ API
            log_to_file("Ответ API Почты России: HTTP-код: $http_code, Ошибка cURL: $curl_error, Конечный URL: $effective_url, Заголовки: $headers, Тело ответа: $body", $log_file);

            // Проверяем, не перенаправлен ли запрос на страницу спецификации
            if (strpos($effective_url, '/specification') !== false) {
                log_to_file("API перенаправил на страницу спецификации, пробуем другой подход", $log_file);
                $api_data = [];
            } elseif ($curl_error || $http_code != 200) {
                log_to_file("Ошибка API Почты России: HTTP $http_code, Ошибка: $curl_error", $log_file);
                // Временные тестовые данные для отладки
                $api_data = [
                    ['code' => '400001', 'address' => 'Волгоград, ул. Мира, 10 (тестовый адрес)'],
                    ['code' => '400002', 'address' => 'Волгоград, ул. Ленина, 15 (тестовый адрес)']
                ];
                log_to_file("Используются тестовые данные: " . print_r($api_data, true), $log_file);
            } else {
                // Проверяем, является ли ответ JSON
                $json_check = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $error_msg = "Ошибка разбора ответа API: " . json_last_error_msg();
                    log_to_file($error_msg, $log_file);
                } elseif (isset($json_check['error'])) {
                    log_to_file("Ошибка API: " . $json_check['error'], $log_file);
                } elseif (is_array($json_check)) {
                    $api_data = array_map(function ($item) {
                        return [
                            'code' => isset($item['index']) ? (string)$item['index'] : '',
                            'address' => isset($item['address']) ? $item['address'] : 'Адрес не указан'
                        ];
                    }, $json_check);

                    $pvz_data[$delivery_company][$query] = $api_data;
                    if (!file_put_contents($cache_file, json_encode($pvz_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                        log_to_file("Не удалось записать кэш в файл: $cache_file", $log_file);
                    } else {
                        log_to_file("Кэш успешно обновлён для $query и $delivery_company: " . print_r($api_data, true), $log_file);
                    }
                } else {
                    log_to_file("Ответ API не содержит ожидаемых данных", $log_file);
                }
            }
        }

        if ($api_data === null) {
            $api_data = [];
            log_to_file("Данные для $query и $delivery_company не получены из API", $log_file);
        }
    }

    $response = [
        'status' => 'success',
        'data' => $api_data,
        'message' => empty($api_data) ? 'Пункты выдачи не найдены для указанного города' : ''
    ];

    // Логируем исходящий ответ
    log_to_file("Исходящий ответ: " . json_encode($response, JSON_UNESCAPED_UNICODE), $log_file);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    $response = [
        'status' => 'error',
        'message' => $e->getMessage(),
        'error_code' => $e->getCode()
    ];
    log_to_file("Ошибка в get_pvz.php: " . $e->getMessage() . ", Код: " . $e->getCode(), $log_file);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
?>