<?php
session_start();
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

// Включаем отображение ошибок для отладки (удалите в продакшене)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Проверка авторизации администратора
if (!isset($_SESSION['admin'])) {
    header('Location: login.php');
    exit();
}

// Генерация CSRF-токена
$csrf_token = generate_csrf_token();

// Обработка параметров
$search = isset($_GET['search']) ? clean_input($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? clean_input($_GET['status']) : 'all';
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$items_per_page = 10;

// Валидация статуса
$allowed_statuses = ['new', 'processing', 'shipped', 'completed', 'canceled', 'all'];
$status_filter = in_array($status_filter, $allowed_statuses) ? $status_filter : 'all';

// Маппинг delivery_service
$delivery_service_map = [
    'cdek' => 'CDEK',
    'post' => 'Почта России',
    'pickup' => 'Самовывоз'
];

// Динамическое построение SQL
$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(c.full_name LIKE :search1 OR o.order_number LIKE :search2 OR c.address LIKE :search3 OR o.tracking_number LIKE :search4)";
    $params[':search1'] = "%$search%";
    $params[':search2'] = "%$search%";
    $params[':search3'] = "%$search%";
    $params[':search4'] = "%$search%";
}

if ($status_filter !== 'all') {
    $where[] = "o.status = :status";
    $params[':status'] = $status_filter;
}

