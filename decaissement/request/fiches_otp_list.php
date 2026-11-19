<?php
header('Content-Type: application/json; charset=utf-8');
// Placeholder endpoint: à brancher avec la logique OTP/boss réelle.
try {
    echo json_encode(['ok' => true, 'count' => 0, 'total' => 0, 'items' => []]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
