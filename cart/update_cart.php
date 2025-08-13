<?php
session_start();
ob_start();
require '../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

$response = [
    'status' => 'error',
    'message' => 'Неизвестная ошибка',
    'code' => 500
];

try {
    error_log("update_cart.php: Начало обработки запроса");

    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Для выполнения действия необходимо авторизоваться", 401);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Метод не поддерживается", 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Некорректный формат данных: " . json_last_error_msg(), 400);
    }
    error_log("update_cart.php: Входные данные: " . print_r($input, true));

    $action = $input['action'] ?? '';
    $allowed_actions = ['add', 'update', 'remove', 'clear'];
    
    if (!in_array($action, $allowed_actions)) {
        throw new Exception("Неизвестное действие: " . htmlspecialchars($action), 400);
    }

    $_SESSION['cart'] = $_SESSION['cart'] ?? [];

    switch ($action) {
        case 'add':
            if (empty($input['product_id']) || !is_numeric($input['product_id'])) {
                throw new Exception("Неверный ID товара", 400);
            }

            $productId = (int)$input['product_id'];
            if (!isset($pdo) || !$pdo) {
                throw new Exception("Ошибка соединения с базой данных", 500);
            }

            $stmt = $pdo->prepare("
                SELECT id, name, price, type, availability 
                FROM products 
                WHERE id = :id 
                LIMIT 1
            ");
            $stmt->bindParam(':id', $productId, PDO::PARAM_INT);
            
            if (!$stmt->execute()) {
                throw new Exception("Ошибка запроса к базе данных", 500);
            }

            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                throw new Exception("Товар не найден", 404);
            }

            if ($product['availability'] !== 'in_stock') {
                throw new Exception("Товар временно недоступен", 400);
            }

            if (!isset($_SESSION['cart'][$productId])) {
                $_SESSION['cart'][$productId] = [
                    'quantity' => 1,
                    'name' => $product['name'],
                    'price' => (float)$product['price'],
                    'type' => $product['type']
                ];
            } else {
                $_SESSION['cart'][$productId]['quantity']++;
            }
            break;

        case 'update':
            if (!isset($input['product_id'], $input['quantity']) || 
                !is_numeric($input['product_id']) || 
                !is_numeric($input['quantity'])) {
                throw new Exception("Неверные данные запроса", 400);
            }

            $productId = (int)$input['product_id'];
            $quantity = max(1, (int)$input['quantity']);

            if (!isset($_SESSION['cart'][$productId])) {
                throw new Exception("Товар не найден в корзине", 404);
            }

            $_SESSION['cart'][$productId]['quantity'] = $quantity;
            break;

        case 'remove':
            if (!isset($input['product_id']) || !is_numeric($input['product_id'])) {
                throw new Exception("Неверный ID товара", 400);
            }

            $productId = (int)$input['product_id'];
            if (isset($_SESSION['cart'][$productId])) {
                unset($_SESSION['cart'][$productId]);
            }
            break;

        case 'clear':
            $_SESSION['cart'] = [];
            break;

        default:
            throw new Exception("Неизвестное действие", 400);
    }

    $totalCount = array_sum(array_column($_SESSION['cart'], 'quantity'));
    $totalSum = array_reduce($_SESSION['cart'], function($sum, $item) {
        return $sum + ($item['price'] * $item['quantity']);
    }, 0);

    $response = [
        'status' => 'success',
        'data' => [
            'cart' => $_SESSION['cart'],
            'total_count' => $totalCount,
            'total_sum' => number_format($totalSum, 2, '.', '')
        ],
        'code' => 200
    ];

} catch (PDOException $e) {
    error_log("update_cart.php: Database error: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
    $response = [
        'status' => 'error',
        'message' => 'Ошибка базы данных',
        'code' => 500
    ];
} catch (Exception $e) {
    error_log("update_cart.php: Error: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
    $response = [
        'status' => 'error',
        'message' => $e->getMessage(),
        'code' => $e->getCode() ?: 500
    ];
}

ob_end_clean();
http_response_code($response['code'] ?? 500);
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit();
?>