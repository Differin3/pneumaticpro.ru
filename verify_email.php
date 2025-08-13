<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$error = '';
$success = '';

if (!isset($_GET['token']) || !isset($_GET['email'])) {
    $error = 'Недействительная ссылка подтверждения.';
} else {
    try {
        $token = clean_input($_GET['token']);
        $email = clean_input($_GET['email']);
        
        $stmt = $pdo->prepare("SELECT id, email_verification_sent_at FROM users WHERE email = ? AND email_verification_token = ?");
        $stmt->execute([$email, $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $sent_time = strtotime($user['email_verification_sent_at']);
            if ((time() - $sent_time) > 24 * 3600) {
                $error = 'Ссылка подтверждения истекла. Пожалуйста, запросите новую.';
            } else {
                $stmt = $pdo->prepare("UPDATE users SET email_verified_at = NOW(), email_verification_token = NULL, email_verification_sent_at = NULL WHERE id = ?");
                $stmt->execute([$user['id']]);
                
                logActivity($pdo, 'email_verified', "Email $email успешно подтвержден", null, $user['id']);
                $success = 'Ваш email успешно подтвержден! Теперь вы можете войти в систему.';
            }
        } else {
            $error = 'Недействительная ссылка подтверждения.';
        }
    } catch (PDOException $e) {
        logToFile("Error verifying email: {$e->getMessage()}", "ERROR");
        $error = 'Ошибка системы. Пожалуйста, попробуйте позже.';
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Подтверждение Email | PneumaticPro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="icon" href="https://pnevmatpro.ru/assets/favicon.png" type="image/png">
    <style>
        .login-wrapper {
            min-height: 100vh;
            background: linear-gradient(135deg, #2A3950 0%, #1A2332 100%);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
            padding: 2rem;
            backdrop-filter: blur(10px);
        }
    </style>
</head>
<body>
<div class="login-wrapper">
    <div class="login-card">
        <div class="text-center mb-4">
            <img src="/assets/logo-admin.png" alt="Logo" width="150" class="mb-3">
            <h2 class="h5">Подтверждение Email</h2>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <div class="text-center">
                <a href="login.php" class="btn btn-primary">Войти</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>