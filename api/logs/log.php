<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$message = clean_input($input['message'] ?? '');
$level = clean_input($input['level'] ?? 'INFO');

if ($message) {
    logToFile($message, $level);
    echo json_encode(['status' => 'success']);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Сообщение для лога не указано']);
}
?>