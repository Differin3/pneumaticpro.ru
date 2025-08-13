<?php
// Устанавливаем имя сессии до начала работы с сессией

// Режим разработки (true - вывод ошибок, false - продакшен)
define('DEBUG_MODE', true);  // По умолчанию false для продакшена

// Настройки отображения ошибок
if (DEBUG_MODE) {
    ini_set('display_errors', PHP_SAPI === 'cli' ? 0 : 1);
    ini_set('display_startup_errors', PHP_SAPI === 'cli' ? 0 : 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// Укажите путь к error_log для всех режимов
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');  // Добавлено для логирования ошибок

// Параметры подключения к базе данных
$db_host = 'localhost';
$db_user = 'admin';
$db_pass = '54785TGU647gj';
$db_name = 'airgun_service';
$db_charset = 'utf8mb4';

// Константы для ограничения попыток входа
$max_login_attempts = 10; // Максимальное количество попыток входа
$login_ban_time = 900;   // Время блокировки в секундах (15 минут)

// Определение константы для директории загрузок
define('UPLOAD_DIR', __DIR__ . '/../uploads/products');  // Сделан lowercase для consistency

// Инициализация сессии только для веб-запросов
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    $dsn = "mysql:host=$db_host;dbname=$db_name;charset=$db_charset";
    $pdo = new PDO(
        $dsn,
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    error_log("Ошибка подключения к БД: " . $e->getMessage());
    die("Ошибка сервера. Пожалуйста, попробуйте позже.");
}
// Настройки API СДЭК
try {
    $stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('cdek_account', 'cdek_secure_password')");
    $cdekSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $cdekSettings = [];
    error_log("Ошибка загрузки настроек СДЭК: " . $e->getMessage());
}
// Настройки API СДЭК
$cdek_account = $cdekSettings['cdek_account'] ?? 'DulGvImdFIiO1Cw9zEBw2JT6sgyXQnb5';
$cdek_secure_password = $cdekSettings['cdek_secure_password'] ?? '6ltMJXculTTKVHN0FT29E9tUs3qLe7ZI';
// Настройки API СДЭК
define('CDEK_ACCOUNT', $cdek_account);
define('CDEK_SECURE_PASSWORD', $cdek_secure_password);


// Дополнительные настройки сайта
$site_url = isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] : 'https://pneumaticpro.ru';
$admin_email = 'admin@pneumaticpro.ru';
$current_year = date('Y');
?>