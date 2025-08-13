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

    $data = [
        'entity_id' => (int)$_POST['entity_id'],
        'entity_type' => $_POST['entity_type'] ?? '',
        'content' => clean_input($_POST['content']),
        'flag_color' => match($_POST['flag_color'] ?? '') {
            'success' => 'green',
            'warning' => 'yellow',
            'danger' => 'red',
            default => 'green'
        },
        'admin_id' => $_SESSION['admin']['id']
    ];

    if (!in_array($data['entity_type'], ['order', 'customer'])) {
        throw new Exception('Invalid entity type', 400);
    }

    $stmt = $pdo->prepare("
        INSERT INTO notes (entity_id, entity_type, content, flag_color, admin_id)
        VALUES (:entity_id, :entity_type, :content, :flag_color, :admin_id)
    ");
    
    if (!$stmt->execute($data)) {
        throw new Exception('Database error', 500);
    }

    echo json_encode(['status' => 'success']);
    
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}