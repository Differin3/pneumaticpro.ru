<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/email.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';
$csrf_token = generate_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!validate_csrf_token($_POST['csrf_token'])) {
            throw new Exception('Ошибка безопасности.');
        }

        $email = clean_input($_POST['email']);
        if (empty($email)) {
            throw new Exception('Пожалуйста, введите email.');
        }

        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare("INSERT INTO password_reset_tokens (email, token) VALUES (?, ?) ON DUPLICATE KEY UPDATE token = ?, created_at = NOW()");
            $stmt->execute([$email, $token, $token]);

            if (sendPasswordResetEmail($email, $token)) {
                $success = "Инструкции по сбросу пароля отправлены на ваш email. Ваш логин: " . htmlspecialchars($user['username']) . ".";
                logActivity($pdo, 'password_reset_request', "Запрошен сброс пароля для $email, имя пользователя: {$user['username']}", null, $user['id']);
            } else {
                throw new Exception('Ошибка отправки письма. Пожалуйста, попробуйте позже.');
            }
        } else {
            $success = 'Инструкции по сбросу пароля отправлены на ваш email.'; // Не раскрываем наличие email
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
        logActivity($pdo, 'password_reset_error', "Ошибка при запросе сброса пароля: {$e->getMessage()}", null, null);
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Восстановление пароля | Pnevmatpro.ru</title>
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
        .form-floating label { color: #6c757d; }
        @media (max-width: 576px) {
            .login-card {
                padding: 1.5rem;
            }
            .form-floating label {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
<div class="login-wrapper">
    <div class="login-card">
        <div class="text-center mb-4">
            <img src="/assets/logo-admin.png" alt="Logo" width="150" class="mb-3">
            <h2 class="h5">Восстановление пароля</h2>
            <p class="text-muted">Введите ваш email для сброса пароля</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= $success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <div class="text-center mt-3">
                <a href="login.php" class="btn btn-secondary w-100 py-2">
                    <i class="bi bi-arrow-left me-2"></i>Назад к входу
                </a>
            </div>
        <?php else: ?>
            <form method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <div class="form-floating mb-3">
                    <input type="email" class="form-control" id="email" name="email" required>
                    <label for="email"><i class="bi bi-envelope me-2"></i>Email</label>
                    <div class="invalid-feedback">Пожалуйста, введите действительный email.</div>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2 mb-2">
                    <i class="bi bi-arrow-repeat me-2"></i>Отправить ссылку
                </button>
                <div class="text-center">
                    <a href="login.php" class="btn btn-secondary w-100 py-2">
                        <i class="bi bi-arrow-left me-2"></i>Назад к входу
                    </a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    form.addEventListener('submit', function(event) {
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        }
        form.classList.add('was-validated');
    });
});
</script>
</body>
</html>