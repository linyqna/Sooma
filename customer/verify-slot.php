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

/**
 * Validasi aturan voucher di sisi server dan hitung total akhir setelah diskon.
 */
function vs_apply_voucher(
    string $voucherCode,
    int $attendeeCount,
    int $baseSubtotal,
    string $startTime,
    string $birthdate,
    bool $hasAcceptedPrivate
): array {
    $code = strtoupper(trim($voucherCode));
    if ($code === '') {
        return [
            'error'    => null,
            'total'    => $baseSubtotal,
            'discount' => 0,
        ];
    }

    $unitPrice = $attendeeCount > 0 ? intdiv($baseSubtotal, $attendeeCount) : 0;

    if ($code === 'SUMMER400') {
        if (!$hasAcceptedPrivate) {
            return ['error' => 'Voucher Summer Day Out hanya dapat digunakan jika Anda sudah melakukan Request Private dan telah di-accept oleh admin.'];
        }
        if ($attendeeCount < 4 || ($attendeeCount % 4) !== 0) {
            return ['error' => 'Voucher Summer Day Out hanya berlaku untuk total peserta (termasuk attendee) berjumlah 4 atau kelipatan 4.'];
        }
        $finalTotal = (int) (($attendeeCount / 4) * 400000);
        return [
            'error'    => null,
            'total'    => $finalTotal,
            'discount' => max(0, $baseSubtotal - $finalTotal),
        ];
    }

    if ($code === 'GENZ100') {
        $birthYear = 0;
        if (preg_match('/(19\d{2}|20\d{2})/', trim($birthdate), $m)) {
            $birthYear = (int) $m[1];
        }
        if ($birthYear < 1997 || $birthYear > 2012) {
            return ['error' => 'Voucher Calling All Gen Z hanya berlaku untuk customer dengan tahun lahir 1997 - 2012.'];
        }
        if ($unitPrice < 100000) {
            return ['error' => 'Voucher Calling All Gen Z hanya berlaku untuk sesi kelas dengan harga Rp100.000 ke atas.'];
        }
        $finalTotal = 100000 * $attendeeCount;
        return [
            'error'    => null,
            'total'    => $finalTotal,
            'discount' => max(0, $baseSubtotal - $finalTotal),
        ];
    }

    if ($code === 'HAPPYHOUR135') {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $startTime, $tm)) {
            return ['error' => 'Waktu mulai sesi kelas tidak valid untuk pengecekan Happy Hour.'];
        }
        $startMinutes    = ((int) $tm[1]) * 60 + ((int) $tm[2]);
        $inMorningSlot   = ($startMinutes >= 11 * 60 && $startMinutes <= 14 * 60);
        $inAfternoonSlot = ($startMinutes >= 15 * 60 && $startMinutes <= 17 * 60);
        if (!$inMorningSlot && !$inAfternoonSlot) {
            return ['error' => 'Voucher Happy Hour hanya dapat digunakan untuk reservasi kelas pada pukul 11.00 - 14.00 atau 15.00 - 17.00.'];
        }
        $finalTotal = 135000 * $attendeeCount;
        return [
            'error'    => null,
            'total'    => $finalTotal,
            'discount' => max(0, $baseSubtotal - $finalTotal),
        ];
    }

    return ['error' => 'Kode voucher tidak valid atau tidak berlaku.'];
}

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

