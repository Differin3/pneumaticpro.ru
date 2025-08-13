<?php
session_start();
require __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Проверка авторизации
if (!isset($_SESSION['admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Неавторизованный доступ']);
    exit;
}

// Установка заголовков для JSON-ответа
header('Content-Type: application/json');

// Инициализация переменных
$interval = isset($_GET['interval']) ? $_GET['interval'] : '24h';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-1 day'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'current';
$stats = [];

// Функция для обработки ошибок
function sendError($message, $code = 500) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

try {
    // Проверка соединения с базой данных
    $pdo->query("SELECT 1")->fetch();

    if ($tab === 'current') {
        // Текущие данные
        $stats['total_orders'] = $pdo->query("SELECT COUNT(*) as total FROM orders")->fetchColumn();

        $completed_orders = $pdo->query("SELECT COUNT(*) as completed FROM orders WHERE status = 'completed'")->fetchColumn();
        $stats['completion_rate'] = $stats['total_orders'] > 0 ? round(($completed_orders / $stats['total_orders']) * 100, 1) : 0;

        $result = $pdo->query("
            SELECT 
                SUM(oi.price * oi.quantity) as total,
                AVG(oi.price * oi.quantity) as avg_order
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE o.status = 'completed'
        ")->fetch(PDO::FETCH_ASSOC);
        $stats['total_revenue'] = $result['total'] ?? 0;
        $stats['avg_order_value'] = $result['avg_order'] ?? 0;

        $stats['top_products'] = $pdo->query("
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
        ")->fetchAll(PDO::FETCH_ASSOC);

        $stats['order_statuses'] = $pdo->query("
            SELECT status, COUNT(*) as count
            FROM orders
            GROUP BY status
        ")->fetchAll(PDO::FETCH_ASSOC);

        $stats['monthly_revenue'] = $pdo->query("
            SELECT 
                DATE_FORMAT(o.created_at, '%Y-%m') as month,
                SUM(oi.price * oi.quantity) as revenue
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            WHERE o.status = 'completed'
            AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY month
            ORDER BY month
        ")->fetchAll(PDO::FETCH_ASSOC);

        $customer_data = $pdo->query("
            SELECT 
                COUNT(DISTINCT customer_id) as unique_customers,
                COUNT(DISTINCT CASE WHEN order_count > 1 THEN customer_id END) as repeat_customers
            FROM (
                SELECT customer_id, COUNT(*) as order_count
                FROM orders
                GROUP BY customer_id
            ) sub
        ")->fetch(PDO::FETCH_ASSOC);
        $stats['customer_stats'] = [
            'unique_customers' => $customer_data['unique_customers'] ?? 0,
            'repeat_customers' => $customer_data['repeat_customers'] ?? 0,
            'repeat_percentage' => $customer_data['unique_customers'] > 0 
                ? round(($customer_data['repeat_customers'] / $customer_data['unique_customers']) * 100, 1) 
                : 0
        ];

        $stats['delivery_services'] = $pdo->query("
            SELECT 
                IFNULL(delivery_service, 'Не выбрано') as delivery_service,
                COUNT(*) as count
            FROM orders
            GROUP BY delivery_service
        ")->fetchAll(PDO::FETCH_ASSOC);

        $stats['peak_hours'] = $pdo->query("
            SELECT 
                HOUR(created_at) as hour,
                COUNT(*) as count
            FROM orders
            GROUP BY hour
            ORDER BY hour
        ")->fetchAll(PDO::FETCH_ASSOC);

        $stats['recent_activity'] = $pdo->query("
            SELECT 
                DATE(created_at) as day,
                COUNT(*) as count
            FROM orders
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY day
            ORDER BY day
        ")->fetchAll(PDO::FETCH_ASSOC);

        $stats['top_cities'] = $pdo->query("
            SELECT 
                IFNULL(o.pickup_city, 'Не указан') as city,
                COUNT(*) as order_count
            FROM orders o
            GROUP BY o.pickup_city
            ORDER BY order_count DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($tab === 'history') {
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
    }

    // Отправка успешного ответа
    echo json_encode($stats);

} catch (PDOException $e) {
    sendError("Ошибка базы данных: " . $e->getMessage());
} catch (Exception $e) {
    sendError("Ошибка: " . $e->getMessage());
}
?>