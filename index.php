<?php
session_start();
require 'includes/config.php';
require 'includes/functions.php';

// Проверка авторизации
$is_logged_in = isset($_SESSION['user_id']);

// Обработка входных данных
$search = isset($_GET['search']) ? clean_input($_GET['search']) : '';
$type_filter = isset($_GET['type']) ? clean_input($_GET['type']) : 'all';
$current_sort = isset($_GET['sort']) ? clean_input($_GET['sort']) : 'price_asc';
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$items_per_page = 10;

// Генерация CSRF-токена
$csrf_token = generate_csrf_token();

// Опции сортировки
$sort_options = [
    'price_asc'  => ['field' => 'price', 'dir' => 'ASC'],
    'price_desc' => ['field' => 'price', 'dir' => 'DESC'],
    'diameter'   => ['field' => 'diameter', 'dir' => 'DESC']
];
$order = $sort_options[$current_sort] ?? $sort_options['price_asc'];

// Проверка допустимых типов
$allowed_types = ['all', 'bullet'];
if (!in_array($type_filter, $allowed_types)) {
    $type_filter = 'all';
}

// Формирование условий SQL
$sql_where = [];
$params = [];

if ($type_filter !== 'all') {
    $sql_where[] = "type = :type";
    $params[':type'] = $type_filter;
}

if (!empty($search)) {
    // Поиск по name, description и vendor_code с уникальными параметрами
    $sql_where[] = "(name LIKE :search_name OR description LIKE :search_desc OR vendor_code LIKE :search_vendor)";
    $params[':search_name'] = "%$search%";
    $params[':search_desc'] = "%$search%";
    $params[':search_vendor'] = "%$search%";
}

