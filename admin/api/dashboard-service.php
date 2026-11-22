<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/dashboard-service.php';

function getDashboardPayload(PDO $pdo, string $csrfToken): array
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec('SET search_path TO sooma_wellness, public');

    $pdo->exec("
        UPDATE sooma_wellness.bookings
           SET booking_status = 'expired',
               updated_at = clock_timestamp()
         WHERE booking_status = 'waiting_payment'
           AND expires_at IS NOT NULL
           AND expires_at <= clock_timestamp()
    ");

    $totalUsers = (int) $pdo->query("
        SELECT COUNT(*) FROM sooma_wellness.customers
    ")->fetchColumn();

    $kpiRow = $pdo->query("
        SELECT
            COUNT(*) AS total_bookings,
            COUNT(*) FILTER (
                WHERE b.booking_status IN ('waiting_payment', 'waiting_verification', 'confirmed')
            ) AS active_bookings,
            COUNT(*) FILTER (
                WHERE (b.booking_status = 'waiting_verification' OR (p.payment_proof IS NOT NULL AND p.payment_proof <> ''))
                  AND b.booking_status NOT IN ('confirmed', 'completed', 'rejected', 'cancelled', 'expired')
                  AND COALESCE(p.verification_status, '') NOT IN ('verified', 'rejected')
            ) AS need_check,
            COUNT(*) FILTER (
                WHERE b.booking_status = 'waiting_payment'
                  AND (p.payment_proof IS NULL OR p.payment_proof = '')
                  AND COALESCE(p.verification_status, '') NOT IN ('verified', 'rejected')
            ) AS pending,
            COUNT(*) FILTER (
                WHERE b.booking_status IN ('confirmed', 'completed') OR p.verification_status = 'verified'
            ) AS confirmed,
            COUNT(*) FILTER (
                WHERE b.booking_status = 'rejected' OR p.verification_status = 'rejected'
            ) AS rejected
        FROM sooma_wellness.bookings b
        LEFT JOIN sooma_wellness.payments p ON p.payment_id = b.payment_id
    ")->fetch(PDO::FETCH_ASSOC);

    $totalRevenue = (int) $pdo->query("
        SELECT COALESCE(SUM(gross_amount), 0)
          FROM sooma_wellness.payments
         WHERE verification_status = 'verified'
    ")->fetchColumn();

    $year = (int) date('Y');
    $monthNames = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];

    $monthsChart = [];
    foreach ($monthNames as $mNum => $mLabel) {
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $mNum, $year);
        $monthsChart[$mLabel] = [
            'days'   => $daysInMonth,
            'values' => array_fill(0, $daysInMonth, 0),
        ];
    }

    $salesStmt = $pdo->prepare("
        SELECT EXTRACT(MONTH FROM COALESCE(p.verified_at, p.uploaded_at, b.booked_at))::int AS m,
               EXTRACT(DAY FROM COALESCE(p.verified_at, p.uploaded_at, b.booked_at))::int   AS d,
               COALESCE(SUM(p.gross_amount), 0)::bigint                                    AS total
          FROM sooma_wellness.payments p
          LEFT JOIN sooma_wellness.bookings b ON b.payment_id = p.payment_id
         WHERE p.verification_status = 'verified'
           AND COALESCE(p.verified_at, p.uploaded_at, b.booked_at) IS NOT NULL
           AND EXTRACT(YEAR FROM COALESCE(p.verified_at, p.uploaded_at, b.booked_at)) = :yr
         GROUP BY 1, 2
    ");
    $salesStmt->execute([':yr' => $year]);

    foreach ($salesStmt->fetchAll(PDO::FETCH_ASSOC) as $sRow) {
        $mNum = (int) $sRow['m'];
        $dNum = (int) $sRow['d'];
        if (isset($monthNames[$mNum])) {
            $mLabel = $monthNames[$mNum];
            if ($dNum >= 1 && $dNum <= $monthsChart[$mLabel]['days']) {
                $monthsChart[$mLabel]['values'][$dNum - 1] = (int) $sRow['total'];
            }
        }
    }

    $defaultMonth = $monthNames[(int) date('n')] ?? 'January';

    $stmt = $pdo->query("
        SELECT b.booking_id,
               COALESCE(b.booking_code, 'BK-' || LPAD(b.booking_id::text, 4, '0')) AS display_id,
               b.booking_status,
               b.payment_type_choice,
               COALESCE(
                   NULLIF(TRIM(to_jsonb(b)->>'class_type'), ''),
                   NULLIF(TRIM(COALESCE(to_jsonb(cs)->>'class_type', to_jsonb(cs)->>'type')), ''),
                   'Regular'
               ) AS class_type,
               u.name AS customer_name,
               mc.class_name,
               mc.price,
               COALESCE(cs.level, 'All Level') AS level,
               cs.schedule_date,
               TO_CHAR(cs.schedule_date, 'YYYY-MM-DD') AS date_iso,
               TO_CHAR(cs.schedule_date, 'DD Mon YYYY') || '<br>' ||
               TO_CHAR(cs.start_time, 'HH24:MI') || ' - ' || TO_CHAR(cs.end_time, 'HH24:MI') AS date_table,
               TO_CHAR(cs.schedule_date, 'DD Mon YYYY') || ' (' ||
               TO_CHAR(cs.start_time, 'HH24:MI') || ' - ' || TO_CHAR(cs.end_time, 'HH24:MI') || ')' AS schedule_full,
               COALESCE(u_inst.name, '-') AS coach,
               p.payment_id,
               p.gross_amount,
               p.payment_proof,
               p.verification_status,
               p.admin_note,
               (
                   SELECT STRING_AGG(NULLIF(TRIM(ba.medical_notes), ''), '; ')
                     FROM sooma_wellness.booking_attendees ba
                    WHERE ba.booking_id = b.booking_id
               ) AS medical_notes,
               (
                   SELECT COUNT(*)
                     FROM sooma_wellness.booking_attendees ba
                    WHERE ba.booking_id = b.booking_id
               ) AS attendee_count
          FROM sooma_wellness.bookings b
          JOIN sooma_wellness.customers c ON c.customer_id = b.customer_id
          JOIN sooma_wellness.users u ON u.user_id = c.user_id
          JOIN sooma_wellness.class_schedules cs ON cs.schedule_id = b.schedule_id
          JOIN sooma_wellness.master_classes mc ON mc.class_id = cs.class_id
          LEFT JOIN sooma_wellness.instructors inst ON inst.instructor_id = cs.instructor_id
          LEFT JOIN sooma_wellness.users u_inst ON u_inst.user_id = inst.user_id
          LEFT JOIN sooma_wellness.payments p ON p.payment_id = b.payment_id
         ORDER BY b.booking_id DESC
    ");

    $bookings     = [];
    $bookingOrder = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $idStr     = (string) $r['booking_id'];
        $bStatus   = strtolower(trim((string) ($r['booking_status'] ?? 'pending')));
        $vStatus   = strtolower(trim((string) ($r['verification_status'] ?? '')));
        $rawProof  = trim((string) ($r['payment_proof'] ?? ''));
        $hasProof  = $rawProof !== '';
        $classType = ucfirst(strtolower(trim((string) ($r['class_type'] ?? 'Regular'))));

        if ($vStatus === 'rejected' || $bStatus === 'rejected') {
            $uiStatus = 'rejected';
        } elseif ($bStatus === 'confirmed' || $bStatus === 'completed' || $vStatus === 'verified') {
            $uiStatus = 'confirmed';
        } elseif ($bStatus === 'waiting_verification' || $hasProof) {
            $uiStatus = 'check';
        } else {
            $uiStatus = 'pending';
        }

        $isAccepted = in_array($bStatus, ['accepted', 'approved', 'confirmed', 'completed'], true)
                      || $uiStatus === 'confirmed';

        $amount = $r['gross_amount'] !== null
            ? (int) $r['gross_amount']
            : ((int) $r['attendee_count'] * (int) $r['price']);

        if (!$hasProof) {
            $proofUrl = '';
        } elseif (str_starts_with($rawProof, 'data:')) {
            $proofUrl = $rawProof;
        } else {
            $proofUrl = '../' . ltrim($rawProof, '/');
        }

        $bookingOrder[] = $idStr;

        $bookings[$idStr] = [
            'id'             => $idStr,
            'display_id'     => '#' . ltrim((string) $r['display_id'], '#'),
            'name'           => (string) $r['customer_name'],
            'class_name'     => (string) $r['class_name'],
            'level'          => (string) $r['level'],
            'class_level'    => $r['class_name'] . ' — ' . $r['level'],
            'class_type'     => $classType,
            'booking_status' => $bStatus,
            'private_status' => $bStatus,
            'is_accepted'    => $isAccepted,
            'date_iso'       => (string) $r['date_iso'],
            'date_table'     => (string) $r['date_table'],
            'schedule'       => (string) $r['schedule_full'],
            'medical'        => $r['medical_notes'] ?: '-',
            'coach'          => (string) $r['coach'],
            'status'         => $uiStatus,
            'amount'         => 'Rp' . number_format($amount, 0, '.', ','),
            'receipt'        => $proofUrl,
            'admin_note'     => (string) ($r['admin_note'] ?? ''),
        ];
    }

    return [
        'csrf'         => $csrfToken,
        'perPage'      => 6,
        'statusLabels' => [
            'check'     => 'Check',
            'pending'   => 'Pending',
            'confirmed' => 'Confirmed',
            'rejected'  => 'Rejected',
        ],
        'kpi' => [
            'total_user'      => ['value' => number_format($totalUsers), 'pct' => 'Active', 'dir' => 'up'],
            'active_bookings' => ['value' => (string) ($kpiRow['active_bookings'] ?? 0), 'pct' => 'Live', 'dir' => 'up'],
            'total_revenue'   => ['value' => 'Rp ' . number_format($totalRevenue, 0, ',', '.'), 'pct' => 'Verified', 'dir' => 'up'],
            'pending_payment' => ['value' => (string) ($kpiRow['pending'] ?? 0), 'pct' => 'Queue', 'dir' => 'up'],
        ],
        'booking_kpi' => [
            'total_bookings' => ['value' => (string) ($kpiRow['total_bookings'] ?? 0), 'pct' => '100%',     'dir' => 'up'],
            'check'          => ['value' => (string) ($kpiRow['need_check'] ?? 0),     'pct' => 'To Review','dir' => 'up'],
            'confirmed'      => ['value' => (string) ($kpiRow['confirmed'] ?? 0),      'pct' => 'Verified', 'dir' => 'up'],
            'rejected'       => ['value' => (string) ($kpiRow['rejected'] ?? 0),       'pct' => 'Declined', 'dir' => 'down'],
        ],
        'chart' => [
            'default' => $defaultMonth,
            'months'  => $monthsChart,
        ],
        'booking_order' => $bookingOrder,
        'bookings'      => (object) $bookings,
    ];
}