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

    $item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
    if ($item_id <= 0) {
        throw new Exception("Неверный ID товара", 400);
    }

    if (isset($_SESSION['cart'])) {
        foreach ($_SESSION['cart'] as $key => $item) {
            if ($item['id'] == $item_id) {
                unset($_SESSION['cart'][$key]);
                $_SESSION['cart'] = array_values($_SESSION['cart']);
                $total_count = array_sum(array_column($_SESSION['cart'], 'quantity'));
                $response = [
                    'status' => 'success',
                    'message' => 'Товар удален из корзины',
                    'data' => ['total_count' => $total_count]
                ];
                break;
            }
        }
    } else {
        throw new Exception("Корзина пуста", 400);
    }

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    $response = ['status' => 'error', 'message' => $e->getMessage()];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
?>м