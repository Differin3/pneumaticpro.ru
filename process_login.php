<?php
session_start();
require 'includes/config.php';
require 'includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

$username = clean_input($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

$errors = [];

if (empty($username) || empty($password)) {
    $errors[] = 'Все поля обязательны для заполнения';
}

if (empty($errors)) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            header('Location: index.php');
            exit;
        } else {
            $errors[] = 'Неверное имя пользователя или пароль';
        }
    } catch (PDOException $e) {
        error_log("Login error: " . $e->getMessage());
        $errors[] = 'Ошибка входа';
    }
}

$_SESSION['errors'] = $errors;
$_SESSION['old_data'] = ['username' => $username];
header('Location: login.php');
exit;