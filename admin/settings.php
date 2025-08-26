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
    $stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('store_name', 'telegram_bot_token', 'telegram_chat_id', 'cdek_account', 'cdek_secure_password')");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    $shop_name = $settings['store_name'] ?? 'pnevmatpro.ru';
    $telegram_bot_token = $settings['telegram_bot_token'] ?? '';
    $telegram_chat_id = $settings['telegram_chat_id'] ?? '7071144296';
    $cdek_account = $settings['cdek_account'] ?? '';
    $cdek_secure_password = $settings['cdek_secure_password'] ?? '';
} catch (PDOException $e) {
    $error = "Ошибка загрузки настроек: " . $e->getMessage();
    $shop_name = 'pnevmatpro.ru';
    $telegram_bot_token = '';
    $telegram_chat_id = '7071144296';
    $cdek_account = '';
    $cdek_secure_password = '';
}

$items_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
try {
    $sql = "SELECT `key`, `value`, `description`, `type`, `group` FROM settings 
            ORDER BY `group`, `key` 
            LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($current_page - 1) * $items_per_page, PDO::PARAM_INT);
    $stmt->execute();
    $all_settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count_sql = "SELECT COUNT(*) FROM settings";
    $count_stmt = $pdo->query($count_sql);
    $total_settings = $count_stmt->fetchColumn();
} catch (PDOException $e) {
    $error = "Ошибка загрузки настроек: " . $e->getMessage();
    $all_settings = [];
    $total_settings = 0;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_shop'])) {
    try {
        if (!validate_csrf_token($_POST['csrf_token'])) {
            throw new Exception('Ошибка безопасности: недействительный токен');
        }

        $shop_name = trim($_POST['shop_name']);

        if (empty($shop_name)) {
            throw new Exception('Название магазина обязательно');
        }

        $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('store_name', ?) 
                       ON DUPLICATE KEY UPDATE `value` = ?")
            ->execute([$shop_name, $shop_name]);

        logActivity($pdo, 'shop_settings_update', 'Администратор обновил настройки магазина', $_SESSION['admin']['id']);
        $success = 'Настройки магазина обновлены!';

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
    <title>Настройки | pnevmatpro.ru</title>
    <link rel="icon" href="data:,">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/../css/admin.css">
    <link rel="icon" href="https://pnevmatpro.ru/assets/favicon.png" type="image/png">
    <style>
    /* Обновленные стили для табов как в orders.php */
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
    }
    
    /* Стили для модального окна как в orders.php */
    .modal-header.bg-light.pt-0 {
        padding-top: 0.5rem !important;
        padding-bottom: 0 !important;
        border-bottom: none !important;
    }
    
    /* Остальные стили без изменений */
    .settings-card-mobile {
        display: none;
        border: 1px solid #dee2e6;
        border-radius: 0.375rem;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .settings-card-mobile .settings-header {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.5rem;
        font-size: 1rem;
        font-weight: 600;
    }

    .settings-card-mobile .settings-body {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
        font-size: 0.9rem;
    }

    .settings-card-mobile .col-span-2 {
        grid-column: span 2;
        text-align: center;
    }

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
        
        .table-responsive {
            border-radius: 8px;
            overflow: hidden;
        }
        
        .nav-link {
            font-size: 14px;
            padding: 10px 12px !important;
        }
        
        .settings-table-desktop {
            display: none;
        }
        
        .settings-card-mobile {
            display: block;
        }
    }

    @media (max-width: 576px) {
        .settings-card-mobile .settings-body {
            grid-template-columns: 1fr;
        }
        
        .settings-card-mobile .col-span-2 {
            grid-column: auto;
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
                <div class="settings-header">
                    <h2 class="mb-3 mb-md-4">Настройки</h2>
                </div>
                
                <button type="button" class="btn btn-primary activity-log-button" id="view-activity-log">
                    <i class="bi bi-journal-text me-2"></i>Журнал действий
                </button>

                <?php if ($error): ?>
                    <div class="alert alert-danger mb-3 mb-md-4 p-2 p-md-3"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success mb-3 mb-md-4 p-2 p-md-3"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

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

                <div class="card admin-form mb-4">
                    <div class="card-body p-3 p-md-4">
                        <h4 class="card-title mb-3 mb-md-4">Настройки магазина</h4>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <div class="mb-4">
                                <label class="form-label">Название магазина:</label>
                                <input type="text" class="form-control form-control-lg" name="shop_name" value="<?= htmlspecialchars($shop_name) ?>" required>
                            </div>
                            <button type="submit" name="update_shop" class="btn btn-primary w-100 py-2">
                                <i class="bi bi-shop me-2"></i>Сохранить настройки магазина
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-body p-3 p-md-4">
                        <h4 class="card-title mb-3 mb-md-4">Все настройки</h4>
                        <?php if(!empty($all_settings)): ?>
                            <!-- Десктопная версия (таблица) -->
                            <div class="table-responsive settings-table-desktop">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Ключ</th>
                                            <th>Значение</th>
                                            <th>Описание</th>
                                            <th>Тип</th>
                                            <th>Группа</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($all_settings as $setting): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($setting['key']) ?></td>
                                                <td><?= htmlspecialchars($setting['value']) ?></td>
                                                <td><?= htmlspecialchars($setting['description'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($setting['type']) ?></td>
                                                <td><?= htmlspecialchars($setting['group']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Мобильная версия (карточки) -->
                            <div class="settings-cards-mobile">
                                <?php foreach($all_settings as $setting): ?>
                                    <div class="settings-card-mobile">
                                        <div class="settings-header">
                                            <strong><?= htmlspecialchars($setting['key']) ?></strong>
                                        </div>
                                        <div class="settings-body">
                                            <div>
                                                <small class="text-muted">Значение</small>
                                                <div><?= htmlspecialchars($setting['value']) ?></div>
                                            </div>
                                            <div>
                                                <small class="text-muted">Описание</small>
                                                <div><?= htmlspecialchars($setting['description'] ?? '-') ?></div>
                                            </div>
                                            <div>
                                                <small class="text-muted">Тип</small>
                                                <div><?= htmlspecialchars($setting['type']) ?></div>
                                            </div>
                                            <div>
                                                <small class="text-muted">Группа</small>
                                                <div><?= htmlspecialchars($setting['group']) ?></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Пагинация -->
                            <?php if ($total_settings > $items_per_page): ?>
                                <nav class="mt-4">
                                    <ul class="pagination justify-content-center flex-wrap">
                                        <?php for ($i = 1; $i <= ceil($total_settings / $items_per_page); $i++): ?>
                                            <li class="page-item <?= $i === $current_page ? 'active' : '' ?>">
                                                <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                                            </li>
                                        <?php endfor; ?>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="bi bi-gear fs-1 text-muted mb-3"></i>
                                <h4 class="text-dark mb-3">Нет настроек</h4>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <input type="hidden" id="csrfToken" value="<?= htmlspecialchars($csrf_token) ?>">

    <!-- Обновленное модальное окно с тремя вкладками -->
    <div class="modal fade" id="activityLogModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <!-- Заголовок с названием модального окна -->
                <div class="modal-header bg-light">
                    <h5 class="modal-title">Журнал действий</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <!-- Табы в стиле orders.php -->
                <div class="modal-header bg-light pt-0 border-bottom-0">
                    <ul class="nav nav-tabs card-header-tabs" id="logTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active text-dark fw-bold" id="activity-log-tab" data-bs-toggle="tab" data-bs-target="#activity-log" type="button" role="tab">Админ. действия</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link text-dark fw-bold" id="user-activity-log-tab" data-bs-toggle="tab" data-bs-target="#user-activity-log" type="button" role="tab">Пользовательские действия</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link text-dark fw-bold" id="delivery-log-tab" data-bs-toggle="tab" data-bs-target="#delivery-log" type="button" role="tab">Лог доставки</button>
                        </li>
                    </ul>
                </div>
                
                <div class="modal-body p-0">
                    <div class="tab-content" id="logTabsContent">
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

        // Инициализация модального окна для журнала действий
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
            loadLogs('activity', 1);
        });

        // Глобальные переменные для текущего типа лога и страницы
        let currentLogType = 'activity';
        let currentPage = 1;

        // Функция для загрузки логов
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

                // Определяем endpoint в зависимости от типа лога
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
                    body: formData
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

        // Вспомогательная функция для получения названия типа лога
        function getLogTypeName(logType) {
            const names = {
                'activity': 'журнала действий администраторов',
                'user-activity': 'журнала действий пользователей',
                'delivery': 'лога доставки'
            };
            return names[logType] || 'логов';
        }

        // Функция отрисовки логов
        window.renderLogs = function(logType, logs, totalPages, currentPage) {
            const contentDivId = `${logType}-log-content`;
            const contentDiv = document.getElementById(contentDivId);
            const paginationDiv = document.getElementById('logPagination');

            if (!logs || logs.length === 0) {
                contentDiv.innerHTML = '<div class="alert alert-info text-center py-4">Нет записей</div>';
                paginationDiv.innerHTML = '';
                return;
            }
            
            // Заголовок для таблицы
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
                    tableHtml += `
                        <tr>
                            <td>${log.created_at}</td>
                            <td><span class="badge bg-secondary">${log.type}</span></td>
                            <td>${log.description}</td>
                            <td>${log.admin_username || '-'}</td>
                            <td><code>${log.ip_address}</code></td>
                        </tr>
                    `;
                });
            } else if (logType === 'user-activity') {
                logs.forEach(log => {
                    tableHtml += `
                        <tr>
                            <td>${log.created_at}</td>
                            <td><span class="badge bg-info">${log.type}</span></td>
                            <td>${log.description}</td>
                            <td>${log.username || '-'}</td>
                            <td><code>${log.ip_address}</code></td>
                        </tr>
                    `;
                });
            } else {
                logs.forEach(log => {
                    // Маппинг статусов для отображения
                    const statusMap = {
                        'new': 'Новый',
                        'processing': 'В обработке',
                        'shipped': 'Отправлен',
                        'ready_for_pickup': 'Готов к выдаче',
                        'completed': 'Завершен',
                        'canceled': 'Отменен'
                    };
                    
                    tableHtml += `
                        <tr>
                            <td>${log.created_at}</td>
                            <td><strong>${log.order_number}</strong></td>
                            <td>
                                <span class="status-badge status-cdek">${log.status_name}</span>
                            </td>
                            <td>
                                <span class="status-badge status-local">${statusMap[log.local_status] || log.local_status || '-'}</span>
                            </td>
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

            // Пагинация
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

        // Функция для показа деталей доставки
        window.showDeliveryDetails = function(logId) {
            alert('Детали доставки для записи ID: ' + logId + '\nЗдесь будет подробная информация о статусе доставки');
            // В реальной реализации здесь будет запрос к API для получения деталей
        };

        // Обработчики вкладок
        document.getElementById('activity-log-tab').addEventListener('click', function() {
            loadLogs('activity', 1);
        });

        document.getElementById('user-activity-log-tab').addEventListener('click', function() {
            loadLogs('user-activity', 1);
        });

        document.getElementById('delivery-log-tab').addEventListener('click', function() {
            loadLogs('delivery', 1);
        });

        // Кнопка просмотра логов
        document.getElementById('view-activity-log').addEventListener('click', function() {
            const modal = new bootstrap.Modal('#activityLogModal');
            modal.show();
        });
    });
    </script>
</body>
</html>