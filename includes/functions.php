<?php
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return $data;
}

function validate_phone($phone) {
    return preg_match('/^\+?[0-9]{10,15}$/', $phone);
}

function validate_name($name) {
    return preg_match('/^[\p{L}\s]{3,50}$/u', $name);
}

function secure_upload($file) {
    $allowed_mime = ['image/jpeg', 'image/png', 'image/gif'];
    $max_size = 2 * 1024 * 1024; // 2MB
    $upload_dir = __DIR__ . '/../uploads/products/';
    
    // Проверка MIME-типа
    $file_info = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($file_info, $file['tmp_name']);
    finfo_close($file_info);

    if (!in_array($mime_type, $allowed_mime)) {
        logToFile("Недопустимый MIME-тип файла: $mime_type", "ERROR");
        return false;
    }

    if ($file['size'] > $max_size) {
        logToFile("Размер файла превышает допустимый: {$file['size']} байт", "ERROR");
        return false;
    }

    // Проверка и создание директории
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            logToFile("Не удалось создать директорию: $upload_dir", "ERROR");
            return false;
        }
    }

    // Проверка прав на запись
    if (!is_writable($upload_dir)) {
        logToFile("Директория недоступна для записи: $upload_dir", "ERROR");
        return false;
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'product_' . time() . '.' . $ext;
    $upload_path = $upload_dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
        logToFile("Ошибка перемещения файла в $upload_path", "ERROR");
        return false;
    }

    logToFile("Файл успешно загружен: $upload_path", "INFO");
    return $filename;
}

function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function hash_password($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

function is_password_strong($password) {
    return strlen($password) >= 8 &&
           preg_match('/[A-Z]/', $password) &&
           preg_match('/[0-9]/', $password) &&
           preg_match('/[^A-Za-z0-9]/', $password);
}

function generate_slug($string) {
    $replace = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'е' => 'e', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ' ' => '-', '_' => '-', '—' => '-'
    ];
    
    $string = mb_strtolower($string, 'UTF-8');
    $slug = strtr($string, $replace);
    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    return trim($slug, '-');
}

function format_size($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    if ($bytes == 0) return '0 B';
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

function format_date($date, $format = 'd.m.Y H:i') {
    return date($format, strtotime($date));
}

function logToFile($message, $level = 'INFO') {
    $logDir = __DIR__ . '/logs';
    $logFile = $logDir . '/api_requests_' . date('Y-m-d') . '.log';
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message\n";
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

function logActivity($pdo, $type, $description, $admin_id = null, $user_id = null) {
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt = $pdo->prepare("
            INSERT INTO activity_log (user_id, admin_id, type, description, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$user_id, $admin_id, $type, $description, $ip_address, $user_agent]);
    } catch (PDOException $e) {
        logToFile("Ошибка записи в activity_log: " . $e->getMessage(), "ERROR");
        throw $e;
    }
}

function getCdekToken($account, $securePassword) {
    // Кэширование токена в сессии
    if (isset($_SESSION['cdek_token']) && isset($_SESSION['cdek_token_expires']) && time() < $_SESSION['cdek_token_expires']) {
        return $_SESSION['cdek_token'];
    }

    $url = 'https://api.cdek.ru/v2/oauth/token';  // Удален лишний ?grant_type
    $data = [
        'grant_type' => 'client_credentials',
        'client_id' => $account,
        'client_secret' => $securePassword
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);  // Добавлен таймаут

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $result = json_decode($response, true);
        $token = $result['access_token'] ?? null;
        if ($token) {
            $_SESSION['cdek_token'] = $token;
            $_SESSION['cdek_token_expires'] = time() + ($result['expires_in'] ?? 3600) - 60;  // Кэш с запасом
            return $token;
        }
    }

    logToFile("Ошибка получения токена СДЭК: HTTP $httpCode, Ответ: $response", 'ERROR');
    return null;
}

function getCdekCityCode($token, $city) {
    $url = 'https://api.cdek.ru/v2/location/cities';
    $params = ['city' => $city];

    $ch = curl_init($url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);  // Добавлен таймаут

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $cities = json_decode($response, true);
        if (!empty($cities)) {
            return $cities[0]['code'] ?? null;
        }
    }

    logToFile("Ошибка получения city_code для города '$city': HTTP $httpCode, Ответ: $response", 'ERROR');
    return null;
}

