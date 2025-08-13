<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$error = '';
$csrf_token = generate_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Валидация CSRF
        if (!validate_csrf_token($_POST['csrf_token'])) {
            throw new Exception('Ошибка безопасности');
        }

        // Валидация SmartCaptcha
        if (!isset($_POST['smart-token']) || !verifySmartCaptcha($_POST['smart-token'])) {
            throw new Exception('Ошибка проверки капчи');
        }

        // Валидация согласия с политикой конфиденциальности
        if (!isset($_POST['privacy_policy']) || $_POST['privacy_policy'] !== 'on') {
            throw new Exception('Необходимо согласиться с политикой конфиденциальности');
        }

        // Получение данных
        $username = clean_input($_POST['username']);
        $email = clean_input($_POST['email']);
        $password = clean_input($_POST['password']);
        $password_confirm = clean_input($_POST['password_confirm']);
        $phone = clean_input($_POST['phone']);

        // Валидация
        $required_fields = [$username, $email, $password, $phone];
        if (in_array('', $required_fields, true)) {
            throw new Exception('Все поля обязательны для заполнения');
        }

        if ($password !== $password_confirm) {
            throw new Exception('Пароли не совпадают');
        }

        // Проверка уникальности (users)
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            throw new Exception('Имя пользователя или email уже заняты');
        }

        // Проверка уникальности (customers)
        $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ?");
        $stmt->execute([$phone]);
        if ($stmt->fetch()) {
            throw new Exception('Номер телефона уже зарегистрирован');
        }

        // Транзакция
        $pdo->beginTransaction();

        try {
            // Создание пользователя
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
            $stmt->execute([$username, $email, $password_hash]);
            $user_id = $pdo->lastInsertId();

            // Создание клиента
            $stmt = $pdo->prepare("INSERT INTO customers (user_id, full_name, phone) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $username, $phone]);

            $pdo->commit();

            // Авторизация
            $_SESSION['user_id'] = $user_id;
            $_SESSION['username'] = $username;
            header('Location: index.php');
            exit();

        } catch (PDOException $e) {
            $pdo->rollBack();
            throw new Exception('Ошибка регистрации: ' . $e->getMessage());
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
    <title>Регистрация | PneumaticPro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://smartcaptcha.yandexcloud.net/captcha.js" defer></script>
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
    </style>
</head>
<body>
<div class="login-wrapper">
    <div class="login-card">
        <div class="text-center mb-4">
            <img src="/assets/logo.png" alt="Logo" width="200" class="mb-4">
            <h2 class="h4">Регистрация</h2>
            <p class="text-muted">Создайте новый аккаунт</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="smart-token" id="smart-token">

            <div class="form-floating mb-3">
                <input type="text" 
                       class="form-control" 
                       id="username" 
                       name="username" 
                       required
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                <label for="username"><i class="bi bi-person-circle me-2"></i>Имя пользователя</label>
            </div>

            <div class="form-floating mb-3">
                <input type="email" 
                       class="form-control" 
                       id="email" 
                       name="email" 
                       required
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                <label for="email"><i class="bi bi-envelope me-2"></i>Email</label>
            </div>

            <div class="form-floating mb-3">
                <input type="tel" 
                       class="form-control" 
                       id="phone" 
                       name="phone" 
                       required
                       pattern="\+?[0-9\s\-\(\)]{7,20}"
                       title="Формат: +7 (XXX) XXX-XX-XX"
                       value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                <label for="phone"><i class="bi bi-phone me-2"></i>Телефон</label>
            </div>

            <div class="form-floating mb-3">
                <input type="password" 
                       class="form-control" 
                       id="password" 
                       name="password" 
                       required>
                <label for="password"><i class="bi bi-lock me-2"></i>Пароль</label>
            </div>

            <div class="form-floating mb-3">
                <input type="password" 
                       class="form-control" 
                       id="password_confirm" 
                       name="password_confirm" 
                       required>
                <label for="password_confirm"><i class="bi bi-lock me-2"></i>Повторите пароль</label>
            </div>

            <div class="mb-3">
                <div id="captcha-container" 
                     data-sitekey="<?= htmlspecialchars(SMARTCAPTCHA_CLIENT_KEY) ?>"
                     data-callback="onSmartCaptchaSuccess">
                </div>
            </div>

            <div class="mb-3 form-check">
                <input type="checkbox" 
                       class="form-check-input" 
                       id="privacy_policy" 
                       name="privacy_policy" 
                       required>
                <label class="form-check-label" for="privacy_policy">
                    Я согласен с <a href="privacy-policy.php" target="_blank">политикой конфиденциальности</a>
                </label>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2">
                <i class="bi bi-person-plus me-2"></i>Зарегистрироваться
            </button>

            <div class="text-center mt-3">
                <a href="login.php" class="text-decoration-none">Уже есть аккаунт? Войдите</a>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function onSmartCaptchaSuccess(token) {
        document.getElementById('smart-token').value = token;
    }
</script>
</body>
</html>