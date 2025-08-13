<?php
session_start();
require __DIR__ . '/../../includes/config.php';
require __DIR__ . '/../../includes/functions.php';

// Устанавливаем заголовок JSON
header('Content-Type: application/json');

try {
    if (!isset($_SESSION['admin'])) {
        throw new Exception('Access denied', 403);
    }

    $note_id = (int)$_POST['note_id'];

    $stmt = $pdo->prepare("DELETE FROM notes WHERE id = ?");
    if (!$stmt->execute([$note_id])) {
        throw new Exception('Database error', 500);
    }

    echo json_encode(['status' => 'success']);
    
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}