<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

$admin_count = $pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();

if ($admin_count > 0) {
    header('Location: login.php');
    exit();
}

$error = '';
$success = '';
$csrf_token = generate_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!validate_csrf_token($_POST['csrf_token'])) {
            throw new Exception('Ошибка безопасности');
        }

        $username = clean_input($_POST['username']);
        $email = clean_input($_POST['email']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];

        // Валидация
        if (empty($username) || empty($email) || empty($password)) {
            throw new Exception('Все поля обязательны для заполнения');
        }

        if ($password !== $confirm_password) {
            throw new Exception('Пароли не совпадают');
        }

        if (!is_password_strong($password)) {
            throw new Exception('Пароль должен содержать минимум 8 символов, включая цифры, заглавные буквы и специальные символы');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Неверный формат email');
        }

        // Создание администратора
        $hashed_password = hash_password($password);
        
        $stmt = $pdo->prepare("INSERT INTO admins (username, email, password) VALUES (?, ?, ?)");
        $stmt->execute([$username, $email, $hashed_password]);
        
        $success = 'Администратор успешно создан!';
        header('Location: login.php');
        exit();

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
    <title>Первоначальная настройка | Pnevmatpro.ru</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="icon" href="https://pnevmatpro.ru/assets/favicon.png" type="image/png">
    <style>
        .login-wrapper {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            <img src="../assets/logo-admin.png" alt="Logo" width="150" class="mb-3">
            <h2 class="h5">Создание администратора</h2>
            <p class="text-muted">Настройка учетной записи администратора</p>
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
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="username" name="username" required>
                    <label for="username"><i class="bi bi-person-circle me-2"></i>Логин</label>
                </div>

                <div class="form-floating mb-3">
                    <input type="email" class="form-control" id="email" name="email" required>
                    <label for="email"><i class="bi bi-envelope me-2"></i>Email</label>
                </div>

                <div class="form-floating mb-3 password-container">
                    <input type="password" class="form-control" id="password" name="password" required>
                    <label for="password"><i class="bi bi-lock me-2"></i>Пароль</label>
                    <i class="bi bi-eye-slash password-toggle" id="toggle_password"></i>
                </div>

                <div class="form-floating mb-3 password-container">
                    <input type="password" class="form-control" id="password_confirm" name="confirm_password" required>
                    <label for="password_confirm"><i class="bi bi-lock me-2"></i>Повторите пароль</label>
                    <i class="bi bi-eye-slash password-toggle" id="toggle_password_confirm"></i>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2">
                    <i class="bi bi-person-plus me-2"></i>Создать администратора
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