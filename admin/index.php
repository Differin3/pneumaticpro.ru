<?php
// Инициализация переменных
$error = '';
$success = '';
$items = [];

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

// Проверка авторизации
if (!isset($_SESSION['admin'])) {
    header('Location: login.php');
    exit();
}

// Обработка параметров
$search = isset($_GET['search']) ? clean_input($_GET['search']) : '';
$type_filter = isset($_GET['type']) ? clean_input($_GET['type']) : 'all';
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$items_per_page = 10;

// Валидация типа
$allowed_types = ['bullet', 'service', 'all'];
$type_filter = in_array($type_filter, $allowed_types) ? $type_filter : 'all';

// Динамическое построение SQL
try {
    $items = [];

    // Запрос для товаров (products)
    if ($type_filter === 'all' || $type_filter === 'bullet') {
        $sql_products = "SELECT 
                            p.id,
                            p.image,
                            p.name,
                            p.vendor_code,
                            c.name AS category,
                            'bullet' AS type,
                            p.price,
                            p.availability AS status,
                            'products' AS table_name,
                            p.created_at
                        FROM products p
                        JOIN categories c ON p.category_id = c.id";
        if (!empty($search)) {
            $sql_products .= " WHERE p.name LIKE :search_products_name OR p.vendor_code LIKE :search_products_vendor";
        }
        $sql_products .= " ORDER BY p.created_at DESC";
        error_log("Products SQL: " . $sql_products);
        $stmt_products = $pdo->prepare($sql_products);
        if (!empty($search)) {
            $params = [
                ':search_products_name' => "%$search%",
                ':search_products_vendor' => "%$search%"
            ];
            error_log("Products params: " . json_encode($params));
            $stmt_products->execute($params);
        } else {
            $stmt_products->execute();
        }
        $products = $stmt_products->fetchAll(PDO::FETCH_ASSOC);
        $items = array_merge($items, $products);
    }

    // Запрос для услуг (services)
    if ($type_filter === 'all' || $type_filter === 'service') {
        $sql_services = "SELECT 
                            s.id,
                            s.image,
                            s.name,
                            s.vendor_code,
                            c.name AS category,
                            'service' AS type,
                            s.price,
                            'in_stock' AS status,
                            'services' AS table_name,
                            s.created_at
                        FROM services s
                        JOIN categories c ON s.category_id = c.id";
        if (!empty($search)) {
            $sql_services .= " WHERE s.name LIKE :search_services_name OR s.vendor_code LIKE :search_services_vendor";
        }
        $sql_services .= " ORDER BY s.created_at DESC";
        error_log("Services SQL: " . $sql_services);
        $stmt_services = $pdo->prepare($sql_services);
        if (!empty($search)) {
            $params = [
                ':search_services_name' => "%$search%",
                ':search_services_vendor' => "%$search%"
            ];
            error_log("Services params: " . json_encode($params));
            $stmt_services->execute($params);
        } else {
            $stmt_services->execute();
        }
        $services = $stmt_services->fetchAll(PDO::FETCH_ASSOC);
        $items = array_merge($items, $services);
    }

    // Сортировка объединенных результатов
    usort($items, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    // Подсчет общего количества
    $total_items = count($items);

    // Пагинация
    $offset = ($current_page - 1) * $items_per_page;
    $items = array_slice($items, $offset, $items_per_page);

} catch (PDOException $e) {
    $error = "Ошибка базы данных: " . htmlspecialchars($e->getMessage());
    error_log("Database error in index.php: " . $e->getMessage());
    $total_items = 0;
    $items = [];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <title>Админ-панель | Управление товарами и услугами</title>
    <link rel="icon" href="data:,">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/../css/admin.css">
    <link rel="icon" href="https://pnevmatpro.ru/assets/favicon.png" type="image/png">
    <style>
        /* Мобильные карточки для товаров и услуг */
        .item-card-mobile {
            display: none; /* Hidden by default, shown on mobile */
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .item-card-mobile .item-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 1rem;
            font-weight: 600;
        }

        .item-card-mobile .item-body {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .item-card-mobile .col-span-2 {
            grid-column: span 2;
            text-align: center;
        }

        /* Стили для миниатюр изображений */
        .product-image-thumb {
            max-width: 100px;
            max-height: 100px;
            object-fit: cover;
        }

        /* Медиа-запросы для мобильной версии */
        @media (max-width: 767.98px) {
            .admin-main {
                padding: 15px;
                width: 100%;
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
            
            .item-table-desktop {
                display: none; /* Hide table on mobile */
            }
            
            .item-card-mobile {
                display: block; /* Show cards on mobile */
            }

            /* Улучшение мобильного меню */
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

            .admin-nav {
                position: fixed;
                top: 0;
                left: 0;
                height: 100%;
                width: 250px;
                background-color: #2c3e50;
                color: white;
                padding: 20px;
                transform: translateX(-100%);
                transition: transform 0.3s ease-in-out;
                z-index: 1050;
            }

            .admin-nav.active {
                transform: translateX(0);
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

            /* Убедимся, что контент не перекрывается */
            .admin-main {
                margin-left: 0;
                padding-top: 60px; /* Отступ для кнопки меню */
            }
        }

        @media (max-width: 576px) {
            .item-card-mobile .item-body {
                grid-template-columns: 1fr; /* Stack items vertically on very small screens */
            }
            
            .item-card-mobile .col-span-2 {
                grid-column: auto; /* Reset to default for stacking */
            }
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

                <!-- Уведомления -->
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger mt-3"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="d-flex justify-content-between flex-wrap align-items-center my-4">
                    <h2 class="mb-3 mb-md-0">Управление товарами и услугами</h2>
                    <div class="d-flex gap-3">
                        <a href="add_product.php" class="btn btn-success">
                            <i class="bi bi-plus-lg"></i> <span class="d-none d-md-inline">Добавить товар/услугу</span>
                        </a>
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
                                        placeholder="Поиск по названию и артикулу..." 
                                        value="<?= htmlspecialchars($search) ?>">
                                </div>
                                <div class="col-md-4 col-12">
                                    <select name="type" class="form-select">
                                        <option value="all">Все типы</option>
                                        <option value="bullet" <?= $type_filter === 'bullet' ? 'selected' : '' ?>>Товар</option>
                                        <option value="service" <?= $type_filter === 'service' ? 'selected' : '' ?>>Услуги</option>
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

                <?php if (!empty($items)): ?>
                    <!-- Десктопная версия (таблица) -->
                    <div class="table-responsive item-table-desktop">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Изображение</th>
                                    <th>Название</th>
                                    <th class="d-none d-md-table-cell">Артикул</th>
                                    <th class="d-none d-lg-table-cell">Категория</th>
                                    <th>Тип</th>
                                    <th class="price-column">Цена</th>
                                    <th>Статус</th>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['id'] ?? '') ?></td>
                                    <td>
                                        <?php 
                                        $filePath = $_SERVER['DOCUMENT_ROOT'] . '/uploads/products/' . $item['image']; // Путь для проверки файла
                                        $imageUrl = !empty($item['image']) ? '/uploads/products/' . $item['image'] : ''; // URL для тега <img>
                                        ?>
                                        <?php if (!empty($imageUrl) && file_exists($filePath)): ?>
                                            <img src="<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>" 
                                                 class="product-image-thumb" 
                                                 alt="<?= htmlspecialchars($item['name'] ?? 'Изображение', ENT_QUOTES, 'UTF-8') ?>"
                                                 loading="lazy">
                                        <?php else: ?>
                                            <div class="img-placeholder bg-light">
                                                <i class="bi bi-image fs-1 text-accent"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($item['name'] ?? '') ?></td>
                                    <td class="d-none d-md-table-cell"><?= htmlspecialchars($item['vendor_code'] ?? 'N/A') ?></td>
                                    <td class="d-none d-lg-table-cell"><?= htmlspecialchars($item['category'] ?? '') ?></td>
                                    <td>
                                        <span class="badge bg-<?= ($item['type'] ?? '') === 'bullet' ? 'primary' : 'success' ?>">
                                            <?= ($item['type'] ?? '') === 'bullet' ? 'Товар' : 'Услуга' ?>
                                        </span>
                                    </td>
                                    <td class="price-column"><?= isset($item['price']) ? number_format($item['price'], 2, '.', ' ') . ' ₽' : '0.00 ₽' ?></td>
                                    <td>
                                        <span class="badge bg-<?= ($item['status'] ?? 'in_stock') === 'in_stock' ? 'success' : 'warning' ?>">
                                            <?= ($item['status'] ?? 'in_stock') === 'in_stock' ? 'В наличии' : 'Нет в наличии' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2 action-btns">
                                            <a href="edit_<?= $item['table_name'] ?>.php?id=<?= $item['id'] ?>" 
                                               class="btn btn-sm btn-warning" title="Редактировать">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <button class="btn btn-sm btn-danger delete-btn" 
                                                    data-id="<?= $item['id'] ?>" 
                                                    data-table="<?= $item['table_name'] ?>" 
                                                    title="Удалить">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Мобильная версия (карточки) -->
                    <div class="item-cards-mobile">
                        <?php foreach ($items as $item): ?>
                        <div class="item-card-mobile">
                            <div class="item-header">
                                <strong>№<?= htmlspecialchars($item['id'] ?? '') ?></strong>
                                <span><?= date('d.m.Y', strtotime($item['created_at'] ?? '')) ?></span>
                            </div>
                            <div class="item-body">
                                <div>
                                    <small class="text-muted">Название</small>
                                    <div><?= htmlspecialchars($item['name'] ?? '') ?></div>
                                </div>
                                <div>
                                    <small class="text-muted">Артикул</small>
                                    <div><?= htmlspecialchars($item['vendor_code'] ?? 'N/A') ?></div>
                                </div>
                                <div>
                                    <small class="text-muted">Категория</small>
                                    <div><?= htmlspecialchars($item['category'] ?? '') ?></div>
                                </div>
                                <div>
                                    <small class="text-muted">Тип</small>
                                    <div>
                                        <span class="badge bg-<?= ($item['type'] ?? '') === 'bullet' ? 'primary' : 'success' ?>">
                                            <?= ($item['type'] ?? '') === 'bullet' ? 'Товар' : 'Услуга' ?>
                                        </span>
                                    </div>
                                </div>
                                <div>
                                    <small class="text-muted">Цена</small>
                                    <div><?= isset($item['price']) ? number_format($item['price'], 2, '.', ' ') . ' ₽' : '0.00 ₽' ?></div>
                                </div>
                                <div>
                                    <small class="text-muted">Статус</small>
                                    <div>
                                        <span class="badge bg-<?= ($item['status'] ?? 'in_stock') === 'in_stock' ? 'success' : 'warning' ?>">
                                            <?= ($item['status'] ?? 'in_stock') === 'in_stock' ? 'В наличии' : 'Нет в наличии' ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="col-span-2 text-center mt-2">
                                    <div class="d-flex gap-2 justify-content-center">
                                        <a href="edit_<?= $item['table_name'] ?>.php?id=<?= $item['id'] ?>" 
                                           class="btn btn-sm btn-warning" title="Редактировать">
                                            <i class="bi bi-pencil"></i> Редактировать
                                        </a>
                                        <button class="btn btn-sm btn-danger delete-btn" 
                                                data-id="<?= $item['id'] ?>" 
                                                data-table="<?= $item['table_name'] ?>" 
                                                title="Удалить">
                                            <i class="bi bi-trash"></i> Удалить
                                        </button>
                                    </div>
                                </div>
                                <?php 
                                $filePath = $_SERVER['DOCUMENT_ROOT'] . '/uploads/products/' . $item['image']; // Путь для проверки файла
                                $imageUrl = !empty($item['image']) ? '/uploads/products/' . $item['image'] : ''; // URL для тега <img>
                                ?>
                                <?php if (!empty($imageUrl) && file_exists($filePath)): ?>
                                    <img src="<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>" 
                                         class="product-image-thumb" 
                                         alt="<?= htmlspecialchars($item['name'] ?? 'Изображение', ENT_QUOTES, 'UTF-8') ?>"
                                         loading="lazy">
                                <?php else: ?>
                                    <div class="img-placeholder bg-light">
                                        <i class="bi bi-image fs-1 text-accent"></i>
                                    </div>
                                <?php endif; ?>
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
                                   href="?page=<?= $i ?>&type=<?= $type_filter ?>&search=<?= urlencode($search) ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="bi bi-box-seam fs-1 text-muted mb-3"></i>
                        <h4 class="text-dark mb-3">Нет товаров или услуг</h4>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Мобильное меню
        const menuBtn = document.querySelector('.mobile-menu-btn');
        const closeBtn = document.querySelector('.close-menu');
        const sidebar = document.querySelector('.admin-nav');
        const overlay = document.querySelector('.overlay');

        function toggleMenu(event) {
            event.preventDefault(); // Предотвращаем стандартное поведение
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

        if (menuBtn) {
            menuBtn.addEventListener('click', toggleMenu);
        }
        if (closeBtn) {
            closeBtn.addEventListener('click', toggleMenu);
        }
        if (overlay) {
            overlay.addEventListener('click', toggleMenu);
        }

        // Обработчик изменения размера окна
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 768) {
                document.body.classList.remove('menu-open');
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                overlay.style.display = 'none';
            }
        });

        // Инициализация: убедиться, что меню закрыто на десктопе
        if (window.innerWidth >= 768) {
            document.body.classList.remove('menu-open');
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
            overlay.style.display = 'none';
        }

        // Удаление товаров или услуг
        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
                const id = this.dataset.id;
                const table = this.dataset.table;

                if (confirm('Вы уверены, что хотите удалить этот элемент?')) {
                    fetch('delete_item.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `id=${id}&table=${table}&csrf_token=${encodeURIComponent(csrfToken)}`
                    })
                    .then(response => {
                        if (response.ok) {
                            window.location.reload();
                        } else {
                            alert("Ошибка при удалении элемента");
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert("Произошла ошибка при отправке запроса");
                    });
                }
            });
        });
    });
    </script>
</body>
</html>