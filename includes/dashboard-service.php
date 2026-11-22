<?php

declare(strict_types=1);

function getDashboardPayload(PDO $pdo, string $csrfToken): array
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec('set search_path to sooma_wellness, public');

    $pdo->exec("
        update sooma_wellness.bookings
           set booking_status = 'expired',
               updated_at = clock_timestamp()
         where booking_status = 'waiting_payment'
           and expires_at is not null
           and expires_at <= clock_timestamp()
    ");

    $totalUsers = (int) $pdo->query("
        select count(*) from sooma_wellness.customers
    ")->fetchColumn();

    $kpiRow = $pdo->query("
        select
            count(*) as total_bookings,
            count(*) filter (
                where b.booking_status in ('waiting_payment', 'waiting_verification', 'confirmed')
            ) as active_bookings,
            count(*) filter (
                where (b.booking_status = 'waiting_verification' or (p.payment_proof is not null and p.payment_proof <> ''))
                  and b.booking_status not in ('confirmed', 'completed', 'rejected', 'cancelled', 'expired')
                  and coalesce(p.verification_status, '') not in ('verified', 'rejected')
            ) as need_check,
            count(*) filter (
                where b.booking_status = 'waiting_payment'
                  and (p.payment_proof is null or p.payment_proof = '')
                  and coalesce(p.verification_status, '') not in ('verified', 'rejected')
            ) as pending,
            count(*) filter (
                where b.booking_status in ('confirmed', 'completed') or p.verification_status = 'verified'
            ) as confirmed,
            count(*) filter (
                where b.booking_status = 'rejected' or p.verification_status = 'rejected'
            ) as rejected
        from sooma_wellness.bookings b
        left join sooma_wellness.payments p on p.payment_id = b.payment_id
    ")->fetch(PDO::FETCH_ASSOC);

    $totalRevenue = (int) $pdo->query("
        select coalesce(sum(gross_amount), 0)
          from sooma_wellness.payments
         where verification_status = 'verified'
    ")->fetchColumn();

    $year = (int) date('Y');
    $monthNames = [
        1 => 'January',
        2 => 'February',
        3 => 'March',
        4 => 'April',
        5 => 'May',
        6 => 'June',
        7 => 'July',
        8 => 'August',
        9 => 'September',
        10 => 'October',
        11 => 'November',
        12 => 'December',
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
        select extract(month from coalesce(p.verified_at, p.uploaded_at, b.booked_at))::int as m,
               extract(day from coalesce(p.verified_at, p.uploaded_at, b.booked_at))::int   as d,
               coalesce(sum(p.gross_amount), 0)::bigint                                    as total
          from sooma_wellness.payments p
          left join sooma_wellness.bookings b on b.payment_id = p.payment_id
         where p.verification_status = 'verified'
           and coalesce(p.verified_at, p.uploaded_at, b.booked_at) is not null
           and extract(year from coalesce(p.verified_at, p.uploaded_at, b.booked_at)) = :yr
         group by 1, 2
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

    $bookings     = [];
    $bookingOrder = [];

    $reqStmt = $pdo->query("
        select rc.request_id,
               'REQ-' || lpad(rc.request_id::text, 4, '0') as display_id,
               coalesce(rc.status, 'pending') as req_status,
               u.name as customer_name,
               mc.class_name,
               mc.price,
               coalesce(to_jsonb(mc)->>'level', 'All Level') as level,
               to_char(rc.preferred_date, 'YYYY-MM-DD') as date_iso,
               to_char(rc.preferred_date, 'DD Mon YYYY') || '<br>' ||
               to_char(rc.preferred_time, 'HH24:MI') || ' - ' || to_char(rc.preferred_time + interval '1 hour', 'HH24:MI') as date_table,
               to_char(rc.preferred_date, 'DD Mon YYYY') || ' (' ||
               to_char(rc.preferred_time, 'HH24:MI') || ' - ' || to_char(rc.preferred_time + interval '1 hour', 'HH24:MI') || ')' as schedule_full,
               rc.medical_notes,
               coalesce(nullif(trim(to_jsonb(rc)->>'preferred_coach'), ''), 'Any Available Coach') as coach,
               coalesce((to_jsonb(rc)->>'attendee_count')::int, 1) as attendee_count,
               coalesce(to_jsonb(rc)->>'admin_note', '') as admin_note
          from sooma_wellness.request_classes rc
          join sooma_wellness.customers c on c.customer_id = rc.customer_id
          join sooma_wellness.users u on u.user_id = c.user_id
          join sooma_wellness.master_classes mc on mc.class_id = rc.class_id
         where lower(coalesce(rc.status, 'pending')) in ('pending', 'rejected')
         order by rc.request_id desc
    ");

    foreach ($reqStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $idStr     = 'req-' . $r['request_id'];
        $reqStatus = strtolower(trim((string) $r['req_status']));
        $uiStatus  = $reqStatus === 'rejected' ? 'rejected' : 'pending';
        $amount    = (int) $r['attendee_count'] * (int) $r['price'];

        $rawMedical = trim((string) ($r['medical_notes'] ?? ''));
        if ($rawMedical !== '') {
            $cleanParts = array_filter(array_map(static function (string $part): string {
                return trim((string) preg_replace('/^[^:;]{1,80}:\s*/u', '', trim($part)));
            }, explode(';', $rawMedical)));
            $medicalDisplay = !empty($cleanParts) ? implode('; ', $cleanParts) : $rawMedical;
        } else {
            $medicalDisplay = '-';
        }

        $bookingOrder[] = $idStr;

        $bookings[$idStr] = [
            'id'                 => $idStr,
            'display_id'         => '#' . $r['display_id'],
            'name'               => (string) $r['customer_name'],
            'class_name'         => (string) $r['class_name'],
            'level'              => (string) $r['level'],
            'class_level'        => $r['class_name'] . ' — ' . $r['level'],
            'class_type'         => 'Private',
            'is_private_request' => true,
            'booking_status'     => $reqStatus,
            'private_status'     => $reqStatus,
            'is_accepted'        => false,
            'date_iso'           => (string) $r['date_iso'],
            'date_table'         => (string) $r['date_table'],
            'schedule'           => (string) $r['schedule_full'],
            'medical'            => $medicalDisplay,
            'coach'              => (string) $r['coach'],
            'status'             => $uiStatus,
            'amount'             => 'Rp' . number_format($amount, 0, '.', ','),
            'receipt'            => '',
            'admin_note'         => (string) ($r['admin_note'] ?? ''),
        ];
    }

    $stmt = $pdo->query("
        select b.booking_id,
               coalesce(b.booking_code, 'BK-' || lpad(b.booking_id::text, 4, '0')) as display_id,
               b.booking_status,
               b.payment_type_choice,
               coalesce(
                   nullif(trim(to_jsonb(b)->>'class_type'), ''),
                   nullif(trim(coalesce(to_jsonb(cs)->>'class_type', to_jsonb(cs)->>'type')), ''),
                   'Regular'
               ) as class_type,
               u.name as customer_name,
               mc.class_name,
               mc.price,
               coalesce(cs.level, 'All Level') as level,
               cs.schedule_date,
               to_char(cs.schedule_date, 'YYYY-MM-DD') as date_iso,
               to_char(cs.schedule_date, 'DD Mon YYYY') || '<br>' ||
               to_char(cs.start_time, 'HH24:MI') || ' - ' || to_char(cs.end_time, 'HH24:MI') as date_table,
               to_char(cs.schedule_date, 'DD Mon YYYY') || ' (' ||
               to_char(cs.start_time, 'HH24:MI') || ' - ' || to_char(cs.end_time, 'HH24:MI') || ')' as schedule_full,
               coalesce(u_inst.name, '-') as coach,
               p.payment_id,
               p.gross_amount,
               p.payment_proof,
               p.verification_status,
               p.admin_note,
               (
                   select string_agg(nullif(trim(ba.medical_notes), ''), '; ')
                     from sooma_wellness.booking_attendees ba
                    where ba.booking_id = b.booking_id
               ) as medical_notes,
               (
                   select count(*)
                     from sooma_wellness.booking_attendees ba
                    where ba.booking_id = b.booking_id
               ) as attendee_count
          from sooma_wellness.bookings b
          join sooma_wellness.customers c on c.customer_id = b.customer_id
          join sooma_wellness.users u on u.user_id = c.user_id
          join sooma_wellness.class_schedules cs on cs.schedule_id = b.schedule_id
          join sooma_wellness.master_classes mc on mc.class_id = cs.class_id
          left join sooma_wellness.instructors inst on inst.instructor_id = cs.instructor_id
          left join sooma_wellness.users u_inst on u_inst.user_id = inst.user_id
          left join sooma_wellness.payments p on p.payment_id = b.payment_id
         order by b.booking_id desc
    ");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $idStr    = (string) $r['booking_id'];
        $bStatus  = strtolower(trim((string) $r['booking_status']));
        $vStatus  = strtolower(trim((string) ($r['verification_status'] ?? '')));
        $rawProof = trim((string) ($r['payment_proof'] ?? ''));
        $hasProof = $rawProof !== '';

        if ($vStatus === 'rejected' || $bStatus === 'rejected') {
            $uiStatus = 'rejected';
        } elseif ($bStatus === 'confirmed' || $bStatus === 'completed' || $vStatus === 'verified') {
            $uiStatus = 'confirmed';
        } elseif ($bStatus === 'waiting_verification' || $hasProof) {
            $uiStatus = 'check';
        } else {
            $uiStatus = 'pending';
        }

        $classType  = ucfirst(strtolower(trim((string) ($r['class_type'] ?? 'Regular'))));
        $isAccepted = ($classType === 'Private')
            || in_array($bStatus, ['accepted', 'approved', 'confirmed', 'completed'], true)
            || $vStatus === 'verified';

        $medicalNote = trim((string) ($r['medical_notes'] ?? ''));
        if ($medicalNote === '') {
            $medicalNote = '-';
        }

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
            'id'                 => $idStr,
            'display_id'         => '#' . $r['display_id'],
            'name'               => (string) $r['customer_name'],
            'class_name'         => (string) $r['class_name'],
            'level'              => (string) $r['level'],
            'class_level'        => $r['class_name'] . ' — ' . $r['level'],
            'class_type'         => $classType,
            'is_private_request' => false,
            'booking_status'     => $bStatus,
            'private_status'     => $classType === 'Private' ? 'approved' : $bStatus,
            'is_accepted'        => $isAccepted,
            'date_iso'           => (string) $r['date_iso'],
            'date_table'         => (string) $r['date_table'],
            'schedule'           => (string) $r['schedule_full'],
            'medical'            => $medicalNote,
            'coach'              => (string) $r['coach'],
            'status'             => $uiStatus,
            'amount'             => 'Rp' . number_format($amount, 0, '.', ','),
            'receipt'            => $proofUrl,
            'admin_note'         => (string) ($r['admin_note'] ?? ''),
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
            'total_bookings' => ['value' => (string) ($kpiRow['total_bookings'] ?? 0), 'pct' => '100%',      'dir' => 'up'],
            'check'          => ['value' => (string) ($kpiRow['need_check'] ?? 0),     'pct' => 'To Review', 'dir' => 'up'],
            'confirmed'      => ['value' => (string) ($kpiRow['confirmed'] ?? 0),      'pct' => 'Verified',  'dir' => 'up'],
            'rejected'       => ['value' => (string) ($kpiRow['rejected'] ?? 0),       'pct' => 'Declined',  'dir' => 'down'],
        ],
        'chart' => [
            'default' => $defaultMonth,
            'months'  => $monthsChart,
        ],
        'booking_order' => $bookingOrder,
        'bookings'      => (object) $bookings,
    ];
}