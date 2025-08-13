<?php
ob_start(); // Start output buffering to capture any unexpected output
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Configure error handling to log errors instead of displaying them
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

session_start();

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
    'db_size' => 0,
    'history' => [],
];

try {
    // Проверка CSRF-токена
    $csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $csrf_token !== $_SESSION['csrf_token']) {
        throw new Exception('Недействительный CSRF-токен');
    }

    // Обработка параметров временного интервала
    $interval = isset($_GET['interval']) ? $_GET['interval'] : '24h';
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-1 day'));
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

    // Валидация параметров
    $valid_intervals = ['1h', '6h', '12h', '24h', '7d', '30d', 'custom'];
    if (!in_array($interval, $valid_intervals)) {
        throw new Exception('Недопустимый интервал');
    }
    if ($interval === 'custom') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            throw new Exception('Недопустимый формат даты');
        }
    }

    // Извлечение данных из Nginx status
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
        if (preg_match('/Active connections: (\d+)/', $response, $matches)) {
            $stats['active_connections'] = (int)$matches[1];
        }
        if (preg_match('/(\d+)\s+(\d+)\s+(\d+)/', $response, $matches)) {
            $stats['accepted_connections'] = (int)$matches[1];
            $stats['handled_connections'] = (int)$matches[2];
            $stats['requests_total'] = (int)$matches[3];
        }
    } else {
        throw new Exception("Ошибка stub_status (HTTP $http_code, URL: $effective_url): " . ($curl_error ?: 'Неверный формат ответа'));
    }

    // Извлечение последних данных из server_logs
    $stmt = $pdo->query("
        SELECT 
            response_time_avg,
            error_4xx_count,
            error_5xx_count,
            bandwidth_in / (1024 * 1024) as bandwidth_in,
            bandwidth_out / (1024 * 1024) as bandwidth_out,
            cache_hits,
            cache_misses,
            cpu_usage,
            memory_usage / (1024 * 1024) as memory_usage
        FROM server_logs
        ORDER BY timestamp DESC
        LIMIT 1
    ");
    $latest_log = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($latest_log) {
        $stats = array_merge($stats, $latest_log);
    }

    // Вычисление размера БД
    $stmt = $pdo->query("
        SELECT SUM(data_length + index_length) / (1024 * 1024) as db_size
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
    ");
    $db_size_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['db_size'] = $db_size_result ? number_format($db_size_result['db_size'], 2) : 0;

    // Извлечение исторических данных с учетом временного интервала
    $where_clause = '';
    if ($interval === 'custom' && $start_date && $end_date) {
        $where_clause = "WHERE timestamp BETWEEN :start_date AND DATE_ADD(:end_date, INTERVAL 1 DAY)";
    } else {
        $interval_map = [
            '1h' => '1 HOUR',
            '6h' => '6 HOUR',
            '12h' => '12 HOUR',
            '24h' => '24 HOUR',
            '7d' => '7 DAY',
            '30d' => '30 DAY'
        ];
        $interval_sql = isset($interval_map[$interval]) ? $interval_map[$interval] : '24 HOUR';
        $where_clause = "WHERE timestamp >= DATE_SUB(NOW(), INTERVAL $interval_sql)";
    }

    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(timestamp, '%Y-%m-%d %H:00') as date_hour,
            AVG(active_connections) as active_connections,
            AVG(accepted_connections) as accepted_connections,
            AVG(handled_connections) as handled_connections,
            SUM(requests_total) as requests_total,
            AVG(response_time_avg) as response_time_avg,
            SUM(error_4xx_count) as error_4xx_count,
            SUM(error_5xx_count) as error_5xx_count,
            SUM(bandwidth_in) / (1024 * 1024) as bandwidth_in,
            SUM(bandwidth_out) / (1024 * 1024) as bandwidth_out,
            SUM(cache_hits) as cache_hits,
            SUM(cache_misses) as cache_misses,
            AVG(cpu_usage) as cpu_usage,
            AVG(memory_usage) / (1024 * 1024) as memory_usage
        FROM server_logs
        $where_clause
        GROUP BY date_hour
        ORDER BY date_hour
    ");

    if ($interval === 'custom' && $start_date && $end_date) {
        $stmt->execute(['start_date' => $start_date . ' 00:00:00', 'end_date' => $end_date . ' 23:59:59']);
    } else {
        $stmt->execute();
    }
    $stats['history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Clear output buffer and send JSON
    ob_end_clean();
    echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>