<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/login-regist/connection.php';
require_once __DIR__ . '/dashboard-service.php';

try {
    echo json_encode(
        getDashboardPayload($pdo, $_SESSION['csrf_token']),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $ex) {
    http_response_code(500);
    echo json_encode(['error' => $ex->getMessage()]);
}