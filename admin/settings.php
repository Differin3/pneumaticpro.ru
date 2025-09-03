<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['admin'])) {
    header('Location: login.php');
    exit();
}

$error = '';
$success = '';

try {
    $stmt = $pdo->prepare("SELECT username, full_name, email, notifications_enabled, telegram_notifications_enabled FROM admins WHERE id = ?");
    $stmt->execute([$_SESSION['admin']['id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    $admin['full_name'] = $admin['full_name'] ?? '';
    $admin['email'] = $admin['email'] ?? '';
    $admin['notifications_enabled'] = $admin['notifications_enabled'] ?? 0;
    $admin['telegram_notifications_enabled'] = $admin['telegram_notifications_enabled'] ?? 0;
} catch (PDOException $e) {
    $error = "Ошибка загрузки данных: " . $e->getMessage();
    $admin = ['full_name' => '', 'email' => '', 'notifications_enabled' => 0, 'telegram_notifications_enabled' => 0];
}

try {
    $stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('telegram_bot_token', 'telegram_chat_id', 'cdek_account', 'cdek_secure_password')");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    $telegram_bot_token = $settings['telegram_bot_token'] ?? '';
    $telegram_chat_id = $settings['telegram_chat_id'] ?? '7071144296';
    $cdek_account = $settings['cdek_account'] ?? '';
    $cdek_secure_password = $settings['cdek_secure_password'] ?? '';
} catch (PDOException $e) {
    $error = "Ошибка загрузки настроек: " . $e->getMessage();
    $telegram_bot_token = '';
    $telegram_chat_id = '7071144296';
    $cdek_account = '';
    $cdek_secure_password = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    try {
        if (!validate_csrf_token($_POST['csrf_token'])) {
            throw new Exception('Ошибка безопасности: недействительный токен');
        }

        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if (empty($current_password) || empty($new_password)) {
            throw new Exception('Все поля обязательны для заполнения');
        }

        if ($new_password !== $confirm_password) {
            throw new Exception('Новые пароли не совпадают');
        }

        if (!is_password_strong($new_password)) {
            throw new Exception('Пароль должен содержать минимум 8 символов, включая цифры и заглавные буквы');
        }

        $stmt = $pdo->prepare("SELECT password FROM admins WHERE id = ?");
        $stmt->execute([$_SESSION['admin']['id']]);
        $admin_password = $stmt->fetchColumn();

        if (!verify_password($current_password, $admin_password)) {
            throw new Exception('Текущий пароль указан неверно');
        }

        $new_hash = hash_password($new_password);
        $pdo->prepare("UPDATE admins SET password = ? WHERE id = ?")
            ->execute([$new_hash, $_SESSION['admin']['id']]);

        logActivity($pdo, 'password_change', 'Администратор сменил пароль', $_SESSION['admin']['id']);
        $success = 'Пароль успешно изменен!';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    try {
        if (!validate_csrf_token($_POST['csrf_token'])) {
            throw new Exception('Ошибка безопасности: недействительный токен');
        }

        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);

        if (empty($full_name) || empty($email)) {
            throw new Exception('Все поля обязательны для заполнения');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Некорректный формат email');
        }

        $pdo->prepare("UPDATE admins SET full_name = ?, email = ? WHERE id = ?")
            ->execute([$full_name, $email, $_SESSION['admin']['id']]);

        $_SESSION['admin']['full_name'] = $full_name;
        $_SESSION['admin']['email'] = $email;

        logActivity($pdo, 'profile_update', 'Администратор обновил профиль', $_SESSION['admin']['id']);
        $success = 'Профиль успешно обновлен!';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_notifications'])) {
    try {
        if (!validate_csrf_token($_POST['csrf_token'])) {
            throw new Exception('Ошибка безопасности: недействительный токен');
        }

        $notifications_enabled = isset($_POST['notifications_enabled']) ? 1 : 0;
        $telegram_notifications_enabled = isset($_POST['telegram_notifications_enabled']) ? 1 : 0;

        $pdo->prepare("UPDATE admins SET notifications_enabled = ?, telegram_notifications_enabled = ? WHERE id = ?")
            ->execute([$notifications_enabled, $telegram_notifications_enabled, $_SESSION['admin']['id']]);

        logActivity($pdo, 'notifications_update', 'Администратор обновил настройки уведомлений', $_SESSION['admin']['id']);
        $success = 'Настройки уведомлений обновлены!';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_telegram'])) {
    try {
        if (!validate_csrf_token($_POST['csrf_token'])) {
            throw new Exception('Ошибка безопасности: недействительный токен');
        }

        $telegram_bot_token = trim($_POST['telegram_bot_token']);
        $telegram_chat_id = trim($_POST['telegram_chat_id']);

        if (empty($telegram_bot_token) || empty($telegram_chat_id)) {
            throw new Exception('Все поля обязательны для заполнения');
        }

        $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('telegram_bot_token', ?) 
                       ON DUPLICATE KEY UPDATE `value` = ?")
            ->execute([$telegram_bot_token, $telegram_bot_token]);
        $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('telegram_chat_id', ?) 
                       ON DUPLICATE KEY UPDATE `value` = ?")
            ->execute([$telegram_chat_id, $telegram_chat_id]);

        logActivity($pdo, 'telegram_settings_update', 'Администратор обновил настройки Telegram', $_SESSION['admin']['id']);
        $success = 'Настройки Telegram обновлены!';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_telegram'])) {
    try {
        if (!validate_csrf_token($_POST['csrf_token'])) {
            throw new Exception('Ошибка безопасности: недействительный токен');
        }

        if (sendTelegramTestMessage($pdo)) {
            $success = 'Тестовое сообщение успешно отправлено в Telegram!';
        } else {
            throw new Exception('Не удалось отправить тестовое сообщение. Проверьте токен и chat_id.');
        }

        logActivity($pdo, 'telegram_test_message', 'Администратор отправил тестовое сообщение в Telegram', $_SESSION['admin']['id']);
        $success = 'Тестовое сообщение успешно отправлено в Telegram!';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_cdek'])) {
    try {
        if (!validate_csrf_token($_POST['csrf_token'])) {
            throw new Exception('Ошибка безопасности: недействительный токен');
        }

        $cdek_account = trim($_POST['cdek_account']);
        $cdek_secure_password = trim($_POST['cdek_secure_password']);

        if (empty($cdek_account) || empty($cdek_secure_password)) {
            throw new Exception('Все поля обязательны для заполнения');
        }

        $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('cdek_account', ?) 
                       ON DUPLICATE KEY UPDATE `value` = ?")
            ->execute([$cdek_account, $cdek_account]);

        $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('cdek_secure_password', ?) 
                       ON DUPLICATE KEY UPDATE `value` = ?")
            ->execute([$cdek_secure_password, $cdek_secure_password]);

        logActivity($pdo, 'cdek_settings_update', 'Администратор обновил настройки СДЭК', $_SESSION['admin']['id']);
        $success = 'Настройки СДЭК обновлены!';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$csrf_token = generate_csrf_token();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token) ?>">
    <title>Настройки | pnevmatpro.ru</title>
    <link rel="icon" href="data:,">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/../css/admin.css">
    <link rel="icon" href="https://pnevmatpro.ru/assets/favicon.png" type="image/png">
    <style>
        /* Стили для табов */
        .nav-tabs .nav-link {
            color: #6c757d !important;
            border: none;
            border-bottom: 2px solid transparent;
            padding: 0.5rem 1rem;
        }

        .nav-tabs .nav-link.active {
            color: #000 !important;
            font-weight: 600;
            border-bottom: 3px solid #0d6efd;
            background-color: transparent;
        }

        .nav-tabs {
            border-bottom: none;
            margin-bottom: 20px;
        }

        /* Стили для модального окна */
        .modal-dialog {
            display: flex;
            align-items: center;
            min-height: calc(100% - 1rem);
            margin: 0.5rem auto;
            max-width: 95%;
        }

        .modal-content {
            border-radius: 8px;
        }

        .modal-body {
            padding: 1rem;
            overflow-y: auto;
            max-height: calc(100vh - 200px);
        }

        .table-responsive {
            /* Убираем прокрутку таблицы */
        }

        .user-agent-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.3rem; /* Уменьшенный отступ между иконками и текстом */
        }

        .user-agent-icon {
            font-size: 1.2rem;
            color: #6c757d;
        }

        .user-agent-text {
            font-size: 0.9rem;
            color: #6c757d;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px; /* Ограничение ширины текста для предотвращения переполнения */
        }

        @media (max-width: 576px) {
            .modal-dialog {
                margin: 0 auto;
                max-width: 100%;
                min-height: 100vh;
            }

            .modal-content {
                border-radius: 0;
            }

            .modal-body {
                padding: 0.5rem;
                max-height: calc(100vh - 150px);
            }

            .table {
                font-size: 0.85rem;
            }

            .table th, .table td {
                padding: 0.5rem;
            }

            .user-agent-icon {
                font-size: 1rem;
            }

            .user-agent-text {
                font-size: 0.8rem;
                max-width: 120px; /* Меньшая ширина для мобильных устройств */
            }
        }

        @media (min-width: 576px) {
            .modal-dialog {
                max-width: 1000px;
            }
        }

        /* Стили для админ-панели */
        .admin-wrapper {
            display: flex;
            min-height: 100vh;
            overflow-y: auto;
            background-color: #f8f9fa;
        }

        .admin-nav {
            background-color: #2c3e50;
            color: white;
            padding: 20px;
            width: 250px;
            position: fixed;
            top: 0;
            left: 0;
            height: 100%;
            transform: translateX(-100%);
            transition: transform 0.3s ease-in-out;
            z-index: 1050;
            overflow-x: hidden;
        }

        .admin-nav.active {
            transform: translateX(0);
        }

        .close-menu {
            display: block;
            width: 100%;
            text-align: left;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        .mobile-menu-btn {
            z-index: 1100;
            position: fixed;
            top: 10px;
            left: 10px;
            background-color: #0d6efd;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
        }

        .overlay.active {
            opacity: 1;
            pointer-events: all;
        }

        .admin-main {
            flex: 1;
            padding: 20px;
            margin-left: 250px;
        }

        .admin-content {
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
            width: 100%;
        }

        .activity-log-button {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 10;
        }

        .status-badge {
            font-weight: 600;
            padding: 0.35rem 0.65rem;
            border-radius: 0.25rem;
            font-size: 0.85rem;
        }

        .status-cdek {
            background-color: #343a40;
            color: white;
        }

        .status-local {
            background-color: #495057;
            color: white;
        }

        @media (max-width: 767.98px) {
            .admin-nav {
                transform: translateX(-100%);
                position: fixed;
                width: 250px;
            }
            .admin-nav.active {
                transform: translateX(0);
            }
            .admin-main {
                padding: 15px;
                width: 100%;
                margin-left: 0;
                padding-top: 60px;
            }
            .admin-content {
                max-width: 100%;
                margin-left: 0;
                margin-right: 0;
            }

            .card {
                border-radius: 8px;
            }

            .nav-link {
                font-size: 14px;
                padding: 10px 12px !important;
            }
        }

        @media (min-width: 768px) {
            .admin-nav {
                transform: translateX(0);
                position: fixed;
            }
            .mobile-menu-btn {
                display: none;
            }
            .overlay {
                display: none;
            }
            .admin-main {
                padding-top: 20px;
            }
        }
    </style>
</head>
<body class="admin-wrapper">
    <!-- Боковая панель -->
    <?php include '_sidebar.php'; ?>

    <!-- Основной контент -->
    <main class="admin-main">
        <!-- Кнопка мобильного меню -->
        <button class="btn btn-primary mobile-menu-btn d-md-none">
            <i class="bi bi-list"></i>
        </button>

        <!-- Оверлей -->
        <div class="overlay"></div>

        <div class="admin-content">
            <div class="container-fluid p-3 p-md-4">
                <div class="d-flex justify-content-between flex-wrap align-items-center my-3">
                    <h2 class="mb-3 mb-md-0">Настройки</h2>
                    <button type="button" class="btn btn-primary activity-log-button" id="view-activity-log">
                        <i class="bi bi-journal-text me-2"></i>Журнал действий
                    </button>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger mb-3 mb-md-4 p-2 p-md-3"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success mb-3 mb-md-4 p-2 p-md-3"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <!-- Табы -->
                <ul class="nav nav-tabs">
                    <li class="nav-item">
                        <a class="nav-link active" id="admin-settings-tab" data-bs-toggle="tab" href="#admin-settings">Настройки администратора</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="api-settings-tab" data-bs-toggle="tab" href="#api-settings">Настройки API</a>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Вкладка настроек администратора -->
                    <div class="tab-pane fade show active" id="admin-settings">
                        <div class="card admin-form mb-4">
                            <div class="card-body p-3 p-md-4">
                                <h4 class="card-title mb-3 mb-md-4">Профиль администратора</h4>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <div class="mb-3">
                                        <label class="form-label">ФИО:</label>
                                        <input type="text" class="form-control form-control-lg" name="full_name" value="<?= htmlspecialchars($admin['full_name']) ?>" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="form-label">Email:</label>
                                        <input type="email" class="form-control form-control-lg" name="email" value="<?= htmlspecialchars($admin['email']) ?>" required>
                                    </div>
                                    <button type="submit" name="update_profile" class="btn btn-primary w-100 py-2">
                                        <i class="bi bi-person me-2"></i>Сохранить профиль
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="card admin-form mb-4">
                            <div class="card-body p-3 p-md-4">
                                <h4 class="card-title mb-3 mb-md-4">Смена пароля</h4>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <div class="mb-3">
                                        <label class="form-label">Текущий пароль:</label>
                                        <input type="password" class="form-control form-control-lg" name="current_password" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Новый пароль:</label>
                                        <input type="password" class="form-control form-control-lg" name="new_password" required>
                                        <div class="form-text">Минимум 8 символов, цифры и заглавные буквы</div>
                                    </div>
                                    <div class="mb-4">
                                        <label class="form-label">Подтвердите новый пароль:</label>
                                        <input type="password" class="form-control form-control-lg" name="confirm_password" required>
                                    </div>
                                    <button type="submit" name="change_password" class="btn btn-primary w-100 py-2">
                                        <i class="bi bi-key me-2"></i>Сменить пароль
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="card admin-form mb-4">
                            <div class="card-body p-3 p-md-4">
                                <h4 class="card-title mb-3 mb-md-4">Уведомления</h4>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" class="form-check-input" name="notifications_enabled" id="notifications_enabled" <?= ($admin['notifications_enabled']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="notifications_enabled">Получать email-уведомления о новых заказах</label>
                                    </div>
                                    <div class="mb-4 form-check">
                                        <input type="checkbox" class="form-check-input" name="telegram_notifications_enabled" id="telegram_notifications_enabled" <?= ($admin['telegram_notifications_enabled']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="telegram_notifications_enabled">Получать Telegram-уведомления о новых заказах</label>
                                    </div>
                                    <button type="submit" name="update_notifications" class="btn btn-primary w-100 py-2">
                                        <i class="bi bi-bell me-2"></i>Сохранить настройки уведомлений
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Вкладка настроек API -->
                    <div class="tab-pane fade" id="api-settings">
                        <div class="card admin-form mb-4">
                            <div class="card-body p-3 p-md-4">
                                <h4 class="card-title mb-3 mb-md-4">Настройки API СДЭК</h4>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <div class="mb-3">
                                        <label class="form-label">Аккаунт СДЭК:</label>
                                        <input type="text" class="form-control form-control-lg" name="cdek_account" value="<?= htmlspecialchars($cdek_account) ?>" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="form-label">Пароль СДЭК:</label>
                                        <input type="text" class="form-control form-control-lg" name="cdek_secure_password" value="<?= htmlspecialchars($cdek_secure_password) ?>" required>
                                    </div>
                                    <button type="submit" name="update_cdek" class="btn btn-primary w-100 py-2">
                                        <i class="bi bi-truck me-2"></i>Сохранить настройки СДЭК
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="card admin-form mb-4">
                            <div class="card-body p-3 p-md-4">
                                <h4 class="card-title mb-3 mb-md-4">Настройки Telegram-уведомлений</h4>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <div class="mb-3">
                                        <label class="form-label">Токен Telegram-бота:</label>
                                        <input type="text" class="form-control form-control-lg" name="telegram_bot_token" value="<?= htmlspecialchars($telegram_bot_token) ?>" required>
                                        <div class="form-text">Получите токен от BotFather в Telegram</div>
                                    </div>
                                    <div class="mb-4">
                                        <label class="form-label">ID чата Telegram:</label>
                                        <input type="text" class="form-control form-control-lg" name="telegram_chat_id" value="<?= htmlspecialchars($telegram_chat_id) ?>" required>
                                        <div class="form-text">Введите 7071144296 для отправки уведомлений</div>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="submit" name="update_telegram" class="btn btn-primary w-50 py-2">
                                            <i class="bi bi-telegram me-2"></i>Сохранить настройки
                                        </button>
                                        <button type="submit" name="test_telegram" class="btn btn-outline-primary w-50 py-2">
                                            <i class="bi bi-telegram me-2"></i>Тестовое сообщение
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <input type="hidden" id="csrfToken" value="<?= htmlspecialchars($csrf_token) ?>">

        <!-- Модальное окно для журнала действий -->
        <div class="modal fade" id="activityLogModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="true">
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <!-- Заголовок -->
                    <div class="modal-header bg-light">
                        <h5 class="modal-title">Журнал действий</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <!-- Табы -->
                    <div class="modal-header bg-light pt-0 border-bottom-0">
                        <ul class="nav nav-tabs card-header-tabs" id="logTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active text-dark fw-bold" id="activity-log-tab" data-bs-toggle="tab" data-bs-target="#activity-log" type="button" role="tab">Админ.действия</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link text-dark fw-bold" id="user-activity-log-tab" data-bs-toggle="tab" data-bs-target="#user-activity-log" type="button" role="tab">Действия пользователей</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link text-dark fw-bold" id="delivery-log-tab" data-bs-toggle="tab" data-bs-target="#delivery-log" type="button" role="tab">Лог доставки</button>
                            </li>
                        </ul>
                    </div>

                    <!-- Контент модального окна -->
                    <div class="modal-body p-0">
                        <div class="tab-content" id="logTabsContent">
                            <!-- Вкладка действий администраторов -->
                            <div class="tab-pane fade show active" id="activity-log" role="tabpanel">
                                <div id="activity-log-content" class="p-3">
                                    <div class="text-center py-5">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Загрузка...</span>
                                        </div>
                                        <p class="mt-2">Загрузка журнала действий администраторов...</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Вкладка действий пользователей -->
                            <div class="tab-pane fade" id="user-activity-log" role="tabpanel">
                                <div id="user-activity-log-content" class="p-3">
                                    <div class="text-center py-5">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Загрузка...</span>
                                        </div>
                                        <p class="mt-2">Загрузка журнала действий пользователей...</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Вкладка лога доставки -->
                            <div class="tab-pane fade" id="delivery-log" role="tabpanel">
                                <div id="delivery-log-content" class="p-3">
                                    <div class="text-center py-5">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Загрузка...</span>
                                        </div>
                                        <p class="mt-2">Загрузка лога доставки...</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Футер с пагинацией -->
                    <div class="modal-footer">
                        <nav aria-label="Page navigation">
                            <ul class="pagination justify-content-center mb-0" id="logPagination"></ul>
                        </nav>
                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">
                            <i class="bi bi-x-lg me-1"></i> Закрыть
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const menuBtn = document.querySelector('.mobile-menu-btn');
            const closeBtn = document.querySelector('.close-menu');
            const sidebar = document.querySelector('.admin-nav');
            const overlay = document.querySelector('.overlay');

            function toggleMenu(event) {
                event.preventDefault();
                const isOpening = !sidebar.classList.contains('active');
                sidebar.classList.toggle('active', isOpening);
                overlay.classList.toggle('active', isOpening);
                document.body.classList.toggle('menu-open', isOpening);
                overlay.style.display = isOpening ? 'block' : 'none';
                setTimeout(() => {
                    if (!isOpening) overlay.style.display = 'none';
                }, 300);
            }

            if (menuBtn) menuBtn.addEventListener('click', toggleMenu);
            if (closeBtn) closeBtn.addEventListener('click', toggleMenu);
            if (overlay) overlay.addEventListener('click', toggleMenu);

            window.addEventListener('resize', () => {
                if (window.innerWidth >= 768) {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                    overlay.style.display = 'none';
                    document.body.classList.remove('menu-open');
                }
            });

            if (window.innerWidth >= 768) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                overlay.style.display = 'none';
            }

            const modal = document.getElementById('activityLogModal');
            if (modal) {
                modal.addEventListener('show.bs.modal', function() {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                    overlay.style.display = 'none';
                    document.body.classList.remove('menu-open');
                });
                modal.addEventListener('hide.bs.modal', function() {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                    overlay.style.display = 'none';
                    document.body.classList.remove('menu-open');
                });
            }

            document.getElementById('view-activity-log').addEventListener('click', function() {
                const modal = new bootstrap.Modal('#activityLogModal');
                modal.show();
                loadLogs('activity', 1);
            });

            let currentLogType = 'activity';
            let currentPage = 1;

            function getUserAgentInfo(userAgent) {
                if (!userAgent || userAgent.trim() === '') {
                    console.log('User Agent is empty or undefined');
                    return {
                        icon: '<i class="bi bi-question-circle user-agent-icon" title="Неизвестно"></i>',
                        osIcon: '<i class="bi bi-question-circle user-agent-icon" title="Неизвестно"></i>',
                        text: 'Неизвестно',
                        os: 'Неизвестно'
                    };
                }

                userAgent = userAgent.toLowerCase();
                console.log('Processing User Agent:', userAgent);

                let browserInfo = {};
                let os = 'Неизвестно';
                let osIcon = '<i class="bi bi-question-circle user-agent-icon" title="Неизвестно"></i>';

                // Определение ОС
                if (userAgent.includes('windows nt')) {
                    os = 'Windows';
                    osIcon = '<i class="bi bi-windows user-agent-icon" title="Windows"></i>';
                    if (userAgent.includes('windows nt 10.0')) os = 'Windows 10/11';
                    else if (userAgent.includes('windows nt 6.3')) os = 'Windows 8.1';
                    else if (userAgent.includes('windows nt 6.2')) os = 'Windows 8';
                    else if (userAgent.includes('windows nt 6.1')) os = 'Windows 7';
                } else if (userAgent.includes('macintosh') || userAgent.includes('mac os x')) {
                    os = 'macOS';
                    osIcon = '<i class="bi bi-apple user-agent-icon" title="macOS"></i>';
                } else if (userAgent.includes('linux') && !userAgent.includes('android')) {
                    os = 'Linux';
                    osIcon = '<i class="bi bi-ubuntu user-agent-icon" title="Linux"></i>';
                } else if (userAgent.includes('android')) {
                    os = 'Android';
                    osIcon = '<i class="bi bi-android2 user-agent-icon" title="Android"></i>';
                } else if (userAgent.includes('iphone') || userAgent.includes('ipad') || userAgent.includes('ipod')) {
                    os = 'iOS';
                    osIcon = '<i class="bi bi-apple user-agent-icon" title="iOS"></i>';
                }

                // Определение браузера
                if (userAgent.includes('yabrowser')) {
                    browserInfo = {
                        icon: '<i class="bi bi-browser-chrome user-agent-icon" title="Яндекс Браузер"></i>',
                        text: 'Яндекс Браузер'
                    };
                } else if (userAgent.includes('mobile') || userAgent.includes('android') || userAgent.includes('iphone') || userAgent.includes('ipad')) {
                    browserInfo = {
                        icon: '<i class="bi bi-phone user-agent-icon" title="Мобильное устройство"></i>',
                        text: 'Мобильное устройство'
                    };
                } else if (userAgent.includes('chrome') && !userAgent.includes('edg')) {
                    browserInfo = {
                        icon: '<i class="bi bi-browser-chrome user-agent-icon" title="Google Chrome"></i>',
                        text: 'Google Chrome'
                    };
                } else if (userAgent.includes('firefox')) {
                    browserInfo = {
                        icon: '<i class="bi bi-browser-firefox user-agent-icon" title="Mozilla Firefox"></i>',
                        text: 'Mozilla Firefox'
                    };
                } else if (userAgent.includes('safari') && !userAgent.includes('chrome') && !userAgent.includes('yabrowser')) {
                    browserInfo = {
                        icon: '<i class="bi bi-browser-safari user-agent-icon" title="Safari"></i>',
                        text: 'Safari'
                    };
                } else if (userAgent.includes('edg')) {
                    browserInfo = {
                        icon: '<i class="bi bi-browser-edge user-agent-icon" title="Microsoft Edge"></i>',
                        text: 'Microsoft Edge'
                    };
                } else {
                    browserInfo = {
                        icon: '<i class="bi bi-display user-agent-icon" title="Десктоп"></i>',
                        text: 'Десктоп'
                    };
                }

                return {
                    icon: browserInfo.icon,
                    osIcon: osIcon,
                    text: `${browserInfo.text}, ${os}`,
                    os: os
                };
            }

            window.loadLogs = async function(logType, page = 1) {
                currentLogType = logType;
                currentPage = page;

                const contentDivId = `${logType}-log-content`;
                const contentDiv = document.getElementById(contentDivId);
                const paginationDiv = document.getElementById('logPagination');

                try {
                    contentDiv.innerHTML = `
                        <div class="text-center py-5">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Загрузка...</span>
                            </div>
                            <p class="mt-2">Загрузка ${getLogTypeName(logType)}...</p>
                        </div>`;

                    const formData = new FormData();
                    formData.append('csrf_token', document.getElementById('csrfToken').value);
                    formData.append('page', page);
                    formData.append('per_page', 10);

                    let endpoint;
                    if (logType === 'activity') {
                        endpoint = 'get_activity_log.php';
                    } else if (logType === 'user-activity') {
                        endpoint = 'get_user_activity_log.php';
                    } else if (logType === 'delivery') {
                        endpoint = 'get_delivery_log.php';
                    }

                    const response = await fetch(endpoint, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'Accept': 'application/json'
                        }
                    });

                    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                    const data = await response.json();

                    if (data.status !== 'success') throw new Error(data.message || 'Ошибка при загрузке данных');

                    renderLogs(logType, data.logs, data.total_pages, page);

                } catch (error) {
                    console.error('Ошибка:', error);
                    contentDiv.innerHTML = `
                        <div class="alert alert-danger">
                            <h5>Ошибка при загрузке ${getLogTypeName(logType)}</h5>
                            <p>${error.message}</p>
                            <button class="btn btn-sm btn-outline-primary" onclick="loadLogs('${logType}', ${page})">
                                <i class="bi bi-arrow-clockwise"></i> Попробовать снова
                            </button>
                        </div>`;
                    paginationDiv.innerHTML = '';
                }
            };

            function getLogTypeName(logType) {
                const names = {
                    'activity': 'журнала действий администраторов',
                    'user-activity': 'журнала действий пользователей',
                    'delivery': 'лога доставки'
                };
                return names[logType] || 'логов';
            }

            window.renderLogs = function(logType, logs, totalPages, currentPage) {
                const contentDivId = `${logType}-log-content`;
                const contentDiv = document.getElementById(contentDivId);
                const paginationDiv = document.getElementById('logPagination');

                if (!logs || logs.length === 0) {
                    contentDiv.innerHTML = '<div class="alert alert-info text-center py-4">Нет записей</div>';
                    paginationDiv.innerHTML = '';
                    return;
                }

                const headerText = {
                    'activity': 'История действий администраторов',
                    'user-activity': 'История действий пользователей',
                    'delivery': 'История статусов доставки'
                }[logType];

                let tableHtml = `
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">${headerText}</h5>
                        <span class="badge bg-dark">Всего записей: ${logs.length}</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle">
                            <thead class="table-dark">
                `;

                if (logType === 'activity') {
                    tableHtml += `
                        <tr>
                            <th>Дата и время</th>
                            <th>Тип</th>
                            <th>Описание</th>
                            <th>Администратор</th>
                            <th>IP-адрес</th>
                            <th>Устройство</th>
                        </tr>
                    `;
                } else if (logType === 'user-activity') {
                    tableHtml += `
                        <tr>
                            <th>Дата и время</th>
                            <th>Тип</th>
                            <th>Описание</th>
                            <th>Пользователь</th>
                            <th>IP-адрес</th>
                            <th>Устройство</th>
                        </tr>
                    `;
                } else {
                    tableHtml += `
                        <tr>
                            <th>Дата проверки</th>
                            <th>Номер заказа</th>
                            <th>Статус СДЭК</th>
                            <th>Местный статус</th>
                            <th>Код статуса</th>
                            <th>Детали</th>
                        </tr>
                    `;
                }

                tableHtml += `
                            </thead>
                            <tbody>
                `;

                if (logType === 'activity') {
                    logs.forEach(log => {
                        console.log('User Agent:', log.user_agent);
                        const userAgentInfo = getUserAgentInfo(log.user_agent);
                        tableHtml += `
                            <tr>
                                <td>${log.created_at}</td>
                                <td><span class="badge bg-secondary">${log.type}</span></td>
                                <td>${log.description}</td>
                                <td>${log.admin_username || '-'}</td>
                                <td><code>${log.ip_address}</code></td>
                                <td><div class="user-agent-container">${userAgentInfo.icon}${userAgentInfo.osIcon} <span class="user-agent-text">${userAgentInfo.text}</span></div></td>
                            </tr>
                        `;
                    });
                } else if (logType === 'user-activity') {
                    logs.forEach(log => {
                        console.log('User Agent:', log.user_agent);
                        const userAgentInfo = getUserAgentInfo(log.user_agent);
                        tableHtml += `
                            <tr>
                                <td>${log.created_at}</td>
                                <td><span class="badge bg-info">${log.type}</span></td>
                                <td>${log.description}</td>
                                <td>${log.username || '-'}</td>
                                <td><code>${log.ip_address}</code></td>
                                <td><div class="user-agent-container">${userAgentInfo.icon}${userAgentInfo.osIcon} <span class="user-agent-text">${userAgentInfo.text}</span></div></td>
                            </tr>
                        `;
                    });
                } else {
                    const statusMap = {
                        'new': 'Новый',
                        'processing': 'В обработке',
                        'shipped': 'Отправлен',
                        'ready_for_pickup': 'Готов к выдаче',
                        'completed': 'Завершен',
                        'canceled': 'Отменен'
                    };
                    logs.forEach(log => {
                        tableHtml += `
                            <tr>
                                <td>${log.created_at}</td>
                                <td><strong>${log.order_number}</strong></td>
                                <td><span class="status-badge status-cdek">${log.status_name}</span></td>
                                <td><span class="status-badge status-local">${statusMap[log.local_status] || log.local_status || '-'}</span></td>
                                <td><span class="badge bg-dark">${log.status_code || '-'}</span></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-dark" onclick="showDeliveryDetails(${log.id})">
                                        <i class="bi bi-info-circle"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                }

                tableHtml += `
                            </tbody>
                        </table>
                    </div>
                `;

                contentDiv.innerHTML = tableHtml;

                if (totalPages > 1) {
                    let paginationHtml = `
                        <li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                            <a class="page-link" href="#" onclick="loadLogs('${logType}', ${currentPage - 1}); return false;">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                    `;

                    for (let i = 1; i <= totalPages; i++) {
                        paginationHtml += `
                            <li class="page-item ${i === currentPage ? 'active' : ''}">
                                <a class="page-link" href="#" onclick="loadLogs('${logType}', ${i}); return false;">${i}</a>
                            </li>
                        `;
                    }

                    paginationHtml += `
                        <li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
                            <a class="page-link" href="#" onclick="loadLogs('${logType}', ${currentPage + 1}); return false;">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                    `;

                    paginationDiv.innerHTML = paginationHtml;
                } else {
                    paginationDiv.innerHTML = '';
                }
            };

            window.showDeliveryDetails = async function(logId) {
                try {
                    const formData = new FormData();
                    formData.append('csrf_token', document.getElementById('csrfToken').value);
                    formData.append('log_id', logId);

                    const response = await fetch('get_delivery_log_details.php', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'Accept': 'application/json'
                        }
                    });

                    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                    const data = await response.json();

                    if (data.status !== 'success') throw new Error(data.message || 'Ошибка при загрузке деталей');

                    const log = data.log;
                    const apiResponse = data.api_response || {};
                    const details = `
                        <strong>ID записи:</strong> ${log.id}<br>
                        <strong>Номер заказа:</strong> ${log.order_number}<br>
                        <strong>Дата проверки:</strong> ${log.created_at}<br>
                        <strong>Статус СДЭК:</strong> ${log.status_name}<br>
                        <strong>Местный статус:</strong> ${log.local_status}<br>
                        <strong>Код статуса:</strong> ${log.status_code || '-'}<br>
                        <strong>Клиент:</strong> ${log.customer_name}<br>
                        <strong>Телефон:</strong> ${log.customer_phone}<br>
                        <strong>Трек-номер:</strong> ${log.tracking_number || '-'}<br>
                        <strong>Служба доставки:</strong> ${log.delivery_service}<br>
                        <strong>API-ответ:</strong> ${JSON.stringify(apiResponse, null, 2).replace(/\n/g, '<br>')}
                    `;
                    alert('Детали доставки:\n' + details);
                } catch (error) {
                    alert('Ошибка при загрузке деталей: ' + error.message);
                }
            };

            document.getElementById('activity-log-tab').addEventListener('click', function() {
                loadLogs('activity', 1);
            });

            document.getElementById('user-activity-log-tab').addEventListener('click', function() {
                loadLogs('user-activity', 1);
            });

            document.getElementById('delivery-log-tab').addEventListener('click', function() {
                loadLogs('delivery', 1);
            });
        });
        </script>
</body>
</html>