try {
    // SQL-запрос для товаров
    $sql = "SELECT * FROM products" . 
        (!empty($sql_where) ? " WHERE " . implode(" AND ", $sql_where) : "") . 
        " ORDER BY {$order['field']} {$order['dir']} 
        LIMIT :limit OFFSET :offset";
    
    // Подготовка запроса
    $stmt = $pdo->prepare($sql);
    
    // Привязка параметров
    if (!empty($search)) {
        $stmt->bindValue(':search_name', $params[':search_name'], PDO::PARAM_STR);
        $stmt->bindValue(':search_desc', $params[':search_desc'], PDO::PARAM_STR);
        $stmt->bindValue(':search_vendor', $params[':search_vendor'], PDO::PARAM_STR);
    }
    if ($type_filter !== 'all') {
        $stmt->bindValue(':type', $params[':type'], PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($current_page - 1) * $items_per_page, PDO::PARAM_INT);
    
    // Отладка SQL
    error_log("SQL Query: $sql");
    error_log("Parameters: " . print_r($params, true));
    
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Подсчет общего количества товаров для пагинации
    $count_sql = "SELECT COUNT(*) FROM products" . 
        (!empty($sql_where) ? " WHERE " . implode(" AND ", $sql_where) : "");
    $count_stmt = $pdo->prepare($count_sql);
    if (!empty($search)) {
        $count_stmt->bindValue(':search_name', $params[':search_name'], PDO::PARAM_STR);
        $count_stmt->bindValue(':search_desc', $params[':search_desc'], PDO::PARAM_STR);
        $count_stmt->bindValue(':search_vendor', $params[':search_vendor'], PDO::PARAM_STR);
    }
    if ($type_filter !== 'all') {
        $count_stmt->bindValue(':type', $params[':type'], PDO::PARAM_STR);
    }
    $count_stmt->execute();
    $total_items = $count_stmt->fetchColumn();

} catch (PDOException $e) {
    error_log("Ошибка загрузки данных: " . $e->getMessage());
    die("Ошибка загрузки данных: " . htmlspecialchars($e->getMessage()));
}

// Получение услуг
try {
    $service_stmt = $pdo->prepare("SELECT * FROM services");
    $service_stmt->execute();
    $services = $service_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Ошибка загрузки услуг: " . $e->getMessage());
    $services = [];
}
?>

<!DOCTYPE html>
<html lang="ru">
<!-- Yandex.Metrika counter -->
<script type="text/javascript">
   (function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
   m[i].l=1*new Date();
   for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
   k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
   (window, document, "script", "https://mc.yandex.ru/metrika/tag.js", "ym");

   ym(102526753, "init", {
        clickmap:true,
        trackLinks:true,
        accurateTrackBounce:true,
        webvisor:true
   });
</script>
<noscript><div><img src="https://mc.yandex.ru/watch/102526753" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
<!-- /Yandex.Metrika counter -->
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>pnevmatpro.ru - Ремонт пневматики от Сарыча</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/index.css?v=1" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="icon" href="https://pnevmatpro.ru/assets/favicon.png" type="image/png">
</head>
<body>
<!-- Модальное окно заказа услуги -->
<div class="modal fade" id="orderServiceModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Оформление заказа услуги</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="serviceOrderForm" class="needs-validation" novalidate>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="fullname" class="form-label">ФИО</label>
                        <input type="text" class="form-control" id="fullname" name="fullname" 
                               value="<?= htmlspecialchars($_SESSION['fullname'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                        <div class="invalid-feedback">Введите ваше ФИО (3-50 символов, только буквы и пробелы).</div>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" 
                               value="<?= htmlspecialchars($_SESSION['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                        <div class="invalid-feedback">Введите действительный email.</div>
                    </div>
                    <div class="mb-3">
                        <label for="phone" class="form-label">Телефон</label>
                        <input type="tel" class="form-control" id="phone" name="phone" 
                               value="<?= htmlspecialchars($_SESSION['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                        <div class="invalid-feedback">Введите действительный номер телефона (10-15 цифр).</div>
                    </div>
                    <div class="mb-3">
                        <label for="delivery_company" class="form-label">Служба доставки</label>
                        <select class="form-select" id="delivery_company" name="delivery_company" required>
                            <option value="" disabled selected>Выберите службу доставки</option>
                            <option value="cdek">CDEK</option>
                            <option value="post">Почта России</option>
                        </select>
                        <div class="invalid-feedback">Выберите службу доставки.</div>
                    </div>
                    <div class="mb-3" id="pickup_point_field">
                        <label for="pickup_point" class="form-label">Адрес пункта выдачи</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="pickup_point" name="pickup_point" readonly required>
                            <button type="button" class="btn btn-outline-primary" id="select_pvz">Выбрать ПВЗ</button>
                        </div>
                        <div class="invalid-feedback">Укажите адрес пункта выдачи.</div>
                    </div>
                    <input type="hidden" name="service_id" id="serviceIdInput">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                    <button type="submit" class="btn btn-primary">Оформить заказ</button>
                    <p class="text-muted"><small>Стоимость указана без учёта доставки</small></p>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Модальное окно для выбора ПВЗ -->
<div class="modal fade" id="pvz_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Выберите пункт выдачи</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="pvz_search" class="form-label">Город или индекс</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="pvz_search" placeholder="Введите город или индекс">
                        <button type="button" class="btn btn-primary" id="search_pvz">Найти</button>
                    </div>
                </div>
                <div id="pvz_list" class="list-group"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно для подробного описания -->
<div class="modal fade" id="productDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="productDetailTitle"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="productDetailBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<!-- Шапка -->
<header class="hero bg-dark text-white">
    <div class="container text-center position-relative">
        <img src="assets/logo.png" alt="Logo" width="400" class="mb-4 logo-float">
        <p class="lead mb-4">Профессиональный ремонт пневматики и продажа комплектующих</p>
        
        <div class="d-flex flex-wrap gap-2 justify-content-center">
            <div class="d-flex flex-wrap gap-2 justify-content-center">
                <a href="#services" class="btn btn-lg btn-primary rounded-pill">
                    <i class="bi bi-tools me-2"></i>Услуги
                </a>
                <a href="#products" class="btn btn-lg btn-success rounded-pill">
                    <i class="bi bi-basket me-2"></i>Каталог
                </a>
                <a href="cart/cart.php" class="btn btn-lg btn-warning position-relative rounded-pill">
                    <i class="bi bi-cart me-2"></i>Корзина
                    <span class="cart-badge badge bg-danger">
                        <?= array_sum(array_column($_SESSION['cart'] ?? [], 'quantity')) ?>
                    </span>
                </a>
            </div>

            <div class="auth-buttons d-flex gap-2 align-items-center mt-2 mt-md-0">
                <?php if ($is_logged_in): ?>
                    <div class="d-flex flex-wrap gap-2 align-items-center justify-content-center">
                        <a href="orders.php" class="btn btn-outline-light btn-sm rounded-pill">
                            <i class="bi bi-person-circle me-2"></i>Личный кабинет
                        </a>
                        <span class="text-white"><?= htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                        <a href="logout.php" class="btn btn-outline-light btn-sm rounded-pill">
                            <i class="bi bi-box-arrow-right"></i>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                        <a href="login.php" class="btn btn-outline-light btn-sm rounded-pill">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Вход
                        </a>
                        <a href="register.php" class="btn btn-outline-light btn-sm rounded-pill">
                            <i class="bi bi-person-plus me-2"></i>Регистрация
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>

<!-- Секция услуг -->
<section id="services" class="py-5 bg-white">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="display-4 mb-3 text-dark fw-bold">Наши услуги</h2>
            <div class="decorative-line mx-auto bg-accent"></div>
            <p class="lead text-muted mt-3">Профессиональное обслуживание пневматики</p>
        </div>

        <div class="row g-4 justify-content-center">
            <?php foreach ($services as $service): ?>
            <div class="col-lg-4 col-md-6 col-12">
                <div class="parent">
                    <div class="card h-100 shadow-sm border-0 product-card">
                        <?php 
                        $imagePath = !empty($service['image']) ? 'uploads/products/' . $service['image'] : '';
                        if (!empty($imagePath) && file_exists($imagePath)): ?>
                            <img src="<?= htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8') ?>" 
                                 class="card-img-top" 
                                 alt="<?= !empty($service['name']) ? htmlspecialchars($service['name'], ENT_QUOTES, 'UTF-8') : 'Услуга' ?>" 
                                 loading="lazy">
                        <?php else: ?>
                            <div class="img-placeholder bg-light">
                                <i class="bi bi-tools fs-1 text-accent"></i>
                            </div>
                        <?php endif; ?>
                        
                        <div class="card-body text-center">
                            <h3 class="h4 mb-3 text-dark">
                                <?= !empty($service['name']) ? htmlspecialchars($service['name'], ENT_QUOTES, 'UTF-8') : 'Услуга без названия' ?>
                            </h3>
                            <p class="card-text text-secondary mb-3">
                                <?= !empty($service['description']) ? htmlspecialchars($service['description'], ENT_QUOTES, 'UTF-8') : 'Описание отсутствует' ?>
                            </p>
                            
                            <div class="service-meta mb-3">
                                <div class="d-flex justify-content-between">
                                    <?php if (!empty($service['duration'])): ?>
                                    <span class="badge bg-info text-dark">
                                        <i class="bi bi-clock me-1"></i>
                                        <?php
                                        $days = (int)$service['duration'];
                                        $dayText = getDayText($days);
                                        echo "$days $dayText";
                                        ?>
                                    </span>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($service['service_type'])): ?>
                                    <span class="badge bg-warning text-dark">
                                        <?= match ($service['service_type']) {
                                            'repair' => 'Ремонт',
                                            'maintenance' => 'Обслуживание',
                                            'custom' => 'Индивидуально',
                                            default => 'Услуга'
                                        } ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="h4 text-accent">
                                    <?= number_format((float)($service['price'] ?? 0), 2) ?> ₽
                                </span>
                                <button class="btn btn-primary rounded-pill order-service"
                                        data-service-id="<?= (int)($service['id'] ?? 0) ?>"
                                        data-service-name="<?= !empty($service['name']) ? htmlspecialchars($service['name'], ENT_QUOTES, 'UTF-8') : '' ?>"
                                        <?= !$is_logged_in ? 'disabled title="Требуется авторизация"' : '' ?>>
                                    <i class="bi bi-briefcase me-2"></i>
                                    <?= $is_logged_in ? 'Заказать' : 'Войдите' ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Секция преимуществ -->
<section class="py-5 bg-light">
    <div class="container">
        <div class="row g-4 py-lg-5">
            <div class="col-md-4 col-12">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body text-center p-4">
                        <div class="icon-wrapper mb-3 bg-accent-light">
                            <i class="bi bi-shield-check fs-1 text-accent"></i>
                        </div>
                        <h3 class="h5 text-dark mb-2">Гарантированное качество</h3>
                        <p class="text-muted mb-0 fs-5">
                            Многоступенчатый контроль каждой единицы товара
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-4 col-12">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body text-center p-4">
                        <div class="icon-wrapper mb-3 bg-accent-light">
                            <i class="bi bi-lightning-charge fs-1 text-accent"></i>
                        </div>
                        <h3 class="h5 text-dark mb-2">Экспресс-доставка</h3>
                        <p class="text-muted mb-0 fs-5">
                            Отправка в течение 2 часов после оформления заказа
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-4 col-12">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body text-center p-4">
                        <div class="icon-wrapper mb-3 bg-accent-light">
                            <i class="bi bi-headset fs-1 text-accent"></i>
                        </div>
                        <h3 class="h5 text-dark mb-2">Круглосуточная поддержка</h3>
                        <p class="text-muted mb-0 fs-5">
                            Профессиональная помощь 24/7 через любые каналы связи
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Секция товаров -->
<section id="products" class="py-5 bg-white">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="display-4 mb-3 text-dark fw-bold">
                <?= match ($type_filter) {
                    'bullet' => 'Пульки для пневматики',
                    default => 'Все товары'
                } ?>
            </h2>
            <div class="decorative-line mx-auto bg-accent"></div>
            <p class="lead text-muted mt-3">Широкий ассортимент качественных товаров</p>
        </div>

        <!-- Фильтры и поиск -->
        <div class="row mb-4">
            <!-- Поиск по названию, описанию и артикулу -->
            <div class="col-md-6 mb-3">
                <form method="GET" class="d-flex">
                    <input type="text" name="search" class="form-control me-2 rounded-pill" 
                           placeholder="Поиск по названию, описанию или артикулу" 
                           value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn btn-primary rounded-pill">
                        <i class="bi bi-search"></i>
                    </button>
                </form>
            </div>
            
            <!-- Фильтр по типу товара -->
            <div class="col-md-3 mb-3">
                <form method="GET" class="d-flex">
                    <input type="hidden" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                    <select name="type" class="form-select rounded-pill" onchange="this.form.submit()">
                        <option value="all" <?= $type_filter === 'all' ? 'selected' : '' ?>>Все товары</option>
                        <option value="bullet" <?= $type_filter === 'bullet' ? 'selected' : '' ?>>Пульки</option>
                    </select>
                </form>
            </div>
            
            <!-- Сортировка товаров -->
            <div class="col-md-3 mb-3">
                <form method="GET" class="d-flex">
                    <input type="hidden" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="type" value="<?= htmlspecialchars($type_filter, ENT_QUOTES, 'UTF-8') ?>">
                    <select name="sort" class="form-select rounded-pill" onchange="this.form.submit()">
                        <option value="price_asc" <?= $current_sort === 'price_asc' ? 'selected' : '' ?>>
                            Цена (по возрастанию)
                        </option>
                        <option value="price_desc" <?= $current_sort === 'price_desc' ? 'selected' : '' ?>>
                            Цена (по убыванию)
                        </option>
                        <option value="diameter" <?= $current_sort === 'diameter' ? 'selected' : '' ?>>
                            Диаметр (большие сначала)
                        </option>
                    </select>
                </form>
            </div>
        </div>
        
        <!-- Активные фильтры -->
        <?php if (!empty($search) || $type_filter !== 'all' || $current_sort !== 'price_asc'): ?>
        <div class="alert alert-info d-flex align-items-center mb-4">
            <i class="bi bi-funnel me-2"></i>
            <span class="me-2">Активные фильтры:</span>
            <div class="d-flex flex-wrap gap-2">
                <?php if (!empty($search)): ?>
                    <span class="badge bg-primary">
                        Поиск: "<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                    </span>
                <?php endif; ?>
                <?php if ($type_filter !== 'all'): ?>
                    <span class="badge bg-primary">
                        Тип: <?= $type_filter === 'bullet' ? 'Пульки' : $type_filter ?>
                    </span>
                <?php endif; ?>
                <?php if ($current_sort !== 'price_asc'): ?>
                    <span class="badge bg-primary">
                        Сортировка: <?= match ($current_sort) {
                            'price_desc' => 'Цена по убыванию',
                            'diameter' => 'Диаметр',
                            default => 'Неизвестно'
                        } ?>
                    </span>
                <?php endif; ?>
            </div>
            <a href="?" class="ms-3 text-danger">
                <i class="bi bi-x-circle me-1"></i> Сбросить
            </a>
        </div>
        <?php endif; ?>

        <?php if (!empty($products)): ?>
            <div class="row g-4 justify-content-center">
                <?php foreach ($products as $product): ?>
                <div class="col-lg-4 col-md-6 col-12">
                    <div class="parent">
                        <div class="card h-100 shadow-sm border-0 product-card">
                            <div class="position-relative">
                                <?php 
                                $filePath = $_SERVER['DOCUMENT_ROOT'] . '/uploads/products/' . ($product['image'] ?? '');
                                $imageUrl = !empty($product['image']) ? '/uploads/products/' . $product['image'] : '';
                                ?>
                                <!-- Отладка: логирование данных изображения -->
                                <?php error_log("Product image: imageUrl=$imageUrl, filePath=$filePath"); ?>
                                <?php if (!empty($imageUrl) && file_exists($filePath)): ?>
                                    <img src="<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>" 
                                         class="card-img-top" 
                                         alt="<?= !empty($product['name']) ? htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') : 'Товар' ?>" 
                                         loading="lazy">
                                <?php else: ?>
                                    <div class="img-placeholder bg-light">
                                        <i class="bi bi-box-seam fs-1 text-accent"></i>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($product['diameter'])): ?>
                                    <span class="badge bg-accent text-white position-absolute top-0 end-0 m-2">
                                        Ø <?= number_format((float)$product['diameter'], 2) ?> мм
                                    </span>
                                <?php endif; ?>
                                
                                <?php
                                $availability_badge_class = match ($product['availability'] ?? '') {
                                    'in_stock' => 'bg-success',
                                    'pre_order' => 'bg-warning text-dark',
                                    'out_of_stock' => 'bg-danger',
                                    default => 'bg-secondary'
                                };
                                $availability_text = match ($product['availability'] ?? '') {
                                    'in_stock' => 'В наличии',
                                    'pre_order' => 'Под заказ',
                                    'out_of_stock' => 'Нет в наличии',
                                    default => 'Доступность неизвестна'
                                };
                                ?>
                                <span class="badge position-absolute top-0 start-0 m-2 <?= $availability_badge_class ?>">
                                    <?= $availability_text ?>
                                </span>
                            </div>
                            <div class="card-body text-center">
                                <h5 class="card-title text-dark">
                                    <?= !empty($product['name']) ? htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') : 'Без названия' ?>
                                </h5>
                                <?php 
                                $description = !empty($product['description']) ? htmlspecialchars($product['description'], ENT_QUOTES, 'UTF-8') : 'Описание отсутствует';
                                $max_length = 100;
                                $short_description = strlen($description) > $max_length ? substr($description, 0, $max_length) . '...' : $description;
                                ?>
                                <p class="card-text text-secondary mb-3 description-short"><?= $short_description ?></p>
                                <?php if (strlen($description) > $max_length): ?>
                                    <button class="btn detail-btn"
                                            data-product-name="<?= htmlspecialchars($product['name'] ?? 'Без названия', ENT_QUOTES, 'UTF-8') ?>"
                                            data-product-description="<?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?>">
                                        Подробнее
                                    </button>
                                <?php endif; ?>
                                
                                <div class="product-specs mb-3">
                                    <div class="d-flex justify-content-between">
                                        <?php if (!empty($product['weight'])): ?>
                                            <span class="badge bg-light text-dark">
                                                <i class="bi bi-speedometer2 me-1"></i>
                                                <?= number_format((float)$product['weight'], 2) ?> г
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($product['vendor_code'])): ?>
                                            <span class="badge bg-light text-dark">
                                                <i class="bi bi-upc-scan me-1"></i>
                                                <?= htmlspecialchars($product['vendor_code'], ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="h4 text-accent">
                                        <?= number_format((float)($product['price'] ?? 0), 2) ?> ₽
                                    </span>
                                    <button class="btn btn-primary rounded-pill add-to-cart"
                                            data-product-id="<?= (int)($product['id'] ?? 0) ?>"
                                            <?= !$is_logged_in ? 'disabled title="Требуется авторизация"' : '' ?>
                                            <?= ($product['availability'] ?? '') !== 'in_stock' ? 'disabled title="Товар недоступен"' : '' ?>>
                                        <i class="bi bi-cart-plus me-2"></i>
                                        <?= $is_logged_in ? 
                                            (($product['availability'] ?? '') === 'in_stock' ? 'В корзину' : 'Недоступно') : 
                                            'Войдите' ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($total_items > $items_per_page): ?>
            <nav class="mt-5">
                <ul class="pagination justify-content-center flex-wrap">
                    <?php for ($i = 1; $i <= ceil($total_items / $items_per_page); $i++): ?>
                    <li class="page-item <?= $i === $current_page ? 'active' : '' ?>">
                        <a class="page-link rounded-pill mx-1 border-accent" 
                           href="?page=<?= $i ?>&type=<?= htmlspecialchars($type_filter, ENT_QUOTES, 'UTF-8') ?>&sort=<?= htmlspecialchars($current_sort, ENT_QUOTES, 'UTF-8') ?>&search=<?= urlencode($search) ?>">
                            <?= $i ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                </ul>
            </nav>
            <?php endif; ?>

        <?php else: ?>
            <div class="alert alert-warning">Товары не найдены</div>
        <?php endif; ?>
    </div>
</section>

<!-- Футер -->
<footer class="bg-dark text-white py-4">
    <div class="container text-center">
        <p>© 2025 pnevmatpro.ru. «пневматПро», ИНН:345801776616</p>
        <p>
            <a href="/privacy-policy.php" class="text-white text-decoration-none">Политика конфиденциальности</a>
        </p>
        <p>Email: saport.pnevmatpro@gmail.com</p>
    </div>
</footer>

<!-- Privacy Consent Card -->
<div class="privacy-overlay" id="privacyOverlay">
    <div class="card privacy-card">
        <div class="content">
            <span class="icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" height="46" width="65">
                    <path stroke="#000" fill="#EAB789" d="M49.157 15.69L44.58.655l-12.422 1.96L21.044.654l-8.499 2.615-6.538 5.23-4.576 9.153v11.114l4.576 8.5 7.846 5.23 10.46 1.96 7.845-2.614 9.153 2.615 11.768-2.615 7.846-7.846 1.96-5.884.655-7.191-7.846-1.308-6.537-3.922z"></path>
                    <path fill="#9C6750" d="M32.286 3.749c-6.94 3.65-11.69 11.053-11.69 19.591 0 8.137 4.313 15.242 10.724 19.052a20.513 20.513 0 01-8.723 1.937c-11.598 0-21-9.626-21-21.5 0-11.875 9.402-21.5 21-21.5 3.495 0 6.79.874 9.689 2.42z" clip-rule="evenodd" fill-rule="evenodd"></path>
                    <path fill="#634647" d="M64.472 20.305a.954.954 0 00-1.172-.824 4.508 4.508 0 01-3.958-.934.953.953 0 00-1.076-.11c-.46.252-.977.383-1.502.382a3.154 3.154 0 01-2.97-2.11.954.954 0 00-.833-.634a4.54 4.54 0 01-4.205-4.507c.002-.23.022-.46.06-.687a.952.952 0 00-.213-.767 3.497 3.497 0 01-.614-3.5.953.953 0 00-.382-1.138 3.522 3.522 0 01-1.5-3.992.951.951 0 00-.762-1.227A22.611 22.611 0 0032.3 2.16 22.41 22.41 0 0022.657.001a22.654 22.654 0 109.648 43.15 22.644 22.644 0 0032.167-22.847zM22.657 43.4a20.746 20.746 0 110-41.493c2.566-.004 5.11.473 7.501 1.407a22.64 22.64 0 00.003 38.682 20.6 20.6 0 01-7.504 1.404zm19.286 0a20.746 20.746 0 112.131-41.384 5.417 5.417 0 001.918 4.635 5.346 5.346 0 00-.133 1.182A5.441 5.441 0 0046.879 11a5.804 5.804 0 00-.028.568 6.456 6.456 0 005.38 6.345 5.053 5.053 0 006.378 2.472 6.412 6.412 0 004.05 1.12 20.768 20.768 0 01-20.716 21.897z"></path>
                    <path fill="#644647" d="M54.962 34.3a17.719 17.719 0 01-2.602 2.378.954.954 0 001.14 1.53 19.637 19.637 0 002.884-2.634.955.955 0 00-1.422-1.274z"></path>
                    <path stroke-width="1.8" stroke="#644647" fill="#845556" d="M44.5 32.829c-.512 0-1.574.215-2 .5-.426.284-.342.263-.537.736a2.59 2.59 0 104.98.99c0-.686-.458-1.241-.943-1.726-.485-.486-.814-.5-1.5-.5zm-30.916-2.5c-.296 0-.912.134-1.159.311-.246.177-.197.164-.31.459a1.725 1.725 0 00-.086.932c.058.312.2.6.41.825.21.226.477.38.768.442.291.062.593.03.867-.092s.508-.329.673-.594a1.7 1.7 0 00.253-.896c0-.428-.266-.774-.547-1.076-.281-.302-.471-.31-.869-.311zm17.805-11.375c-.143-.492-.647-1.451-1.04-1.78-.392-.33-.348-.255-.857-.31a2.588 2.588 0 10.441 5.06c.66-.194 1.064-.788 1.395-1.39.33-.601.252-.92.06-1.58zm-22 2c-.143-.492-.647-1.451-1.04-1.78-.391-.33-.347-.255-.856-.31a2.589 2.589 0 10.44 5.06c.66-.194 1.064-.788 1.395-1.39.33-.601.252-.92.06-1.58zM38.112 7.329c-.395 0-1.216.179-1.545.415-.328.236-.263.218-.415.611-.151.393-.19.826-.114 1.243.078.417.268.8.548 1.1.28.301.636.506 1.024.59.388.082.79.04 1.155-.123.366-.163.678-.438.898-.792.22-.354.337-.77.337-1.195 0-.57-.354-1.031-.73-1.434-.374-.403-.628-.415-1.158-.415zm-19.123.703c.023-.296-.062-.92-.219-1.18-.157-.26-.148-.21-.432-.347a1.726 1.726 0 00-.922-.159 1.654 1.654 0 00-.856.344 1.471 1.471 0 00-.501.73c-.085.285-.077.589.023.872.1.282.287.532.538.718a1.7 1.7 0 00.873.323c.427.033.793-.204 1.116-.46.324-.256.347-.445.38-.841z"></path>
                    <path fill="#634647" d="M15.027 15.605a.954.954 0 00-1.553 1.108l1.332 1.863a.955.955 0 001.705-.77.955.955 0 00-.153-.34l-1.331-1.861z"></path>
                    <path fill="#644647" d="M43.31 23.21a.954.954 0 101.553-1.11l-1.266-1.772a.954.954 0 10-1.552 1.11l1.266 1.772z"></path>
                    <path fill="#644647" d="M19.672 35.374a.954.954 0 00-.954.953v2.363a.954.954 0 001.907 0v-2.362a.954.954 0 00-.953-.954z"></path>
                    <path fill="#644647" d="M33.129 29.18l-2.803 1.065a.953.953 0 00-.053 1.764.957.957 0 00.73.022l2.803-1.065a.953.953 0 00-.677-1.783v-.003zm24.373-3.628l-2.167.823a.956.956 0 00-.054 1.764.954.954 0 00.73.021l2.169-.823a.954.954 0 10-.678-1.784v-.001z"></path>
                </svg>
            </span>

            <p class="title">Ваша конфиденциальность важна для нас</p>

            <p class="description">
                Мы обрабатываем вашу личную информацию для анализа и улучшения наших сайтов и услуг, поддержки рекламных кампаний и предоставления персонализированного контента.
                <br />
                Для получения дополнительной информации ознакомьтесь с нашей
                <a href="/privacy-policy.php" class="privacy-link">Политикой конфиденциальности</a>.
            </p>

            <button class="more-options" aria-label="Подробнее о политике конфиденциальности">Подробнее</button>
            <button class="accept-button" type="button" aria-label="Принять политику конфиденциальности">Принять</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    console.log('DOMContentLoaded event fired');

    // Privacy Consent Handling
    function initPrivacyConsent() {
        console.log('Initializing privacy consent logic');
        const privacyOverlay = document.getElementById('privacyOverlay');
        const acceptButton = document.querySelector('.accept-button');
        const moreOptionsButton = document.querySelector('.more-options');

        if (!privacyOverlay || !acceptButton || !moreOptionsButton) {
            console.error('Privacy consent elements not found');
            return;
        }

        // Check localStorage for consent
        let hasConsent = false;
        try {
            hasConsent = localStorage.getItem('privacyConsent') === 'accepted';
            console.log('Privacy consent status:', hasConsent ? 'Accepted' : 'Not accepted');
        } catch (e) {
            console.warn('localStorage access error:', e);
        }

        // Show privacy card if no consent
        if (!hasConsent) {
            privacyOverlay.style.display = 'flex';
            console.log('Showing privacy consent card');
        } else {
            privacyOverlay.style.display = 'none';
            console.log('Hiding privacy consent card (consent already given)');
        }

        // Handle "Accept" button click
        acceptButton.addEventListener('click', () => {
            try {
                localStorage.setItem('privacyConsent', 'accepted');
                console.log('Privacy consent accepted');
            } catch (e) {
                console.warn('Error setting localStorage:', e);
            }
            privacyOverlay.style.display = 'none';
            showToast('Политика конфиденциальности принята!', 'success');
        });

        // Handle "More Options" button click
        moreOptionsButton.addEventListener('click', () => {
            console.log('More options clicked, redirecting to /privacy-policy.php');
            window.location.href = '/privacy-policy.php';
        });
    }

    // Initialize other page functionality
    const isLoggedIn = <?= $is_logged_in ? 'true' : 'false' ?>;
    const cartBadges = document.querySelectorAll('.cart-badge');
    const orderServiceModal = new bootstrap.Modal('#orderServiceModal', { backdrop: 'static', keyboard: false });
    const pvzModal = new bootstrap.Modal('#pvz_modal', { backdrop: 'static', keyboard: false });
    const productDetailModal = new bootstrap.Modal('#productDetailModal', { backdrop: 'static', keyboard: false });
    const serviceIdInput = document.getElementById('serviceIdInput');
    let currentServiceName = '';
    let isSubmitting = false;
    let isSearchingPvz = false;
    let isOpeningPvz = false;

    const updateCartBadge = (count) => {
        cartBadges.forEach(badge => badge.textContent = count);
    };

    const showToast = (message, type = 'info') => {
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
    };

    $('#delivery_company').off('change').on('change', function() {
        const value = $(this).val();
        const $pickupField = $('#pickup_point_field');
        const $selectPvzButton = $('#select_pvz');

        $pickupField.toggle(value !== '');
        $('#pickup_point').prop('required', value !== '');

        const shouldShowButton = (value === 'cdek' || value === 'post');
        $selectPvzButton.toggle(shouldShowButton);

        if (!shouldShowButton) {
            $('#pickup_point').val('').prop('readonly', false);
        }

        console.log('Служба доставки:', value, 'Поле ПВЗ видимо:', $pickupField.is(':visible'), 'Кнопка ПВЗ видимо:', $selectPvzButton.is(':visible'));
    }).trigger('change');

    $('#select_pvz').off('click').on('click', function() {
        if (isOpeningPvz || pvzModal._isShown) return;
        isOpeningPvz = true;

        orderServiceModal.hide();
        pvzModal.show();
        console.log('Модальное окно ПВЗ открыто');

        setTimeout(() => { isOpeningPvz = false; }, 300);
    });

    $('#search_pvz').off('click').on('click', function() {
        if (isSearchingPvz) return;
        isSearchingPvz = true;

        const query = $('#pvz_search').val().trim();
        const delivery_company = $('#delivery_company').val();
        const csrf_token = $('input[name="csrf_token"]').val();

        if (!query) {
            $('#pvz_list').html('<div class="alert alert-warning">Введите город или индекс.</div>');
            console.log('Пустой запрос для поиска ПВЗ');
            isSearchingPvz = false;
            return;
        }

        if (!csrf_token) {
            $('#pvz_list').html('<div class="alert alert-danger">Ошибка: CSRF-токен отсутствует.</div>');
            console.log('CSRF-токен отсутствует');
            isSearchingPvz = false;
            return;
        }

        console.log('delivery_company:', delivery_company, 'typeof:', typeof delivery_company);
        const api_url = delivery_company === 'cdek' ? '/api/get_cdek_pvz.php' : '/api/get_pvz.php';
        console.log('Запрос ПВЗ для:', query, 'API:', api_url);

        $.ajax({
            url: api_url,
            type: 'POST',
            data: { 
                query: query, 
                delivery_company: delivery_company,
                csrf_token: csrf_token 
            },
            dataType: 'json',
            beforeSend: function() {
                $('#pvz_list').html('<div class="text-center"><i class="bi bi-spinner"></i> Загрузка...</div>');
            },
            success: function(response) {
                console.log('Ответ от API:', response);
                if (response.status === 'success') {
                    if (response.data.length > 0) {
                        const pvzHtml = response.data.map(pvz => `
                            <div class="pvz-item" data-address="${pvz.address}" 
                                 data-code="${pvz.code || ''}" 
                                 data-city="${pvz.city || query}">
                                <strong>${pvz.address}</strong>
                                ${pvz.code ? '<br><small>Код ПВЗ: ' + pvz.code + '</small>' : ''}
                            </div>
                        `).join('');
                        $('#pvz_list').html(pvzHtml);
                    } else {
                        $('#pvz_list').html('<div class="alert alert-info">' + (response.message || 'Пункты выдачи не найдены') + '</div>');
                    }
                } else {
                    $('#pvz_list').html('<div class="alert alert-danger">Ошибка: ' + (response.message || 'Неизвестная ошибка') + '</div>');
                }
            },
            error: function(xhr) {
                console.log('Ошибка API:', xhr.responseText);
                $('#pvz_list').html('<div class="alert alert-danger">Ошибка сервера: ' + (xhr.responseJSON?.message || xhr.statusText) + '</div>');
            },
            complete: function() {
                isSearchingPvz = false;
            }
        });
    });

    $('#pvz_list').off('click').on('click', '.pvz-item', function() {
        const address = $(this).data('address');
        console.log('Данные ПВЗ:', $(this).data());
        if (!address) {
            showToast('Адрес пункта выдачи не указан', 'danger');
            return;
        }

        $('#pickup_point').val(address).prop('readonly', true);
        console.log('Установка pickup_point:', $('#pickup_point').val());
        pvzModal.hide();
        orderServiceModal.show();
    });

    $('#serviceOrderForm').off('submit').on('submit', function(e) {
        e.preventDefault();
        if (!isLoggedIn) {
            showToast('Требуется авторизация для оформления заказа', 'danger');
            return;
        }

        if (isSubmitting) return;
        isSubmitting = true;

        const form = this;
        const pickupPoint = $('#pickup_point').val().trim();
        const deliveryCompany = $('#delivery_company').val();

        if ((deliveryCompany === 'cdek' || deliveryCompany === 'post') && !pickupPoint) {
            showToast('Укажите адрес пункта выдачи', 'danger');
            form.classList.add('was-validated');
            isSubmitting = false;
            return;
        }

        if (!form.checkValidity()) {
            e.stopPropagation();
            form.classList.add('was-validated');
            isSubmitting = false;
            return;
        }

        const $notification = $('#serviceOrderForm').find('.notification');
        if (!$notification.length) {
            $('<div class="notification mt-3" style="display: none;"></div>').insertBefore(form.querySelector('.modal-footer'));
        }
        $notification.hide();
        const formData = new FormData(form);
        console.log('Отправляемые данные формы:', Object.fromEntries(formData));

        $.ajax({
            url: '/api/order_service.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    $notification.removeClass('alert-danger').addClass('alert-success')
                        .text(response.message || 'Заказ услуги успешно оформлен!')
                        .show();
                    form.reset();
                    form.classList.remove('was-validated');
                    orderServiceModal.hide();
                    setTimeout(() => {
                        window.location.href = '/orders.php';
                    }, 2000);
                } else {
                    $notification.removeClass('alert-success').addClass('alert-danger')
                        .text(response.message || 'Ошибка при оформлении заказа услуги.')
                        .show();
                }
            },
            error: function(xhr) {
                $notification.removeClass('alert-success').addClass('alert-danger')
                    .text('Ошибка сервера: ' + (xhr.responseJSON?.message || 'Неизвестная ошибка'))
                    .show();
            },
            complete: function() {
                isSubmitting = false;
            }
        });
    });

    $('#fullname').off('input').on('input', function() {
        const value = this.value;
        const valid = /^[А-Яа-я\s]{3,50}$/u.test(value);
        this.setCustomValidity(valid ? '' : 'ФИО должно содержать 3-50 символов, только буквы и пробелы.');
    });

    $('#phone').off('input').on('input', function() {
        const value = this.value;
        const valid = /^\+?[0-9]{10,15}$/.test(value);
        this.setCustomValidity(valid ? '' : 'Номер телефона должен содержать 10-15 цифр.');
    });

    $('#pvz_search').off('input').on('input', function() {
        const value = this.value.trim();
        const valid = value === '' || /^[А-Яа-я\s-]{1,100}$/u.test(value);
        this.setCustomValidity(valid ? '' : 'Город должен содержать только буквы, пробелы или дефисы (максимум 100 символов).');
    });

    document.querySelectorAll('.add-to-cart').forEach(btn => {
        btn.addEventListener('click', async function(e) {
            e.preventDefault();
            if (!isLoggedIn) {
                const confirmLogin = confirm('Для добавления товаров необходимо авторизоваться. Перейти на страницу входа?');
                if (confirmLogin) {
                    window.location.href = `login.php?return_url=${encodeURIComponent(window.location.href)}`;
                }
                return;
            }
            try {
                const productId = this.dataset.productId;
                const response = await fetch('cart/update_cart.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        action: 'add',
                        product_id: productId
                    })
                });
                const result = await response.json();
                if (result.status === 'success') {
                    updateCartBadge(result.data.total_count);
                    showToast('Товар успешно добавлен в корзину!', 'success');
                } else {
                    showToast(result.message, 'danger');
                }
            } catch (error) {
                console.error('Ошибка:', error);
                showToast('Ошибка при добавлении товара', 'danger');
            }
        });
    });

    document.querySelectorAll('.order-service').forEach(btn => {
        btn.addEventListener('click', function() {
            if (!isLoggedIn) {
                const confirmLogin = confirm('Для заказа услуг необходимо авторизоваться. Перейти на страницу входа?');
                if (confirmLogin) {
                    window.location.href = `login.php?return_url=${encodeURIComponent(window.location.href)}`;
                }
                return;
            }
            serviceIdInput.value = this.dataset.serviceId;
            currentServiceName = this.dataset.serviceName;
            if (pvzModal._isShown) {
                pvzModal.hide();
            }
            orderServiceModal.show();
        });
    });

    document.querySelectorAll('.detail-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const productName = this.dataset.productName;
            const productDescription = this.dataset.productDescription;
            document.getElementById('productDetailTitle').textContent = productName;
            document.getElementById('productDetailBody').textContent = productDescription;
            productDetailModal.show();
        });
    });

    // Initialize privacy consent after other scripts
    try {
        initPrivacyConsent();
    } catch (e) {
        console.error('Error initializing privacy consent:', e);
        showToast('Ошибка при загрузке уведомления о конфиденциальности', 'danger');
    }
});
</script>
<?php
function getDayText($number) {
    $number = abs($number) % 100;
    $lastTwoDigits = $number % 10;

    if ($number > 10 && $number < 20) return 'дней';
    if ($lastTwoDigits > 1 && $lastTwoDigits < 5) return 'дня';
    if ($lastTwoDigits == 1) return 'день';

    return 'дней';
}
?>
</body>
</html>