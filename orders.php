<?php
session_start();
require 'includes/config.php';
require 'includes/functions.php';

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Генерация CSRF-токена
$csrf_token = generate_csrf_token();

// Получение текущего ФИО
try {
    $stmt = $pdo->prepare("SELECT full_name FROM customers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    $current_full_name = $customer['full_name'] ?? '';
} catch (PDOException $e) {
    error_log("Ошибка получения ФИО: " . $e->getMessage());
    $current_full_name = '';
}

// Обработка изменения имени пользователя и ФИО
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_profile' && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $new_username = clean_input($_POST['new_username']);
    $new_full_name = clean_input($_POST['new_full_name']);
    $error = ''; // Ensure $error is always initialized
    
    try {
        $changes = [];
        
        // Валидация имени пользователя
        if (!preg_match('/^[A-Za-zА-Яа-я0-9_-]{3,50}$/u', $new_username)) {
            $error = "Имя пользователя должно содержать 3-50 символов (буквы, цифры, дефис, подчеркивание)";
        } elseif ($new_username !== $_SESSION['username']) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$new_username, $_SESSION['user_id']]);
            if ($stmt->fetch()) {
                $error = "Это имя пользователя уже занято";
            } else {
                $changes[] = "username: {$_SESSION['username']} → {$new_username}";
            }
        }
        
        // Валидация ФИО
        if (!$error && !preg_match('/^[А-Яа-я\s-]{3,100}$/u', $new_full_name)) {
            $error = "ФИО должно содержать 3-100 символов (только буквы, пробелы, дефис)";
        } elseif ($new_full_name !== $current_full_name) {
            $changes[] = "full_name: {$current_full_name} → {$new_full_name}";
        }
        
        if (!$error && !empty($changes)) {
            // Обновление имени пользователя
            if ($new_username !== $_SESSION['username']) {
                $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
                $stmt->execute([$new_username, $_SESSION['user_id']]);
                $_SESSION['username'] = $new_username;
            }
            
            // Обновление ФИО
            if ($new_full_name !== $current_full_name) {
                $stmt = $pdo->prepare("UPDATE customers SET full_name = ? WHERE user_id = ?");
                $stmt->execute([$new_full_name, $_SESSION['user_id']]);
                $_SESSION['full_name'] = $new_full_name;
                // Re-fetch current_full_name to ensure consistency
                $stmt = $pdo->prepare("SELECT full_name FROM customers WHERE user_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $customer = $stmt->fetch(PDO::FETCH_ASSOC);
                $current_full_name = $customer['full_name'] ?? '';
            }
            
            // Логирование действия
            $stmt = $pdo->prepare("
                INSERT INTO activity_log (user_id, type, description, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $_SESSION['user_id'],
                'profile_update',
                "Пользователь изменил данные: " . implode(', ', $changes),
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            // Redirect to refresh the page with updated data
            header("Location: orders.php");
            exit();
        } elseif (!$error && empty($changes)) {
            $success = "Данные не были изменены";
        }
    } catch (PDOException $e) {
        error_log("Ошибка при обновлении профиля: " . $e->getMessage());
        $error = "Ошибка при обновлении профиля";
    }
}

// Обработка изменения пароля
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password' && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $error = ''; // Ensure $error is always initialized
    
    try {
        // Проверка текущего пароля
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!password_verify($current_password, $user['password_hash'])) {
            $error = "Неверный текущий пароль";
        } elseif ($new_password !== $confirm_password) {
            $error = "Новые пароли не совпадают";
        } elseif (strlen($new_password) < 8) {
            $error = "Новый пароль должен содержать минимум 8 символов";
        } else {
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$new_password_hash, $_SESSION['user_id']]);
            
            // Логирование действия
            $stmt = $pdo->prepare("
                INSERT INTO activity_log (user_id, type, description, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $_SESSION['user_id'],
                'profile_update',
                "Пользователь изменил пароль",
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            // Redirect to refresh the page
            header("Location: orders.php");
            exit();
        }
    } catch (PDOException $e) {
        error_log("Ошибка при обновлении пароля: " . $e->getMessage());
        $error = "Ошибка при обновлении пароля";
    }
}

