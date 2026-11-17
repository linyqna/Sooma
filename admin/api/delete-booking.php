<?php
session_start();
header('Content-Type: application/json');
require_once dirname(__DIR__, 2) . '/login-regist/connection.php';

$bookingCode = $_POST['booking_id'] ?? '';
if (empty($bookingCode)) {
    exit(json_encode(['success' => false]));
}

$pdo->exec("SET search_path TO sooma_wellness, public");
$stmt = $pdo->prepare("DELETE FROM bookings WHERE booking_code = :code");
$ok = $stmt->execute(['code' => $bookingCode]);

echo json_encode(['success' => $ok]);