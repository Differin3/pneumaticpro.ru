<?php
session_start();
header('Content-Type: application/json');

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

$response = ['status' => 'error', 'message' => 'Неизвестная ошибка'];

try {
    // Проверка CSRF-токена
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        throw new Exception("Недействительный CSRF-токен", 403);
    }

    $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 0;

    if ($product_id <= 0) {
        throw new Exception("Неверный ID товара", 400);
    }
    if ($quantity < 1) {
        throw new Exception("Количество должно быть больше 0", 400);
    }

    if (isset($_SESSION['cart'][$product_id])) {
        $_SESSION['cart'][$product_id]['quantity'] = $quantity;
        $total_count = array_sum(array_column($_SESSION['cart'], 'quantity'));
        $response = [
            'status' => 'success',
            'message' => 'Количество обновлено',
            'data' => ['total_count' => $total_count]
        ];
    } else {
        throw new Exception("Товар не найден в корзине", 400);
    }

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    $response = ['status' => 'error', 'message' => $e->getMessage()];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
?>