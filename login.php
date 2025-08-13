<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token']) && isset($_COOKIE['user_id'])) {
    try {
        $user_id = clean_input($_COOKIE['user_id']);
        $token = clean_input($_COOKIE['remember_token']);
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && validate_remember_token($pdo, $user_id, $token)) {
            if (!$user['email_verified_at']) {
                $error = 'Пожалуйста, подтвердите ваш email перед входом.';
            } else {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                
                $stmt = $pdo->prepare("SELECT * FROM customers WHERE user_id = ?");
                $stmt->execute([$user['id']]);
                $customer = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($customer) {
                    $_SESSION['fullname'] = $customer['full_name'];
                    $_SESSION['phone'] = $customer['phone'];
                    $_SESSION['address'] = $customer['address'];
                }

                $_SESSION['login_attempts'] = 0;
                logActivity($pdo, 'login_success', "Успешный вход пользователя {$user['username']} через remember_token", null, $user_id);
                header('Location: index.php');
                exit();
            }
        } else {
            error_log("Remember token validation failed for user_id $user_id: Token or expiration invalid");
            setcookie('remember_token', '', time() - 3600, '/', '', true, true);
            setcookie('user_id', '', time() - 3600, '/', '', true, true);
            clear_remember_token($pdo, $user_id);
        }
    } catch (PDOException $e) {
        error_log("Ошибка проверки remember_token: " . $e->getMessage());
        logActivity($pdo, 'login_error', "Ошибка проверки remember_token для user_id $user_id: " . $e->getMessage(), null, null);
    }
}

$error = '';
$login_attempts = $_SESSION['login_attempts'] ?? 0;
$attempted_username = $_POST['username'] ?? ($_SESSION['last_attempted_username'] ?? 'unknown');

if (!isset($max_login_attempts)) {
    $max_login_attempts = 5;
    error_log("Warning: max_login_attempts not defined, using default value 5");
}
if (!isset($login_ban_time)) {
    $login_ban_time = 900;
    error_log("Warning: login_ban_time not defined, using default value 900");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!validate_csrf_token($_POST['csrf_token'])) {
            throw new Exception('Ошибка безопасности: Недействительный токен');
        }

        $username = clean_input($_POST['username']);
        $password = clean_input($_POST['password']);
        $remember = isset($_POST['remember']) && $_POST['remember'] === '1';
        $_SESSION['last_attempted_username'] = $username;

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if (!$user['email_verified_at']) {
                throw new Exception('Пожалуйста, подтвердите ваш email перед входом.');
            }
            if (password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                
                $stmt = $pdo->prepare("SELECT * FROM customers WHERE user_id = ?");
                $stmt->execute([$user['id']]);
                $customer = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($customer) {
                    $_SESSION['fullname'] = $customer['full_name'];
                    $_SESSION['phone'] = $customer['phone'];
                    $_SESSION['address'] = $customer['address'];
                }

                if ($remember) {
                    $token = generate_remember_token();
                    if (set_remember_token($pdo, $user['id'], $token)) {
                        setcookie('remember_token', $token, time() + 30 * 24 * 3600, '/', '', true, true);
                        setcookie('user_id', $user['id'], time() + 30 * 24 * 3600, '/', '', true, true);
                    } else {
                        error_log("Failed to set remember token for user_id {$user['id']}");
                    }
                }

                $_SESSION['login_attempts'] = 0;
                logActivity($pdo, 'login_success', "Успешный вход пользователя $username" . ($remember ? ' с remember_token' : ''), null, $user['id']);
                unset($_SESSION['last_attempted_username']);
                header('Location: index.php');
                exit();
            }
        }

        $_SESSION['login_attempts'] = ++$login_attempts;
        logActivity($pdo, 'login_failed', "Неудачная попытка входа для пользователя $username", null, null);
        $error = 'Неверное имя пользователя или пароль';

        if ($login_attempts >= $max_login_attempts) {
            $_SESSION['login_blocked'] = time() + $login_ban_time;
            logActivity($pdo, 'login_blocked', "Учетная запись $username заблокирована после $login_attempts попыток", null, null);
            error_log("Login blocked until: " . $_SESSION['login_blocked']);
        }

    } catch (PDOException $e) {
        error_log("Ошибка базы данных: " . $e->getMessage());
        logActivity($pdo, 'login_error', "Ошибка базы данных при входе для пользователя $username: " . $e->getMessage(), null, null);
        $error = 'Ошибка системы. Пожалуйста, попробуйте позже.';
    } catch (Exception $e) {
        logActivity($pdo, 'login_error', "Ошибка при входе для пользователя $username: " . $e->getMessage(), null, null);
        $error = $e->getMessage();
    }
}

if (isset($_SESSION['login_blocked']) && time() < $_SESSION['login_blocked']) {
    $remaining_time = $_SESSION['login_blocked'] - time();
    $error = "Доступ заблокирован. Попробуйте через " . ceil($remaining_time/60) . " минут.";
    logActivity($pdo, 'login_blocked_check', "Попытка входа во время блокировки для пользователя $attempted_username", null, null);
}

$csrf_token = generate_csrf_token();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход | pnevmatpro.ru</title>
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
    .attempts-warning {
        font-size: 0.9rem;
        color: #dc3545;
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
            <img src="/assets/logo-admin.png" alt="Logo" width="200" class="mb-4">
            <h2 class="h4">Вход в личный кабинет</h2>
            <p class="text-muted">Пожалуйста, авторизуйтесь</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!isset($_SESSION['login_blocked']) || time() >= $_SESSION['login_blocked']): ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

                <div class="form-floating mb-3">
                    <input type="text"
                           class="form-control <?= $login_attempts > 0 ? 'is-invalid' : '' ?>"
                           id="username"
                           name="username"
                           required
                           autocomplete="username"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    <label for="username"><i class="bi bi-person-circle me-2"></i>Логин</label>
                </div>

                <div class="form-floating mb-3 password-container">
                    <input type="password"
                           class="form-control <?= $login_attempts > 0 ? 'is-invalid' : '' ?>"
                           id="password"
                           name="password"
                           required
                           autocomplete="current-password">
                    <label for="password"><i class="bi bi-lock me-2"></i>Пароль</label>
                    <i class="bi bi-eye-slash password-toggle" id="toggle_password"></i>
                </div>

                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1">
                    <label class="form-check-label" for="remember">
                        Запомнить меня
                    </label>
                </div>

                <?php if ($login_attempts > 0): ?>
                    <div class="attempts-warning text-center mb-3">
                        Неверная попытка входа: <?= $login_attempts ?>/<?= $max_login_attempts ?>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary w-100 py-2">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Войти
                </button>
                
                <div class="text-center mt-3">
                    <a href="forgot_password.php" class="text-decoration-none">Забыли пароль?</a>
                    <span class="mx-2">|</span>
                    <a href="register.php" class="text-decoration-none">Нет аккаунта? Зарегистрируйтесь</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    <?php if ($login_attempts > 0): ?>
    document.getElementById('username').focus();
    <?php endif; ?>
    
    document.getElementById('toggle_password').addEventListener('click', function() {
        const passwordInput = document.getElementById('password');
        const toggleIcon = this;
        const isPassword = passwordInput.type === 'password';
        
        passwordInput.type = isPassword ? 'text' : 'password';
        toggleIcon.classList.toggle('bi-eye', isPassword);
        toggleIcon.classList.toggle('bi-eye-slash', !isPassword);
    });
</script>
</body>
</html>