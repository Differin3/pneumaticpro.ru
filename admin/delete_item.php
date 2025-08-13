<?php
// delete_item.php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Проверка авторизации администратора
if (!isset($_SESSION['admin']) || !isset($_SESSION['admin']['id'])) {
    header("HTTP/1.1 403 Forbidden");
    exit("Access Denied");
}

// Валидация CSRF-токена
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    error_log("CSRF validation failed for IP: {$_SERVER['REMOTE_ADDR']}");
    header("HTTP/1.1 403 Forbidden");
    exit("Invalid CSRF token");
}

try {
    // Получение и валидация параметров
    $id = (int)($_POST['id'] ?? 0);
    $table = $_POST['table'] ?? '';
    
    if ($id <= 0) {
        throw new Exception("Invalid item ID");
    }
    
    // Проверка допустимых таблиц
    $allowed_tables = ['products', 'services'];
    if (!in_array($table, $allowed_tables)) {
        throw new Exception("Invalid table name");
    }

    // Определение директории для изображений
    if (!defined('UPLOAD_DIR')) {
        define('UPLOAD_DIR', __DIR__ . '/../Uploads');
    }

    // Начало транзакции
    $pdo->beginTransaction();

    // Получение информации об элементе для логирования
    $stmt = $pdo->prepare("SELECT name, vendor_code, image FROM `$table` WHERE id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        throw new Exception("Item not found");
    }

    // Удаление изображения
    if ($item['image']) {
        $file_path = realpath(UPLOAD_DIR . '/' . $table . '/' . $item['image']);
        if ($file_path && strpos($file_path, realpath(UPLOAD_DIR)) === 0) {
            if (file_exists($file_path)) {
                if (!unlink($file_path)) {
                    error_log("Failed to delete image for item ID $id in table $table: $file_path");
                    throw new Exception("Не удалось удалить изображение");
                }
                error_log("Successfully deleted image for item ID $id in table $table: $file_path");
            } else {
                error_log("Image file not found for item ID $id in table $table: $file_path");
            }
        } else {
            error_log("Invalid image path for item ID $id in table $table: $file_path");
        }
    }

    // Удаление записи
    $stmt = $pdo->prepare("DELETE FROM `$table` WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception("Item not found during deletion");
    }

    // Логирование действия
    $adminId = (int)$_SESSION['admin']['id'];
    $type = $table === 'products' ? 'product_delete' : 'service_delete';
    $logDescription = "Удалён " . ($table === 'products' ? 'товар' : 'услуга') . ": '{$item['name']}' (ID: $id, артикул: {$item['vendor_code']})";
    logActivity($pdo, $type, $logDescription, $adminId);

    // Фиксация изменений
    $pdo->commit();
    
    // Отправка успешного ответа
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Элемент успешно удалён']);
    
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("PDO Exception in delete_item.php: " . $e->getMessage());
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(['success' => false, 'message' => "Ошибка базы данных: " . $e->getMessage()]);
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Exception in delete_item.php: " . $e->getMessage());
    header("HTTP/1.1 400 Bad Request");
    echo json_encode(['success' => false, 'message' => "Ошибка: " . $e->getMessage()]);
}

exit();
?>