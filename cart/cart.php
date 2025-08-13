<?php
session_start();
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

$is_logged_in = isset($_SESSION['user_id']);
$cart = $_SESSION['cart'] ?? [];

// Генерация CSRF-токена
$csrf_token = generate_csrf_token();

// Предзаполнение формы из сессии или базы
$fullname = htmlspecialchars($_GET['fullname'] ?? (isset($_SESSION['user_id']) ? getCustomerFullName($_SESSION['user_id']) : ''));
$email = htmlspecialchars($_GET['email'] ?? ($_SESSION['email'] ?? ''));
$phone = htmlspecialchars($_GET['phone'] ?? (isset($_SESSION['user_id']) ? getUserPhone($_SESSION['user_id']) : ''));
$delivery_company = htmlspecialchars($_GET['delivery_company'] ?? 'cdek'); // Дефолтное значение 'cdek'
$pickup_point = htmlspecialchars($_GET['pickup_point'] ?? '');

$total_price = 0;
$total_items = 0;
if (!empty($cart)) {
    $product_ids = array_keys($cart);
    if (!empty($product_ids)) {
        try {
            $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
            $sql = "SELECT id, name, price, image, availability, diameter, weight, vendor_code FROM products WHERE id IN ($placeholders) AND type = 'bullet'";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($product_ids);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($products as $product) {
                if (!isset($product['id']) || !is_numeric($product['id']) || !isset($cart[$product['id']])) {
                    error_log("Некорректный или отсутствующий ID продукта: " . print_r($product, true));
                    continue;
                }
                $cart[$product['id']]['name'] = $product['name'] ?? 'Без названия';
                $cart[$product['id']]['price'] = $product['price'] ?? 0;
                $cart[$product['id']]['image'] = $product['image'] ?? '';
                $cart[$product['id']]['availability'] = $product['availability'] ?? 'unknown';
                $cart[$product['id']]['diameter'] = $product['diameter'] ?? null;
                $cart[$product['id']]['weight'] = $product['weight'] ?? null;
                $cart[$product['id']]['vendor_code'] = $product['vendor_code'] ?? '';
                $total_price += (float)($product['price'] ?? 0) * (int)($cart[$product['id']]['quantity'] ?? 0);
                $total_items += (int)($cart[$product['id']]['quantity'] ?? 0);
            }
        } catch (PDOException $e) {
            error_log("Ошибка загрузки данных корзины: " . $e->getMessage());
            $cart = [];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
    <!-- Yandex.Metrika counter -->
<script type="text/javascript" >
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
    <title>pnevmatpro.ru - Корзина</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <link href="/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" crossorigin="anonymous">
    <link rel="icon" href="https://pnevmatpro.ru/assets/favicon.png" type="image/png">
    <style>
        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
        }
        .btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        .img-placeholder {
            height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card-img-top {
            height: 120px;
            object-fit: cover;
            width: 100%;
        }
        .logo-float {
            animation: float 6s ease-in-out infinite;
            max-width: 100%;
            height: auto;
        }
        .hero {
            padding: 2rem 0;
        }
        @media (max-width: 768px) {
            .hero {
                padding: 1.5rem 0;
            }
            .logo-float {
                width: 280px;
            }
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
        .card {
            transition: transform 0.3s;
        }
        .card:hover {
            transform: translateY(-5px);
        }
        .product-card {
            margin-bottom: 1rem;
        }
        .quantity-input {
            width: 60px;
            font-size: 0.9rem;
            color: #212529 !important;
            background-color: #fff !important;
            border: 1px solid #ced4da;
            padding: 0.25rem;
        }
        .quantity-input::-webkit-inner-spin-button,
        .quantity-input::-webkit-outer-spin-button {
            opacity: 1;
        }
        section {
            padding: 2rem 0;
        }
        @media (max-width: 768px) {
            section {
                padding: 1.5rem 0;
            }
        }
        .lead {
            font-size: 1.1rem;
        }
        @media (max-width: 768px) {
            .display-4 {
                font-size: 2.2rem;
            }
            .lead {
                font-size: 1rem;
            }
        }
        .order-summary {
            background-color: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
        }
        .cart-items {
            max-height: 70vh;
            overflow-y: auto;
        }
        @media (max-width: 992px) {
            .cart-items {
                max-height: none;
            }
        }
        #pickup_point_field { display: none; }
        #pvz_modal .modal-body { max-height: 60vh; overflow-y: auto; }
        .pvz-item { cursor: pointer; padding: 0.5rem; border-bottom: 1px solid #dee2e6; }
        .pvz-item:hover { background-color: #f8f9fa; }
    </style>
</head>
<body>
<!-- Шапка -->
<header class="hero bg-dark text-white">
    <div class="container text-center position-relative">
        <img src="/assets/logo.png" alt="Logo" width="400" class="mb-4 logo-float">
        <p class="lead mb-4">Профессиональный ремонт пневматического оружия и продажа комплектующих</p>
        
        <div class="d-flex flex-wrap gap-2 justify-content-center">
            <div class="d-flex flex-wrap gap-2 justify-content-center">
                <a href="/index.php#services" class="btn btn-lg btn-primary rounded-pill">
                    <i class="bi bi-tools me-2"></i>Услуги
                </a>
                <a href="/index.php#products" class="btn btn-lg btn-success rounded-pill">
                    <i class="bi bi-basket me-2"></i>Каталог
                </a>
                <a href="/cart/cart.php" class="btn btn-lg btn-warning position-relative rounded-pill">
                    <i class="bi bi-cart me-2"></i>Корзина
                    <span class="cart-badge badge bg-danger">
                        <?= $total_items ?>
                    </span>
                </a>
            </div>

            <div class="auth-buttons d-flex gap-2 align-items-center mt-2 mt-md-0">
                <?php if($is_logged_in): ?>
                    <div class="d-flex flex-wrap gap-2 align-items-center justify-content-center">
                        <a href="/orders.php" class="btn btn-outline-light btn-sm rounded-pill">
                            <i class="bi bi-person-circle me-2"></i>Личный кабинет
                        </a>
                        <span class="text-white"><?= htmlspecialchars($_SESSION['username']) ?></span>
                        <a href="/logout.php" class="btn btn-outline-light btn-sm rounded-pill">
                            <i class="bi bi-box-arrow-right"></i>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                        <a href="/login.php" class="btn btn-outline-light btn-sm rounded-pill">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Вход
                        </a>
                        <a href="/register.php" class="btn btn-outline-light btn-sm rounded-pill">
                            <i class="bi bi-person-plus me-2"></i>Регистрация
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>

<!-- Секция корзины -->
<section id="cart" class="py-5 bg-white">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="display-4 mb-3 text-dark fw-bold">Ваша корзина</h2>
            <div class="decorative-line mx-auto bg-accent"></div>
            <p class="lead text-muted mt-3">Управляйте товарами и оформите заказ</p>
        </div>

        <?php if (!empty($cart)): ?>
            <div class="row g-4">
                <!-- Левая часть: Оформление заказа -->
                <div class="col-lg-5 col-md-12 order-lg-2 order-1">
                    <div class="order-summary">
                        <h3 class="mb-4">Оформление заказа</h3>
                        <form id="checkoutForm" class="needs-validation" novalidate>
                            <div class="mb-3">
                                <label for="fullname" class="form-label">ФИО</label>
                                <input type="text" class="form-control" id="fullname" name="fullname" 
                                       value="<?= $fullname ?>" required>
                                <div class="invalid-feedback">Введите ваше ФИО (3-50 символов, только буквы и пробелы).</div>
                            </div>
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?= $email ?>" required>
                                <div class="invalid-feedback">Введите действительный email.</div>
                            </div>
                            <div class="mb-3">
                                <label for="phone" class="form-label">Телефон</label>
                                <input type="tel" class="form-control" id="phone" name="phone" 
                                       value="<?= $phone ?>" required>
                                <div class="invalid-feedback">Введите действительный номер телефона (10-15 цифр).</div>
                            </div>
                            <div class="mb-3">
                                <label for="delivery_company" class="form-label">Служба доставки</label>
                                <select class="form-select" id="delivery_company" name="delivery_company" required>
                                    <option value="" disabled selected>Выберите службу доставки</option>
                                    <option value="cdek" <?= $delivery_company === 'cdek' ? 'selected' : '' ?>>CDEK</option>
                                    <option value="post" <?= $delivery_company === 'post' ? 'selected' : '' ?>>Почта России</option>
                                </select>
                                <div class="invalid-feedback">Выберите службу доставки.</div>
                            </div>
                            <div class="mb-3" id="pickup_point_field">
                                <label for="pickup_point" class="form-label">Адрес пункта выдачи</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="pickup_point" name="pickup_point" 
                                           value="<?= $pickup_point ?>" readonly required>
                                    <button type="button" class="btn btn-outline-primary" id="select_pvz">Выбрать ПВЗ</button>
                                </div>
                                <div class="invalid-feedback">Укажите адрес пункта выдачи.</div>
                                <!-- Добавлено отображение кода ПВЗ -->
                                <div class="mt-2">
                                    <small>Код ПВЗ: <span id="pvz_code_display">Не выбран</span></small>
                                </div>
                            </div>
                            <!-- Скрытые поля для города и кода ПВЗ -->
                            <input type="hidden" name="pickup_city" id="pickup_city" value="">
                            <input type="hidden" name="pickup_point_code" id="pickup_point_code" value="">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <button type="submit" class="btn btn-primary w-100 <?= $is_logged_in && $total_price > 0 ? '' : 'disabled' ?>">
                                <i class="bi bi-checkout me-2"></i>Оформить заказ
                            </button>
                        </form>
                        <div id="notification" class="mt-3" style="display: none;"></div>
                        <div class="mt-4">
                            <h4>Информация о заказе</h4>
                            <p><strong>Товаров:</strong> <?= $total_items ?></p>
                            <p><strong>Итого:</strong> <?= number_format((float)$total_price, 2) ?> ₽</p>
                            <p class="text-muted"><small>Стоимость указана без учёта доставки</small></p>
                        </div>
                    </div>
                </div>

                <!-- Правая часть: Товары -->
                <div class="col-lg-7 col-md-12 order-lg-1 order-2">
                    <div class="cart-items">
                        <?php foreach ($cart as $product_id => $item): ?>
                            <?php if (isset($item['name'], $item['price'])): ?>
                                <div class="card h-100 shadow-sm border-0 product-card mb-3">
                                    <div class="row g-0">
                                        <div class="col-4">
                                            <?php 
                                            // ИСПРАВЛЕНИЕ: Правильный путь к изображениям
                                            $imageUrl = !empty($item['image']) ? '/uploads/products/' . $item['image'] : '';
                                            $absolutePath = $_SERVER['DOCUMENT_ROOT'] . $imageUrl;
                                            if (!empty($item['image']) && file_exists($absolutePath)): ?>
                                                <img src="<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>" 
                                                     class="card-img-top" 
                                                     alt="<?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?>"
                                                     loading="lazy">
                                            <?php else: ?>
                                                <div class="img-placeholder bg-light">
                                                    <i class="bi bi-box-seam fs-3 text-accent"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-8">
                                            <div class="card-body p-3">
                                                <h6 class="card-title text-dark mb-2">
                                                    <?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?>
                                                </h6>
                                                <div class="product-specs mb-2">
                                                    <?php if (!empty($item['diameter'])): ?>
                                                        <span class="badge bg-accent text-white me-1">
                                                            Ø <?= number_format((float)$item['diameter'], 2) ?> мм
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($item['weight'])): ?>
                                                        <span class="badge bg-light text-dark me-1">
                                                            <?= number_format((float)$item['weight'], 2) ?> г
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($item['vendor_code'])): ?>
                                                        <span class="badge bg-light text-dark">
                                                            <?= htmlspecialchars($item['vendor_code'], ENT_QUOTES, 'UTF-8') ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <span class="badge <?= match($item['availability'] ?? '') {
                                                        'in_stock' => 'bg-success',
                                                        'pre_order' => 'bg-warning text-dark',
                                                        'out_of_stock' => 'bg-danger',
                                                        default => 'bg-secondary'
                                                    } ?>">
                                                        <?= match($item['availability'] ?? '') {
                                                            'in_stock' => 'В наличии',
                                                            'pre_order' => 'Под заказ',
                                                            'out_of_stock' => 'Нет в наличии',
                                                            default => 'Неизвестно'
                                                        } ?>
                                                    </span>
                                                </div>
                                                <div class="mb-2">
                                                    <span class="text-accent">
                                                        <?= number_format((float)$item['price'], 2) ?> ₽
                                                    </span>
                                                </div>
                                                <div class="mb-2 d-flex align-items-center">
                                                    <label class="form-label me-2 mb-0">Кол-во:</label>
                                                    <input type="number" 
                                                           class="form-control quantity-input update-cart" 
                                                           data-product-id="<?= (int)$product_id ?>" 
                                                           value="<?= (int)$item['quantity'] ?>" 
                                                           min="1" 
                                                           <?= $item['availability'] !== 'in_stock' ? 'disabled' : '' ?>>
                                                </div>
                                                <div class="mb-2">
                                                    <strong>Сумма:</strong> 
                                                    <?= number_format((float)($item['price'] * $item['quantity']), 2) ?> ₽
                                                </div>
                                                <button class="btn btn-danger btn-sm rounded-pill remove-from-cart" 
                                                        data-product-id="<?= (int)$product_id ?>">
                                                    <i class="bi bi-trash me-1"></i>Удалить
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning text-center">
                Ваша корзина пуста. <a href="/index.php#products" class="alert-link">Перейти в каталог</a>.
            </div>
        <?php endif; ?>
    </div>
</section>

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
                        <input type="text" class="form-control" id="pvz_search" placeholder="Введите город или индекс" required>
                        <button type="button" class="btn btn-primary" id="search_pvz">Найти</button>
                    </div>
                    <div class="invalid-feedback">Введите город или индекс для поиска.</div>
                </div>
                <div id="pvz_list" class="list-group"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Передаём isLoggedIn через inline-скрипт -->
<script>
    const isLoggedIn = <?= $is_logged_in ? 'true' : 'false' ?>;
</script>
<script src="/cart/js/cart.js"></script>
</body>
</html>