$scheduleId  = (int) ($_POST['schedule_id'] ?? 0);
$classQuery  = bd_sanitize_class_query((string) ($_POST['class_query'] ?? ''));
$data        = bd_parse_submission($_POST);
$packages    = bd_get_packages($userId);
$errors      = bd_validate_submission($data, $packages);
$voucherCode = strtoupper(trim((string) ($_POST['voucher_code'] ?? '')));

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

    $customerId = bd_get_or_create_customer_id($pdo, $userId);

    // Ambil birthdate customer untuk validasi GENZ100
    $customerBirthdate = (string) (
        $sessionUser['birthdate']
        ?? $sessionUser['birth_date']
        ?? $sessionUser['date_of_birth']
        ?? $sessionUser['dob']
        ?? $sessionUser['tanggal_lahir']
        ?? ''
    );
    $userEmail = (string) ($sessionUser['email'] ?? '');

    if ($customerBirthdate === '') {
        try {
            $stBirth = $pdo->prepare("
                SELECT COALESCE(
                    NULLIF(to_jsonb(c)->>'birthdate', ''),
                    NULLIF(to_jsonb(c)->>'birth_date', ''),
                    NULLIF(to_jsonb(c)->>'date_of_birth', ''),
                    NULLIF(to_jsonb(c)->>'dob', ''),
                    NULLIF(to_jsonb(c)->>'tanggal_lahir', ''),
                    NULLIF(to_jsonb(u)->>'birthdate', ''),
                    NULLIF(to_jsonb(u)->>'birth_date', ''),
                    NULLIF(to_jsonb(u)->>'date_of_birth', ''),
                    NULLIF(to_jsonb(u)->>'dob', ''),
                    NULLIF(to_jsonb(u)->>'tanggal_lahir', '')
                ) AS dob
                  FROM sooma_wellness.users u
                  LEFT JOIN sooma_wellness.customers c ON c.user_id = u.user_id
                 WHERE u.user_id = :uid
                    OR c.customer_id = :cid
                    OR (:email <> '' AND LOWER(u.email) = LOWER(:email))
                 LIMIT 1
            ");
            $stBirth->execute([
                ':uid'   => $userId,
                ':cid'   => $customerId,
                ':email' => $userEmail,
            ]);
            $dbBirth = $stBirth->fetchColumn();
            if ($dbBirth) {
                $customerBirthdate = (string) $dbBirth;
            }
        } catch (Throwable $ignore) {
        }
    }

    // Cek apakah customer sudah melakukan request private dan di-accept oleh admin
    $hasAcceptedPrivate = false;
    try {
        $stPriv = $pdo->prepare("
            SELECT 1
              FROM sooma_wellness.private_requests pr
             WHERE (
                    (to_jsonb(pr)->>'customer_id') = :cid_txt
                 OR (to_jsonb(pr)->>'user_id') = :uid_txt
             )
               AND LOWER(COALESCE(
                    NULLIF(to_jsonb(pr)->>'status', ''),
                    NULLIF(to_jsonb(pr)->>'request_status', ''),
                    NULLIF(to_jsonb(pr)->>'approval_status', ''),
                    ''
               )) IN ('accepted', 'approved')
             LIMIT 1
        ");
        $stPriv->execute([
            ':cid_txt' => (string) $customerId,
            ':uid_txt' => (string) $userId,
        ]);
        $hasAcceptedPrivate = (bool) $stPriv->fetchColumn();
    } catch (Throwable $ignore) {
        $hasAcceptedPrivate = false;
    }

    // Tentukan batas maksimal attendee (2 untuk reguler, atau kapasitas kelas untuk private)
    $maxBookingLimit = 2;
    $isPrivateSession = stripos((string) ($classQuery['type'] ?? ''), 'private') !== false
        || stripos((string) ($classQuery['className'] ?? ''), 'private') !== false
        || $hasAcceptedPrivate;

    if ($isPrivateSession) {
        $stCap = $pdo->prepare("SELECT member_slot FROM sooma_wellness.class_schedules WHERE schedule_id = :sid LIMIT 1");
        $stCap->execute([':sid' => $scheduleId]);
        $slotCap = (int) $stCap->fetchColumn();
        $maxBookingLimit = $slotCap > 0 ? $slotCap : max(4, (int) ($classQuery['capacity'] ?? 4));
    }

    $attendeeCount = count($data['attendees']);
    if ($errors || $attendeeCount < 1 || $attendeeCount > $maxBookingLimit) {
        echo json_encode(['status' => 'invalid']);
        exit;
    }

    // Validasi awal voucher sebelum menahan/membuat booking
    if ($data['payment_method'] === 'dropin' && $voucherCode !== '') {
        $preCheck = vs_apply_voucher(
            $voucherCode,
            $attendeeCount,
            100000 * $attendeeCount,
            (string) ($classQuery['start'] ?? ''),
            $customerBirthdate,
            $hasAcceptedPrivate
        );
        if ($preCheck['error'] !== null) {
            echo json_encode([
                'status'  => 'error',
                'message' => $preCheck['error']
            ]);
            exit;
        }
    }

    $sessionBookingId = (int) ($_SESSION['booking_draft']['db_id'] ?? 0);

    $result = bd_reserve_slot($pdo, $scheduleId, $userId, $data, $classQuery, $sessionBookingId);

    if ($result['status'] === 'available' || $result['status'] === 'existing') {
        $clientExpectedTotal = (int) ($_POST['expected_total'] ?? -1);
        $baseSubtotal        = (int) ($result['booking']['total'] ?? 0);
        $serverTotal         = $baseSubtotal;
        $discountAmount      = 0;

        if ($data['payment_method'] === 'dropin') {
            $voucherCalc = vs_apply_voucher(
                $voucherCode,
                $attendeeCount,
                $baseSubtotal,
                (string) ($classQuery['start'] ?? ''),
                $customerBirthdate,
                $hasAcceptedPrivate
            );

            if ($voucherCalc['error'] !== null) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => $voucherCalc['error']
                ]);
                exit;
            }

            $serverTotal    = (int) $voucherCalc['total'];
            $discountAmount = (int) $voucherCalc['discount'];

            if ($result['status'] === 'available' && $clientExpectedTotal >= 0 && $clientExpectedTotal !== $serverTotal) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'Class price has changed. Please refresh the page to verify the latest price.'
                ]);
                exit;
            }

            $result['booking']['total'] = $serverTotal;
        }

        bd_store_booking_session($result['booking'], $classQuery);

        $bookingDbId = (int) (
            $result['booking']['db_id']
            ?? $result['booking']['id']
            ?? $_SESSION['booking_draft']['db_id']
            ?? 0
        );

        // Simpan / perbarui data voucher untuk dibaca oleh payment.php
        if ($bookingDbId > 0) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS sooma_wellness.booking_vouchers (
                    booking_id      INT PRIMARY KEY,
                    voucher_code    VARCHAR(32) NOT NULL,
                    discount_amount INT NOT NULL DEFAULT 0,
                    final_total     INT NOT NULL,
                    created_at      TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
                )
            ");

            if ($data['payment_method'] === 'dropin' && $voucherCode !== '') {
                $pdo->prepare("
                    INSERT INTO sooma_wellness.booking_vouchers (booking_id, voucher_code, discount_amount, final_total)
                    VALUES (:bid, :code, :disc, :total)
                    ON CONFLICT (booking_id)
                    DO UPDATE SET
                        voucher_code    = EXCLUDED.voucher_code,
                        discount_amount = EXCLUDED.discount_amount,
                        final_total     = EXCLUDED.final_total,
                        created_at      = clock_timestamp()
                ")->execute([
                    ':bid'   => $bookingDbId,
                    ':code'  => $voucherCode,
                    ':disc'  => $discountAmount,
                    ':total' => $serverTotal,
                ]);

                $_SESSION['booking_vouchers'][$bookingDbId] = [
                    'voucher_code'    => $voucherCode,
                    'discount_amount' => $discountAmount,
                    'final_total'     => $serverTotal,
                ];
            } else {
                $pdo->prepare("DELETE FROM sooma_wellness.booking_vouchers WHERE booking_id = :bid")
                    ->execute([':bid' => $bookingDbId]);
                unset($_SESSION['booking_vouchers'][$bookingDbId]);
            }

            // Lepas slot_holds sementara karena slot sudah masuk ke tabel bookings
            try {
                $pdo->prepare("DELETE FROM sooma_wellness.slot_holds WHERE schedule_id = :sid AND user_id = :uid")
                    ->execute([':sid' => $scheduleId, ':uid' => $userId]);
            } catch (Throwable $ignore) {
            }
        }

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