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
        'status'  => 'unauthenticated',
        'message' => 'Please login first.'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Method not allowed.'
    ]);
    exit;
}

$action     = (string) ($_POST['action'] ?? '');
$scheduleId = (int) ($_POST['schedule_id'] ?? 0);
$targetQty  = max(1, min(2, (int) ($_POST['qty'] ?? 1)));
$holdExpr   = sprintf("clock_timestamp() + interval '%d minutes'", defined('BD_HOLD_MINUTES') ? BD_HOLD_MINUTES : 30);

$step = 'init';

try {
    $step = 'connect_db';
    $pdo = bd_pdo();

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $step = 'set_search_path';
    $pdo->exec('SET search_path TO sooma_wellness, public');

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
    $pdo->exec("
        UPDATE sooma_wellness.bookings
           SET booking_status = 'expired', updated_at = clock_timestamp()
         WHERE booking_status = 'waiting_payment'
           AND expires_at IS NOT NULL
           AND expires_at <= clock_timestamp()
    ");

    $step = 'get_customer_id';
    $customerId = bd_get_or_create_customer_id($pdo, $userId);
    $draftDbId  = (int) ($_SESSION['booking_draft']['db_id'] ?? 0);

    if ($action === 'release') {
        $step = 'release_hold';
        if ($draftDbId > 0) {
            $pdo->prepare("DELETE FROM sooma_wellness.booking_attendees WHERE booking_id = :bid")
                ->execute([':bid' => $draftDbId]);

            $pdo->prepare("
                UPDATE sooma_wellness.bookings
                   SET booking_status = 'cancelled', updated_at = clock_timestamp()
                 WHERE booking_id = :bid
                   AND customer_id = :cid
                   AND booking_status = 'waiting_payment'
            ")->execute([':bid' => $draftDbId, ':cid' => $customerId]);
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

    $step = 'check_existing_booking';
    $stmtExisting = $pdo->prepare("
        SELECT booking_id AS id, booking_code, booking_status AS status
          FROM sooma_wellness.bookings
         WHERE schedule_id = :sid
           AND booking_status IN ('waiting_payment', 'waiting_verification', 'confirmed')
           AND (booking_id = :sess OR customer_id = :cid)
         ORDER BY booking_id DESC
         LIMIT 1
    ");
    $stmtExisting->execute([
        ':sid'  => $scheduleId,
        ':sess' => $draftDbId,
        ':cid'  => $customerId,
    ]);
    $existing = $stmtExisting->fetch() ?: null;

    if ($existing && $existing['status'] !== 'waiting_payment') {
        echo json_encode([
            'status'  => 'error',
            'message' => 'You already have an active booking for this class session.'
        ]);
        exit;
    }

    if ($draftDbId > 0 && (!$existing || (int) $existing['id'] !== $draftDbId)) {
        $step = 'cancel_previous_draft';
        $pdo->prepare("DELETE FROM sooma_wellness.booking_attendees WHERE booking_id = :bid")
            ->execute([':bid' => $draftDbId]);
        $pdo->prepare("
            UPDATE sooma_wellness.bookings
               SET booking_status = 'cancelled', updated_at = clock_timestamp()
             WHERE booking_id = :bid AND customer_id = :cid AND booking_status = 'waiting_payment'
        ")->execute([':bid' => $draftDbId, ':cid' => $customerId]);
        unset($_SESSION['booking_draft']);
    }

    $excludeId = $existing ? (int) $existing['id'] : 0;

    $step = 'count_used_slots';
    $stmtUsed = $pdo->prepare("
        SELECT COALESCE(COUNT(ba.attendee_id), 0)
          FROM sooma_wellness.booking_attendees ba
          JOIN sooma_wellness.bookings b ON b.booking_id = ba.booking_id
         WHERE b.schedule_id = :sid
           AND b.booking_id <> :ex
           AND " . bd_active_hold_sql('b')
    );
    $stmtUsed->execute([':sid' => $scheduleId, ':ex' => $excludeId]);
    $usedByOthers     = (int) $stmtUsed->fetchColumn();
    $availableForUser = $capacity - $usedByOthers;

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

    if ($existing) {
        $step = 'update_existing_hold';
        $bookingId = (int) $existing['id'];
        $code      = (string) ($existing['booking_code'] ?: sprintf('BK-%04d', $bookingId));

        $pdo->prepare("
            UPDATE sooma_wellness.bookings
               SET expires_at = {$holdExpr}, updated_at = clock_timestamp()
             WHERE booking_id = :id
        ")->execute([':id' => $bookingId]);
    } else {
        $step = 'insert_new_hold';
        $insBook = $pdo->prepare("
            INSERT INTO sooma_wellness.bookings (customer_id, schedule_id, payment_type_choice, booking_status, expires_at)
            VALUES (:cid, :sid, 'dropin', 'waiting_payment', {$holdExpr})
            RETURNING booking_id
        ");
        $insBook->execute([':cid' => $customerId, ':sid' => $scheduleId]);
        $bookingId = (int) $insBook->fetchColumn();
        $code      = sprintf('BK-%04d', $bookingId);

        $pdo->prepare("UPDATE sooma_wellness.bookings SET booking_code = :code WHERE booking_id = :id")
            ->execute([':code' => $code, ':id' => $bookingId]);
    }

    $step = 'sync_attendees';
    $pdo->prepare("DELETE FROM sooma_wellness.booking_attendees WHERE booking_id = :bid")
        ->execute([':bid' => $bookingId]);

    $insAtt = $pdo->prepare("
        INSERT INTO sooma_wellness.booking_attendees (booking_id, user_id, attendee_name)
        VALUES (:bid, :uid, :name)
    ");
    for ($i = 1; $i <= $targetQty; $i++) {
        $insAtt->execute([
            ':bid'  => $bookingId,
            ':uid'  => $i === 1 ? $userId : null,
            ':name' => (string) ($sessionUser['full_name'] ?? $sessionUser['name'] ?? "Hold Slot #{$i}")
        ]);
    }

    $classQuery = [
        'scheduleId' => $scheduleId,
        'date'       => (string) ($_POST['date'] ?? ''),
        'start'      => (string) ($_POST['start'] ?? ''),
        'end'        => (string) ($_POST['end'] ?? ''),
        'instructor' => (string) ($_POST['instructor'] ?? ''),
        'className'  => (string) ($_POST['className'] ?? ''),
        'category'   => (string) ($_POST['category'] ?? ''),
        'level'      => (string) ($_POST['level'] ?? ''),
        'type'       => (string) ($_POST['type'] ?? ''),
        'slot'       => $usedByOthers + $targetQty,
        'capacity'   => $capacity,
    ];
    bd_store_booking_session(['id' => $bookingId, 'code' => $code], $classQuery);

    echo json_encode([
        'status'           => 'ok',
        'booking_id'       => $bookingId,
        'schedule_id'      => $scheduleId,
        'held_qty'         => $targetQty,
        'remaining_others' => max(0, $availableForUser - $targetQty),
        'max_allowed'      => min(2, $availableForUser),
        'unit_price'       => $unitPrice,
        'total_price'      => $unitPrice * $targetQty
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