<?php
session_start();
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

$response = ['status' => 'error', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!validate_csrf_token($_POST['csrf_token'])) {
            throw new Exception('Ошибка безопасности: недействительный токен');
        }

        $user_id = (int)$_POST['user_id'];

        // Валидация
        if (empty($user_id)) {
            throw new Exception('ID пользователя обязателен');
        }

        // Проверка, что пользователь существует
        $stmt = $pdo->prepare("SELECT email_verified_at FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new Exception('Пользователь не найден');
        }

        if ($user['email_verified_at']) {
            throw new Exception('Email уже верифицирован');
        }

        // Обновление статуса верификации
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE users SET email_verified_at = NOW(), updated_at = NOW() WHERE id = ?");
        $stmt->execute([$user_id]);

        // Логирование действия
        logActivity($pdo, 'email_verification', "Администратор верифицировал email пользователя ID $user_id");

        $pdo->commit();

        $response['status'] = 'success';
        $response['message'] = 'Email успешно верифицирован';

    } catch (Exception $e) {
        $pdo->rollBack();
        $response['message'] = $e->getMessage();
    }
}

echo json_encode($response);
?>