// Получение списка заказов
try {
    $sql_where = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "
        SELECT o.*, 
               c.full_name AS customer_name,
               c.phone AS customer_phone,
               c.address AS customer_address,
               u.email AS customer_email,
               COUNT(oi.id) AS items_count,
               COALESCE(SUM(oi.price * oi.quantity), 0) AS total_amount,
               (SELECT COUNT(*) FROM notes n WHERE n.entity_id = o.id AND n.entity_type = 'order') AS note_count,
               (SELECT MAX(CASE 
                           WHEN n.flag_color = 'red' THEN 3 
                           WHEN n.flag_color = 'yellow' THEN 2 
                           ELSE 1 
                         END) 
                FROM notes n 
                WHERE n.entity_id = o.id AND n.entity_type = 'order') AS flag_priority
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN users u ON c.user_id = u.id
        LEFT JOIN order_items oi ON o.id = oi.order_id
        $sql_where
        GROUP BY o.id
        ORDER BY o.created_at DESC
        LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($current_page - 1) * $items_per_page, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Преобразование приоритета флага в цвет
    foreach ($orders as &$order) {
        $order['flag_color'] = match((int)($order['flag_priority'] ?? 0)) {
            3 => 'danger',    // красный
            2 => 'warning',   // желтый
            1 => 'success',   // зеленый
            default => ''
        };
    }
    unset($order); // сброс ссылки

    // Подсчет общего количества
    $count_sql = "SELECT COUNT(*) FROM orders o LEFT JOIN customers c ON o.customer_id = c.id $sql_where";
    $count_stmt = $pdo->prepare($count_sql);
    foreach ($params as $key => $value) {
        $count_stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $count_stmt->execute();
    $total_items = $count_stmt->fetchColumn();

} catch (PDOException $e) {
    error_log("Ошибка загрузки заказов: " . $e->getMessage());
    http_response_code(500);
    die("Ошибка загрузки заказов: " . htmlspecialchars($e->getMessage()));
} catch (Exception $e) {
    error_log("Общая ошибка: " . $e->getMessage());
    http_response_code(500);
    die("Общая ошибка: " . htmlspecialchars($e->getMessage()));
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ-панель | Управление заказами</title>
    <link rel="icon" href="data:,">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/css/admin.css">
    <link rel="icon" href="https://pnevmatpro.ru/assets/favicon.png" type="image/png">
    <style>
        .badge-new { background-color: #0d6efd; }
        .badge-processing { background-color: #0dcaf0; }
        .badge-shipped { background-color: #ffc107; color: #000; }
        .badge-ready { background-color: #ff8c00; color: #fff; }
        .badge-completed { background-color: #198754; }
        .badge-canceled { background-color: #dc3545; }
        .order-item-image { width: 50px; height: 50px; object-fit: cover; }
        #orderDetailsModal .modal-body { max-height: 70vh; overflow-y: auto; }
        .status-select { min-width: 180px; }
        .tracking-link { color: #0d6efd; text-decoration: underline; }
        .tracking-link.text-muted { text-decoration: none; cursor: default; }
        .is-invalid { border-color: #dc3545 !important; }
        .invalid-feedback { display: block; color: #dc3545; font-size: 0.875em; margin-top: 0.25rem; }
        
        .bi-flag-fill.text-success { color: #198754; }
        .bi-flag-fill.text-warning { color: #ffc107; }
        .bi-flag-fill.text-danger { color: #dc3545; }
        
        .flag-color-btn.active {
            transform: scale(1.1);
            box-shadow: 0 0 0 3px rgba(0,0,0,0.1);
        }
        
        /* Стили для активных табов */
        .nav-tabs .nav-link.active {
            color: #000 !important;
            font-weight: 600;
            border-bottom: 3px solid #0d6efd;
            background-color: transparent;
        }

        /* Стили для неактивных табов */
        .nav-tabs .nav-link {
            color: #6c757d !important;
            border: none;
            border-bottom: 2px solid transparent;
            padding: 0.5rem 1rem;
        }

        /* Убираем стандартную границу у табов */
        .nav-tabs {
            border-bottom: none;
        }

        /* Отступ для заголовка с табами */
        .modal-header.bg-light.pt-0 {
            padding-top: 0.5rem !important;
            padding-bottom: 0 !important;
            border-bottom: none !important;
        }
        
        @media (max-width: 991.98px) {
            #orderDetailsModal .modal-dialog {
                margin: 0.5rem;
                max-width: none;
            }
        }
        
        /* Мобильные карточки для заказов */
        .order-card-mobile {
            display: none;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 0.75rem;
            margin-bottom: 0.75rem;
        }
        
        .order-card-mobile .order-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.25rem;
            font-size: 0.95rem;
            font-weight: 600;
        }
        
        .order-card-mobile .order-body {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.25rem;
            font-size: 0.85rem;
        }
        
        .order-card-mobile .col-span-2 {
            grid-column: span 2;
            text-align: center;
            margin-top: 0.25rem;
        }
        
        .order-card-mobile .btn-sm {
            padding: 0.2rem 0.5rem;
            font-size: 0.8rem;
        }
        
        .order-card-mobile .text-muted {
            font-size: 0.75rem;
        }
        
        /* Общие стили для мобильного меню и десктопа */
        .admin-wrapper {
            display: flex;
            min-height: 100vh;
            overflow-y: auto;
        }

        .admin-nav {
            background-color: #2c3e50;
            color: white;
            padding: 20px;
            width: 250px;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            transform: translateX(0);
            transition: transform 0.3s ease-in-out;
            z-index: 1050;
        }

        .admin-nav .nav {
            height: calc(100% - 100px);
            overflow-y: auto;
        }

        .admin-nav.active {
            transform: translateX(0);
        }

        .close-menu {
            display: block;
            width: 100%;
            text-align: left;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        .mobile-menu-btn {
            z-index: 1100;
            position: fixed;
            top: 10px;
            left: 10px;
            background-color: #0d6efd;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
        }

        .overlay.active {
            opacity: 1;
            pointer-events: all;
        }

        .admin-main {
            flex: 1;
            padding: 20px;
            margin-left: 250px;
            padding-top: 20px;
            overflow-y: auto;
        }

        @media (min-width: 768px) {
            .admin-nav {
                transform: translateX(0);
                position: fixed;
            }
            .mobile-menu-btn {
                display: none;
            }
            .overlay {
                display: none;
            }
            .admin-main {
                padding-top: 20px;
            }
        }

        @media (max-width: 767.98px) {
            .admin-nav {
                transform: translateX(-100%);
                position: fixed;
            }
            .admin-main {
                padding: 15px;
                width: 100%;
                margin-left: 0;
                padding-top: 60px;
            }
            
            .card {
                border-radius: 8px;
            }
            
            .table-responsive {
                border-radius: 8px;
                overflow: hidden;
            }
            
            .nav-link {
                font-size: 14px;
                padding: 10px 12px !important;
            }

            .order-table-desktop {
                display: none;
            }
            
            .order-card-mobile {
                display: block;
            }
        }

        @media (max-width: 576px) {
            .order-card-mobile .order-body {
                grid-template-columns: 1fr;
            }
            
            .order-card-mobile .col-span-2 {
                grid-column: auto;
            }
        }
        
        /* Стили для заметок */
        .note-content {
            white-space: pre-wrap;       /* Сохраняет пробелы и переносы */
            word-break: break-word;      /* Переносит длинные слова */
            font-family: monospace;      /* Моноширинный шрифт */
            background-color: #f8f9fa;   /* Светлый фон */
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #dee2e6;
        }
        
        /* Группа кнопок выбора цвета */
        .flag-color-group {
            margin-top: 8px;
            display: flex;
            gap: 6px;
        }
    </style>
</head>
<body class="admin-wrapper">
<div class="container-fluid">
    <div class="row flex-nowrap">
        <!-- Боковая панель -->
        <?php include '_sidebar.php'; ?>

        <!-- Основной контент -->
        <main class="admin-main">
            <!-- Кнопка мобильного меню -->
            <button class="btn btn-primary mobile-menu-btn d-md-none">
                <i class="bi bi-list"></i>
            </button>

            <!-- Оверлей -->
            <div class="overlay d-md-none"></div>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="display-6 text-dark fw-bold">Управление заказами</h2>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-secondary" id="refresh-orders">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                    <a href="logout.php" class="btn btn-danger d-md-none">
                        <i class="bi bi-box-arrow-right"></i>
                    </a>
                </div>
            </div>

            <!-- Фильтры -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET">
                        <div class="row g-3">
                            <div class="col-md-5 col-12">
                                <input type="text" name="search" class="form-control" 
                                       placeholder="Поиск по имени, номеру заказа, адресу или трек-номеру..." 
                                       value="<?= htmlspecialchars($search) ?>">
                            </div>
                            <div class="col-md-4 col-12">
                                <select name="status" class="form-select">
                                    <option value="all">Все статусы</option>
                                    <option value="new" <?= $status_filter === 'new' ? 'selected' : '' ?>>Новый</option>
                                    <option value="processing" <?= $status_filter === 'processing' ? 'selected' : '' ?>>Готовится к отправке</option>
                                    <option value="shipped" <?= $status_filter === 'shipped' ? 'selected' : '' ?>>Отправлен</option>
                                    <option value="ready_for_pickup" <?= $status_filter === 'ready_for_pickup' ? 'selected' : '' ?>>Готов к выдаче</option>
                                    <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Завершен</option>
                                    <option value="canceled" <?= $status_filter === 'canceled' ? 'selected' : '' ?>>Отменен</option>
                                </select>
                            </div>
                            <div class="col-md-3 col-12">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-funnel"></i> <span class="d-none d-md-inline">Применить</span>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (!empty($orders)): ?>
                <!-- Десктопная версия (таблица) -->
                <div class="table-responsive order-table-desktop">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>№ заказа</th>
                                <th class="d-none d-lg-table-cell">Клиент</th>
                                <th>Дата</th>
                                <th class="d-none d-md-table-cell">Товаров</th>
                                <th>Сумма</th>
                                <th>Статус</th>
                                <th>Транспортная компания</th>
                                <th class="d-none d-md-table-cell">Трек-номер</th>
                                <th>Заметка</th>
                                <th class="text-end">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td><?= htmlspecialchars($order['order_number'] ?? '') ?></td>
                                    <td class="d-none d-lg-table-cell"><?= htmlspecialchars($order['customer_name'] ?? '—') ?></td>
                                    <td><?= $order['created_at'] ? date('d.m.Y H:i', strtotime($order['created_at'])) : '—' ?></td>
                                    <td class="d-none d-md-table-cell"><?= (int)($order['items_count'] ?? 0) ?></td>
                                    <td><?= number_format($order['total_amount'] ?? 0, 2, ',', ' ') ?> ₽</td>
                                    <td>
                                        <span class="badge <?= match ($order['status'] ?? '') {
                                            'new' => 'badge-new',
                                            'processing' => 'badge-processing',
                                            'shipped' => 'badge-shipped',
                                            'ready_for_pickup' => 'badge-ready',
                                            'completed' => 'badge-completed',
                                            'canceled' => 'badge-canceled',
                                            default => 'bg-secondary'
                                        } ?>">
                                            <?= match ($order['status'] ?? '') {
                                                'new' => 'Новый',
                                                'processing' => 'Готовится к отправке',
                                                'shipped' => 'Отправлен',
                                                'ready_for_pickup' => 'Готов к выдаче',
                                                'completed' => 'Завершен',
                                                'canceled' => 'Отменен',
                                                default => 'Неизвестен'
                                            } ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= !empty($order['delivery_service']) && isset($delivery_service_map[$order['delivery_service']]) 
                                            ? htmlspecialchars($delivery_service_map[$order['delivery_service']]) 
                                            : 'Не выбрано' ?>
                                    </td>
                                    <td class="d-none d-md-table-cell">
                                        <?php if (!empty($order['tracking_number'])): ?>
                                            <?php
                                            $tracking_urls = [
                                                'cdek' => 'https://www.cdek.ru/ru/tracking?order_id=',
                                                'post' => 'https://www.pochta.ru/tracking?barcode='
                                            ];
                                            $tracking_url = ($order['delivery_service'] === 'pickup' || empty($order['delivery_service']) || !isset($tracking_urls[$order['delivery_service']]))
                                                ? '#'
                                                : $tracking_urls[$order['delivery_service']] . urlencode($order['tracking_number']);
                                            ?>
                                            <a href="<?= htmlspecialchars($tracking_url) ?>" 
                                               target="_blank" 
                                               class="tracking-link <?= $tracking_url === '#' ? 'text-muted' : '' ?>">
                                                <?= htmlspecialchars($order['tracking_number']) ?>
                                                <?php if ($tracking_url !== '#'): ?>
                                                    <i class="bi bi-box-arrow-up-right ms-1"></i>
                                                <?php endif; ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($order['note_count'] > 0): ?>
                                            <i class="bi bi-flag-fill text-<?= $order['flag_color'] ?>"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-primary view-order-details" 
                                                data-order-id="<?= htmlspecialchars($order['id'] ?? '') ?>"
                                                data-order-number="<?= htmlspecialchars($order['order_number'] ?? '') ?>">
                                            <i class="bi bi-eye"></i> <span class="d-none d-md-inline">Подробнее</span>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Мобильная версия (карточки) -->
                <div class="order-cards-mobile">
                    <?php foreach ($orders as $order): ?>
                        <div class="order-card-mobile">
                            <div class="order-header">
                                <strong>№: <?= htmlspecialchars($order['order_number'] ?? '') ?></strong>
                                <span><?= $order['created_at'] ? date('d.m.Y', strtotime($order['created_at'])) : '—' ?></span>
                            </div>
                            <div class="order-body">
                                <div>
                                    <small class="text-muted">Клиент</small>
                                    <div><?= htmlspecialchars($order['customer_name'] ?? '—') ?></div>
                                </div>
                                <div>
                                    <small class="text-muted">Тр. компания</small>
                                    <div>
                                        <?= !empty($order['delivery_service']) && isset($delivery_service_map[$order['delivery_service']]) 
                                            ? htmlspecialchars($delivery_service_map[$order['delivery_service']]) 
                                            : 'Не выбрано' ?>
                                    </div>
                                </div>
                                <div>
                                    <small class="text-muted">Сумма</small>
                                    <div><?= number_format($order['total_amount'] ?? 0, 2, ',', ' ') ?> ₽</div>
                                </div>
                                <div>
                                    <small class="text-muted">Трек-номер</small>
                                    <div>
                                        <?php if (!empty($order['tracking_number'])): ?>
                                            <?php
                                            $tracking_urls = [
                                                'cdek' => 'https://www.cdek.ru/ru/tracking?order_id=',
                                                'post' => 'https://www.pochta.ru/tracking?barcode='
                                            ];
                                            $tracking_url = ($order['delivery_service'] === 'pickup' || empty($order['delivery_service']) || !isset($tracking_urls[$order['delivery_service']]))
                                                ? '#'
                                                : $tracking_urls[$order['delivery_service']] . urlencode($order['tracking_number']);
                                            ?>
                                            <a href="<?= htmlspecialchars($tracking_url) ?>" 
                                               target="_blank" 
                                               class="tracking-link <?= $tracking_url === '#' ? 'text-muted' : '' ?>">
                                                <?= htmlspecialchars($order['tracking_number']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div>
                                    <small class="text-muted">Статус</small>
                                    <div>
                                        <span class="badge <?= match ($order['status'] ?? '') {
                                            'new' => 'badge-new',
                                            'processing' => 'badge-processing',
                                            'ready_for_pickup' => 'badge-ready',
                                            'shipped' => 'badge-shipped',
                                            'completed' => 'badge-completed',
                                            'canceled' => 'badge-canceled',
                                            default => 'bg-secondary'
                                        } ?>">
                                            <?= match ($order['status'] ?? '') {
                                                'new' => 'Новый',
                                                'processing' => 'Готовится к отправке',
                                                'shipped' => 'Отправлен',
                                                'ready_for_pickup' => 'Готов к выдаче',
                                                'completed' => 'Завершен',
                                                'canceled' => 'Отменен',
                                                default => 'Неизвестен'
                                            } ?>
                                        </span>
                                    </div>
                                </div>
                                <div>
                                    <small class="text-muted">Товаров</small>
                                    <div><?= (int)($order['items_count'] ?? 0) ?></div>
                                </div>
                                <div>
                                    <small class="text-muted">Заметка</small>
                                    <div>
                                        <?php if ($order['note_count'] > 0): ?>
                                            <i class="bi bi-flag-fill text-<?= $order['flag_color'] ?>"></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-span-2 text-center mt-2">
                                    <button class="btn btn-sm btn-outline-primary view-order-details" 
                                            data-order-id="<?= htmlspecialchars($order['id'] ?? '') ?>" 
                                            data-order-number="<?= htmlspecialchars($order['order_number'] ?? '') ?>">
                                        <i class="bi bi-eye"></i> Подробнее
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Пагинация -->
                <?php if ($total_items > $items_per_page): ?>
                    <nav class="mt-4">
                        <ul class="pagination justify-content-center flex-wrap">
                            <?php for ($i = 1; $i <= ceil($total_items / $items_per_page); $i++): ?>
                                <li class="page-item <?= $i === $current_page ? 'active' : '' ?>">
                                    <a class="page-link" 
                                       href="?page=<?= htmlspecialchars($i) ?>&status=<?= htmlspecialchars(urlencode($status_filter)) ?>&search=<?= htmlspecialchars(urlencode($search)) ?>">
                                        <?= htmlspecialchars($i) ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-box-seam fs-1 text-muted mb-3"></i>
                    <h4 class="text-dark mb-3">Нет заказов</h4>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Модальное окно для деталей заказа -->
<div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <!-- Заголовок с номером заказа -->
            <div class="modal-header bg-light">
                <h5 class="modal-title">Детали заказа <span id="modal-order-number"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <!-- Табы с черным текстом -->
            <div class="modal-header bg-light pt-0 border-bottom-0">
                <ul class="nav nav-tabs card-header-tabs" id="orderTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active text-dark fw-bold" id="order-details-tab" data-bs-toggle="tab" data-bs-target="#order-details" type="button" role="tab">Данные</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link text-dark fw-bold" id="notes-tab" data-bs-toggle="tab" data-bs-target="#notes-content" type="button" role="tab">Заметки</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link text-dark fw-bold" id="create-note-tab" data-bs-toggle="tab" data-bs-target="#create-note" type="button" role="tab">Создать заметку</button>
                    </li>
                </ul>
            </div>
            
            <div class="modal-body">
                <div class="tab-content" id="orderTabsContent">
                    <div class="tab-pane fade show active" id="order-details" role="tabpanel">
                        <!-- Контент деталей заказа будет здесь -->
                    </div>
                    <div class="tab-pane fade" id="notes-content" role="tabpanel">
                        <div id="notes-container" class="mb-3">
                            <!-- Заметки будут загружены здесь -->
                        </div>
                    </div>
                    <div class="tab-pane fade" id="create-note" role="tabpanel">
                        <form id="add-note-form">
                            <input type="hidden" name="entity_id" id="note-entity-id">
                            <input type="hidden" name="entity_type" value="order">
                            <div class="mb-3">
                                <label class="form-label">Текст заметки</label>
                                <textarea class="form-control" name="content" rows="3" required></textarea>
                            </div>
                            <!-- Цвет флажка перенесен ниже -->
                            <div class="mb-3">
                                <label class="form-label">Цвет флажка</label>
                                <div class="flag-color-group">
                                    <button type="button" class="btn btn-success flag-color-btn active" data-color="success">Зеленый</button>
                                    <button type="button" class="btn btn-warning flag-color-btn" data-color="warning">Желтый</button>
                                    <button type="button" class="btn btn-danger flag-color-btn" data-color="danger">Красный</button>
                                </div>
                                <input type="hidden" name="flag_color" value="success">
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                <button type="button" class="btn btn-outline-primary" id="update-delivery-status" style="display: none;"><i class="bi bi-arrow-clockwise"></i> Обновить статус из СДЭК</button>
                <button type="button" class="btn btn-primary" id="save-order-changes" style="display: none;" disabled>Сохранить изменения</button>
                <button type="button" class="btn btn-primary" id="add-note-button" style="display: none;">Добавить заметку</button>
            </div>
        </div>
    </div>
</div>

<!-- Скрытое поле для CSRF-токена -->
<input type="hidden" id="csrfToken" value="<?= htmlspecialchars($csrf_token) ?>">

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const menuBtn = document.querySelector('.mobile-menu-btn');
    const closeBtn = document.querySelector('.close-menu');
    const sidebar = document.querySelector('.admin-nav');
    const overlay = document.querySelector('.overlay');

    function toggleMenu(event) {
        event.preventDefault();
        const isOpening = !sidebar.classList.contains('active');
        sidebar.classList.toggle('active', isOpening);
        overlay.classList.toggle('active', isOpening);
        document.body.classList.toggle('menu-open', isOpening);
        overlay.style.display = isOpening ? 'block' : 'none';
        setTimeout(() => {
            if (!isOpening) overlay.style.display = 'none';
        }, 300);
    }

    if (menuBtn) menuBtn.addEventListener('click', toggleMenu);
    if (closeBtn) closeBtn.addEventListener('click', toggleMenu);
    if (overlay) overlay.addEventListener('click', toggleMenu);

    // Сброс состояния при изменении размера окна
    window.addEventListener('resize', () => {
        if (window.innerWidth >= 768) {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
            overlay.style.display = 'none';
            document.body.classList.remove('menu-open');
        }
    });

    // Инициализация
    if (window.innerWidth < 768) {
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
        overlay.style.display = 'none';
    }

    // Инициализация модального окна
    let modal;
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        modal = new bootstrap.Modal('#orderDetailsModal');
    } else {
        console.error('Bootstrap Modal не доступен. Убедитесь, что Bootstrap 5.3 загружен корректно.');
        return;
    }

    let currentOrderId = null;
    let currentOrderData = null;
    const saveButton = document.getElementById('save-order-changes');
    const updateStatusButton = document.getElementById('update-delivery-status');
    const addNoteButton = document.getElementById('add-note-button');

    // Обработчик кнопки "Обновить"
    document.getElementById('refresh-orders').addEventListener('click', function() {
        window.location.reload();
    });

    // Обработчик кнопки "Подробнее"
    document.querySelectorAll('.view-order-details').forEach(btn => {
        btn.addEventListener('click', function() {
            currentOrderId = this.dataset.orderId;
            const orderNumber = this.dataset.orderNumber;
            document.getElementById('modal-order-number').textContent = orderNumber || '';
            loadOrderDetails(orderNumber);
        });
    });

    // Управление кнопками при переключении вкладок
    document.querySelectorAll('#orderTabs button[data-bs-toggle="tab"]').forEach(tab => {
        tab.addEventListener('shown.bs.tab', event => {
            const targetId = event.target.getAttribute('data-bs-target');
            
            // Скрыть все кнопки в футере кроме "Закрыть"
            saveButton.style.display = 'none';
            updateStatusButton.style.display = 'none';
            addNoteButton.style.display = 'none';
            
            // Показать соответствующие кнопки для активной вкладки
            if (targetId === '#order-details') {
                saveButton.style.display = 'inline-block';
                if (currentOrderData && currentOrderData.order && 
                    currentOrderData.order.delivery_service === 'cdek' && 
                    currentOrderData.order.tracking_number) {
                    updateStatusButton.style.display = 'inline-block';
                }
            } 
            else if (targetId === '#create-note') {
                addNoteButton.style.display = 'inline-block';
            }
        });
    });

    // Сохраняем оригинальный текст кнопки
    addNoteButton._originalText = addNoteButton.innerHTML;
    
    // Обработчик кнопки "Добавить заметку"
    addNoteButton.addEventListener('click', function() {
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status"></span> Добавление...`;
        // Отправляем форму
        document.getElementById('add-note-form').dispatchEvent(new Event('submit'));
    });

    // Функция загрузки деталей заказа
    async function loadOrderDetails(orderNumber) {
        const contentDiv = document.getElementById('order-details');
        const csrfToken = document.getElementById('csrfToken').value;
        
        try {
            contentDiv.innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Загрузка...</span>
                    </div>
                    <p class="mt-2">Загрузка данных заказа...</p>
                </div>`;
            
            modal.show();
            
            const response = await fetch(`get_order_details.php?order_number=${encodeURIComponent(orderNumber)}&csrf_token=${encodeURIComponent(csrfToken)}`);
            
            if (!response.ok) {
                const err = await response.json().catch(() => ({}));
                throw new Error(err.message || `HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.status !== 'success') {
                throw new Error(data.message || 'Ошибка при загрузке данных');
            }
            
            currentOrderData = data.data;
            renderOrderDetails(data.data);
            checkForChanges();
            
            // Установка ID для заметок
            document.getElementById('note-entity-id').value = data.data.order.id;
            
            // Загрузка заметок
            loadNotes(data.data.order.id, 'order');
            
        } catch (error) {
            console.error('Ошибка:', error);
            contentDiv.innerHTML = `
                <div class="alert alert-danger">
                    <h5>Ошибка при загрузке данных заказа</h5>
                    <p>${error.message}</p>
                    <button class="btn btn-sm btn-outline-primary" onclick="loadOrderDetails('${encodeURIComponent(orderNumber)}')">
                        <i class="bi bi-arrow-clockwise"></i> Попробовать снова
                    </button>
                </div>`;
        }
    }

    // Функция отображения деталей заказа
    function renderOrderDetails(orderData) {
        const contentDiv = document.getElementById('order-details');
        
        if (!orderData || !orderData.order || !orderData.customer) {
            contentDiv.innerHTML = '<div class="alert alert-danger">Ошибка: Неполные данные заказа</div>';
            return;
        }

        const formatDate = (dateString) => (!dateString ? '—' : new Date(dateString).toLocaleString('ru-RU'));
        const formatPrice = (price) => (isNaN(parseFloat(price)) ? '0.00' : parseFloat(price).toFixed(2).replace('.', ','));

        const delivery_service_map = {
            'cdek': 'CDEK',
            'post': 'Почта России',
            'pickup': 'Самовывоз'
        };

        const items = Array.isArray(orderData.items) ? orderData.items : [];
        const itemsHtml = items.length > 0 
            ? items.map(item => {
                const specs = [];
                if (item.type === 'bullet') {
                    if (item.diameter) specs.push(`Диаметр: ${item.diameter} мм`);
                    if (item.weight) specs.push(`Вес: ${item.weight} г`);
                } else if (item.type === 'service') {
                    if (item.duration) specs.push(`Длительность: ${Math.floor(item.duration/60)} ч ${item.duration%60} мин`);
                    if (item.service_type) {
                        const serviceTypes = {
                            'repair': 'Ремонт',
                            'maintenance': 'Обслуживание',
                            'custom': 'Индивидуальная услуга'
                        };
                        specs.push(`Тип: ${serviceTypes[item.service_type] || item.service_type}`);
                    }
                }

                return `
                <tr>
                    <td>
                        ${item.product_id ? `<a href="/admin/products/edit.php?id=${item.product_id}" target="_blank">${item.name || '—'}</a>` : item.name || '—'}
                        ${item.vendor_code ? `<br><small class="text-muted">Арт. ${item.vendor_code}</small>` : ''}
                        ${specs.length > 0 ? `<br><small class="text-muted">${specs.join(', ')}</small>` : ''}
                    </td>
                    <td>${formatPrice(item.price)} ₽</td>
                    <td>${item.quantity || '—'}</td>
                    <td>${formatPrice(item.price * (item.quantity || 1))} ₽</td>
                </tr>
                `;
            }).join('')
            : `<tr><td colspan="4" class="text-center text-muted">Нет товаров в заказе</td></tr>`;

        const history = Array.isArray(orderData.history) ? orderData.history : [];
        const historyHtml = history.length > 0
            ? history.map(entry => `
                <tr>
                    <td>${formatDate(entry.created_at)}</td>
                    <td>${entry.action === 'status_update' ? 'Обновление статуса' : (entry.action || '—')}</td>
                    <td>${entry.details || '—'}</td>
                </tr>
            `).join('')
            : `<tr><td colspan="3" class="text-center text-muted">Нет записей в истории</td></tr>`;

        const customer = orderData.customer || {};
        
        contentDiv.innerHTML = `
            <div class="container-fluid">
                <div class="row">
                    <div class="col-md-6">
                        <div class="card mb-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">Информация о заказе</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">Статус заказа</label>
                                    <select class="form-select status-select" id="order-status">
                                        <option value="new" ${orderData.order.status === 'new' ? 'selected' : ''}>Новый</option>
                                        <option value="processing" ${orderData.order.status === 'processing' ? 'selected' : ''}>Готовится к отправке</option>
                                        <option value="shipped" ${orderData.order.status === 'shipped' ? 'selected' : ''}>Отправлен</option>
                                        <option value="ready_for_pickup" ${orderData.order.status === 'ready_for_pickup' ? 'selected' : ''}>Готов к выдаче</option>
                                        <option value="completed" ${orderData.order.status === 'completed' ? 'selected' : ''}>Завершен</option>
                                        <option value="canceled" ${orderData.order.status === 'canceled' ? 'selected' : ''}>Отменен</option>
                                    </select>
                                    <div class="invalid-feedback" id="order-status-error"></div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Транспортная компания</label>
                                    <div class="form-control-plaintext">
                                        ${delivery_service_map[orderData.order.delivery_service] || 'Не выбрано'}
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Трек-номер</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="tracking-number" 
                                               value="${orderData.order.tracking_number || ''}" placeholder="Введите трек-номер">
                                        ${orderData.order.tracking_number && orderData.order.delivery_service && orderData.order.delivery_service !== 'pickup' ? `
                                        <a href="${orderData.order.delivery_service === 'cdek' ? 'https://www.cdek.ru/ru/tracking?order_id=' : 'https://www.pochta.ru/tracking?barcode='}${encodeURIComponent(orderData.order.tracking_number)}" 
                                           target="_blank" class="btn btn-outline-secondary">
                                            Отследить
                                        </a>
                                        ` : ''}
                                    </div>
                                    <div class="invalid-feedback" id="tracking-number-error"></div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Город ПВЗ</label>
                                    <div class="form-control-plaintext">
                                        ${orderData.order.pickup_city || '—'}
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Адрес ПВЗ</label>
                                    <div class="form-control-plaintext">
                                        ${orderData.order.pickup_address || '—'}
                                    </div>
                                </div>
                                
                                <table class="table table-bordered mb-0">
                                    <tbody>
                                        <tr>
                                            <th width="40%">Номер заказа</th>
                                            <td>${orderData.order.order_number || '—'}</td>
                                        </tr>
                                        <tr>
                                            <th>Общая сумма</th>
                                            <td>${formatPrice(orderData.order.total || orderData.order.total_amount)} ₽</td>
                                        </tr>
                                        <tr>
                                            <th>Дата создания</th>
                                            <td>${formatDate(orderData.order.created_at)}</td>
                                        </tr>
                                        <tr>
                                            <th>Дата обновления</th>
                                            <td>${formatDate(orderData.order.updated_at)}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">Информация о клиенте</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered mb-0">
                                    <tbody>
                                        <tr>
                                            <th width="40%">ФИО</th>
                                            <td id="customerName">${customer.full_name || '—'}</td>
                                        </tr>
                                        <tr>
                                            <th>Телефон</th>
                                            <td id="customerPhone">${customer.phone || '—'}</td>
                                        </tr>
                                        <tr>
                                            <th>Email</th>
                                            <td id="customerEmail">${customer.email || '—'}</td>
                                        </tr>
                                        ${customer.birth_date ? `
                                        <tr>
                                            <th>Дата рождения</th>
                                            <td>${customer.birth_date}</td>
                                        </tr>
                                        ` : ''}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card mb-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">Товары в заказе (${items.length})</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead>
                                            <tr>
                                                <th>Товар</th>
                                                <th width="15%">Цена</th>
                                                <th width="15%">Кол-во</th>
                                                <th width="20%">Сумма</th>
                                            </tr>
                                        </thead>
                                        <tbody>${itemsHtml}</tbody>
                                        <tfoot>
                                            <tr class="table-light">
                                                <th colspan="3" class="text-end">Итого:</th>
                                                <th>${formatPrice(orderData.order.total || orderData.order.total_amount)} ₽</th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">История изменений</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead>
                                            <tr>
                                                <th width="25%">Дата</th>
                                                <th width="25%">Действие</th>
                                                <th>Детали</th>
                                            </tr>
                                        </thead>
                                        <tbody>${historyHtml}</tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        const statusSelect = document.getElementById('order-status');
        const trackingInput = document.getElementById('tracking-number');
        if (statusSelect) {
            statusSelect.addEventListener('change', checkForChanges);
        }
        if (trackingInput) {
            trackingInput.addEventListener('input', checkForChanges);
        }
        document.getElementById('save-order-changes').addEventListener('click', saveOrderChanges);

        // Показываем кнопку обновления статуса только для CDEK и если есть трек-номер
        if (orderData.order.delivery_service === 'cdek' && orderData.order.tracking_number) {
            updateStatusButton.style.display = 'inline-block';
            updateStatusButton.addEventListener('click', updateDeliveryStatus);
        } else {
            updateStatusButton.style.display = 'none';
        }
        
        // Обработчики для кнопок цвета заметок
        document.querySelectorAll('.flag-color-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.flag-color-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                document.querySelector('input[name="flag_color"]').value = this.dataset.color;
            });
        });
        
        // Обработчик табуляции в textarea
        document.querySelectorAll('textarea[name="content"]').forEach(textarea => {
            textarea.addEventListener('keydown', function(e) {
                if (e.key === 'Tab') {
                    e.preventDefault();
                    const start = this.selectionStart;
                    const end = this.selectionEnd;
                    
                    // Вставляем табуляцию
                    this.value = this.value.substring(0, start) + '\t' + this.value.substring(end);
                    
                    // Перемещаем курсор
                    this.selectionStart = this.selectionEnd = start + 1;
                }
            });
        });
        
        // Обработчик добавления заметки
        document.getElementById('add-note-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('csrf_token', document.getElementById('csrfToken').value);
            
            try {
                const response = await fetch('notes_api/add_note.php', {
                    method: 'POST',
                    body: formData
                });
                
                if (!response.ok) {
                    const errorText = await response.text();
                    throw new Error(`Ошибка сети: ${response.status}, ${errorText}`);
                }

                const result = await response.json();
                if (result.error) {
                    throw new Error(result.error);
                }
                
                this.reset();
                loadNotes(currentOrderId, 'order');
                setTimeout(() => location.reload(), 1000);
                
            } catch (error) {
                console.error('Ошибка:', error);
                alert('Ошибка при добавлении заметки: ' + error.message);
                // Восстанавливаем кнопку при ошибке
                if (addNoteButton) {
                    addNoteButton.disabled = false;
                    addNoteButton.innerHTML = addNoteButton._originalText;
                }
            }
        });
    }
    
    // Функция загрузки заметок
    async function loadNotes(entityId, entityType) {
        const container = document.getElementById('notes-container');
        container.innerHTML = '<div class="text-center"><div class="spinner-border"></div></div>';
        
        try {
            const response = await fetch(`notes_api/get_notes.php?entity_id=${entityId}&entity_type=${entityType}`);
            const notes = await response.json();
            
            let html = '';
            if (notes.length > 0) {
                notes.forEach(note => {
                    html += `
                    <div class="card mb-2">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <span class="badge bg-${note.flag_color} me-2">${note.created_at}</span>
                                    ${note.admin_name}
                                </div>
                                <button class="btn btn-sm btn-outline-danger delete-note" data-note-id="${note.id}">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                            <div class="mt-2 note-content">${note.content}</div>
                        </div>
                    </div>`;
                });
            } else {
                html = '<p class="text-center text-muted">Нет заметок</p>';
            }
            
            container.innerHTML = html;
            
            // Обработчики удаления
            document.querySelectorAll('.delete-note').forEach(btn => {
                btn.addEventListener('click', async function() {
                    const noteId = this.dataset.noteId;
                    const formData = new FormData();
                    formData.append('note_id', noteId);
                    formData.append('csrf_token', document.getElementById('csrfToken').value);
                    
                    const response = await fetch('notes_api/delete_note.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    if (response.ok) {
                        loadNotes(entityId, entityType);
                        // Обновляем страницу чтобы обновить флажки в списке
                        setTimeout(() => location.reload(), 1000);
                    }
                });
            });
            
        } catch (error) {
            console.error('Ошибка загрузки заметок:', error);
            container.innerHTML = `
                <div class="alert alert-danger">
                    <h5>Ошибка загрузки заметок</h5>
                    <p>${error.message}</p>
                </div>`;
        }
    }

    // Функция проверки изменений
    function checkForChanges() {
        const status = document.getElementById('order-status').value;
        const trackingNumber = document.getElementById('tracking-number').value.trim();

        const hasChanges = 
            status !== (currentOrderData.order.status || '') ||
            trackingNumber !== (currentOrderData.order.tracking_number || '');

        saveButton.disabled = !hasChanges;
    }

    // Функция валидации данных
    function validateForm() {
        let isValid = true;
        const status = document.getElementById('order-status').value;
        const trackingNumber = document.getElementById('tracking-number').value.trim();

        const statusField = document.getElementById('order-status');
        const statusError = document.getElementById('order-status-error');
        if (!status) {
            statusField.classList.add('is-invalid');
            statusError.textContent = 'Статус обязателен';
            isValid = false;
        } else {
            statusField.classList.remove('is-invalid');
            statusError.textContent = '';
        }

        const trackingField = document.getElementById('tracking-number');
        const trackingError = document.getElementById('tracking-number-error');
        if (trackingNumber) {
            const trackingRegex = /^[a-zA-Z0-9-]{1,50}$/;
            if (!trackingRegex.test(trackingNumber)) {
                trackingField.classList.add('is-invalid');
                trackingError.textContent = 'Трек-номер должен содержать только буквы, цифры и дефисы, максимум 50 символов';
                isValid = false;
            } else {
                trackingField.classList.remove('is-invalid');
                trackingError.textContent = '';
            }
        } else {
            trackingField.classList.remove('is-invalid');
            trackingError.textContent = '';
        }

        return isValid;
    }

    // Функция сохранения изменений
    async function saveOrderChanges() {
        const btn = this;
        const originalText = btn.innerHTML;
        const csrfToken = document.getElementById('csrfToken').value;
        
        if (!validateForm()) {
            return;
        }

        btn.disabled = true;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status"></span> Сохранение...`;
        
        try {
            const status = document.getElementById('order-status').value;
            const trackingNumber = document.getElementById('tracking-number').value.trim();
            
            const formData = new FormData();
            formData.append('order_id', currentOrderId);
            formData.append('status', status);
            formData.append('tracking_number', trackingNumber);
            formData.append('old_status', currentOrderData.order.status || '');
            formData.append('old_tracking', currentOrderData.order.tracking_number || '');
            formData.append('csrf_token', csrfToken);

            const response = await fetch('update_order.php', {
                method: 'POST',
                body: formData
            });
            
            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(`HTTP error! status: ${response.status}, response: ${errorText}`);
            }
            
            const result = await response.json();
            
            if (result.status !== 'success') {
                throw new Error(result.message || 'Ошибка сохранения');
            }
            
            const contentDiv = document.getElementById('order-details');
            contentDiv.innerHTML = `
                <div class="alert alert-success text-center">
                    <h5>Успех!</h5>
                    <p>Изменения успешно сохранены.</p>
                    <p>Страница будет перезагружена через 2 секунды...</p>
                </div>`;
            
            setTimeout(() => {
                window.location.reload();
            }, 2000);
            
        } catch (error) {
            console.error('Ошибка:', error);
            const contentDiv = document.getElementById('order-details');
            contentDiv.innerHTML = `
                <div class="alert alert-danger">
                    <h5>Ошибка при сохранении изменений</h5>
                    <p>${error.message}</p>
                    <button class="btn btn-sm btn-outline-primary" onclick="loadOrderDetails('${encodeURIComponent(currentOrderData.order.order_number || '')}')">
                        <i class="bi bi-arrow-left"></i> Вернуться к заказу
                    </button>
                </div>`;
            btn.innerHTML = originalText;
            btn.disabled = false;
            checkForChanges();
        }
    }

    // Функция обновления статуса из СДЭК API
    async function updateDeliveryStatus() {
        const btn = this;
        const originalText = btn.innerHTML;
        const csrfToken = document.getElementById('csrfToken').value;
        const trackingNumber = document.getElementById('tracking-number').value.trim();

        if (!trackingNumber) {
            alert('Трек-номер не указан');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status"></span> Обновление...`;

        try {
            const formData = new FormData();
            formData.append('action', 'get_delivery_status');
            formData.append('tracking_number', trackingNumber);
            formData.append('order_id', currentOrderId);
            formData.append('csrf_token', csrfToken);

            const response = await fetch('/api/order_check_cdek.php', {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(`HTTP error! status: ${response.status}, response: ${errorText}`);
            }

            const result = await response.json();

            if (result.status !== 'success') {
                throw new Error(result.message || 'Ошибка обновления статуса');
            }

            const data = result.data;

            // Обновляем внутренний статус, если он получен
            if (data.internal_status) {
                document.getElementById('order-status').value = data.internal_status;
                checkForChanges();
            }

            // Показываем статус доставки
            alert(`Статус доставки из СДЭК: ${data.delivery_status}`);

            // Обновляем UI после успешного обновления статуса
            loadOrderDetails(currentOrderData.order.order_number);

        } catch (error) {
            console.error('Ошибка:', error);
            let errorMessage = error.message;
            try {
                const errorData = JSON.parse(error.message.split('response: ')[1]);
                errorMessage = errorData.message || 'Ошибка обновления статуса';
            } catch (e) {
                // Если не удалось распарсить JSON, оставляем исходное сообщение
            }
            alert(`Ошибка при обновлении статуса: ${errorMessage}`);
            logToFile(`Ошибка в updateDeliveryStatus для трек-номера ${trackingNumber}: ${error.message}`, 'ERROR');
        } finally {
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    }

    // Функция логирования на клиентской стороне
    function logToFile(message, level = 'INFO') {
        fetch('/../api/logs/log.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message, level })
        }).catch(error => console.error('Ошибка логирования:', error));
    }
});
</script>
</body>
</html>