<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/login-regist/connection.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$csrf = (string) ($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$bookingId = (int) ($_POST['booking_id'] ?? 0);
if ($bookingId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
    exit;
}

try {
    $pdo->exec('SET search_path TO sooma_wellness, public');
    $stmt = $pdo->prepare("DELETE FROM sooma_wellness.bookings WHERE booking_id = :id");
    $stmt->execute([':id' => $bookingId]);

    echo json_encode(['success' => $stmt->rowCount() === 1]);
} catch (Throwable $ex) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $ex->getMessage()]);
}