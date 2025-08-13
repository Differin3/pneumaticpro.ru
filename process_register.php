<?php
session_start();
require 'includes/config.php';
require 'includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: register.php');
    exit;
}

// Валидация данных
$username = clean_input($_POST['username'] ?? '');
$email = clean_input($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

$errors = [];

if (empty($username)) {
    $errors[] = 'Имя пользователя обязательно';
} elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
    $errors[] = 'Имя пользователя должно содержать 3-20 символов (буквы, цифры, подчеркивания)';
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Некорректный email';
}

if (strlen($password) < 8) {
    $errors[] = 'Пароль должен содержать минимум 8 символов';
}

// Проверка уникальности
if (empty($errors)) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            $errors[] = 'Пользователь с таким именем или email уже существует';
        }
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        $errors[] = 'Ошибка базы данных';
    }
}

if (!empty($errors)) {
    $_SESSION['errors'] = $errors;
    $_SESSION['old_data'] = ['username' => $username, 'email' => $email];
    header('Location: register.php');
    exit;
}

// Создание пользователя
try {
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
    $stmt->execute([$username, $email, $password_hash]);
    
    $_SESSION['success'] = 'Регистрация успешна! Теперь вы можете войти';
    header('Location: login.php');
    exit;
    
} catch (PDOException $e) {
    error_log("Registration error: " . $e->getMessage());
    $_SESSION['errors'] = ['Ошибка при регистрации'];
    header('Location: register.php');
    exit;
}