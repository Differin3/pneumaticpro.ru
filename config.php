<?php
/* ----------------------------------------------------------
  НАСТРОЙКА СРЕДЫ
---------------------------------------------------------- */

// Режим разработки (false - продакшен)
define('DEBUG_MODE', true);

// Настройки отображения ошибок
if (DEBUG_MODE) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

/* ----------------------------------------------------------
  ЗАГОЛОВКИ БЕЗОПАСНОСТИ
---------------------------------------------------------- */
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://cdn.jsdelivr.net; script-src 'self' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self' https://api.telegram.org;");

// Защита от фиксации сессии
if (!isset($_SESSION['CREATED'])) {
    $_SESSION['CREATED'] = time();
} elseif (time() - $_SESSION['CREATED'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['CREATED'] = time();
}

/* ----------------------------------------------------------
  НАСТРОЙКИ БАЗЫ ДАННЫХ (через переменные окружения)
---------------------------------------------------------- */
require_once __DIR__ . '/vendor/autoload.php'; // Предполагается установка phpdotenv через Composer
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

define('DB_HOST', $_ENV['DB_HOST'] ?: 'localhost');
define('DB_USER', $_ENV['DB_USER'] ?: 'admin');
define('DB_PASS', $_ENV['DB_PASS'] ?: 'default_password');
define('DB_NAME', $_ENV['DB_NAME'] ?: 'airgun_service');
define('DB_CHARSET', 'utf8mb4');

/* ----------------------------------------------------------
  НАСТРОЙКИ БЕЗОПАСНОСТИ
---------------------------------------------------------- */
define('CSRF_TOKEN_LIFE', 3600);     // Время жизни CSRF-токена (сек)
define('MAX_LOGIN_ATTEMPTS', 100);   // Максимум попыток входа
define('LOGIN_BAN_TIME', 300);       // Блокировка при флуде (сек)

/* ----------------------------------------------------------
  НАСТРОЙКИ ЗАГРУЗКИ ФАЙЛОВ
---------------------------------------------------------- */
define('UPLOAD_DIR', realpath(__DIR__ . '/../uploads')); // Путь к загрузкам
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5 МБ
define('ALLOWED_MIME_TYPES', [     // Разрешенные типы
    'image/jpeg',
    'image/png',
    'image/webp'
]);

/* ----------------------------------------------------------
  ПОДКЛЮЧЕНИЕ К БАЗЕ ДАННЫХ
---------------------------------------------------------- */
$pdo = null; // Инициализация переменной

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO(
        $dsn,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    error_log("Ошибка подключения к БД: " . $e->getMessage());
    if (DEBUG_MODE) {
        die("Ошибка подключения к базе данных: " . $e->getMessage());
    } else {
        die("Ошибка сервера. Пожалуйста, попробуйте позже.");
    }
}

/* ----------------------------------------------------------
  ВСПОМОГАТЕЛЬНЫЕ КОНСТАНТЫ
---------------------------------------------------------- */
define('SITE_URL', 'https://' . $_SERVER['HTTP_HOST']); // Принудительный HTTPS
define('ADMIN_EMAIL', 'admin@pneumaticpro.ru');
define('CURRENT_YEAR', date('Y'));

/* ----------------------------------------------------------
  ОБРАБОТЧИКИ ОШИБОК
---------------------------------------------------------- */
set_exception_handler(function (Throwable $e) {
    error_log("Uncaught Exception: " . $e->getMessage());
    http_response_code(500);
    die(DEBUG_MODE ? $e->getMessage() : 'Внутренняя ошибка сервера');
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

/* ----------------------------------------------------------
  АВТОЗАГРУЗКА КЛАССОВ
---------------------------------------------------------- */
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/classes/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});