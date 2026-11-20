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
    echo json_encode(['status' => 'unauthenticated', 'message' => 'Please login first.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

$action     = (string) ($_POST['action'] ?? '');
$scheduleId = (int) ($_POST['schedule_id'] ?? 0);
$targetQty  = max(1, min(2, (int) ($_POST['qty'] ?? 1)));

$step = 'init';

try {
    $step = 'connect_db';
    $pdo = bd_pdo();
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $step = 'set_search_path';
    $pdo->exec('SET search_path TO sooma_wellness, public');

    // Tabel sementara khusus hold sebelum Continue Payment (tidak masuk tabel bookings)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sooma_wellness.slot_holds (
            hold_id     SERIAL PRIMARY KEY,
            schedule_id INT NOT NULL,
            user_id     INT NOT NULL,
            qty         INT NOT NULL DEFAULT 1,
            expires_at  TIMESTAMPTZ NOT NULL,
            UNIQUE (schedule_id, user_id)
        )
    ");

    if ($action !== 'release' && !empty($_POST['date'])) {
        $step = 'find_schedule';
        $synced = bd_find_or_create_schedule($pdo, [
            'schedule_id' => $scheduleId,
            'date'        => $_POST['date'] ?? '',
            'start'       => $_POST['start'] ?? '',
            'end'         => $_POST['end'] ?? '',
            'instructor'  => $_POST['instructor'] ?? '',
            'className'   => $_POST['className'] ?? '',
            'category'    => $_POST['category'] ?? '',
            'level'       => $_POST['level'] ?? '',
        ]);
        if (!empty($synced['id'])) {
            $scheduleId = (int) $synced['id'];
        }
    }

    $step = 'expire_old_holds';
    $pdo->exec("DELETE FROM sooma_wellness.slot_holds WHERE expires_at <= clock_timestamp()");
    $pdo->exec("
        UPDATE sooma_wellness.bookings
           SET booking_status = 'expired', updated_at = clock_timestamp()
         WHERE booking_status = 'waiting_payment'
           AND expires_at IS NOT NULL
           AND expires_at <= clock_timestamp()
    ");

    if ($action === 'release') {
        $step = 'release_hold';
        if ($scheduleId > 0) {
            $pdo->prepare("DELETE FROM sooma_wellness.slot_holds WHERE schedule_id = :sid AND user_id = :uid")
                ->execute([':sid' => $scheduleId, ':uid' => $userId]);
        } else {
            $pdo->prepare("DELETE FROM sooma_wellness.slot_holds WHERE user_id = :uid")
                ->execute([':uid' => $userId]);
        }
        unset($_SESSION['booking_draft']);

        echo json_encode(['status' => 'released']);
        exit;
    }

    $step = 'fetch_schedule';
    $stmtLock = $pdo->prepare("
        SELECT schedule_id, class_id, member_slot
          FROM sooma_wellness.class_schedules
         WHERE schedule_id = :sid
         LIMIT 1
    ");
    $stmtLock->execute([':sid' => $scheduleId]);
    $schedRow = $stmtLock->fetch();

    if (!$schedRow) {
        echo json_encode([
            'status'  => 'error',
            'message' => "Schedule ID ({$scheduleId}) not found in class_schedules."
        ]);
        exit;
    }

    $step = 'fetch_price';
    $stmtPrice = $pdo->prepare("
        SELECT COALESCE(price, 120000)::int
          FROM sooma_wellness.master_classes
         WHERE class_id = :cid
         LIMIT 1
    ");
    $stmtPrice->execute([':cid' => (int) $schedRow['class_id']]);
    $unitPrice = (int) ($stmtPrice->fetchColumn() ?: 120000);
    $capacity  = (int) $schedRow['member_slot'];

    $step = 'count_used_slots';
    // 1. Slot dari tabel bookings yang benar-benar sudah Continue Payment / Confirmed
    $stmtUsed = $pdo->prepare("
        SELECT COALESCE(COUNT(ba.attendee_id), 0)
          FROM sooma_wellness.booking_attendees ba
          JOIN sooma_wellness.bookings b ON b.booking_id = ba.booking_id
         WHERE b.schedule_id = :sid
           AND " . bd_active_hold_sql('b')
    );
    $stmtUsed->execute([':sid' => $scheduleId]);
    $bookedCount = (int) $stmtUsed->fetchColumn();

    // 2. Slot yang sedang ditahan user LAIN di halaman booking-detail
    $stmtOtherHolds = $pdo->prepare("
        SELECT COALESCE(SUM(qty), 0)
          FROM sooma_wellness.slot_holds
         WHERE schedule_id = :sid
           AND user_id <> :uid
           AND expires_at > clock_timestamp()
    ");
    $stmtOtherHolds->execute([':sid' => $scheduleId, ':uid' => $userId]);
    $otherHolds = (int) $stmtOtherHolds->fetchColumn();

    $availableForUser = max(0, $capacity - $bookedCount - $otherHolds);

    if ($availableForUser < 1) {
        echo json_encode([
            'status'  => 'full',
            'message' => 'Oops, sorry! The last spot in your selected class has just been booked. Kindly choose another available class.'
        ]);
        exit;
    }

    if ($action === 'update_count' && $availableForUser < $targetQty) {
        echo json_encode([
            'status'    => 'insufficient_for_add',
            'available' => $availableForUser,
            'message'   => 'Only 1 spot is left in this class. You cannot add another attendee.'
        ]);
        exit;
    }

    $step = 'upsert_slot_hold';
    $pdo->prepare("
        INSERT INTO sooma_wellness.slot_holds (schedule_id, user_id, qty, expires_at)
        VALUES (:sid, :uid, :qty, clock_timestamp() + interval '15 minutes')
        ON CONFLICT (schedule_id, user_id)
        DO UPDATE SET qty = EXCLUDED.qty, expires_at = EXCLUDED.expires_at
    ")->execute([
        ':sid' => $scheduleId,
        ':uid' => $userId,
        ':qty' => $targetQty,
    ]);

    echo json_encode([
        'status'           => 'ok',
        'schedule_id'      => $scheduleId,
        'held_qty'         => $targetQty,
        'remaining_others' => max(0, $availableForUser - $targetQty),
        'max_allowed'      => min(2, $availableForUser),
        'unit_price'       => $unitPrice,
        'total_price'      => $unitPrice * $targetQty,
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("[api-hold-slot][{$step}] " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => "Server Error at [{$step}]: " . $e->getMessage()
    ]);
}