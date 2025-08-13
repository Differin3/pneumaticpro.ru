<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

try {
    // Инициализация переменных
    $stats = [
        'active_connections' => 0,
        'accepted_connections' => 0,
        'handled_connections' => 0,
        'requests_total' => 0,
        'response_time_avg' => 0,
        'error_4xx_count' => 0,
        'error_5xx_count' => 0,
        'bandwidth_in' => 0,
        'bandwidth_out' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'cpu_usage' => 0,
        'memory_usage' => 0,
    ];

    // Получение данных из Nginx stub_status
    $status_urls = ['http://127.0.0.1/nginx_status', 'https://127.0.0.1/nginx_status'];
    $response = false;
    $http_code = 0;
    $curl_error = '';
    $effective_url = '';

    foreach ($status_urls as $url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        if (strpos($url, 'https') === 0) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($response !== false && $http_code == 200) {
            break;
        }
    }

    if ($response !== false && $http_code == 200) {
        // Парсинг stub_status
        if (preg_match('/Active connections: (\d+)/', $response, $matches)) {
            $stats['active_connections'] = (int)$matches[1];
        } else {
            error_log("Ошибка: Не удалось извлечь active_connections из stub_status (URL: $effective_url)");
        }
        if (preg_match('/(\d+)\s+(\d+)\s+(\d+)/', $response, $matches)) {
            $stats['accepted_connections'] = (int)$matches[1];
            $stats['handled_connections'] = (int)$matches[2];
            $stats['requests_total'] = (int)$matches[3];
        } else {
            error_log("Ошибка: Не удалось извлечь connections/requests из stub_status (URL: $effective_url). Ответ: " . htmlspecialchars(substr($response, 0, 100)));
        }
    } else {
        error_log("Ошибка stub_status (HTTP $http_code, URL: $effective_url): " . ($curl_error ?: 'Неверный формат ответа'));
    }

    // Заглушки для метрик (замените на реальные данные, если доступны)
    $stats['response_time_avg'] = rand(50, 500) / 1000; // В секундах
    $stats['error_4xx_count'] = rand(0, 10);
    $stats['error_5xx_count'] = rand(0, 5);
    $stats['bandwidth_in'] = rand(1000, 10000) * 1024; // В байтах
    $stats['bandwidth_out'] = rand(1000, 10000) * 1024; // В байтах
    $stats['cache_hits'] = rand(100, 1000);
    $stats['cache_misses'] = rand(10, 100);
    $stats['cpu_usage'] = rand(10, 90); // Процент (замените на данные из /proc/stat или psutil)
    $stats['memory_usage'] = rand(100, 1000) * 1024 * 1024; // В байтах (замените на данные из /proc/meminfo или psutil)

    // Сохранение в базу
    $stmt = $pdo->prepare("
        INSERT INTO server_logs (
            active_connections, accepted_connections, handled_connections, 
            requests_total, response_time_avg, error_4xx_count, error_5xx_count, 
            bandwidth_in, bandwidth_out, cache_hits, cache_misses, 
            cpu_usage, memory_usage, timestamp
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $stats['active_connections'],
        $stats['accepted_connections'],
        $stats['handled_connections'],
        $stats['requests_total'],
        $stats['response_time_avg'],
        $stats['error_4xx_count'],
        $stats['error_5xx_count'],
        $stats['bandwidth_in'],
        $stats['bandwidth_out'],
        $stats['cache_hits'],
        $stats['cache_misses'],
        $stats['cpu_usage'],
        $stats['memory_usage']
    ]);

} catch (Exception $e) {
    error_log("Ошибка в collect_server_stats.php: " . $e->getMessage());
}
?>