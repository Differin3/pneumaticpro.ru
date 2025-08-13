<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$response = ['status' => 'error', 'message' => ''];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Недопустимый метод запроса');
    }

    if (!validate_csrf_token($_POST['csrf_token'])) {
        throw new Exception('Недействительный CSRF-токен');
    }

    $email = clean_input($_POST['email'] ?? '');
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Недействительный email');
    }

    $stmt = $pdo->prepare("SELECT username FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $response['status'] = 'success';
        $response['username'] = $user['username'];
        logActivity($pdo, 'username_fetch', "Получено имя пользователя для email $email", null, null);
    } else {
        $response['status'] = 'success'; // Не раскрываем, существует ли email
        $response['username'] = '';
    }

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    logActivity($pdo, 'username_fetch_error', "Ошибка при получении имени пользователя: {$e->getMessage()}", null, null);
}

echo json_encode($response);
exit();