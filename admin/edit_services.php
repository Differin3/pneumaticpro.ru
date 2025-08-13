<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Проверка авторизации администратора
if (!isset($_SESSION['admin']) || !isset($_SESSION['admin']['id'])) {
    header("HTTP/1.1 403 Forbidden");
    exit("Access Denied");
}

// Генерация CSRF-токена
$csrf_token = generate_csrf_token();

// Получение ID услуги
$service_id = (int)($_GET['id'] ?? 0);
if ($service_id <= 0) {
    $_SESSION['error'] = "Неверный ID услуги";
    header("Location: index.php");
    exit();
}

// Получение данных об услуге
try {
    $stmt = $pdo->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->execute([$service_id]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$service) {
        $_SESSION['error'] = "Услуга не найдена";
        header("Location: index.php");
        exit();
    }
} catch (PDOException $e) {
    error_log("Ошибка при получении услуги: " . $e->getMessage());
    $_SESSION['error'] = "Ошибка базы данных";
    header("Location: index.php");
    exit();
}

// Обработка формы редактирования
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Валидация CSRF-токена
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception("Недействительный CSRF-токен");
        }

        // Получение и очистка данных
        $name = clean_input($_POST['name'] ?? '');
        $description = clean_input($_POST['description'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $duration = (int)($_POST['duration'] ?? 0);
        // Исправлено: $service_ype → $service_type
        $service_type = clean_input($_POST['service_type'] ?? 'repair'); // !!! Исправлено
        $category_id = (int)($_POST['category_id'] ?? 0);

        // Валидация данных
        if (empty($name) || empty($description)) {
            throw new Exception("Название и описание обязательны");
        }
        if ($price <= 0 || $duration <= 0 || $category_id <= 0) {
            throw new Exception("Неверные значения цены, длительности или категории");
        }
        $allowed_service_types = ['repair', 'maintenance', 'custom'];
        // Теперь переменная $service_type существует
        if (!in_array($service_type, $allowed_service_types)) { 
            throw new Exception("Недопустимый тип услуги");
        }

        // Проверка существования категории
        $stmt = $pdo->prepare("SELECT id FROM categories WHERE id = ? AND type = 'service'");
        $stmt->execute([$category_id]);
        if (!$stmt->fetch()) {
            throw new Exception("Категория не найдена или не подходит для услуги");
        }

        // Обновление услуги
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            UPDATE services 
            SET name = ?, description = ?, price = ?, duration = ?, service_type = ?, 
                category_id = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$name, $description, $price, $duration, $service_type, $category_id, $service_id]);

        // Обработка изображения
        $image_updated = false;
        if (isset($_POST['delete_image']) && $_POST['delete_image'] == '1' && $service['image']) {
            if (file_exists(__DIR__ . '/../uploads/products/' . $service['image'])) {
                if (!unlink(__DIR__ . '/../uploads/products/' . $service['image'])) {
                    logToFile("Ошибка удаления изображения: {$service['image']}", "ERROR");
                }
            }
            $stmt = $pdo->prepare("UPDATE services SET image = NULL WHERE id = ?");
            $stmt->execute([$service_id]);
            $image_updated = true;
        } elseif (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            logToFile("Попытка загрузки нового изображения для услуги ID $service_id: " . json_encode($_FILES['image']), "DEBUG");
            $old_image = $service['image'];
            if ($old_image && file_exists(__DIR__ . '/../uploads/products/' . $old_image)) {
                if (!unlink(__DIR__ . '/../uploads/products/' . $old_image)) {
                    logToFile("Ошибка удаления старого изображения: $old_image", "ERROR");
                }
            }
            if ($new_image = secure_upload($_FILES['image'])) {
                $stmt = $pdo->prepare("UPDATE services SET image = ? WHERE id = ?");
                $stmt->execute([$new_image, $service_id]);
                $image_updated = true;
                logToFile("Изображение успешно обновлено: $new_image", "INFO");
            } else {
                throw new Exception("Ошибка загрузки изображения. Проверьте формат и размер файла.");
            }
        }

        // Логирование действия
        $adminId = (int)$_SESSION['admin']['id'];
        $logDescription = "Обновлена услуга: '{$name}' (ID: $service_id, артикул: {$service['vendor_code']})" . ($image_updated ? ", изображение обновлено" : "");
        logActivity($pdo, 'service_edit', $logDescription, $adminId);

        $pdo->commit();
        $_SESSION['success'] = "Услуга успешно обновлена";
        header("Location: index.php");
        exit();

    } catch (Exception $e) {
        // Безопасный откат транзакции
        if (isset($pdo) && $pdo->inTransaction()) { // !!! Исправлено
            $pdo->rollBack();
        }
        logToFile("Ошибка редактирования услуги ID $service_id: {$e->getMessage()}", "ERROR");
        $_SESSION['error'] = "Ошибка: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактировать услугу | pnevmatpro.ru</title>
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
        .card {
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
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
        .btn-lg {
            padding: 10px 20px;
            font-size: 1.1rem;
        }
        .current-image {
            max-width: 100px;
            height: auto;
            border-radius: 4px;
            margin-top: 10px;
        }
        .form-label {
            font-weight: 500;
        }
        .alert {
            border-radius: 8px;
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
            .d-flex {
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
                        <h2 class="mb-0"><i class="bi bi-pencil-square"></i> Редактировать услугу</h2>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Назад к списку
                        </a>
                    </div>

                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger">
                            <?= htmlspecialchars($_SESSION['error']) ?>
                            <?php unset($_SESSION['error']); ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                        <!-- Основная информация -->
                        <div class="card mb-4">
                            <div class="card-header">Основная информация</div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="name" class="form-label">Название *</label>
                                        <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($service['name']) ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="category_id" class="form-label">Категория *</label>
                                        <select class="form-select" id="category_id" name="category_id" required>
                                            <?php
                                            $stmt = $pdo->query("SELECT id, name FROM categories WHERE type = 'service'");
                                            while ($category = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                                $selected = $category['id'] == $service['category_id'] ? 'selected' : '';
                                                echo "<option value=\"{$category['id']}\" $selected>" . htmlspecialchars($category['name']) . "</option>";
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="price" class="form-label">Цена (₽) *</label>
                                        <input type="number" step="0.01" class="form-control" id="price" name="price" value="<?= $service['price'] ?>" required>
                                    </div>
                                    <div class="col-12 mb-3">
                                        <label for="description" class="form-label">Описание *</label>
                                        <textarea class="form-control" id="description" name="description" rows="5" required><?= htmlspecialchars($service['description']) ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Характеристики услуги -->
                        <div class="card mb-4">
                            <div class="card-header">Характеристики услуги</div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="service_type" class="form-label">Тип услуги *</label>
                                        <select class="form-select" id="service_type" name="service_type" required>
                                            <option value="repair" <?= $service['service_type'] === 'repair' ? 'selected' : '' ?>>Ремонт</option>
                                            <option value="maintenance" <?= $service['service_type'] === 'maintenance' ? 'selected' : '' ?>>Обслуживание</option>
                                            <option value="custom" <?= $service['service_type'] === 'custom' ? 'selected' : '' ?>>Индивидуальная</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="duration" class="form-label">Длительность (дни) *</label>
                                        <input type="number" class="form-control" id="duration" name="duration" value="<?= $service['duration'] ?>" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Изображение -->
                        <div class="card mb-4">
                            <div class="card-header">Изображение услуги</div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="image" class="form-label">Загрузить новое изображение</label>
                                    <input type="file" class="form-control" id="image" name="image" accept="image/jpeg,image/png,image/gif">
                                    <small class="form-text text-muted">Максимальный размер файла: 2MB (JPEG, PNG, GIF)</small>
                                    <?php if ($service['image']): ?>
                                        <div class="mt-2">
                                            <img src="/uploads/products/<?= htmlspecialchars($service['image']) ?>" alt="Текущее изображение" class="current-image" style="max-width: 100px; max-height: 100px; object-fit: cover;">
                                            <div class="mt-2">
                                                <label for="delete_image" class="form-label">Удалить текущее изображение</label>
                                                <input type="checkbox" id="delete_image" name="delete_image" value="1">
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Кнопки действий -->
                        <div class="card mt-4">
                            <div class="card-body">
                                <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
                                    <a href="index.php" class="btn btn-secondary btn-lg">
                                        <i class="bi bi-arrow-left"></i> Отмена
                                    </a>
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="bi bi-save"></i> Сохранить изменения
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const menuBtn = document.querySelector('.mobile-menu-btn');
                const closeBtn = document.querySelector('.close-menu');
                const sidebar = document.querySelector('.admin-nav');
                const overlay = document.querySelector('.overlay');

                function toggleMenu(event) {
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
    </main>
</body>
</html>