function createCdekOrder($token, $orderData) {
    $url = 'https://api.cdek.ru/v2/orders';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($orderData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);  // Добавлен таймаут

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 || $httpCode === 202) {
        return json_decode($response, true);
    }

    logToFile("Ошибка создания заказа в СДЭК: HTTP $httpCode, Ответ: $response", 'ERROR');
    return null;
}

function sendTelegramMessage($pdo, $message, $isTest = false) {
    try {
        logToFile("Function sendTelegramMessage started" . ($isTest ? " (test mode)" : ""), "DEBUG");

        $stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('telegram_bot_token', 'telegram_chat_id')");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $botToken = $settings['telegram_bot_token'] ?? '';
        $chatId = $settings['telegram_chat_id'] ?? '';
        
        if (empty($botToken)) {
            throw new Exception('Не настроен токен Telegram-бота');
        }
        
        // Удалена хардкод-проверка chatId, используем из БД
        if (empty($chatId)) {
            throw new Exception('Не настроен chat_id Telegram');
        }

        if ($isTest) {
            $message = "<b>Тестовое сообщение</b>\n" . $message;
        }

        logToFile("Telegram settings - Bot token: " . substr($botToken, 0, 10) . "... Chat ID: $chatId", "INFO");

        $url = "https://api.telegram.org/bot$botToken/sendMessage";
        $data = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML'
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("Ошибка cURL: $curlError");
        }

        if ($httpCode !== 200) {
            $result = json_decode($response, true);
            throw new Exception("Ошибка API Telegram (HTTP $httpCode): " . ($result['description'] ?? 'Неизвестная ошибка'));
        }

        logToFile("Message sent successfully to Telegram chat ID $chatId", "INFO");
        return true;

    } catch (Exception $e) {
        logToFile("Failed to send Telegram message: {$e->getMessage()}", "ERROR");
        return false;
    }
}

function sendTelegramTestMessage($pdo) {
    try {
        logToFile("Отправка тестового сообщения Telegram", "DEBUG");

        $message = "<b>Тестовое уведомление о заказе</b>\n\n";
        $message .= "<b>Клиент:</b>\n";
        $message .= "Имя: Тестовый Пользователь\n";
        $message .= "Email: test@example.com\n";
        $message .= "Телефон: +79991234567\n\n";
        $message .= "<b>Детали:</b>\n";
        $message .= "Сумма: 1000.00 ₽\n";
        $message .= "Статус: Новый\n";
        $message .= "Дата: " . date('d.m.Y H:i') . "\n\n";
        $message .= "<b>Доставка:</b>\n";
        $message .= "Служба: СДЭК\n";
        $message .= "Город: Тестовый Город\n";
        $message .= "Адрес: ул. Примерная, д. 1\n\n";
        $message .= "<b>Состав заказа:</b>\n";
        $message .= "- Услуга: Тестовая Услуга (x1): 1000.00 ₽\n";

        $result = sendTelegramMessage($pdo, $message, true);
        if ($result) {
            logToFile("Тестовое сообщение успешно отправлено", "INFO");
        } else {
            logToFile("Не удалось отправить тестовое сообщение", "ERROR");
        }
        return $result;

    } catch (Exception $e) {
        logToFile("Ошибка отправки тестового сообщения: {$e->getMessage()}", "ERROR");
        return false;
    }
}

function sendTelegramOrderNotification($pdo, $order_id) {
    try {
        logToFile("Начало вызова API для отправки уведомления о заказе $order_id", "DEBUG");

        $site_url = $GLOBALS['site_url'];
        logToFile("Используемый site_url: $site_url", "DEBUG");
        if (empty($site_url)) {
            throw new Exception('Переменная site_url не определена');
        }

        $csrf_token = generate_csrf_token();
        $url = $site_url . '/api/send_telegram_notification.php';
        logToFile("URL для API: $url", "DEBUG");

        $data = [
            'order_id' => $order_id,
            'csrf_token' => $csrf_token
        ];
        logToFile("Отправляемые данные в API: " . json_encode($data), "DEBUG");

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);  // Добавлена проверка SSL

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        logToFile("Ответ от API: HTTP $httpCode, Response: $response", "DEBUG");

        if ($response === false) {
            throw new Exception("Ошибка cURL: $curlError");
        }

        if ($httpCode !== 200) {
            $result = json_decode($response, true);
            throw new Exception("Ошибка API Telegram (HTTP $httpCode): " . ($result['message'] ?? 'Неизвестная ошибка'));
        }

        $result = json_decode($response, true);
        if ($result['status'] === 'success') {
            logToFile("Уведомление о заказе $order_id отправлено через API", "INFO");
            return true;
        } else {
            throw new Exception($result['message'] ?? 'Неизвестная ошибка API');
        }

    } catch (Exception $e) {
        logToFile("Ошибка вызова API уведомления для заказа $order_id: {$e->getMessage()}", "ERROR");
        return false;
    }
}

