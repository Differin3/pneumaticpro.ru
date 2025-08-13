<?php
// delete_product.php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Define UPLOAD_DIR if not defined in config.php
if (!defined('UPLOAD_DIR')) {
    define('UPLOAD_DIR', __DIR__ . '/../uploads');
}

// 1. Проверка авторизации администратора
if (!isset($_SESSION['admin']) || !isset($_SESSION['admin']['id'])) {
    header("HTTP/1.1 403 Forbidden");
    exit("Access Denied");
}

// 2. Валидация CSRF-токена
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    error_log("CSRF validation failed for IP: {$_SERVER['REMOTE_ADDR']}");
    die("Invalid CSRF token");
}

try {
    // 3. Получение и валидация ID
    $product_id = (int)($_POST['id'] ?? 0);
    if ($product_id <= 0) {
        throw new Exception("Invalid product ID");
    }

    // 4. Начало транзакции
    $pdo->beginTransaction();

    // 5. Получение информации о продукте для логирования
    $stmt = $pdo->prepare("SELECT name, vendor_code, image FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        throw new Exception("Product not found");
    }

    // 6. Удаление изображения
    if ($product['image']) {
        $file_path = realpath(UPLOAD_DIR . '/' . $product['image']);
        if ($file_path && strpos($file_path, realpath(UPLOAD_DIR)) === 0) {
            if (file_exists($file_path)) {
                if (!unlink($file_path)) {
                    error_log("Failed to delete image for product ID $product_id: $file_path");
                    throw new Exception("Не удалось удалить изображение");
                }
                error_log("Successfully deleted image for product ID $product_id: $file_path");
            } else {
                error_log("Image file not found for product ID $product_id: $file_path");
            }
        } else {
            error_log("Invalid image path for product ID $product_id: $file_path");
        }
    }

    // 7. Удаление товара (каскадное удаление)
    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception("Product not found during deletion");
    }

    // 8. Логирование действия
    $adminId = (int)$_SESSION['admin']['id'];
    $logDescription = "Удалён товар: '{$product['name']}' (ID: $product_id, артикул: {$product['vendor_code']})";
    logActivity($pdo, 'product_delete', $logDescription, $adminId);

    // 9. Фиксация изменений
    $pdo->commit();
    $_SESSION['success'] = "Товар успешно удален";

} catch (PDOException $e) {
    // 10. Откат при ошибке
    $pdo->rollBack();
    error_log("PDO Exception in delete_product.php: " . $e->getMessage());
    $_SESSION['error'] = "Ошибка базы данных: " . $e->getMessage();
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Exception in delete_product.php: " . $e->getMessage());
    $_SESSION['error'] = "Ошибка: " . $e->getMessage();
}

// 11. Перенаправление
header("Location: index.php");
exit();
?>