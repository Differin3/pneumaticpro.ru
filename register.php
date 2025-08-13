<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/email.php';

error_log("Starting register.php execution");

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';
$csrf_token = generate_csrf_token();
error_log("Generated CSRF token: " . $csrf_token);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("Processing POST request");
    try {
        error_log("Submitted CSRF token: " . ($_POST['csrf_token'] ?? 'not set'));

        if (!isset($_POST['privacy_policy']) || $_POST['privacy_policy'] !== 'on') {
            throw new Exception('Вы должны согласиться с политикой конфиденциальности.');
        }

        if (!validate_csrf_token($_POST['csrf_token'])) {
            logToFile("CSRF validation failed for IP: {$_SERVER['REMOTE_ADDR']}", "ERROR");
            throw new Exception('Ошибка безопасности.');
        }

        $username = clean_input($_POST['username']);
        $email = clean_input($_POST['email']);
        $password = clean_input($_POST['password']);
        $password_confirm = clean_input($_POST['password_confirm']);
        $phone = clean_input($_POST['phone']);

        $required_fields = [$username, $email, $password, $phone];
        if (in_array('', $required_fields, true)) {
            throw new Exception('Все поля обязательны для заполнения.');
        }

        if ($password !== $password_confirm) {
            throw new Exception('Пароли не совпадают.');
        }

        if (!is_password_strong($password)) {
            throw new Exception('Пароль должен содержать минимум 8 символов, включая заглавные буквы, цифры и специальные символы.');
        }

        if (!validate_phone($phone)) {
            throw new Exception('Некорректный формат номера телефона.');
        }

        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new Exception('Email уже занят.');
        }

        $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ?");
        $stmt->execute([$phone]);
        if ($stmt->fetch()) {
            throw new Exception('Номер телефона уже зарегистрирован.');
        }

        $pdo->beginTransaction();

        try {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
            $stmt->execute([$username, $email, $password_hash]);
            $user_id = $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO customers (user_id, full_name, phone) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $username, $phone]);

            $pdo->commit();

            if (sendVerificationEmail($user_id, $email, $username)) {
                $success = 'Регистрация успешна! Пожалуйста, проверьте вашу почту для подтверждения email.';
                logToFile("User registered successfully: {$username} (ID: {$user_id})", "INFO");
                logActivity($pdo, 'register', "Регистрация пользователя {$username}", null, $user_id);
            } else {
                throw new Exception('Ошибка отправки письма подтверждения. Пожалуйста, свяжитесь с поддержкой.');
            }

        } catch (PDOException $e) {
            $pdo->rollBack();
            logToFile("Database error during registration: {$e->getMessage()}", "ERROR");
            throw new Exception('Ошибка регистрации: ' . $e->getMessage());
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

error_log("Rendering HTML");
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация | PneumaticPro</title>
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
            padding: 1.5rem;
            backdrop-filter: blur(10px);
        }
        .form-floating label { 
            color: #6c757d;
            font-size: 0.9rem;
        }
        .form-floating input {
            padding: 0.5rem 0.75rem;
        }
        .mb-3, .mb-4 {
            margin-bottom: 0.75rem !important;
        }
        .btn-primary {
            background-color: #007bff;
            border-color: #007bff;
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
        <div class="text-center mb-3">
            <img src="/assets/logo-admin.png" alt="Logo" width="150" class="mb-3">
            <h2 class="h5">Регистрация</h2>
            <p class="text-muted small">Создайте новый аккаунт</p>
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
        <?php endif; ?>

        <?php if (!$success): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

            <div class="form-floating mb-3">
                <input type="text" 
                       class="form-control <?= !empty($_POST['username']) ? 'bg-warning-subtle' : '' ?>" 
                       id="username" 
                       name="username" 
                       required
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                <label for="username"><i class="bi bi-person-circle me-2"></i>Имя пользователя</label>
            </div>

            <div class="form-floating mb-3">
                <input type="email" 
                       class="form-control <?= !empty($_POST['email']) ? 'bg-warning-subtle' : '' ?>" 
                       id="email" 
                       name="email" 
                       required
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                <label for="email"><i class="bi bi-envelope me-2"></i>Email</label>
            </div>

            <div class="form-floating mb-3">
                <input type="tel" 
                       class="form-control <?= !empty($_POST['phone']) ? 'bg-warning-subtle' : '' ?>" 
                       id="phone" 
                       name="phone" 
                       required
                       pattern="\+?[0-9\s\-\(\)]{7,20}"
                       title="Формат: +7 (XXX) XXX-XX-XX"
                       value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                <label for="phone"><i class="bi bi-phone me-2"></i>Телефон</label>
            </div>

            <div class="form-floating mb-3 password-container">
                <input type="password" 
                       class="form-control <?= !empty($_POST['password']) ? 'bg-warning-subtle' : '' ?>" 
                       id="password" 
                       name="password" 
                       required>
                <label for="password"><i class="bi bi-lock me-2"></i>Пароль</label>
                <i class="bi bi-eye-slash password-toggle" id="toggle_password"></i>
            </div>

            <div class="form-floating mb-3 password-container">
                <input type="password" 
                       class="form-control <?= !empty($_POST['password_confirm']) ? 'bg-warning-subtle' : '' ?>" 
                       id="password_confirm" 
                       name="password_confirm" 
                       required>
                <label for="password_confirm"><i class="bi bi-lock me-2"></i>Повторите пароль</label>
                <i class="bi bi-eye-slash password-toggle" id="toggle_password_confirm"></i>
            </div>

            <div class="mb-3 form-check">
                <input type="checkbox" 
                       class="form-check-input" 
                       id="privacy_policy" 
                       name="privacy_policy" 
                       required>
                <label class="form-check-label" for="privacy_policy">
                    Я согласен с <a href="privacy-policy.php" target="_blank" class="text-decoration-none">политикой конфиденциальности</a>
                </label>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2">
                <i class="bi bi-person-plus me-2"></i>Зарегистрироваться
            </button>

            <div class="text-center mt-2">
                <a href="login.php" class="text-decoration-none small">Уже есть аккаунт? Войдите</a>
            </div>
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
<?php
error_log("register.php executed successfully");
?>