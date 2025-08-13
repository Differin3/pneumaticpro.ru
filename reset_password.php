<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';
$csrf_token = generate_csrf_token();

if (!isset($_GET['token']) || !isset($_GET['email'])) {
    $error = ' Недействительная ссылка для сброса пароля. Закройти это окно и апросите ссылку повторно.';
} else {
    $email = clean_input($_GET['email']);
    $token = clean_input($_GET['token']);
    
    try {
        $stmt = $pdo->prepare("SELECT created_at FROM password_reset_tokens WHERE email = ? AND token = ?");
        $stmt->execute([$email, $token]);
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reset || (time() - strtotime($reset['created_at']) > 3600)) {
            $error = 'Ссылка для сброса пароля недействительна или истекла. Запросите ссылку повторно.';
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!validate_csrf_token($_POST['csrf_token'])) {
                throw new Exception('Ошибка безопасности.');
            }

            $password = clean_input($_POST['password']);
            $password_confirm = clean_input($_POST['password_confirm']);

            if ($password !== $password_confirm) {
                throw new Exception('Пароли не совпадают.');
            }

            if (!is_password_strong($password)) {
                throw new Exception('Пароль должен содержать минимум 8 символов, включая заглавные буквы, цифры и специальные символы.');
            }

            $pdo->beginTransaction();
            try {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
                $stmt->execute([$password_hash, $email]);

                $stmt = $pdo->prepare("DELETE FROM password_reset_tokens WHERE email = ?");
                $stmt->execute([$email]);

                $pdo->commit();
                $success = 'Пароль успешно изменен. Теперь вы можете войти.';
                logActivity($pdo, 'password_reset', "Пароль успешно сброшен для $email");
            } catch (PDOException $e) {
                $pdo->rollBack();
                throw new Exception('Ошибка изменения пароля: ' . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Сброс пароля | Pnevmatpro.ru</title>
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
        .password-container {
            position: relative;
        }
        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #6c757d;
            font-size: 1.2rem;
        }
        .form-floating input[type="password"],
        .form-floating input[type="text"] {
            padding-right: 40px;
        }
    </style>
</head>
<body>
<div class="login-wrapper">
    <div class="login-card">
        <div class="text-center mb-4">
            <img src="/assets/logo-admin.png" alt="Logo" width="150" class="mb-3">
            <h2 class="h5">Сброс пароля</h2>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <div class="text-center mt-3">
                <a href="reset_password.php" class="btn btn-secondary w-100 py-2">
                    <i class="bi bi-arrow-left me-2"></i>Назад
                </a>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <div class="text-center mt-3">
                <a href="login.php" class="btn btn-primary w-100 py-2">
                    <i class="bi bi-check-circle me-2"></i>Войти
                </a>
            </div>
        <?php elseif (!$error): ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="form-floating mb-3 password-container">
                    <input type="password" class="form-control" id="password" name="password" required>
                    <label for="password"><i class="bi bi-lock me-2"></i>Новый пароль</label>
                    <i class="bi bi-eye-slash password-toggle" id="toggle_password"></i>
                </div>
                <div class="form-floating mb-3 password-container">
                    <input type="password" class="form-control" id="password_confirm" name="password_confirm" required>
                    <label for="password_confirm"><i class="bi bi-lock me-2"></i>Повторите пароль</label>
                    <i class="bi bi-eye-slash password-toggle" id="toggle_password_confirm"></i>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2 mb-2">
                    <i class="bi bi-check-circle me-2"></i>Изменить пароль
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function setupPasswordToggle(toggleId, inputId) {
        const toggle = document.getElementById(toggleId);
        const input = document.getElementById(inputId);
        toggle.addEventListener('click', function() {
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            this.classList.toggle('bi-eye', isPassword);
            this.classList.toggle('bi-eye-slash', !isPassword);
        });
    }
    
    setupPasswordToggle('toggle_password', 'password');
    setupPasswordToggle('toggle_password_confirm', 'password_confirm');
</script>
</body>
</html>