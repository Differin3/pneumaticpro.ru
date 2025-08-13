<?php
session_start();
require '../includes/config.php';
require '../includes/functions.php';

if (!isset($_SESSION['admin'])) {
    die('Доступ запрещен');
}

if (!validate_csrf_token($_POST['csrf_token'])) {
    die('Недействительный CSRF-токен');
}

$productId = (int)$_POST['product_id'];

// Удаление изображения
$stmt = $pdo->prepare("SELECT image FROM products WHERE id = ?");
$stmt->execute([$productId]);
$image = $stmt->fetchColumn();

if ($image && file_exists("../uploads/$image")) {
    unlink("../uploads/$image");
}

// Удаление записи
$stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
$stmt->execute([$productId]);

header("Location: index.php?success=Товар удален");
exit();