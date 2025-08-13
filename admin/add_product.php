<?php
session_start();
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

// Проверка авторизации администратора
if (!isset($_SESSION['admin'])) {
    header('Location: login.php');
    exit();
}

$error = '';
$form_data = [
    'name' => '',
    'type' => 'bullet',
    'price' => 0,
    'description' => '',
    'category_id' => 1, // Default to "Пневматические винтовки" for bullets
    'diameter' => 0,
    'weight' => 0,
    'duration' => 0,
    'service_type' => 'repair'
];

// Генерация CSRF-токена
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    try {
        // Валидация CSRF-токена
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception('Ошибка безопасности: недействительный CSRF-токен');
        }

        // Извлекаем type напрямую из $_POST
        $type = clean_input($_POST['type'] ?? '');

        // Проверяем корректность типа
        if (!in_array($type, ['bullet', 'service'])) {
            throw new Exception('Неверный тип товара');
        }

        // Обработка данных формы
        $form_data = [
            'name' => clean_input($_POST['name'] ?? ''),
            'type' => $type,
            'price' => (float)($_POST['price'] ?? 0),
            'description' => clean_input($_POST['description'] ?? ''),
            'category_id' => (int)($_POST['category_id'] ?? ($type === 'service' ? 2 : 1)),
            'diameter' => $type === 'bullet' ? (float)($_POST['diameter'] ?? 0) : null,
            'weight' => $type === 'bullet' ? (float)($_POST['weight'] ?? 0) : null,
            'duration' => $type === 'service' ? (int)($_POST['duration'] ?? 0) : null,
            'service_type' => $type === 'service' ? clean_input($_POST['service_type'] ?? 'repair') : null
        ];

        // Основная валидация
        if (empty($form_data['name'])) throw new Exception('Укажите название товара');
        if ($form_data['price'] <= 0) throw new Exception('Укажите корректную цену');
        if (empty($form_data['description'])) throw new Exception('Укажите описание товара');

        // Валидация категории
        $stmt = $pdo->prepare("SELECT type FROM categories WHERE id = ?");
        $stmt->execute([$form_data['category_id']]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$category) {
            throw new Exception('Выбранная категория не существует');
        }
        if ($form_data['type'] === 'bullet' && $category['type'] !== 'product') {
            throw new Exception('Для пулек выберите категорию типа "product"');
        }
        if ($form_data['type'] === 'service' && $category['type'] !== 'service') {
            throw new Exception('Для услуг выберите категорию типа "service"');
        }

        if ($form_data['type'] === 'bullet') {
            if ($form_data['diameter'] <= 0 || $form_data['weight'] <= 0) {
                throw new Exception('Для пулек укажите диаметр и вес');
            }
        } elseif ($form_data['type'] === 'service') {
            if ($form_data['duration'] <= 0) {
                throw new Exception('Для услуг укажите длительность');
            }
        }

        // Обработка изображения
        $image = null;
        if (!empty($_FILES['image']['name'])) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $file_info = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($file_info, $_FILES['image']['tmp_name']);
            finfo_close($file_info);

            if (!in_array($mime_type, $allowed_types)) {
                throw new Exception('Допустимы только изображения в формате JPG, PNG или GIF');
            }

            $max_size = 2 * 1024 * 1024; // 2MB
            if ($_FILES['image']['size'] > $max_size) {
                throw new Exception('Размер изображения не должен превышать 2MB');
            }

            $upload_dir = __DIR__ . '/../uploads/products/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $image = 'product_' . time() . '.' . $extension;
            $upload_file = $upload_dir . $image;

            if (!move_uploaded_file($_FILES['image']['tmp_name'], $upload_file)) {
                throw new Exception('Ошибка загрузки изображения');
            }
        }

        // Генерация уникального slug
        $slug = generate_slug($form_data['name']);
        $table = $form_data['type'] === 'bullet' ? 'products' : 'services';
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE slug = ?");
        $stmt->execute([$slug]);
        $counter = 1;

        while ($stmt->fetchColumn() > 0) {
            $new_slug = $slug . '-' . $counter;
            $stmt->execute([$new_slug]);
            if ($stmt->fetchColumn() === 0) {
                $slug = $new_slug;
                break;
            }
            $counter++;
        }

        // Вставка данных в соответствующую таблицу
        $vendor_code = generate_vendor_code($form_data['type']);
        if (!$vendor_code) {
            throw new Exception('Не удалось сгенерировать уникальный артикул для ' . ($form_data['type'] === 'bullet' ? 'товара' : 'услуги'));
        }

        if ($form_data['type'] === 'bullet') {
            $stmt = $pdo->prepare("
                INSERT INTO products 
                (vendor_code, name, slug, type, price, diameter, weight, 
                 description, image, category_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $result = $stmt->execute([
                $vendor_code,
                $form_data['name'],
                $slug,
                $form_data['type'],
                $form_data['price'],
                $form_data['diameter'],
                $form_data['weight'],
                $form_data['description'],
                $image,
                $form_data['category_id']
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO services 
                (vendor_code, name, slug, price, service_type, duration, 
                 description, image, category_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $result = $stmt->execute([
                $vendor_code,
                $form_data['name'],
                $slug,
                $form_data['price'],
                $form_data['service_type'],
                $form_data['duration'],
                $form_data['description'],
                $image,
                $form_data['category_id']
            ]);
        }

        if (!$result) {
            throw new Exception('Ошибка базы данных: ' . implode(', ', $stmt->errorInfo()));
        }

        // Логирование действия
        $adminId = (int)$_SESSION['admin']['id'];
        $logDescription = "Добавлен " . ($form_data['type'] === 'bullet' ? 'товар' : 'услуга') . ": '{$form_data['name']}' (артикул: {$vendor_code})";
        logActivity($pdo, $form_data['type'] === 'bullet' ? 'product_add' : 'service_add', $logDescription, $adminId);

        $_SESSION['success'] = 'Товар успешно добавлен!';
        header('Location: index.php');
        exit();

    } catch (Exception $e) {
        $error = $e->getMessage();
        error_log('Ошибка добавления товара: ' . $e->getMessage());
    }
}

// Загрузка категорий
try {
    $categories = $pdo->query("SELECT id, name, type FROM categories WHERE is_active = 1 ORDER BY name")->fetchAll();
} catch (PDOException $e) {
    $error = 'Ошибка загрузки категорий: ' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Добавление товара | Админ-панель</title>
    <link rel="icon" href="data:,">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/../css/admin.css">
    <link rel="icon" href="https://pnevmatpro.ru/assets/favicon.png" type="image/png">
    <style>
        body {
            background-color: #f8f9fa;
            overflow: auto !important;
        }
        .admin-wrapper {
            display: flex;
            min-height: 100vh;
            overflow-y: auto;
        }
        .admin-nav {
            background: #2c3e50;
            width: 250px;
            padding: 20px;
            color: white;
        }
        .admin-main {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            min-height: 100vh;
        }
        .form-section {
            display: none;
        }
        .form-section.active {
            display: block;
        }
        .card {
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            width: 100%;
        }
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #eee;
            font-weight: 600;
            padding: 12px 20px;
        }
        .btn-primary {
            background-color: #0d6efd;
            border-color: #0d6efd;
        }
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1100;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #2c3e50;
            color: white;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
        }
        .overlay.active {
            opacity: 1;
            pointer-events: all;
        }
        .container-fluid {
            min-height: 100%;
            overflow-y: auto;
        }
        @media (max-width: 767.98px) {
            .container-fluid {
                padding: 0;
            }
            .admin-main {
                padding: 10px;
                margin-left: 0 !important;
                width: 100% !important;
                overflow-y: auto;
                min-height: 100vh;
            }
            .card {
                margin-bottom: 15px;
                width: 100%;
                padding: 10px;
            }
            .nav-link {
                font-size: 14px;
                padding: 10px 12px !important;
            }
            .mobile-menu-btn {
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .admin-nav {
                transform: translateX(-100%);
                transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            }
            .admin-nav.active {
                transform: translateX(0);
            }
            .row > .col-md-6 {
                flex: 0 0 100%;
                max-width: 100%;
            }
            .card-body .row {
                flex-direction: column;
            }
            .form-label {
                font-size: 0.9rem;
            }
            .form-control, .form-select {
                font-size: 0.9rem;
                margin-bottom: 10px;
                width: 100%;
            }
            .card-body .mb-3 {
                margin-bottom: 15px !important;
            }
            .card-body .d-flex {
                flex-direction: column;
                gap: 10px;
                align-items: center;
            }
            .btn-lg {
                width: 100%;
                padding: 10px;
                font-size: 1rem;
            }
        }
        @media (max-width: 576px) {
            .card-header {
                font-size: 1rem;
                padding: 10px 15px;
            }
            .form-label {
                font-size: 0.85rem;
            }
            .form-control, .form-select {
                font-size: 0.85rem;
            }
        }
        body.menu-open {
            overflow: hidden;
        }
    </style>
</head>
<body class="admin-wrapper">
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

        <div class="container-fluid mt-4">
            <div class="row">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h2 class="mb-0"><i class="bi bi-plus-circle"></i> Новый товар</h2>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Назад к списку
                        </a>
                    </div>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        
                        <!-- Основная информация -->
                        <div class="card mb-4">
                            <div class="card-header">Основная информация</div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Название товара *</label>
                                        <input type="text" name="name" value="<?= htmlspecialchars($form_data['name']) ?>" class="form-control" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Категория *</label>
                                        <select name="category_id" id="categorySelect" class="form-select" required>
                                            <?php foreach ($categories as $category): ?>
                                                <option value="<?= $category['id'] ?>" 
                                                        data-type="<?= $category['type'] ?>" 
                                                        <?= $category['id'] == $form_data['category_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($category['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Тип товара *</label>
                                        <select name="type" id="productType" class="form-select" required>
                                            <option value="bullet" <?= $form_data['type'] == 'bullet' ? 'selected' : '' ?>>Пули</option>
                                            <option value="service" <?= $form_data['type'] == 'service' ? 'selected' : '' ?>>Услуга</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Цена (₽) *</label>
                                        <input type="number" step="0.01" name="price" value="<?= htmlspecialchars($form_data['price']) ?>" class="form-control" required>
                                    </div>
                                    <div class="col-12 mb-3">
                                        <label class="form-label">Описание *</label>
                                        <textarea name="description" class="form-control" rows="5" required><?= htmlspecialchars($form_data['description']) ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Секция для пулек -->
                        <div class="card mb-4 form-section <?= $form_data['type'] == 'bullet' ? 'active' : '' ?>" id="bulletFields">
                            <div class="card-header">Характеристики пулек</div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Диаметр (мм) *</label>
                                        <input type="number" step="0.01" name="diameter" value="<?= htmlspecialchars($form_data['diameter']) ?>" class="form-control">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Вес (г) *</label>
                                        <input type="number" step="0.01" name="weight" value="<?= htmlspecialchars($form_data['weight']) ?>" class="form-control">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Секция для услуг -->
                        <div class="card mb-4 form-section <?= $form_data['type'] == 'service' ? 'active' : '' ?>" id="serviceFields">
                            <div class="card-header">Характеристики услуги</div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Тип услуги *</label>
                                        <select name="service_type" class="form-select">
                                            <option value="repair" <?= $form_data['service_type'] == 'repair' ? 'selected' : '' ?>>Ремонт</option>
                                            <option value="maintenance" <?= $form_data['service_type'] == 'maintenance' ? 'selected' : '' ?>>Обслуживание</option>
                                            <option value="custom" <?= $form_data['service_type'] == 'custom' ? 'selected' : '' ?>>Индивидуальная</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Длительность (дни) *</label>
                                        <input type="number" name="duration" value="<?= htmlspecialchars($form_data['duration']) ?>" class="form-control" min="1">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Изображение -->
                        <div class="card mb-4">
                            <div class="card-header">Изображение товара</div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">Загрузить изображение</label>
                                    <input type="file" name="image" class="form-control" accept="image/*">
                                    <small class="form-text text-muted">Максимальный размер файла: 2MB</small>
                                </div>
                            </div>
                        </div>

                        <!-- Кнопки действий -->
                        <div class="card mt-4">
                            <div class="card-body">
                                <div class="text-center">
                                    <div class="d-flex flex-column flex-md-row justify-content-center gap-3">
                                        <a href="index.php" class="btn btn-secondary btn-lg">
                                            <i class="bi bi-arrow-left"></i> Отмена
                                        </a>
                                        <button type="submit" name="add_product" class="btn btn-primary btn-lg">
                                            <i class="bi bi-save"></i> Добавить товар
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const productTypeSelect = document.getElementById('productType');
                const categorySelect = document.getElementById('categorySelect');
                const bulletFields = document.getElementById('bulletFields');
                const serviceFields = document.getElementById('serviceFields');
                const bulletInputs = document.querySelectorAll('#bulletFields input');
                const serviceInputs = document.querySelectorAll('#serviceFields input, #serviceFields select');

                function updateCategoryOptions() {
                    const selectedType = productTypeSelect.value;
                    const repairServicesCategoryId = '2'; // ID категории "Ремонтные услуги"

                    // Показ/скрытие секций
                    bulletFields.classList.toggle('active', selectedType === 'bullet');
                    serviceFields.classList.toggle('active', selectedType === 'service');

                    // Управление обязательностью и отключением полей
                    bulletInputs.forEach(el => {
                        el.required = selectedType === 'bullet';
                        el.disabled = selectedType !== 'bullet';
                    });
                    serviceInputs.forEach(el => {
                        el.required = selectedType === 'service';
                        el.disabled = selectedType !== 'service';
                    });

                    // Автоматический выбор категории "Ремонтные услуги" для услуг
                    if (selectedType === 'service') {
                        categorySelect.value = repairServicesCategoryId;
                    } else {
                        // Для пулек можно оставить текущую категорию или сбросить на первую product-категорию
                        const firstProductCategory = Array.from(categorySelect.options)
                            .find(opt => opt.getAttribute('data-type') === 'product');
                        if (firstProductCategory && categorySelect.value !== firstProductCategory.value) {
                            categorySelect.value = firstProductCategory.value;
                        }
                    }

                    // Фильтрация категорий по типу
                    Array.from(categorySelect.options).forEach(option => {
                        const optionType = option.getAttribute('data-type');
                        option.style.display = (selectedType === 'bullet' && optionType === 'product') || 
                                             (selectedType === 'service' && optionType === 'service') ? '' : 'none';
                    });
                }

                productTypeSelect.addEventListener('change', updateCategoryOptions);

                // Инициализация при загрузке
                updateCategoryOptions();

                // Мобильное меню
                const menuBtn = document.querySelector('.mobile-menu-btn');
                const closeBtn = document.querySelector('.close-menu');
                const sidebar = document.querySelector('.admin-nav');
                const overlay = document.querySelector('.overlay');

                function toggleMenu(event) {
                    const isOpening = !sidebar.classList.contains('active');
                    document.body.classList.toggle('menu-open', isOpening);
                    overlay.style.display = isOpening ? 'block' : 'none';
                    setTimeout(() => {
                        overlay.classList.toggle('active', isOpening);
                        sidebar.classList.toggle('active', isOpening);
                        if (!isOpening) {
                            setTimeout(() => {
                                if (!sidebar.classList.contains('active')) {
                                    overlay.style.display = 'none';
                                }
                            }, 300);
                        }
                    }, 10);
                    event.stopPropagation();
                }

                if (menuBtn) {
                    menuBtn.addEventListener('click', toggleMenu);
                }
                if (closeBtn) {
                    closeBtn.addEventListener('click', toggleMenu);
                }
                if (overlay) {
                    overlay.addEventListener('click', function(event) {
                        if (sidebar.classList.contains('active')) {
                            toggleMenu(event);
                        }
                    });
                }

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

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    </main>
</body>
</html>