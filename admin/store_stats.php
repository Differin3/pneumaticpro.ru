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
$stats = [
    'total_orders' => 0,
    'total_revenue' => 0,
    'avg_order_value' => 0,
    'completion_rate' => 0,
    'top_products' => [],
    'top_services' => [], // Добавлено для услуг
    'order_statuses' => [],
    'monthly_revenue' => [],
    'customer_stats' => [
        'unique_customers' => 0,
        'repeat_customers' => 0,
        'repeat_percentage' => 0,
    ],
    'delivery_services' => [],
    'peak_hours' => [],
    'recent_activity' => [],
    'top_cities' => [],
];

// Обработка параметров временного интервала для вкладки "История"
$interval = isset($_GET['interval']) ? $_GET['interval'] : '24h';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-1 day'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

try {
    // Текущие данные
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM orders");
    $stats['total_orders'] = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) as completed FROM orders WHERE status IN ('completed', 'ready_for_pickup')");
    $completed_orders = $stmt->fetchColumn();
    $stats['completion_rate'] = $stats['total_orders'] > 0 ? round(($completed_orders / $stats['total_orders']) * 100, 1) : 0;

    $stmt = $pdo->query("
        SELECT 
            SUM(oi.price * oi.quantity) as total,
            AVG(oi.price * oi.quantity) as avg_order
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        WHERE o.status IN ('completed', 'ready_for_pickup')
    ");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_revenue'] = $result['total'] ?? 0;
    $stats['avg_order_value'] = $result['avg_order'] ?? 0;

    $stmt = $pdo->query("
        SELECT 
            p.name,
            p.vendor_code,
            p.price,
            COUNT(oi.id) as order_count
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        GROUP BY p.id, p.name, p.vendor_code, p.price
        ORDER BY order_count DESC
        LIMIT 5
    ");
    $stats['top_products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Новый запрос для получения топ 5 услуг
    $stmt = $pdo->query("
        SELECT 
            s.name,
            s.vendor_code,
            s.price,
            COUNT(oi.id) as order_count
        FROM order_items oi
        JOIN services s ON oi.service_id = s.id
        GROUP BY s.id, s.name, s.vendor_code, s.price
        ORDER BY order_count DESC
        LIMIT 5
    ");
    $stats['top_services'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT status, COUNT(*) as count
        FROM orders
        GROUP BY status
    ");
    $stats['order_statuses'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT 
            DATE_FORMAT(o.created_at, '%Y-%m') as month,
            SUM(oi.price * oi.quantity) as revenue
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        WHERE o.status IN ('completed', 'ready_for_pickup')
        AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY month
        ORDER BY month
    ");
    $stats['monthly_revenue'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT 
            COUNT(DISTINCT customer_id) as unique_customers,
            COUNT(DISTINCT CASE WHEN order_count > 1 THEN customer_id END) as repeat_customers
        FROM (
            SELECT customer_id, COUNT(*) as order_count
            FROM orders
            GROUP BY customer_id
        ) sub
    ");
    $customer_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['customer_stats']['unique_customers'] = $customer_data['unique_customers'] ?? 0;
    $stats['customer_stats']['repeat_customers'] = $customer_data['repeat_customers'] ?? 0;
    $stats['customer_stats']['repeat_percentage'] = $stats['customer_stats']['unique_customers'] > 0 
        ? round(($stats['customer_stats']['repeat_customers'] / $stats['customer_stats']['unique_customers']) * 100, 1) 
        : 0;

    $stmt = $pdo->query("
        SELECT 
            IFNULL(delivery_service, 'Не выбрано') as delivery_service,
            COUNT(*) as count
        FROM orders
        GROUP BY delivery_service
    ");
    $stats['delivery_services'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT 
            HOUR(created_at) as hour,
            COUNT(*) as count
        FROM orders
        GROUP BY hour
        ORDER BY hour
    ");
    $stats['peak_hours'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT 
            DATE(created_at) as day,
            COUNT(*) as count
        FROM orders
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY day
        ORDER BY day
    ");
    $stats['recent_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT 
            IFNULL(o.pickup_city, 'Не указан') as city,
            COUNT(*) as order_count
        FROM orders o
        GROUP BY o.pickup_city
        ORDER BY order_count DESC
        LIMIT 5
    ");
    $stats['top_cities'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Исторические данные
    $where_clause = '';
    if ($interval === 'custom' && $start_date && $end_date) {
        $where_clause = "WHERE o.created_at BETWEEN :start_date AND DATE_ADD(:end_date, INTERVAL 1 DAY)";
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
        $where_clause = "WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL $interval_sql)";
    }

    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(o.created_at, '%Y-%m-%d %H:00') as date_hour,
            COUNT(o.id) as total_orders,
            SUM(oi.price * oi.quantity) as total_revenue,
            AVG(oi.price * oi.quantity) as avg_order_value,
            (SUM(CASE WHEN o.status = 'completed' THEN 1 ELSE 0 END) / COUNT(o.id) * 100) as completion_rate
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
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

} catch (PDOException $e) {
    $error = "Ошибка базы данных: " . $e->getMessage();
} catch (Exception $e) {
    $error = "Ошибка: " . $e->getMessage();
}

// Форматирование текущего времени
$current_time = date('Y-m-d H:i:s');
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <title>Админ-панель | Статистика магазина</title>
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
        .card { height: 100%; min-height: 220px; display: flex; flex-direction: column; }
        .card-body { flex-grow: 1; display: flex; flex-direction: column; justify-content: center; }
        .table-responsive { max-height: 220px; overflow-y: auto; min-width: 300px; }
        .table th, .table td { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 150px; }
        canvas { max-height: 260px; width: 100% !important; }
        .row.g-3 { align-items: stretch; }
        .card.graph-card { min-height: 300px; }
        .card { width: 100%; }
        .nav-tabs { margin-bottom: 20px; }
        .nav-tabs .nav-link { color: #000 !important; }
        .nav-tabs .nav-link.active { color: #000 !important; background-color: #f8f9fa; }
        .last-updated { font-size: 0.9rem; color: #6c757d; margin-bottom: 1rem; }
        .date-picker { width: 150px; display: inline-block; }
        .interval-select { width: 120px; display: inline-block; }
        @media (min-width: 768px) {
            .admin-nav { transform: translateX(0); position: relative; margin-left: 0; }
            .mobile-menu-btn { display: none; }
            .overlay { display: none; }
            .admin-main { padding-top: 0; }
        }
        @media (max-width: 767.98px) {
            .admin-main { width: 100%; }
            .card { min-height: auto; }
            .table th, .table td { max-width: 120px; }
            .date-picker { width: 120px; }
            .interval-select { width: 100px; }
        }
        .badge-ready {
            background-color: #ff8c00;
            color: #fff;
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
                <h2 class="mb-3 mb-md-0">Статистика магазина</h2>
                <div class="d-flex gap-3">
                    <a href="logout.php" class="btn btn-danger d-md-none">
                        <i class="bi bi-box-arrow-right"></i>
                    </a>
                    <a href="server_stats.php" class="btn btn-primary">
                        <i class="bi bi-server me-2"></i>Статистика сервера
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
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="current">
                    <div class="last-updated" id="current-last-updated">Последнее обновление: <?= $current_time ?></div>
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="card">
                                <div class="card-body text-center">
                                    <i class="bi bi-receipt display-4 text-primary mb-3"></i>
                                    <h5 class="card-title">Всего заказов</h5>
                                    <p class="card-text display-6" id="total_orders"><?= $stats['total_orders'] ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="card">
                                <div class="card-body text-center">
                                    <i class="bi bi-cash-coin display-4 text-success mb-3"></i>
                                    <h5 class="card-title">Общая выручка</h5>
                                    <p class="card-text display-6" id="total_revenue"><?= number_format($stats['total_revenue'], 2, '.', ' ') ?> ₽</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="card">
                                <div class="card-body text-center">
                                    <i class="bi bi-cart display-4 text-warning mb-3"></i>
                                    <h5 class="card-title">Средняя стоимость заказа</h5>
                                    <p class="card-text display-6" id="avg_order_value"><?= number_format($stats['avg_order_value'], 2, '.', ' ') ?> ₽</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="card">
                                <div class="card-body text-center">
                                    <i class="bi bi-check-circle display-4 text-info mb-3"></i>
                                    <h5 class="card-title">Процент завершенных</h5>
                                    <p class="card-text display-6" id="completion_rate"><?= $stats['completion_rate'] ?>%</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-8">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">Статистика клиентов</h5>
                                    <div class="row text-center">
                                        <div class="col-4">
                                            <i class="bi bi-people display-4 text-primary mb-3"></i>
                                            <h6>Уникальные клиенты</h6>
                                            <p class="display-6" id="unique_customers"><?= $stats['customer_stats']['unique_customers'] ?></p>
                                        </div>
                                        <div class="col-4">
                                            <i class="bi bi-person-check display-4 text-success mb-3"></i>
                                            <h6>Повторные клиенты</h6>
                                            <p class="display-6" id="repeat_customers"><?= $stats['customer_stats']['repeat_customers'] ?></p>
                                        </div>
                                        <div class="col-4">
                                            <i class="bi bi-percent display-4 text-info mb-3"></i>
                                            <h6>Доля повторных</h6>
                                            <p class="display-6" id="repeat_percentage"><?= $stats['customer_stats']['repeat_percentage'] ?>%</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <div class="card graph-card">
                                <div class="card-body">
                                    <h5 class="card-title">Топ 5 заказываемых продуктов</h5>
                                    <div class="row">
                                        <div class="col-12 col-md-6">
                                            <div class="table-responsive">
                                                <table class="table table-hover mb-0">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Название</th>
                                                            <th>Артикул</th>
                                                            <th>Цена</th>
                                                            <th>Заказано</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="top-products-table">
                                                        <?php foreach ($stats['top_products'] as $product): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($product['name']) ?></td>
                                                            <td><?= htmlspecialchars($product['vendor_code'] ?? 'N/A') ?></td>
                                                            <td><?= number_format($product['price'], 2, '.', ' ') ?> ₽</td>
                                                            <td><?= $product['order_count'] ?> раз</td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                        <?php if (empty($stats['top_products'])): ?>
                                                        <tr>
                                                            <td colspan="4" class="text-center text-muted">Нет данных</td>
                                                        </tr>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <canvas id="topProductsChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Новый блок: Топ 5 заказываемых услуг -->
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <div class="card graph-card">
                                <div class="card-body">
                                    <h5 class="card-title">Топ 5 заказываемых услуг</h5>
                                    <div class="row">
                                        <div class="col-12 col-md-6">
                                            <div class="table-responsive">
                                                <table class="table table-hover mb-0">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Название</th>
                                                            <th>Артикул</th>
                                                            <th>Цена</th>
                                                            <th>Заказано</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="top-services-table">
                                                        <?php foreach ($stats['top_services'] as $service): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($service['name']) ?></td>
                                                            <td><?= htmlspecialchars($service['vendor_code'] ?? 'N/A') ?></td>
                                                            <td><?= number_format($service['price'], 2, '.', ' ') ?> ₽</td>
                                                            <td><?= $service['order_count'] ?> раз</td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                        <?php if (empty($stats['top_services'])): ?>
                                                        <tr>
                                                            <td colspan="4" class="text-center text-muted">Нет данных</td>
                                                        </tr>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <canvas id="topServicesChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <div class="card graph-card">
                                <div class="card-body">
                                    <h5 class="card-title">Топ 5 городов по заказам</h5>
                                    <div class="row">
                                        <div class="col-12 col-md-6">
                                            <div class="table-responsive">
                                                <table class="table table-hover mb-0">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Город</th>
                                                            <th>Заказы</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="top-cities-table">
                                                        <?php foreach ($stats['top_cities'] as $city): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($city['city']) ?></td>
                                                            <td><?= $city['order_count'] ?></td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                        <?php if (empty($stats['top_cities'])): ?>
                                                        <tr>
                                                            <td colspan="2" class="text-center text-muted">Нет данных</td>
                                                        </tr>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <canvas id="cityChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-12 col-md-6 col-lg-6">
                            <div class="card graph-card">
                                <div class="card-body">
                                    <h5 class="card-title">Статусы заказов</h5>
                                    <canvas id="statusChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-6">
                            <div class="card graph-card">
                                <div class="card-body">
                                    <h5 class="card-title">Службы доставки</h5>
                                    <canvas id="deliveryChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-6">
                            <div class="card graph-card">
                                <div class="card-body">
                                    <h5 class="card-title">Выручка по месяцам</h5>
                                    <canvas id="revenueChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-6">
                            <div class="card graph-card">
                                <div class="card-body">
                                    <h5 class="card-title">Пиковые часы заказов</h5>
                                    <canvas id="peakHoursChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-6">
                            <div class="card graph-card">
                                <div class="card-body">
                                    <h5 class="card-title">Активность за последние 7 дней</h5>
                                    <canvas id="recentActivityChart"></canvas>
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
                                            <canvas id="ordersChart"></canvas>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <canvas id="revenueHistoryChart"></canvas>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <canvas id="avgOrderValueChart"></canvas>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <canvas id="completionRateChart"></canvas>
                                        </div>
                                    </div>
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
        status: null,
        delivery: null,
        revenue: null,
        peakHours: null,
        recentActivity: null,
        topProducts: null,
        topServices: null, // Добавлено для услуг
        city: null,
        orders: null,
        revenueHistory: null,
        avgOrderValue: null,
        completionRate: null
    };
    let isFetchingCurrent = false;
    let isFetchingHistory = false;
    let lastUpdatedCurrent = new Date('<?= $current_time ?>');
    let lastUpdatedHistory = new Date('<?= $current_time ?>');

    // Инициализация flatpickr
    const datePicker = flatpickr('#date-range', {
        mode: 'range',
        dateFormat: 'Y-m-d',
        defaultDate: ['<?= htmlspecialchars($start_date) ?>', '<?= htmlspecialchars($end_date) ?>'],
        onChange: function(selectedDates) {
            if (selectedDates.length === 2) {
                document.getElementById('interval-select').value = 'custom';
                fetchStats('history');
            }
        }
    });

    // Обработка изменения интервала
    document.getElementById('interval-select').addEventListener('change', function() {
        const interval = this.value;
        document.getElementById('date-range').style.display = interval === 'custom' ? 'inline-block' : 'none';
        if (interval !== 'custom') {
            fetchStats('history');
        }
    });

    function formatDateTime(date) {
        return date.toISOString().replace('T', ' ').split('.')[0];
    }

    function updateCurrentStats(data) {
        if (!data) {
            console.error('No data received for current stats');
            return;
        }

        // Обновление текущих данных
        document.getElementById('total_orders').textContent = Math.round(data.total_orders || 0);
        document.getElementById('total_revenue').textContent = new Intl.NumberFormat('ru-RU').format(Number(data.total_revenue || 0).toFixed(2)) + ' ₽';
        document.getElementById('avg_order_value').textContent = new Intl.NumberFormat('ru-RU').format(Number(data.avg_order_value || 0).toFixed(2)) + ' ₽';
        document.getElementById('completion_rate').textContent = Number(data.completion_rate || 0).toFixed(1) + '%';
        document.getElementById('unique_customers').textContent = Math.round(data.customer_stats?.unique_customers || 0);
        document.getElementById('repeat_customers').textContent = Math.round(data.customer_stats?.repeat_customers || 0);
        document.getElementById('repeat_percentage').textContent = Number(data.customer_stats?.repeat_percentage || 0).toFixed(1) + '%';

        // Обновление таблицы топ-продуктов
        const topProductsTable = document.getElementById('top-products-table');
        topProductsTable.innerHTML = '';
        if (data.top_products && data.top_products.length > 0) {
            data.top_products.forEach(product => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${product.name || 'N/A'}</td>
                    <td>${product.vendor_code || 'N/A'}</td>
                    <td>${new Intl.NumberFormat('ru-RU').format(Number(product.price || 0).toFixed(2))} ₽</td>
                    <td>${product.order_count || 0} раз</td>
                `;
                topProductsTable.appendChild(row);
            });
        } else {
            topProductsTable.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Нет данных</td></tr>';
        }

        // Обновление таблицы топ-услуг
        const topServicesTable = document.getElementById('top-services-table');
        topServicesTable.innerHTML = '';
        if (data.top_services && data.top_services.length > 0) {
            data.top_services.forEach(service => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${service.name || 'N/A'}</td>
                    <td>${service.vendor_code || 'N/A'}</td>
                    <td>${new Intl.NumberFormat('ru-RU').format(Number(service.price || 0).toFixed(2))} ₽</td>
                    <td>${service.order_count || 0} раз</td>
                `;
                topServicesTable.appendChild(row);
            });
        } else {
            topServicesTable.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Нет данных</td></tr>';
        }

        // Обновление таблицы топ-городов
        const topCitiesTable = document.getElementById('top-cities-table');
        topCitiesTable.innerHTML = '';
        if (data.top_cities && data.top_cities.length > 0) {
            data.top_cities.forEach(city => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${city.city || 'N/A'}</td>
                    <td>${city.order_count || 0}</td>
                `;
                topCitiesTable.appendChild(row);
            });
        } else {
            topCitiesTable.innerHTML = '<tr><td colspan="2" class="text-center text-muted">Нет данных</td></tr>';
        }

        // Обновление графиков текущих данных
        if (chartInstances.status) chartInstances.status.destroy();
        chartInstances.status = new Chart(document.getElementById('statusChart'), {
            type: 'pie',
            data: {
                labels: data.order_statuses?.map(status => {
                    return {
                        'new': 'Новый',
                        'processing': 'Готовится к отправке',
                        'shipped': 'Отправлен',
                        'ready_for_pickup': 'Готов к выдаче',
                        'completed': 'Завершен',
                        'canceled': 'Отменен'
                    }[status.status] || 'Неизвестен';
                }) || [],
                datasets: [{
                    label: 'Количество заказов',
                    data: data.order_statuses?.map(status => status.count) || [],
                    backgroundColor: data.order_statuses?.map(status => {
                        return {
                            'new': '#0d6efd',
                            'processing': '#0dcaf0',
                            'shipped': '#ffc107',
                            'ready_for_pickup': '#ff8c00',
                            'completed': '#198754',
                            'canceled': '#dc3545'
                        }[status.status] || '#6c757d';
                    }) || []
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } }
            }
        });

        if (chartInstances.delivery) chartInstances.delivery.destroy();
        chartInstances.delivery = new Chart(document.getElementById('deliveryChart'), {
            type: 'pie',
            data: {
                labels: data.delivery_services?.map(service => {
                    return {
                        'cdek': 'СДЭК',
                        'post': 'Почта России',
                        'pickup': 'Самовывоз'
                    }[service.delivery_service] || 'Не выбрано';
                }) || [],
                datasets: [{
                    label: 'Количество заказов',
                    data: data.delivery_services?.map(service => service.count) || [],
                    backgroundColor: ['#0d6efd', '#198754', '#ffc107', '#6c757d']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } }
            }
        });

        if (chartInstances.revenue) chartInstances.revenue.destroy();
        chartInstances.revenue = new Chart(document.getElementById('revenueChart'), {
            type: 'line',
            data: {
                labels: data.monthly_revenue?.map(rev => rev.month) || [],
                datasets: [{
                    label: 'Выручка (₽)',
                    data: data.monthly_revenue?.map(rev => rev.revenue) || [],
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.2)',
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `Выручка: ${new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB' }).format(context.raw)}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return new Intl.NumberFormat('ru-RU').format(value) + ' ₽';
                            }
                        }
                    }
                }
            }
        });

        if (chartInstances.peakHours) chartInstances.peakHours.destroy();
        chartInstances.peakHours = new Chart(document.getElementById('peakHoursChart'), {
            type: 'bar',
            data: {
                labels: data.peak_hours?.map(hour => `${hour.hour.toString().padStart(2, '0')}:00`) || [],
                datasets: [{
                    label: 'Количество заказов',
                    data: data.peak_hours?.map(hour => hour.count) || [],
                    backgroundColor: '#0d6efd',
                    borderColor: '#0d6efd',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });

        if (chartInstances.recentActivity) chartInstances.recentActivity.destroy();
        chartInstances.recentActivity = new Chart(document.getElementById('recentActivityChart'), {
            type: 'line',
            data: {
                labels: data.recent_activity?.map(activity => new Date(activity.day).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' })) || [],
                datasets: [{
                    label: 'Количество заказов',
                    data: data.recent_activity?.map(activity => activity.count) || [],
                    borderColor: '#198754',
                    backgroundColor: 'rgba(25, 135, 84, 0.2)',
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });

        if (chartInstances.topProducts) chartInstances.topProducts.destroy();
        chartInstances.topProducts = new Chart(document.getElementById('topProductsChart'), {
            type: 'bar',
            data: {
                labels: data.top_products?.map(product => product.name) || [],
                datasets: [{
                    label: 'Количество заказов',
                    data: data.top_products?.map(product => product.order_count) || [],
                    backgroundColor: ['#0d6efd', '#6f42c1', '#20c997', '#ffc107', '#dc3545']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });

        // Новый график для услуг
        if (chartInstances.topServices) chartInstances.topServices.destroy();
        chartInstances.topServices = new Chart(document.getElementById('topServicesChart'), {
            type: 'bar',
            data: {
                labels: data.top_services?.map(service => service.name) || [],
                datasets: [{
                    label: 'Количество заказов',
                    data: data.top_services?.map(service => service.order_count) || [],
                    backgroundColor: ['#0d6efd', '#6f42c1', '#20c997', '#ffc107', '#dc3545']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });

        if (chartInstances.city) chartInstances.city.destroy();
        chartInstances.city = new Chart(document.getElementById('cityChart'), {
            type: 'pie',
            data: {
                labels: data.top_cities?.map(city => city.city) || [],
                datasets: [{
                    label: 'Количество заказов',
                    data: data.top_cities?.map(city => city.order_count) || [],
                    backgroundColor: ['#0d6efd', '#6f42c1', '#20c997', '#ffc107', '#dc3545']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } }
            }
        });

        lastUpdatedCurrent = new Date();
        document.getElementById('current-last-updated').textContent = `Последнее обновление: ${formatDateTime(lastUpdatedCurrent)}`;
    }

    function updateHistoryCharts(data) {
        if (!data || !data.history) {
            console.error('Invalid data for history charts:', data);
            return;
        }

        const labels = data.history.map(row => row.date_hour || 'N/A');

        // Orders Chart
        if (chartInstances.orders) chartInstances.orders.destroy();
        chartInstances.orders = new Chart(document.getElementById('ordersChart'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Количество заказов',
                    data: data.history.map(row => row.total_orders || 0),
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.2)',
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } },
                scales: {
                    x: { title: { display: true, text: 'Дата и час' } },
                    y: { beginAtZero: true, title: { display: true, text: 'Заказы' } }
                }
            }
        });

        // Revenue History Chart
        if (chartInstances.revenueHistory) chartInstances.revenueHistory.destroy();
        chartInstances.revenueHistory = new Chart(document.getElementById('revenueHistoryChart'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Выручка (₽)',
                    data: data.history.map(row => row.total_revenue || 0),
                    borderColor: '#198754',
                    backgroundColor: 'rgba(25, 135, 84, 0.2)',
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `Выручка: ${new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB' }).format(context.raw)}`;
                            }
                        }
                    }
                },
                scales: {
                    x: { title: { display: true, text: 'Дата и час' } },
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Выручка (₽)' },
                        ticks: {
                            callback: function(value) {
                                return new Intl.NumberFormat('ru-RU').format(value) + ' ₽';
                            }
                        }
                    }
                }
            }
        });

        // Average Order Value Chart
        if (chartInstances.avgOrderValue) chartInstances.avgOrderValue.destroy();
        chartInstances.avgOrderValue = new Chart(document.getElementById('avgOrderValueChart'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Средняя стоимость заказа (₽)',
                    data: data.history.map(row => row.avg_order_value || 0),
                    borderColor: '#ffc107',
                    backgroundColor: 'rgba(255, 193, 7, 0.2)',
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `Средняя стоимость: ${new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB' }).format(context.raw)}`;
                            }
                        }
                    }
                },
                scales: {
                    x: { title: { display: true, text: 'Дата и час' } },
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Средняя стоимость (₽)' },
                        ticks: {
                            callback: function(value) {
                                return new Intl.NumberFormat('ru-RU').format(value) + ' ₽';
                            }
                        }
                    }
                }
            }
        });

        // Completion Rate Chart
        if (chartInstances.completionRate) chartInstances.completionRate.destroy();
        chartInstances.completionRate = new Chart(document.getElementById('completionRateChart'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Процент завершенных (%)',
                    data: data.history.map(row => row.completion_rate || 0),
                    borderColor: '#17a2b8',
                    backgroundColor: 'rgba(23, 162, 184, 0.2)',
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } },
                scales: {
                    x: { title: { display: true, text: 'Дата и час' } },
                    y: {
                        beginAtZero: true,
                        max: 100,
                        title: { display: true, text: 'Процент завершенных (%)' },
                        ticks: { callback: value => value + '%' }
                    }
                }
            }
        });

        lastUpdatedHistory = new Date();
        document.getElementById('history-last-updated').textContent = `Последнее обновление: ${formatDateTime(lastUpdatedHistory)}`;
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

        if (tab === 'current') isFetchingCurrent = true;
        if (tab === 'history') isFetchingHistory = true;

        const interval = document.getElementById('interval-select')?.value || '24h';
        let url = 'fetch_store_stats.php?tab=' + encodeURIComponent(tab) + '&interval=' + encodeURIComponent(interval);
        if (interval === 'custom') {
            const dates = document.getElementById('date-range')?.value.split(' to ');
            if (dates?.length === 2) {
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
                        updateHistoryCharts(data);
                    } else {
                        updateCurrentStats(data);
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
            });
    }

    // Initial chart render for current tab
    updateCurrentStats(<?php echo json_encode($stats); ?>);

    // Initial chart render for history tab
    if (document.querySelector('#history-tab').classList.contains('active')) {
        fetchStats('history');
    }

    // Poll every 10 seconds for current data
    setInterval(() => fetchStats('current'), 10000);

    // Manual refresh for history tab
    document.getElementById('refresh-history')?.addEventListener('click', () => {
        fetchStats('history');
    });

    // Обновление данных при переключении вкладки
    document.querySelectorAll('.nav-link').forEach(tab => {
        tab.addEventListener('shown.bs.tab', function (event) {
            if (event.target.id === 'history-tab') {
                fetchStats('history');
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