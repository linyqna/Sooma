<?php
// D:\TaskMatrix\TaskNode_III\Sooma\includes\dashboard_service.php

function calcTrend(float $current, float $previous): array {
    if ($previous <= 0) {
        $pct = $current > 0 ? 100.0 : 0.0;
    } else {
        $pct = (($current - $previous) / $previous) * 100;
    }
    return [
        'pct' => number_format(abs($pct), 1, '.', '') . '%',
        'dir' => $pct >= 0 ? 'up' : 'down'
    ];
}

function getDashboardPayload(PDO $pdo, string $csrfToken): array {
    $pdo->exec("SET search_path TO sooma_wellness, public");

    // 1. KPI Dashboard: Total User & Tren (Hari ini vs Kemarin)
    $userRow = $pdo->query("
        SELECT 
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE DATE(created_at) = CURRENT_DATE) AS today,
            COUNT(*) FILTER (WHERE DATE(created_at) = CURRENT_DATE - INTERVAL '1 day') AS yesterday
        FROM users
        WHERE role_id IS DISTINCT FROM 1
    ")->fetch(PDO::FETCH_ASSOC);
    $userTrend = calcTrend((float)$userRow['today'], (float)$userRow['yesterday']);

    // 2. KPI Dashboard & Booking Management: Statistik Booking Lengkap
    $bookRow = $pdo->query("
        SELECT 
            -- Total seluruh booking
            COUNT(*) AS total_all,
            COUNT(*) FILTER (WHERE b.booked_at >= DATE_TRUNC('week', CURRENT_DATE)) AS total_this_week,
            COUNT(*) FILTER (
                WHERE b.booked_at >= DATE_TRUNC('week', CURRENT_DATE) - INTERVAL '7 days'
                  AND b.booked_at < DATE_TRUNC('week', CURRENT_DATE)
            ) AS total_last_week,

            -- Active Bookings (Confirmed & jadwal kelas belum lewat)
            COUNT(*) FILTER (
                WHERE LOWER(b.booking_status) = 'confirmed' 
                  AND (c.schedule_date + c.start_time) >= NOW()
            ) AS active_now,

            -- Total Confirmed
            COUNT(*) FILTER (WHERE LOWER(b.booking_status) = 'confirmed') AS confirmed_all,
            COUNT(*) FILTER (
                WHERE LOWER(b.booking_status) = 'confirmed' 
                  AND b.booked_at >= DATE_TRUNC('week', CURRENT_DATE)
            ) AS confirmed_this_week,
            COUNT(*) FILTER (
                WHERE LOWER(b.booking_status) = 'confirmed' 
                  AND b.booked_at >= DATE_TRUNC('week', CURRENT_DATE) - INTERVAL '7 days'
                  AND b.booked_at < DATE_TRUNC('week', CURRENT_DATE)
            ) AS confirmed_last_week,

            -- Completed (Confirmed & jadwal kelas sudah selesai/lewat)
            COUNT(*) FILTER (
                WHERE LOWER(b.booking_status) = 'confirmed' 
                  AND (c.schedule_date + c.start_time) < NOW()
            ) AS completed_all,
            COUNT(*) FILTER (
                WHERE LOWER(b.booking_status) = 'confirmed' 
                  AND (c.schedule_date + c.start_time) < NOW()
                  AND (c.schedule_date + c.start_time) >= DATE_TRUNC('week', CURRENT_DATE)
            ) AS completed_this_week,
            COUNT(*) FILTER (
                WHERE LOWER(b.booking_status) = 'confirmed' 
                  AND (c.schedule_date + c.start_time) < DATE_TRUNC('week', CURRENT_DATE)
                  AND (c.schedule_date + c.start_time) >= DATE_TRUNC('week', CURRENT_DATE) - INTERVAL '7 days'
            ) AS completed_last_week,

            -- Pending / Check Payment
            COUNT(*) FILTER (
                WHERE LOWER(COALESCE(p.verification_status, 'pending')) IN ('pending', 'check')
            ) AS pending_all,
            COUNT(*) FILTER (
                WHERE LOWER(COALESCE(p.verification_status, 'pending')) IN ('pending', 'check')
                  AND b.booked_at >= DATE_TRUNC('week', CURRENT_DATE)
            ) AS pending_this_week,
            COUNT(*) FILTER (
                WHERE LOWER(COALESCE(p.verification_status, 'pending')) IN ('pending', 'check')
                  AND b.booked_at >= DATE_TRUNC('week', CURRENT_DATE) - INTERVAL '7 days'
                  AND b.booked_at < DATE_TRUNC('week', CURRENT_DATE)
            ) AS pending_last_week
        FROM bookings b
        JOIN classes c ON b.schedule_id = c.schedule_id
        LEFT JOIN payments p ON b.payment_id = p.payment_id
    ")->fetch(PDO::FETCH_ASSOC);

    $totalBookTrend     = calcTrend((float)$bookRow['total_this_week'], (float)$bookRow['total_last_week']);
    $confirmedBookTrend = calcTrend((float)$bookRow['confirmed_this_week'], (float)$bookRow['confirmed_last_week']);
    $completedBookTrend = calcTrend((float)$bookRow['completed_this_week'], (float)$bookRow['completed_last_week']);
    $pendingWeekTrend   = calcTrend((float)$bookRow['pending_this_week'], (float)$bookRow['pending_last_week']);

    // 3. KPI Dashboard: Total Revenue & Tren (Hari ini vs Kemarin)
    $revRow = $pdo->query("
        SELECT 
            COALESCE(SUM(gross_amount) FILTER (WHERE LOWER(verification_status) = 'confirmed'), 0) AS total,
            COALESCE(SUM(gross_amount) FILTER (WHERE LOWER(verification_status) = 'confirmed' AND DATE(verified_at) = CURRENT_DATE), 0) AS today,
            COALESCE(SUM(gross_amount) FILTER (WHERE LOWER(verification_status) = 'confirmed' AND DATE(verified_at) = CURRENT_DATE - INTERVAL '1 day'), 0) AS yesterday
        FROM payments
    ")->fetch(PDO::FETCH_ASSOC);
    $revTrend = calcTrend((float)$revRow['today'], (float)$revRow['yesterday']);

    // 4. KPI Dashboard: Pending Payment & Tren Harian
    $pendRow = $pdo->query("
        SELECT 
            COUNT(*) FILTER (WHERE LOWER(verification_status) IN ('pending', 'check')) AS total,
            COUNT(*) FILTER (WHERE LOWER(verification_status) IN ('pending', 'check') AND DATE(uploaded_at) = CURRENT_DATE) AS today,
            COUNT(*) FILTER (WHERE LOWER(verification_status) IN ('pending', 'check') AND DATE(uploaded_at) = CURRENT_DATE - INTERVAL '1 day') AS yesterday
        FROM payments
    ")->fetch(PDO::FETCH_ASSOC);
    $pendDailyTrend = calcTrend((float)$pendRow['today'], (float)$pendRow['yesterday']);

    // 5. Data Grafik Sales Details (3 Bulan Terakhir)
    $monthsData = [];
    for ($i = 0; $i < 3; $i++) {
        $ts        = strtotime("-$i months");
        $monthName = date('F', $ts);
        $monthNum  = (int) date('m', $ts);
        $yearNum   = (int) date('Y', $ts);
        $daysCount = (int) date('t', $ts);

        $stmt = $pdo->prepare("
            SELECT 
                EXTRACT(DAY FROM COALESCE(verified_at, uploaded_at))::INT AS day,
                COALESCE(SUM(gross_amount), 0) AS total
            FROM payments
            WHERE LOWER(verification_status) = 'confirmed'
              AND EXTRACT(MONTH FROM COALESCE(verified_at, uploaded_at)) = :m
              AND EXTRACT(YEAR FROM COALESCE(verified_at, uploaded_at)) = :y
            GROUP BY day
        ");
        $stmt->execute(['m' => $monthNum, 'y' => $yearNum]);
        $dailyMap = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $values = [];
        for ($d = 1; $d <= $daysCount; $d++) {
            $values[] = isset($dailyMap[$d]) ? (float) $dailyMap[$d] : 0;
        }

        $monthsData[$monthName] = [
            'days'   => $daysCount,
            'values' => $values
        ];
    }

    // 6. Data Tabel Bookings (Dipakai bersama oleh admin-dashboard.php & booking-management.php)
    $stmtBookings = $pdo->query("
        SELECT 
            b.booking_code AS id,
            u.name AS customer_name,
            c.class_name,
            c.level,
            c.instructor AS coach,
            c.schedule_date,
            c.start_time,
            COALESCE(
                NULLIF(STRING_AGG(NULLIF(TRIM(ba.medical_notes), ''), '; '), ''),
                '-'
            ) AS medical,
            LOWER(COALESCE(p.verification_status, 'pending')) AS status,
            COALESCE(p.payment_proof, '') AS receipt,
            COALESCE(p.gross_amount, c.price) AS raw_amount
        FROM bookings b
        JOIN users u ON b.customer_id = u.user_id
        JOIN classes c ON b.schedule_id = c.schedule_id
        LEFT JOIN payments p ON b.payment_id = p.payment_id
        LEFT JOIN booking_attendees ba ON b.booking_id = ba.booking_id
        GROUP BY 
            b.booking_id, b.booking_code, b.booked_at,
            u.name, c.class_name, c.level, c.instructor, c.schedule_date, c.start_time, c.price,
            p.verification_status, p.payment_proof, p.gross_amount
        ORDER BY b.booked_at DESC
    ");

    $bookingsMap = [];
    $monthsId = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'Mei',6=>'Jun',7=>'Jul',8=>'Agu',9=>'Sep',10=>'Okt',11=>'Nov',12=>'Des'];

    while ($row = $stmtBookings->fetch(PDO::FETCH_ASSOC)) {
        $dt        = new DateTime($row['schedule_date'] . ' ' . $row['start_time']);
        $mIdx      = (int) $dt->format('n');
        $dateShort = $dt->format('d') . ' ' . $monthsId[$mIdx] . ' ' . $dt->format('Y');
        $timeShort = $dt->format('H.i');

        $bookingsMap[$row['id']] = [
            'id'          => $row['id'],
            'display_id'  => '#' . $row['id'],
            'name'        => $row['customer_name'],
            'class_name'  => $row['class_name'],
            'level'       => $row['level'],
            'class_level' => $row['class_name'] . ' — ' . $row['level'],
            'date_iso'    => $row['schedule_date'],
            'date_table'  => $dateShort . '<br>' . $timeShort,
            'schedule'    => $dateShort . ', ' . $timeShort . ' WIB',
            'medical'     => $row['medical'],
            'coach'       => $row['coach'],
            'status'      => $row['status'],
            'receipt'     => $row['receipt'],
            'amount'      => 'Rp ' . number_format((float)$row['raw_amount'], 0, ',', '.'),
            'admin_note'  => ''
        ];
    }

    return [
        'perPage' => 6,
        'statusLabels' => [
            'check'     => 'Check',
            'pending'   => 'Pending',
            'confirmed' => 'Confirmed',
            'rejected'  => 'Rejected'
        ],
        'csrf' => $csrfToken,
        'kpi' => [
            'total_user' => [
                'value' => number_format((int)$userRow['total'], 0, ',', '.'),
                'pct'   => $userTrend['pct'],
                'dir'   => $userTrend['dir']
            ],
            'active_bookings' => [
                'value' => number_format((int)$bookRow['active_now'], 0, ',', '.'),
                'pct'   => $confirmedBookTrend['pct'],
                'dir'   => $confirmedBookTrend['dir']
            ],
            'total_revenue' => [
                'value' => 'Rp ' . number_format((float)$revRow['total'], 0, ',', '.'),
                'pct'   => $revTrend['pct'],
                'dir'   => $revTrend['dir']
            ],
            'pending_payment' => [
                'value' => (string) $pendRow['total'],
                'pct'   => $pendDailyTrend['pct'],
                'dir'   => $pendDailyTrend['dir']
            ]
        ],
        'booking_kpi' => [
            'total_bookings' => [
                'value' => number_format((int)$bookRow['total_all'], 0, ',', '.'),
                'pct'   => $totalBookTrend['pct'],
                'dir'   => $totalBookTrend['dir']
            ],
            'pending' => [
                'value' => number_format((int)$bookRow['pending_all'], 0, ',', '.'),
                'pct'   => $pendingWeekTrend['pct'],
                'dir'   => $pendingWeekTrend['dir']
            ],
            'confirmed' => [
                'value' => number_format((int)$bookRow['confirmed_all'], 0, ',', '.'),
                'pct'   => $confirmedBookTrend['pct'],
                'dir'   => $confirmedBookTrend['dir']
            ],
            'completed' => [
                'value' => number_format((int)$bookRow['completed_all'], 0, ',', '.'),
                'pct'   => $completedBookTrend['pct'],
                'dir'   => $completedBookTrend['dir']
            ]
        ],
        'bookings' => $bookingsMap,
        'chart' => [
            'default' => date('F'),
            'months'  => $monthsData
        ]
    ];
}