<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// 1. Проверка авторизации администратора
if (!isset($_SESSION['admin'])) {
    header("HTTP/1.1 403 Forbidden");
    exit("Access Denied");
}

// 2. Генерация CSRF-токена
$csrf_token = generate_csrf_token();

// 3. Получение ID товара
$product_id = (int)($_GET['id'] ?? 0);
if ($product_id <= 0) {
    $_SESSION['error'] = "Неверный ID товара";
    header("Location: index.php");
    exit();
}

// 4. Получение данных о товаре
try {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        $_SESSION['error'] = "Товар не найден";
        header("Location: index.php");
        exit();
    }
} catch (PDOException $e) {
    error_log("Ошибка при получении товара: " . $e->getMessage());
    $_SESSION['error'] = "Ошибка базы данных";
    header("Location: index.php");
    exit();
}

// 5. Обработка формы редактирования
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
        $diameter = (float)($_POST['diameter'] ?? 0);
        $weight = (float)($_POST['weight'] ?? 0);
        $category_id = (int)($_POST['category_id'] ?? 0);
        $availability = $_POST['availability'] ?? 'in_stock';

        // Валидация данных
        if (empty($name) || empty($description)) {
            throw new Exception("Название и описание обязательны");
        }
        if ($price <= 0 || $diameter <= 0 || $weight <= 0 || $category_id <= 0) {
            throw new Exception("Неверные значения цены, диаметра, веса или категории");
        }
        $allowed_availability = ['in_stock', 'pre_order', 'out_of_stock'];
        if (!in_array($availability, $allowed_availability)) {
            throw new Exception("Недопустимый статус доступности");
        }

        // Проверка существования категории
        $stmt = $pdo->prepare("SELECT id FROM categories WHERE id = ? AND type = 'product'");
        $stmt->execute([$category_id]);
        if (!$stmt->fetch()) {
            throw new Exception("Категория не найдена или не подходит для товара");
        }

        // Обновление товара
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            UPDATE products 
            SET name = ?, description = ?, price = ?, diameter = ?, weight = ?, 
                category_id = ?, availability = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$name, $description, $price, $diameter, $weight, $category_id, $availability, $product_id]);

        // Обработка изображения (если загружено новое)
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $old_image = $product['image'];
            if ($old_image && file_exists(UPLOAD_DIR . '/' . $old_image)) {
                unlink(UPLOAD_DIR . '/' . $old_image);
            }
            if ($new_image = secure_upload($_FILES['image'])) {
                $stmt = $pdo->prepare("UPDATE products SET image = ? WHERE id = ?");
                $stmt->execute([$new_image, $product_id]);
            } else {
                throw new Exception("Ошибка загрузки изображения");
            }
        }

        $pdo->commit();
        $_SESSION['success'] = "Товар успешно обновлен";
        header("Location: index.php");
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Ошибка: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактировать товар | PneumaticPro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body>
<div class="container mt-5">
    <h2>Редактировать товар</h2>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger">
            <?= htmlspecialchars($_SESSION['error']) ?>
            <?php unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

        <div class="mb-3">
            <label for="name" class="form-label">Название</label>
            <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($product['name']) ?>" required>
        </div>

        <div class="mb-3">
            <label for="description" class="form-label">Описание</label>
            <textarea class="form-control" id="description" name="description" required><?= htmlspecialchars($product['description']) ?></textarea>
        </div>

        <div class="mb-3">
            <label for="price" class="form-label">Цена</label>
            <input type="number" step="0.01" class="form-control" id="price" name="price" value="<?= $product['price'] ?>" required>
        </div>

        <div class="mb-3">
            <label for="diameter" class="form-label">Диаметр (мм)</label>
            <input type="number" step="0.01" class="form-control" id="diameter" name="diameter" value="<?= $product['diameter'] ?>" required>
        </div>

        <div class="mb-3">
            <label for="weight" class="form-label">Вес (г)</label>
            <input type="number" step="0.01" class="form-control" id="weight" name="weight" value="<?= $product['weight'] ?>" required>
        </div>

        <div class="mb-3">
            <label for="category_id" class="form-label">Категория</label>
            <select class="form-select" id="category_id" name="category_id" required>
                <?php
                $stmt = $pdo->query("SELECT id, name FROM categories WHERE type = 'product'");
                while ($category = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $selected = $category['id'] == $product['category_id'] ? 'selected' : '';
                    echo "<option value=\"{$category['id']}\" $selected>" . htmlspecialchars($category['name']) . "</option>";
                }
                ?>
            </select>
        </div>

        <div class="mb-3">
            <label for="availability" class="form-label">Доступность</label>
            <select class="form-select" id="availability" name="availability" required>
                <option value="in_stock" <?= $product['availability'] === 'in_stock' ? 'selected' : '' ?>>В наличии</option>
                <option value="pre_order" <?= $product['availability'] === 'pre_order' ? 'selected' : '' ?>>Предзаказ</option>
                <option value="out_of_stock" <?= $product['availability'] === 'out_of_stock' ? 'selected' : '' ?>>Нет в наличии</option>
            </select>
        </div>

        <div class="mb-3">
            <label for="image" class="form-label">Изображение</label>
            <input type="file" class="form-control" id="image" name="image" accept="image/jpeg,image/png,image/webp">
            <?php if ($product['image']): ?>
                <div class="mt-2">
                    <img src="<?= htmlspecialchars(UPLOAD_DIR . '/' . $product['image']) ?>" alt="Текущее изображение" width="100">
                </div>
            <?php endif; ?>
        </div>

        <button type="submit" class="btn btn-primary">Сохранить изменения</button>
        <a href="index.php" class="btn btn-secondary">Отмена</a>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>