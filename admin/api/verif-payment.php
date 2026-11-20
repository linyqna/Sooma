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
$status    = (string) ($_POST['status'] ?? '');
$note      = trim((string) ($_POST['note'] ?? $_POST['reason'] ?? ''));

$sessionUser = $_SESSION['user'] ?? [];
$adminUserId = (int) ($sessionUser['id'] ?? $sessionUser['user_id'] ?? 0);

if ($bookingId <= 0 || !in_array($status, ['confirmed', 'rejected'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request parameters']);
    exit;
}

if ($status === 'rejected' && $note === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Rejection reason is required']);
    exit;
}

try {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec('SET search_path TO sooma_wellness, public');

    $st = $pdo->prepare("
        SELECT booking_id, payment_id
          FROM sooma_wellness.bookings
         WHERE booking_id = :id
         LIMIT 1
    ");
    $st->execute([':id' => $bookingId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
        exit;
    }

    $paymentId = (int) ($row['payment_id'] ?? 0);
    $vStatus   = $status === 'confirmed' ? 'verified' : 'rejected';
    $bStatus   = $status === 'confirmed' ? 'confirmed' : 'rejected';

    if ($paymentId > 0) {
        $upPay = $pdo->prepare("
            UPDATE sooma_wellness.payments
               SET verification_status = :vstatus,
                   verified_by_user_id = NULLIF(:admin_id, 0),
                   verified_at = clock_timestamp(),
                   admin_note = :note
             WHERE payment_id = :pid
        ");
        $upPay->execute([
            ':vstatus'  => $vStatus,
            ':admin_id' => $adminUserId,
            ':note'     => $note !== '' ? $note : null,
            ':pid'      => $paymentId,
        ]);
    }

    $upBook = $pdo->prepare("
        UPDATE sooma_wellness.bookings
           SET booking_status = :bstatus,
               updated_at = clock_timestamp()
         WHERE booking_id = :id
    ");
    $upBook->execute([
        ':bstatus' => $bStatus,
        ':id'      => $bookingId,
    ]);

    echo json_encode(['success' => true, 'status' => $status]);
} catch (Throwable $ex) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $ex->getMessage()]);
}