try {
    // Получаем ID клиента текущего пользователя
    $stmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        throw new Exception("Профиль клиента не найден");
    }

    // Получаем заказы пользователя с актуальными данными
    $stmt = $pdo->prepare("
        SELECT 
            o.id,
            o.order_number,
            o.created_at,
            o.status,
            o.tracking_number,
            o.delivery_service,
            o.pickup_address,
            o.total,
            COUNT(oi.id) as items_count
        FROM orders o
        LEFT JOIN order_items oi ON o.id = oi.order_id
        WHERE o.customer_id = ?
        GROUP BY o.id
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$customer['id']]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Ошибка загрузки заказов: " . $e->getMessage());
    die("Ошибка загрузки заказов: " . htmlspecialchars($e->getMessage()));
} catch (Exception $e) {
    error_log("Ошибка: " . $e->getMessage());
    die($e->getMessage());
}

// Маппинг delivery_service на читаемые названия
$delivery_service_map = [
    'cdek' => 'CDEK',
    'post' => 'Почта России',
    'pickup' => 'Самовывоз'
];
?>
<!DOCTYPE html>
<!-- Yandex.Metrika counter -->
<script type="text/javascript" >
   (function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
   m[i].l=1*new Date();
   for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
   k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
   (window, document, "script", "https://mc.yandex.ru/metrika/tag.js", "ym");

   ym(102526594, "init", {
        clickmap:true,
        trackLinks:true,
        accurateTrackBounce:true,
        webvisor:true
   });
</script>
<noscript><div><img src="https://mc.yandex.ru/watch/102526594" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
<!-- /Yandex.Metrika counter -->
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate"> <!-- Prevent caching -->
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>pnevmatpro.ru - Личный кабинет</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="icon" href="https://pnevmatpro.ru/assets/favicon.png" type="image/png">
    <style>
        .order-table th {
            background-color: #f8f9fa;
        }
        .order-row:hover {
            background-color: rgba(0,0,0,0.03);
        }
        .badge-new { background-color: #0d6efd; }
        .badge-processing { background-color: #0dcaf0; }
        .badge-shipped { background-color: #ffc107; color: #000; }
        .badge-ready { background-color: #ff8c00; color: #fff; }
        .badge-completed { background-color: #198754; }
        .badge-canceled { background-color: #dc3545; }
        .tracking-link {
            color: #0d6efd;
            text-decoration: underline;
        }
        .tracking-link.text-muted {
            text-decoration: none;
            cursor: default;
        }
        .logo-float {
            animation: float 6s ease-in-out infinite;
            max-width: 100%;
            height: auto;
        }
        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0px); }
        }
        .hero { padding-bottom: 1.5rem; }
        @media (min-width: 768px) {
            .hero { padding: 2rem 0; }
            .logo-float { width: 400px; }
        }
        .btn-lg {
            padding: 0.5rem 1rem;
            font-size: 1rem;
        }
        @media (min-width: 768px) {
            .btn-lg {
                padding: 0.75rem 1.5rem;
                font-size: 1.25rem;
            }
        }
        .order-table { font-size: 0.9rem; }
        @media (min-width: 768px) {
            .order-table { font-size: 1rem; }
        }
        @media (max-width: 767px) {
            .order-table th:nth-child(3),
            .order-table td:nth-child(3),
            .order-table th:nth-child(7),
            .order-table td:nth-child(7) {
                display: none;
            }
        }
        .order-card {
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .order-card .order-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }
        .order-card .order-body {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
        }
        .lead { font-size: 1.1rem; }
        @media (max-width: 768px) {
            .display-4 { font-size: 2rem; }
            .lead { font-size: 1rem; }
        }
        .profile-form {
            max-width: 500px;
            margin: 0 auto;
        }
        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
        }
    </style>
</head>
<body>
<header class="hero bg-dark text-white">
    <div class="container text-center position-relative">
        <img src="assets/logo.png" alt="Logo" width="400" class="mb-3 mb-md-4 logo-float">
        <p class="lead mb-3 mb-md-4">Профессиональный ремонт пневматического оружия и продажа комплектующих</p>
        <div class="d-flex flex-wrap gap-2 justify-content-center">
            <a href="index.php" class="btn btn-lg btn-outline-light rounded-pill">
                <i class="bi bi-arrow-left me-2"></i>На главную
            </a>
            <a href="cart/cart.php" class="btn btn-lg btn-warning position-relative rounded-pill">
                <i class="bi bi-cart me-2"></i>Корзина
                <span class="cart-badge badge bg-danger">
                    <?= array_sum(array_column($_SESSION['cart'] ?? [], 'quantity')) ?>
                </span>
            </a>
            <div class="d-flex align-items-center gap-2">
                <span class="text-white"><?= htmlspecialchars($_SESSION['username'] ?? 'Пользователь') ?></span>
                <a href="logout.php" class="btn btn-outline-light btn-sm rounded-pill">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
</header>

<section class="py-4 py-md-5 bg-white">
    <div class="container">
        <div class="text-center mb-4 mb-md-5">
            <h2 class="display-4 mb-2 mb-md-3 text-dark fw-bold">Личный кабинет</h2>
            <div class="decorative-line mx-auto bg-accent"></div>
            <p class="lead text-muted mt-2 mt-md-3">Управление заказами и настройками профиля</p>
        </div>

        <!-- Вкладки -->
        <ul class="nav nav-tabs mb-4" id="profileTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="orders-tab" data-bs-toggle="tab" data-bs-target="#orders" type="button" role="tab" aria-controls="orders" aria-selected="true">Мои заказы</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" type="button" role="tab" aria-controls="profile" aria-selected="false">Профиль</button>
            </li>
        </ul>

        <div class="tab-content" id="profileTabsContent">
            <!-- Вкладка заказов -->
            <div class="tab-pane fade show active" id="orders" role="tabpanel" aria-labelledby="orders-tab">
                <?php if(!empty($orders)): ?>
                    <!-- Десктопная версия (таблица) -->
                    <div class="d-none d-md-block">
                        <div class="table-responsive">
                            <table class="table table-hover order-table">
                                <thead>
                                    <tr>
                                        <th>№ заказа</th>
                                        <th>Дата</th>
                                        <th>Товаров</th>
                                        <th>Сумма</th>
                                        <th>Статус</th>
                                        <th>Трек-номер</th>
                                        <th>Транспортная компания</th>
                                        <th>Адрес ПВЗ</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($orders as $order): ?>
                                        <tr class="order-row" data-order-id="<?= $order['id'] ?>">
                                            <td><?= htmlspecialchars($order['order_number']) ?></td>
                                            <td><?= date('d.m.Y H:i', strtotime($order['created_at'])) ?></td>
                                            <td><?= $order['items_count'] ?></td>
                                            <td><?= number_format($order['total'], 2) ?> ₽</td>
                                            <td>
                                                <span class="badge <?= match($order['status']) {
                                                    'new' => 'badge-new',
                                                    'processing' => 'badge-processing',
                                                    'shipped' => 'badge-shipped',
                                                    'ready_for_pickup' => 'badge-ready', // Новый статус
                                                    'completed' => 'badge-completed',
                                                    'canceled' => 'badge-canceled',
                                                    default => 'bg-secondary'
                                                } ?>">
                                                    <?= match($order['status']) {
                                                        'new' => 'Новый',
                                                        'processing' => 'Готовится к отправке',
                                                        'shipped' => 'Отправлен',
                                                        'ready_for_pickup' => 'Готов к выдаче', // Новый статус
                                                        'completed' => 'Завершен',
                                                        'canceled' => 'Отменен',
                                                        default => $order['status']
                                                    } ?>
                                                </span>
                                            </td>
                                            <td class="tracking-cell">
                                                <?php if(!empty($order['tracking_number'])): ?>
                                                    <?php
                                                    $tracking_urls = [
                                                        'cdek' => 'https://www.cdek.ru/ru/tracking?order_id=',
                                                        'post' => 'https://www.pochta.ru/tracking?barcode='
                                                    ];
                                                    $tracking_url = ($order['delivery_service'] === 'pickup' || empty($order['delivery_service']) || !isset($tracking_urls[$order['delivery_service']]))
                                                        ? '#'
                                                        : $tracking_urls[$order['delivery_service']] . $order['tracking_number'];
                                                    ?>
                                                    <a href="<?= $tracking_url ?>" 
                                                       target="_blank" 
                                                       class="tracking-link <?= $tracking_url === '#' ? 'text-muted' : '' ?>">
                                                        <?= $order['tracking_number'] ?>
                                                        <?php if($tracking_url !== '#'): ?>
                                                            <i class="bi bi-box-arrow-up-right ms-1"></i>
                                                        <?php endif; ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= !empty($order['delivery_service']) && isset($delivery_service_map[$order['delivery_service']]) 
                                                    ? htmlspecialchars($delivery_service_map[$order['delivery_service']]) 
                                                    : '<span class="text-muted">—</span>' ?>
                                            </td>
                                            <td>
                                                <?= !empty($order['pickup_address']) 
                                                    ? htmlspecialchars($order['pickup_address']) 
                                                    : '<span class="text-muted">—</span>' ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary details-btn" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#orderDetailsModal"
                                                        data-order-id="<?= $order['id'] ?>">
                                                    Подробнее
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Мобильная версия (карточки) -->
                    <div class="d-md-none">
                        <?php foreach($orders as $order): ?>
                            <div class="order-card">
                                <div class="order-header">
                                    <strong>№<?= htmlspecialchars($order['order_number']) ?></strong>
                                    <span><?= date('d.m.Y', strtotime($order['created_at'])) ?></span>
                                </div>
                                <div class="order-body">
                                    <div>
                                        <small class="text-muted">Сумма</small>
                                        <div><?= number_format($order['total'], 2) ?> ₽</div>
                                    </div>
                                    <div>
                                        <small class="text-muted">Статус</small>
                                        <div>
                                            <span class="badge <?= match($order['status']) {
                                                'new' => 'badge-new',
                                                'processing' => 'badge-processing',
                                                'shipped' => 'badge-shipped',
                                                'ready_for_pickup' => 'badge-ready', // Новый статус
                                                'completed' => 'badge-completed',
                                                'canceled' => 'badge-canceled',
                                                default => 'bg-secondary'
                                            } ?>">
                                                <?= match($order['status']) {
                                                    'new' => 'Новый',
                                                    'processing' => 'Готовится к отправке', // Обновленный текст
                                                    'shipped' => 'Отправлен',
                                                    'ready_for_pickup' => 'Готов к выдаче', // Новый статус
                                                    'completed' => 'Завершен',
                                                    'canceled' => 'Отменен',
                                                    default => $order['status']
                                                } ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div>
                                        <small class="text-muted">Трек-номер</small>
                                        <div>
                                            <?php if(!empty($order['tracking_number'])): ?>
                                                <?php
                                                $tracking_urls = [
                                                    'cdek' => 'https://www.cdek.ru/ru/tracking?order_id=',
                                                    'post' => 'https://www.pochta.ru/tracking?barcode='
                                                ];
                                                $tracking_url = ($order['delivery_service'] === 'pickup' || empty($order['delivery_service']) || !isset($tracking_urls[$order['delivery_service']]))
                                                    ? '#'
                                                    : $tracking_urls[$order['delivery_service']] . $order['tracking_number'];
                                                ?>
                                                <a href="<?= $tracking_url ?>" 
                                                   target="_blank" 
                                                   class="tracking-link <?= $tracking_url === '#' ? 'text-muted' : '' ?>">
                                                    <?= $order['tracking_number'] ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <small class="text-muted">Транспортная компания</small>
                                        <div>
                                            <?= !empty($order['delivery_service']) && isset($delivery_service_map[$order['delivery_service']]) 
                                                ? htmlspecialchars($delivery_service_map[$order['delivery_service']]) 
                                                : '<span class="text-muted">—</span>' ?>
                                        </div>
                                    </div>
                                    <div>
                                        <small class="text-muted">Адрес ПВЗ</small>
                                        <div>
                                            <?= !empty($order['pickup_address']) 
                                                ? htmlspecialchars($order['pickup_address']) 
                                                : '<span class="text-muted">—</span>' ?>
                                        </div>
                                    </div>
                                    <div>
                                        <button class="btn btn-sm btn-outline-primary details-btn mt-2" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#orderDetailsModal"
                                                data-order-id="<?= $order['id'] ?>">
                                            Подробнее
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4 py-md-5">
                        <i class="bi bi-box-seam fs-1 text-muted mb-3"></i>
                        <h4 class="text-dark mb-3">У вас пока нет заказов</h4>
                        <p class="text-muted mb-4">Совершите покупки в нашем каталоге, чтобы увидеть здесь свои заказы</p>
                        <a href="index.php#products" class="btn btn-primary rounded-pill px-4">
                            <i class="bi bi-basket me-2"></i>Перейти в каталог
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Вкладка профиля -->
            <div class="tab-pane fade" id="profile" role="tabpanel" aria-labelledby="profile-tab">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <?php if (isset($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="profile-form">
                    <h3 class="mb-4">Изменить данные профиля</h3>
                    <form method="POST" class="needs-validation" novalidate>
                        <input type="hidden" name="action" value="change_profile">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <div class="mb-3">
                            <label for="new_username" class="form-label">Имя пользователя</label>
                            <input type="text" class="form-control" id="new_username" name="new_username" 
                                   value="<?= htmlspecialchars($_SESSION['username']) ?>" required
                                   pattern="[A-Za-zА-Яа-я0-9_-]{3,50}">
                            <div class="invalid-feedback">
                                Имя пользователя должно содержать 3-50 символов (буквы, цифры, дефис, подчеркивание)
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="new_full_name" class="form-label">ФИО</label>
                            <input type="text" class="form-control" id="new_full_name" name="new_full_name" 
                                   value="<?= htmlspecialchars($current_full_name) ?>" required
                                   pattern="[А-Яа-я\s-]{3,100}">
                            <div class="invalid-feedback">
                                ФИО должно содержать 3-100 символов (только буквы, пробелы, дефис)
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary rounded-pill">Сохранить</button>
                    </form>

                    <hr class="my-5">

                    <h3 class="mb-4">Изменить пароль</h3>
                    <form method="POST" class="needs-validation" novalidate>
                        <input type="hidden" name="action" value="change_password">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Текущий пароль</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                            <div class="invalid-feedback">
                                Введите текущий пароль
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="new_password" class="form-label">Новый пароль</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" 
                                   required pattern=".{8,}">
                            <div class="invalid-feedback">
                                Пароль должен содержать минимум 8 символов
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Подтверждение пароля</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                   required pattern=".{8,}">
                            <div class="invalid-feedback">
                                Подтвердите новый пароль
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary rounded-pill">Сохранить</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Modal for Order Details -->
<div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-labelledby="orderDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="orderDetailsModalLabel">Детали заказа</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="order-details-content">
                    <p>Загрузка...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.details-btn').forEach(button => {
    button.addEventListener('click', function() {
        const orderId = this.getAttribute('data-order-id');
        const modalContent = document.getElementById('order-details-content');
        modalContent.innerHTML = '<p>Загрузка...</p>';

        fetch('get_order_details.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'order_id=' + encodeURIComponent(orderId)
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                modalContent.innerHTML = `<p class="text-danger">${data.error}</p>`;
                return;
            }

            let html = `
                <h6>Заказ №${data.order_number}</h6>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Тип</th>
                            <th>Наименование</th>
                            <th>Количество</th>
                            <th>Цена за единицу</th>
                            <th>Итого</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            data.items.forEach(item => {
                html += `
                    <tr>
                        <td>${item.type}</td>
                        <td>${item.name}</td>
                        <td>${item.quantity}</td>
                        <td>${item.price} ₽</td>
                        <td>${(item.quantity * item.price).toFixed(2)} ₽</td>
                    </tr>
                `;
            });

            html += `
                    </tbody>
                </table>
                <p class="fw-bold">Общая сумма: ${data.total} ₽</p>
            `;
            modalContent.innerHTML = html;
        })
        .catch(error => {
            modalContent.innerHTML = '<p class="text-danger">Ошибка загрузки данных</p>';
            console.error('Error:', error);
        });
    });
});

// Валидация форм
document.querySelectorAll('.needs-validation').forEach(form => {
    form.addEventListener('submit', function(event) {
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        }
        form.classList.add('was-validated');
    }, false);
});

// Показ уведомлений
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-white bg-${type} border-0`;
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">${message}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;
    document.body.appendChild(toast);
    new bootstrap.Toast(toast, { autohide: true, delay: 3000 }).show();
    setTimeout(() => toast.remove(), 3500);
}
</script>
</body>
</html>