function generate_vendor_code($type) {
    global $pdo;
    $prefix = ($type === 'bullet') ? 'BUL' : 'SRV';
    $date = date('Ymd');
    $suffix_length = 4;
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $max_attempts = 10;  // Добавлен лимит попыток
    
    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        $suffix = '';
        for ($i = 0; $i < $suffix_length; $i++) {
            $suffix .= $characters[rand(0, strlen($characters) - 1)];
        }
        $vendor_code = "{$prefix}-{$date}-{$suffix}";
        $table = ($type === 'bullet') ? 'products' : 'services';
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE vendor_code = ?");
        $stmt->execute([$vendor_code]);
        $exists = $stmt->fetchColumn();
        if ($exists == 0) {
            return $vendor_code;
        }
    }
    
    logToFile("Не удалось сгенерировать уникальный vendor_code после $max_attempts попыток", "ERROR");
    return null;
}

function getUserPhone($user_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT phone FROM customers WHERE user_id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        return $customer['phone'] ?? '';
    } catch (PDOException $e) {
        logToFile("Ошибка получения телефона: " . $e->getMessage(), "ERROR");
        return '';
    }
}

function getCustomerFullName($user_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT full_name FROM customers WHERE user_id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        return $customer['full_name'] ?? '';
    } catch (PDOException $e) {
        logToFile("Ошибка получения ФИО: " . $e->getMessage(), "ERROR");
        return '';
    }
}

function generate_remember_token() {
    return bin2hex(random_bytes(32));
}

function set_remember_token($pdo, $user_id, $token) {
    try {
        $hashed_token = hash('sha256', $token);
        $stmt = $pdo->prepare("UPDATE users SET remember_token = ?, remember_token_expires = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?");
        $stmt->execute([$hashed_token, $user_id]);
        return true;
    } catch (PDOException $e) {
        logToFile("Ошибка сохранения remember_token для user_id $user_id: " . $e->getMessage(), "ERROR");
        return false;
    }
}

function validate_remember_token($pdo, $user_id, $token) {
    try {
        $stmt = $pdo->prepare("SELECT remember_token, remember_token_expires FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !$user['remember_token'] || strtotime($user['remember_token_expires']) < time()) {
            return false;
        }
        
        return hash_equals($user['remember_token'], hash('sha256', $token));
    } catch (PDOException $e) {
        logToFile("Ошибка проверки remember_token для user_id $user_id: " . $e->getMessage(), "ERROR");
        return false;
    }
}

function clear_remember_token($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL, remember_token_expires = NULL WHERE id = ?");
        $stmt->execute([$user_id]);
    } catch (PDOException $e) {
        logToFile("Ошибка очистки remember_token для user_id $user_id: " . $e->getMessage(), "ERROR");
    }
}

function set_admin_remember_token($pdo, $admin_id, $token) {
    try {
        $hashed_token = hash('sha256', $token);
        $stmt = $pdo->prepare("UPDATE admins SET remember_token = ?, remember_token_expires = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?");
        $stmt->execute([$hashed_token, $admin_id]);
        return true;
    } catch (PDOException $e) {
        logToFile("Ошибка сохранения remember_token для admin_id $admin_id: " . $e->getMessage(), "ERROR");
        return false;
    }
}

function validate_admin_remember_token($pdo, $admin_id, $token) {
    try {
        $stmt = $pdo->prepare("SELECT remember_token, remember_token_expires FROM admins WHERE id = ?");
        $stmt->execute([$admin_id]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$admin || !$admin['remember_token'] || strtotime($admin['remember_token_expires']) < time()) {
            return false;
        }
        
        return hash_equals($admin['remember_token'], hash('sha256', $token));
    } catch (PDOException $e) {
        logToFile("Ошибка проверки remember_token для admin_id $admin_id: " . $e->getMessage(), "ERROR");
        return false;
    }
}

function clear_admin_remember_token($pdo, $admin_id) {
    try {
        $stmt = $pdo->prepare("UPDATE admins SET remember_token = NULL, remember_token_expires = NULL WHERE id = ?");
        $stmt->execute([$admin_id]);
    } catch (PDOException $e) {
        logToFile("Ошибка очистки remember_token для admin_id $admin_id: " . $e->getMessage(), "ERROR");
    }
}
?>