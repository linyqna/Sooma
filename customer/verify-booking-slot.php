<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$requireLogin = true;
$loginPage    = '/login-regist/index.php?page=login';
$paymentUrl   = 'payment.php';

require __DIR__ . '/../includes/booking-service.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function bd_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    bd_json(['status' => 'error', 'message' => 'Method not allowed.'], 405);
}

$csrf = (string) ($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
    bd_json(['status' => 'error', 'message' => 'Your session has expired. Please refresh the page.'], 400);
}

$sessionUser = $_SESSION['user'] ?? [];
$userId      = (int) ($sessionUser['id'] ?? 0);
if ($requireLogin && $userId <= 0) {
    bd_json(['status' => 'unauthenticated', 'message' => 'Please log in to continue.', 'redirect' => $loginPage], 401);
}

$scheduleId = filter_var($_POST['schedule_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($scheduleId === false) {
    bd_json(['status' => 'error', 'message' => 'Invalid class schedule.'], 400);
}

$data     = bd_parse_submission($_POST);
$packages = bd_get_packages($userId);
$errors   = bd_validate_submission($data, $packages);

// attendee_count dari browser hanya pemeriksaan konsistensi; angka yang dipakai tetap hasil parse server.
$claimed = filter_var($_POST['attendee_count'] ?? null, FILTER_VALIDATE_INT);
if ($claimed !== false && $claimed !== count($data['attendees'])) {
    $errors['form'] = 'Attendee data is out of sync. Please refresh the page and try again.';
}

if ($errors) {
    bd_json(['status' => 'invalid', 'message' => 'Please check the highlighted fields.', 'errors' => $errors], 422);
}

$classQuery = bd_sanitize_class_query((string) ($_POST['class_query'] ?? ''));

try {
    $result = bd_reserve_slot(
        bd_pdo(),
        $scheduleId,
        $userId,
        $data,
        $classQuery,
        (int) ($_SESSION['booking_draft']['db_id'] ?? 0)
    );
} catch (Throwable $e) {
    error_log('[verify-booking-slot] ' . $e->getMessage());
    bd_json(['status' => 'error', 'message' => 'We could not verify slot availability right now. Please try again.'], 500);
}

switch ($result['status']) {
    case 'available':
    case 'existing':
        bd_store_booking_session($result['booking'], $classQuery);
        bd_json([
            'status'     => 'available',
            'redirect'   => $paymentUrl,
            'booking_id' => $result['booking']['code'],
            'expires_in' => $result['booking']['remaining'] ?? null,
        ]);

    case 'full':
        bd_json([
            'status'     => 'full',
            'message'    => $result['message'],
            'slots_left' => $result['slots_left'],
        ], 409);

    default:
        bd_json(['status' => 'error', 'message' => $result['message'] ?? 'Unexpected error.'], (int) ($result['http'] ?? 500));
}
