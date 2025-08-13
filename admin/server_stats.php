<?php
session_start();
require __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Проверка авторизации
if (!isset($_SESSION['admin'])) {
    header('Location: login.php');
    exit;
}

// Инициализация переменных
$error = '';
$server_stats = [
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
    'db_size' => 0,
    'history' => [],
    'db_stats' => [
        'total_size' => 0,
        'table_sizes' => [],
        'table_counts' => [],
        'recent_activity' => []
    ]
];

// Обработка параметров временного интервала
$interval = isset($_GET['interval']) ? $_GET['interval'] : '24h';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-1 day'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

try {
    // Проверка соединения с базой данных
    $pdo->query("SELECT 1")->fetch();

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
            $server_stats['active_connections'] = (int)$matches[1];
        }
        if (preg_match('/(\d+)\s+(\d+)\s+(\d+)/', $response, $matches)) {
            $server_stats['accepted_connections'] = (int)$matches[1];
            $server_stats['handled_connections'] = (int)$matches[2];
            $server_stats['requests_total'] = (int)$matches[3];
        }
    } else {
        $error .= "<br>Ошибка stub_status (HTTP $http_code, URL: $effective_url): " . htmlspecialchars($curl_error ?: 'Неверный формат ответа');
    }

    // Извлечение последних данных для остальных метрик из server_logs (без cpu_usage, memory_usage)
    $stmt = $pdo->query("
        SELECT 
            response_time_avg,
            error_4xx_count,
            error_5xx_count,
            bandwidth_in / (1024 * 1024) as bandwidth_in,
            bandwidth_out / (1024 * 1024) as bandwidth_out,
            cache_hits,
            cache_misses
        FROM server_logs
        ORDER BY timestamp DESC
        LIMIT 1
    ");
    $latest_log = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($latest_log) {
        $server_stats = array_merge($server_stats, $latest_log);
    }

    // Вычисление размера БД
    $stmt = $pdo->query("
        SELECT SUM(data_length + index_length) / (1024 * 1024) as db_size
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
    ");
    $db_size_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $server_stats['db_size'] = $db_size_result ? number_format($db_size_result['db_size'], 2) : 0;

    // Извлечение размеров отдельных таблиц
    $stmt = $pdo->query("
        SELECT 
            table_name,
            ROUND((data_length + index_length) / (1024 * 1024), 2) as size_mb
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
        AND table_name IN ('server_logs', 'orders', 'products', 'services', 'users')
    ");
    $server_stats['db_stats']['table_sizes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Подсчет количества записей в таблицах
    $tables = ['users', 'orders', 'products', 'services', 'server_logs'];
    $table_counts = [];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$table`");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $table_counts[] = ['table_name' => $table, 'count' => (int)$result['count']];
    }
    $server_stats['db_stats']['table_counts'] = $table_counts;

    // Извлечение последних 5 записей из activity_log
    $stmt = $pdo->query("
        SELECT 
            type,
            description,
            ip_address,
            user_agent,
            created_at
        FROM activity_log
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $server_stats['db_stats']['recent_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    $server_stats['history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Ошибка базы данных: " . $e->getMessage();
} catch (Exception $e) {
    $error = "Ошибка: " . $e->getMessage();
}

// Форматирование текущего времени для начального отображения
$current_time = date('Y-m-d H:i:s');
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <title>Админ-панель | Статистика сервера</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="/../css/admin.css">
    <link rel="icon" href="https://pnevmatpro.ru/assets/favicon.png" type="image/png">
    <style>
        .admin-wrapper { display: flex; min-height: 100vh; overflow-y: auto; }
        .admin-nav { background-color: #2c3e50; color: white; padding: 20px; width: 250px; position: fixed; top: 0; left: 0; height: 100%; transform: translateX(-100%); transition: transform 0.3s ease-in-out; z-index: 1050; }
        .admin-nav.active { transform: translateX(0); }
        .close-menu { display: block; width: 100%; text-align: left; background: none; border: none; color: white; font-size: 1.5rem; margin-bottom: 1rem; }
        .mobile-menu-btn { z-index: 1100; position: fixed; top: 10px; left: 10px; background-color: #0d6efd; border: none; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; }
        .overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); z-index: 1000; opacity: 0; transition: opacity 0.3s; pointer-events: none; }
        .overlay.active { opacity: 1; pointer-events: all; }
        .admin-main { flex: 1; padding: 20px; margin-left: 0; padding-top: 60px; overflow-y: auto; }
        .card { height: 100%; min-height: 150px; display: flex; flex-direction: column; }
        .card-body { flex-grow: 1; display: flex; flex-direction: column; justify-content: center; padding: 1.5rem; }
        .card-title { font-size: 1.25rem; }
        .card-text { font-size: 1.5rem; font-weight: bold; }
        canvas { max-height: 200px; width: 100% !important; }
        .row.g-3 { align-items: stretch; }
        .card.graph-card { min-height: 250px; }
        .card { width: 100%; }
        .nav-tabs { margin-bottom: 20px; }
        .nav-tabs .nav-link { color: #000 !important; }
        .nav-tabs .nav-link.active { color: #000 !important; background-color: #f8f9fa; }
        .last-updated { font-size: 0.9rem; color: #6c757d; margin-bottom: 1rem; }
        .card-icon { font-size: 3rem; margin-bottom: 0.75rem; }
        .container-fluid { padding-left: 0; padding-right: 0; }
        .date-picker { width: 150px; display: inline-block; }
        .interval-select { width: 120px; display: inline-block; }
        .table-sm { font-size: 0.85rem; }
        @media (min-width: 768px) {
            .admin-nav { transform: translateX(0); position: relative; margin-left: 0; }
            .mobile-menu-btn { display: none; }
            .overlay { display: none; }
            .admin-main { padding-top: 0; }
        }
        @media (max-width: 767.98px) {
            .admin-main { width: 100%; }
            .card { min-height: 150px; }
            .card-icon { font-size: 2.5rem; }
            .card-title { font-size: 1.1rem; }
            .card-text { font-size: 1.3rem; }
            .date-picker { width: 120px; }
            .interval-select { width: 100px; }
        }
    </style>
</head>
<body class="admin-wrapper">
<div class="d-flex">
    <?php include '_sidebar.php'; ?>
    <main class="admin-main">
        <button class="btn btn-primary mobile-menu-btn d-md-none">
            <i class="bi bi-list"></i>
        </button>
        <div class="overlay d-md-none"></div>
        <div class="container-fluid">
            <div id="error_alert">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger mt-3"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
            </div>
            <div class="d-flex justify-content-between flex-wrap align-items-center my-3">
                <h2 class="mb-3 mb-md-0">Статистика сервера</h2>
                <div class="d-flex gap-3">
                    <a href="store_stats.php" class="btn btn-primary">
                        <i class="bi bi-shop me-1"></i> Статистика магазина
                    </a>
                    <a href="logout.php" class="btn btn-danger d-md-none">
                        <i class="bi bi-box-arrow-right"></i>
                    </a>
                </div>
            </div>
            <ul class="nav nav-tabs">
                <li class="nav-item">
                    <a class="nav-link active" id="current-tab" data-bs-toggle="tab" href="#current">Текущие данные</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="history-tab" data-bs-toggle="tab" href="#history">История</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="database-tab" data-bs-toggle="tab" href="#database">База данных</a>
                </li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="current">
                    <div class="last-updated" id="current-last-updated">Последнее обновление: <?= $current_time ?></div>
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-7 col-lg-4">
                            <div class="card">
                                <div class="card-body text-center">
                                    <i class="bi bi-diagram-3 text-info card-icon"></i>
                                    <h6 class="card-title">Акт. соединения</h6>
                                    <p class="card-text" id="active_connections"><?= round($server_stats['active_connections']) ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-7 col-lg-4">
                            <div class="card">
                                <div class="card-body text-center">
                                    <i class="bi bi-arrow-down-up text-primary card-icon"></i>
                                    <h6 class="card-title">Прин. соединения</h6>
                                    <p class="card-text" id="accepted_connections"><?= round($server_stats['accepted_connections']) ?></p>
                                </div>
            </div>
        </div>
        <div class="col-12 col-md-7 col-lg-4">
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-check-circle text-success card-icon"></i>
                    <h6 class="card-title">Обр. соединения</h6>
                    <p class="card-text" id="handled_connections"><?= round($server_stats['handled_connections']) ?></p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7 col-lg-4">
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-arrow-up-right text-warning card-icon"></i>
                    <h6 class="card-title">Запросы</h6>
                    <p class="card-text" id="requests_total"><?= round($server_stats['requests_total']) ?></p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7 col-lg-4">
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-clock text-info card-icon"></i>
                    <h6 class="card-title">Время ответа (мс)</h6>
                    <p class="card-text" id="response_time_avg"><?= number_format($server_stats['response_time_avg'] * 1000, 2) ?></p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7 col-lg-4">
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-exclamation-triangle text-danger card-icon"></i>
                    <h6 class="card-title">Ошибки 4xx</h6>
                    <p class="card-text" id="error_4xx_count"><?= round($server_stats['error_4xx_count']) ?></p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7 col-lg-4">
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-exclamation-triangle text-danger card-icon"></i>
                    <h6 class="card-title">Ошибки 5xx</h6>
                    <p class="card-text" id="error_5xx_count"><?= round($server_stats['error_5xx_count']) ?></p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7 col-lg-4">
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-download text-primary card-icon"></i>
                    <h6 class="card-title">Вход. трафик (МБ)</h6>
                    <p class="card-text" id="bandwidth_in"><?= number_format($server_stats['bandwidth_in'], 2) ?></p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7 col-lg-4">
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-upload text-primary card-icon"></i>
                    <h6 class="card-title">Исх. трафик (МБ)</h6>
                    <p class="card-text" id="bandwidth_out"><?= number_format($server_stats['bandwidth_out'], 2) ?></p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7 col-lg-4">
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-check-circle text-success card-icon"></i>
                    <h6 class="card-title">Кэш-попадания</h6>
                    <p class="card-text" id="cache_hits"><?= round($server_stats['cache_hits']) ?></p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7 col-lg-4">
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-x-circle text-danger card-icon"></i>
                    <h6 class="card-title">Кэш-промахи</h6>
                    <p class="card-text" id="cache_misses"><?= round($server_stats['cache_misses']) ?></p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7 col-lg-4">
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-database text-primary card-icon"></i>
                    <h6 class="card-title">Размер БД (МБ)</h6>
                    <p class="card-text" id="db_size"><?= number_format($server_stats['db_size'], 2) ?></p>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="tab-pane fade" id="history">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div class="last-updated" id="history-last-updated">Последнее обновление: <?= $current_time ?></div>
        <div class="d-flex gap-2 align-items-center">
            <select id="interval-select" class="form-select interval-select">
                <option value="1h" <?= $interval === '1h' ? 'selected' : '' ?>>1 час</option>
                <option value="6h" <?= $interval === '6h' ? 'selected' : '' ?>>6 часов</option>
                <option value="12h" <?= $interval === '12h' ? 'selected' : '' ?>>12 часов</option>
                <option value="24h" <?= $interval === '24h' ? 'selected' : '' ?>>24 часа</option>
                <option value="7d" <?= $interval === '7d' ? 'selected' : '' ?>>7 дней</option>
                <option value="30d" <?= $interval === '30d' ? 'selected' : '' ?>>30 дней</option>
                <option value="custom" <?= $interval === 'custom' ? 'selected' : '' ?>>Пользовательский</option>
            </select>
            <input type="text" id="date-range" class="form-control date-picker" placeholder="Выберите даты" value="<?= $interval === 'custom' ? htmlspecialchars("$start_date - $end_date") : '' ?>" style="display: <?= $interval === 'custom' ? 'inline-block' : 'none' ?>;">
            <button class="btn btn-primary" id="refresh-history">
                <i class="bi bi-arrow-repeat me-1"></i> Обновить
            </button>
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="card graph-card">
                <div class="card-body">
                    <h5 class="card-title">Графики</h5>
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <canvas id="connectionsChart"></canvas>
                        </div>
                        <div class="col-12 col-md-6">
                            <canvas id="requestsChart"></canvas>
                        </div>
                        <div class="col-12 col-md-6">
                            <canvas id="responseTimeChart"></canvas>
                        </div>
                        <div class="col-12 col-md-6">
                            <canvas id="errorsChart"></canvas>
                        </div>
                        <div class="col-12 col-md-6">
                            <canvas id="bandwidthChart"></canvas>
                        </div>
                        <div class="col-12 col-md-6">
                            <canvas id="cacheChart"></canvas>
                        </div>
                        <div class="col-12 col-md-6">
                            <canvas id="resourceChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="tab-pane fade" id="database">
    <div class="last-updated" id="database-last-updated">Последнее обновление: <?= $current_time ?></div>
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-7 col-lg-4">
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-database text-primary card-icon"></i>
                    <h6 class="card-title">Общий размер БД (МБ)</h6>
                    <p class="card-text" id="db_total_size"><?= number_format($server_stats['db_size'], 2) ?></p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7 col-lg-4">
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-table text-info card-icon"></i>
                    <h6 class="card-title">Размер server_logs (МБ)</h6>
                    <p class="card-text" id="db_table_server_logs">
                        <?= number_format(
                            array_column($server_stats['db_stats']['table_sizes'], 'size_mb', 'table_name')['server_logs'] ?? 0,
                            2
                        ) ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7 col-lg-4">
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-table text-info card-icon"></i>
                    <h6 class="card-title">Размер orders (МБ)</h6>
                    <p class="card-text" id="db_table_orders">
                        <?= number_format(
                            array_column($server_stats['db_stats']['table_sizes'], 'size_mb', 'table_name')['orders'] ?? 0,
                            2
                        ) ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7 col-lg-4">
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-table text-info card-icon"></i>
                    <h6 class="card-title">Размер products (МБ)</h6>
                    <p class="card-text" id="db_table_products">
                        <?= number_format(
                            array_column($server_stats['db_stats']['table_sizes'], 'size_mb', 'table_name')['products'] ?? 0,
                            2
                        ) ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7 col-lg-4">
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-table text-info card-icon"></i>
                    <h6 class="card-title">Размер services (МБ)</h6>
                    <p class="card-text" id="db_table_services">
                        <?= number_format(
                            array_column($server_stats['db_stats']['table_sizes'], 'size_mb', 'table_name')['services'] ?? 0,
                            2
                        ) ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7 col-lg-4">
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-table text-info card-icon"></i>
                    <h6 class="card-title">Размер users (МБ)</h6>
                    <p class="card-text" id="db_table_users">
                        <?= number_format(
                            array_column($server_stats['db_stats']['table_sizes'], 'size_mb', 'table_name')['users'] ?? 0,
                            2
                        ) ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7 col-lg-4">
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-list-ul text-success card-icon"></i>
                    <h6 class="card-title">Записей в users</h6>
                    <p class="card-text" id="db_count_users">
                        <?= array_column($server_stats['db_stats']['table_counts'], 'count', 'table_name')['users'] ?? 0 ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7 col-lg-4">
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-list-ul text-success card-icon"></i>
                    <h6 class="card-title">Записей в orders</h6>
                    <p class="card-text" id="db_count_orders">
                        <?= array_column($server_stats['db_stats']['table_counts'], 'count', 'table_name')['orders'] ?? 0 ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7 col-lg-4">
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-list-ul text-success card-icon"></i>
                    <h6 class="card-title">Записей в server_logs</h6>
                    <p class="card-text" id="db_count_server_logs">
                        <?= array_column($server_stats['db_stats']['table_counts'], 'count', 'table_name')['server_logs'] ?? 0 ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Последние действия (activity_log)</h5>
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Тип</th>
                                <th>Описание</th>
                                <th>IP-адрес</th>
                                <th>Дата</th>
                            </tr>
                        </thead>
                        <tbody id="recent-activity-table">
                            <?php foreach ($server_stats['db_stats']['recent_activity'] as $activity): ?>
                                <tr>
                                    <td><?= htmlspecialchars($activity['type']) ?></td>
                                    <td><?= htmlspecialchars($activity['description']) ?></td>
                                    <td><?= htmlspecialchars($activity['ip_address'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($activity['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
</div>
</main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    let chartInstances = {
        connections: null,
        requests: null,
        responseTime: null,
        errors: null,
        bandwidth: null,
        cache: null,
        resource: null
    };
    let isFetchingCurrent = false;
    let isFetchingHistory = false;
    let isFetchingDatabase = false;
    let lastUpdatedCurrent = new Date('<?= $current_time ?>');
    let lastUpdatedHistory = new Date('<?= $current_time ?>');
    let lastUpdatedDatabase = new Date('<?= $current_time ?>');

    // Инициализация flatpickr
    const datePicker = flatpickr('#date-range', {
        mode: 'range',
        dateFormat: 'Y-m-d',
        defaultDate: ['<?= htmlspecialchars($start_date) ?>', '<?= htmlspecialchars($end_date) ?>'],
        onChange: function(selectedDates) {
            if (selectedDates.length === 2) {
                document.getElementById('interval-select').value = 'custom';
                console.log('Custom date range selected:', selectedDates);
                fetchStats('history');
            }
        }
    });

    // Обработка изменения интервала
    document.getElementById('interval-select').addEventListener('change', function() {
        const interval = this.value;
        console.log('Interval changed to:', interval);
        document.getElementById('date-range').style.display = interval === 'custom' ? 'inline-block' : 'none';
        if (interval !== 'custom') {
            fetchStats('history');
        }
    });

    function formatDateTime(date) {
        return date.toISOString().replace('T', ' ').split('.')[0];
    }

    function updateCharts(data) {
        if (!data || !data.history) {
            console.error('Invalid data for charts:', data);
            return;
        }

        console.log('Updating charts with history data:', data.history);

        const labels = data.history.map(row => row.date_hour || 'N/A');

        // Connections Chart
        const connectionsData = {
            labels: labels,
            datasets: [
                {
                    label: 'Активные соединения',
                    data: data.history.map(row => row.active_connections || 0),
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.2)',
                    fill: true
                },
                {
                    label: 'Принятые соединения',
                    data: data.history.map(row => row.accepted_connections || 0),
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.2)',
                    fill: true
                },
                {
                    label: 'Обработанные соединения',
                    data: data.history.map(row => row.handled_connections || 0),
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.2)',
                    fill: true
                }
            ]
        };

        // Requests Chart
        const requestsData = {
            labels: labels,
            datasets: [
                {
                    label: 'Общее количество запросов',
                    data: data.history.map(row => row.requests_total || 0),
                    borderColor: '#ffc107',
                    backgroundColor: 'rgba(255, 193, 7, 0.2)',
                    fill: true
                }
            ]
        };

        // Response Time Chart
        const responseTimeData = {
            labels: labels,
            datasets: [
                {
                    label: 'Время ответа (мс)',
                    data: data.history.map(row => (row.response_time_avg * 1000) || 0),
                    borderColor: '#17a2b8',
                    backgroundColor: 'rgba(23, 162, 184, 0.2)',
                    fill: true,
                    yAxisID: 'y'
                }
            ]
        };

        // Errors Chart
        const errorsData = {
            labels: labels,
            datasets: [
                {
                    label: 'Ошибки 4xx',
                    data: data.history.map(row => row.error_4xx_count || 0),
                    borderColor: '#fd7e14',
                    backgroundColor: 'rgba(253, 126, 20, 0.2)',
                    fill: true
                },
                {
                    label: 'Ошибки 5xx',
                    data: data.history.map(row => row.error_5xx_count || 0),
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.2)',
                    fill: true
                }
            ]
        };

        // Bandwidth Chart
        const bandwidthData = {
            labels: labels,
            datasets: [
                {
                    label: 'Входящий трафик (МБ)',
                    data: data.history.map(row => row.bandwidth_in || 0),
                    borderColor: '#6f42c1',
                    backgroundColor: 'rgba(111, 66, 193, 0.2)',
                    fill: true
                },
                {
                    label: 'Исходящий трафик (МБ)',
                    data: data.history.map(row => row.bandwidth_out || 0),
                    borderColor: '#20c997',
                    backgroundColor: 'rgba(32, 201, 151, 0.2)',
                    fill: true
                }
            ]
        };

        // Cache Chart
        const cacheData = {
            labels: labels,
            datasets: [
                {
                    label: 'Кэш-попадания',
                    data: data.history.map(row => row.cache_hits || 0),
                    borderColor: '#007bff',
                    backgroundColor: 'rgba(0, 123, 255, 0.2)',
                    fill: true
                },
                {
                    label: 'Кэш-промахи',
                    data: data.history.map(row => row.cache_misses || 0),
                    borderColor: '#ff073a',
                    backgroundColor: 'rgba(255, 7, 58, 0.2)',
                    fill: true
                }
            ]
        };

        // Resource Usage Chart
        const resourceData = {
            labels: labels,
            datasets: [
                {
                    label: 'CPU (%)',
                    data: data.history.map(row => row.cpu_usage || 0),
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.2)',
                    fill: true,
                    yAxisID: 'y'
                },
                {
                    label: 'Память (МБ)',
                    data: data.history.map(row => row.memory_usage || 0),
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.2)',
                    fill: true,
                    yAxisID: 'y1'
                }
            ]
        };

        // Update or create charts
        const chartConfigs = [
            { id: 'connectionsChart', data: connectionsData, options: { scales: { y: { beginAtZero: true, title: { display: true, text: 'Соединения' } } } } },
            { id: 'requestsChart', data: requestsData, options: { scales: { y: { beginAtZero: true, title: { display: true, text: 'Запросы' } } } } },
            { id: 'responseTimeChart', data: responseTimeData, options: { scales: { y: { beginAtZero: true, title: { display: true, text: 'Время ответа (мс)' } } } } },
            { id: 'errorsChart', data: errorsData, options: { scales: { y: { beginAtZero: true, title: { display: true, text: 'Ошибки' } } } } },
            { id: 'bandwidthChart', data: bandwidthData, options: { scales: { y: { beginAtZero: true, title: { display: true, text: 'Трафик (МБ)' } } } } },
            { id: 'cacheChart', data: cacheData, options: { scales: { y: { beginAtZero: true, title: { display: true, text: 'Кэш' } } } } },
            { id: 'resourceChart', data: resourceData, options: {
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: 'CPU (%)' } },
                    y1: { beginAtZero: true, position: 'right', title: { display: true, text: 'Память (МБ)' }, grid: { drawOnChartArea: false } }
                }
            } }
        ];

        chartConfigs.forEach(config => {
            if (chartInstances[config.id.replace('Chart', '')]) {
                chartInstances[config.id.replace('Chart', '')].destroy();
            }
            chartInstances[config.id.replace('Chart', '')] = new Chart(document.getElementById(config.id), {
                type: 'line',
                data: config.data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'top' } },
                    scales: {
                        x: { title: { display: true, text: 'Дата и час' } },
                        ...config.options.scales
                    }
                }
            });
        });

        lastUpdatedHistory = new Date();
        document.getElementById('history-last-updated').textContent = `Последнее обновление: ${formatDateTime(lastUpdatedHistory)}`;
    }

    function updateStats(data) {
        if (!data) {
            console.error('No data received for current stats');
            return;
        }

        console.log('Updating current stats:', data);

        // Обновление dashboard (без cpu_usage и memory_usage)
        document.getElementById('active_connections').textContent = Math.round(data.active_connections || 0);
        document.getElementById('accepted_connections').textContent = Math.round(data.accepted_connections || 0);
        document.getElementById('handled_connections').textContent = Math.round(data.handled_connections || 0);
        document.getElementById('requests_total').textContent = Math.round(data.requests_total || 0);
        document.getElementById('response_time_avg').textContent = Number((data.response_time_avg || 0) * 1000).toFixed(2);
        document.getElementById('error_4xx_count').textContent = Math.round(data.error_4xx_count || 0);
        document.getElementById('error_5xx_count').textContent = Math.round(data.error_5xx_count || 0);
        document.getElementById('bandwidth_in').textContent = Number(data.bandwidth_in || 0).toFixed(2);
        document.getElementById('bandwidth_out').textContent = Number(data.bandwidth_out || 0).toFixed(2);
        document.getElementById('cache_hits').textContent = Math.round(data.cache_hits || 0);
        document.getElementById('cache_misses').textContent = Math.round(data.cache_misses || 0);
        document.getElementById('db_size').textContent = Number(data.db_size || 0).toFixed(2);

        lastUpdatedCurrent = new Date();
        document.getElementById('current-last-updated').textContent = `Последнее обновление: ${formatDateTime(lastUpdatedCurrent)}`;
    }

    function updateDatabaseStats(data) {
        if (!data || !data.db_stats) {
            console.error('No database stats received:', data);
            return;
        }

        console.log('Updating database stats:', data.db_stats);

        // Update database size
        document.getElementById('db_total_size').textContent = Number(data.db_size || 0).toFixed(2);

        // Update table sizes
        const tableSizes = Object.fromEntries(data.db_stats.table_sizes.map(item => [item.table_name, item.size_mb]));
        document.getElementById('db_table_server_logs').textContent = Number(tableSizes['server_logs'] || 0).toFixed(2);
        document.getElementById('db_table_orders').textContent = Number(tableSizes['orders'] || 0).toFixed(2);
        document.getElementById('db_table_products').textContent = Number(tableSizes['products'] || 0).toFixed(2);
        document.getElementById('db_table_services').textContent = Number(tableSizes['services'] || 0).toFixed(2);
        document.getElementById('db_table_users').textContent = Number(tableSizes['users'] || 0).toFixed(2);

        // Update table counts
        const tableCounts = Object.fromEntries(data.db_stats.table_counts.map(item => [item.table_name, item.count]));
        document.getElementById('db_count_users').textContent = tableCounts['users'] || 0;
        document.getElementById('db_count_orders').textContent = tableCounts['orders'] || 0;
        document.getElementById('db_count_server_logs').textContent = tableCounts['server_logs'] || 0;

        // Update recent activity table
        const activityTable = document.getElementById('recent-activity-table');
        activityTable.innerHTML = '';
        data.db_stats.recent_activity.forEach(activity => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${activity.type || 'N/A'}</td>
                <td>${activity.description || 'N/A'}</td>
                <td>${activity.ip_address || 'N/A'}</td>
                <td>${activity.created_at || 'N/A'}</td>
            `;
            activityTable.appendChild(row);
        });

        lastUpdatedDatabase = new Date();
        document.getElementById('database-last-updated').textContent = `Последнее обновление: ${formatDateTime(lastUpdatedDatabase)}`;
    }

    function fetchStats(tab) {
        if (tab === 'current' && isFetchingCurrent) {
            console.log('Предыдущий запрос для текущих данных ещё выполняется, пропускаем...');
            return;
        }
        if (tab === 'history' && isFetchingHistory) {
            console.log('Предыдущий запрос для истории ещё выполняется, пропускаем...');
            return;
        }
        if (tab === 'database' && isFetchingDatabase) {
            console.log('Предыдущий запрос для базы данных ещё выполняется, пропускаем...');
            return;
        }

        if (tab === 'current') isFetchingCurrent = true;
        if (tab === 'history') isFetchingHistory = true;
        if (tab === 'database') isFetchingDatabase = true;

        const interval = document.getElementById('interval-select').value;
        let url = 'fetch_stats.php?interval=' + encodeURIComponent(interval);
        if (interval === 'custom') {
            const dates = document.getElementById('date-range').value.split(' to ');
            if (dates.length === 2) {
                url += '&start_date=' + encodeURIComponent(dates[0]) + '&end_date=' + encodeURIComponent(dates[1]);
            }
        }

        console.log('Fetching stats for', tab, 'with URL:', url);

        fetch(url, {
            method: 'GET',
            headers: {
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! Status: ${response.status}`);
                }
                // Log the raw response for debugging
                return response.text().then(text => {
                    try {
                        const data = JSON.parse(text);
                        return data;
                    } catch (e) {
                        console.error('Invalid JSON response:', text);
                        throw new Error(`Invalid JSON: ${e.message}`);
                    }
                });
            })
            .then(data => {
                if (data.error) {
                    console.error('Server error:', data.error);
                    document.getElementById('error_alert').innerHTML = `<div class="alert alert-danger mt-3">${data.error}</div>`;
                } else {
                    document.getElementById('error_alert').innerHTML = '';
                    if (tab === 'history') {
                        updateCharts(data);
                    } else if (tab === 'database') {
                        updateDatabaseStats(data);
                    } else {
                        updateStats(data);
                    }
                }
            })
            .catch(error => {
                console.error('Fetch error:', error.message);
                document.getElementById('error_alert').innerHTML = `<div class="alert alert-danger mt-3">Ошибка загрузки данных: ${error.message}</div>`;
            })
            .finally(() => {
                if (tab === 'current') isFetchingCurrent = false;
                if (tab === 'history') isFetchingHistory = false;
                if (tab === 'database') isFetchingDatabase = false;
            });
    }

    // Initial chart render for history tab
    if (document.querySelector('#history-tab').classList.contains('active')) {
        fetchStats('history');
    }

    // Initial database stats render
    if (document.querySelector('#database-tab').classList.contains('active')) {
        fetchStats('database');
    }

    // Poll every 10 seconds for current data
    setInterval(() => fetchStats('current'), 10000);

    // Manual refresh for history tab
    document.getElementById('refresh-history').addEventListener('click', () => {
        console.log('Refresh button clicked');
        fetchStats('history');
    });

    // Обновление данных при переключении вкладки
    document.querySelectorAll('.nav-link').forEach(tab => {
        tab.addEventListener('shown.bs.tab', function (event) {
            if (event.target.id === 'history-tab') {
                console.log('History tab shown');
                fetchStats('history');
            } else if (event.target.id === 'database-tab') {
                console.log('Database tab shown');
                fetchStats('database');
            }
        });
    });

    // Menu toggle functionality
    const menuBtn = document.querySelector('.mobile-menu-btn');
    const closeBtn = document.querySelector('.close-menu');
    const sidebar = document.querySelector('.admin-nav');
    const overlay = document.querySelector('.overlay');

    function toggleMenu(event) {
        event.preventDefault();
        const isOpening = !sidebar.classList.contains('active');

        if (isOpening) {
            document.body.classList.add('menu-open');
            overlay.style.display = 'block';
            setTimeout(() => {
                overlay.classList.add('active');
                sidebar.classList.add('active');
            }, 10);
        } else {
            document.body.classList.remove('menu-open');
            overlay.classList.remove('active');
            sidebar.classList.remove('active');
            setTimeout(() => {
                if (!sidebar.classList.contains('active')) {
                    overlay.style.display = 'none';
                }
            }, 300);
        }
        event.stopPropagation();
    }

    if (menuBtn) menuBtn.addEventListener('click', toggleMenu);
    if (closeBtn) closeBtn.addEventListener('click', toggleMenu);
    if (overlay) overlay.addEventListener('click', toggleMenu);

    window.addEventListener('resize', () => {
        if (window.innerWidth >= 768) {
            document.body.classList.remove('menu-open');
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
            overlay.style.display = 'none';
        }
    });

    if (window.innerWidth >= 768) {
        document.body.classList.remove('menu-open');
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
        overlay.style.display = 'none';
    }
});
</script>
</body>
</html>