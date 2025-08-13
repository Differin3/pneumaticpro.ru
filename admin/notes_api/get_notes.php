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

    $entity_id = (int)$_GET['entity_id'];
    $entity_type = $_GET['entity_type'] ?? '';

    if (!in_array($entity_type, ['order', 'customer'])) {
        throw new Exception('Invalid entity type', 400);
    }

    $stmt = $pdo->prepare("
        SELECT n.*, a.username AS admin_name 
        FROM notes n
        JOIN admins a ON n.admin_id = a.id
        WHERE n.entity_id = ? AND n.entity_type = ?
        ORDER BY n.created_at DESC
    ");
    $stmt->execute([$entity_id, $entity_type]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Конвертируем цвет в класс Bootstrap
    foreach ($notes as &$note) {
        $note['flag_color'] = match($note['flag_color']) {
            'red' => 'danger',
            'yellow' => 'warning',
            'green' => 'success',
            default => 'primary'
        };
    }

    echo json_encode($notes);
    
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}