<?php

declare(strict_types=1);

ini_set('display_errors', '0');
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'status'  => 'error',
            'message' => 'PHP Fatal Error: ' . $err['message'] . ' in ' . basename($err['file']) . ' line ' . $err['line']
        ]);
    }
});

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../includes/booking-service.php';

$sessionUser = $_SESSION['user'] ?? [];
$userId = (int) ($sessionUser['id'] ?? $sessionUser['user_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode([
        'status'   => 'unauthenticated',
        'redirect' => '/login-regist/index.php?page=login'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid security token. Please refresh the page.']);
    exit;
}

$scheduleId = (int) ($_POST['schedule_id'] ?? 0);
$classQuery = bd_sanitize_class_query((string) ($_POST['class_query'] ?? ''));
$data       = bd_parse_submission($_POST);
$packages   = bd_get_packages($userId);
$errors     = bd_validate_submission($data, $packages);

if ($errors || count($data['attendees']) < 1 || count($data['attendees']) > 2) {
    echo json_encode(['status' => 'invalid']);
    exit;
}

try {
    $pdo = bd_pdo();
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec('SET search_path TO sooma_wellness, public');

    // Jika schedule_id dari form kosong/0, cari ulang dari class_query
    if ($scheduleId <= 0 && !empty($classQuery)) {
        $foundSched = bd_find_or_create_schedule($pdo, $classQuery);
        if (!empty($foundSched['id'])) {
            $scheduleId = (int) $foundSched['id'];
        }
    }

    $sessionBookingId = (int) ($_SESSION['booking_draft']['db_id'] ?? 0);

    $result = bd_reserve_slot($pdo, $scheduleId, $userId, $data, $classQuery, $sessionBookingId);

    if ($result['status'] === 'available') {
        // Verifikasi kecocokan total harga antara tampilan frontend dan kalkulasi database
        $clientExpectedTotal = (int) ($_POST['expected_total'] ?? -1);
        $serverTotal         = (int) ($result['booking']['total'] ?? 0);

        if ($data['payment_method'] === 'dropin' && $clientExpectedTotal >= 0 && $clientExpectedTotal !== $serverTotal) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Class price has changed. Please refresh the page to verify the latest price.'
            ]);
            exit;
        }

        bd_store_booking_session($result['booking'], $classQuery);
        echo json_encode([
            'status'   => 'available',
            'redirect' => 'payment.php'
        ]);
        exit;
    }

    if ($result['status'] === 'existing') {
        bd_store_booking_session($result['booking'], $classQuery);
        echo json_encode([
            'status'   => 'available',
            'redirect' => 'payment.php'
        ]);
        exit;
    }

    if ($result['status'] === 'full') {
        echo json_encode([
            'status'  => 'full',
            'message' => 'Oops, sorry! The last spot in your selected class has just been booked. Kindly choose another available class.'
        ]);
        exit;
    }

    echo json_encode([
        'status'  => 'error',
        'message' => $result['message'] ?? 'Unable to verify slot availability.'
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[verify-slot] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Verify Error: ' . $e->getMessage()
    ]);
}