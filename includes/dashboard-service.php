<?php

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

    // 1. KPI: Total User & Tren (Hari ini vs Kemarin)
    $userRow = $pdo->query("
        SELECT 
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE DATE(created_at) = CURRENT_DATE) AS today,
            COUNT(*) FILTER (WHERE DATE(created_at) = CURRENT_DATE - INTERVAL '1 day') AS yesterday
        FROM users
        WHERE role_id IS DISTINCT FROM 1
    ")->fetch(PDO::FETCH_ASSOC);
    $userTrend = calcTrend((float)$userRow['today'], (float)$userRow['yesterday']);

    // 2. KPI: Active Bookings & Tren (Minggu ini vs Minggu lalu)
    $bookRow = $pdo->query("
        SELECT 
            COUNT(*) FILTER (
                WHERE LOWER(b.booking_status) = 'confirmed' 
                  AND (c.schedule_date + c.start_time) >= NOW()
            ) AS active_now,
            COUNT(*) FILTER (
                WHERE LOWER(b.booking_status) = 'confirmed' 
                  AND b.booked_at >= DATE_TRUNC('week', CURRENT_DATE)
            ) AS this_week,
            COUNT(*) FILTER (
                WHERE LOWER(b.booking_status) = 'confirmed' 
                  AND b.booked_at >= DATE_TRUNC('week', CURRENT_DATE) - INTERVAL '7 days'
                  AND b.booked_at < DATE_TRUNC('week', CURRENT_DATE)
            ) AS last_week
        FROM bookings b
        JOIN classes c ON b.schedule_id = c.schedule_id
    ")->fetch(PDO::FETCH_ASSOC);
    $bookTrend = calcTrend((float)$bookRow['this_week'], (float)$bookRow['last_week']);

    // 3. KPI: Total Revenue & Tren (Hari ini vs Kemarin)
    $revRow = $pdo->query("
        SELECT 
            COALESCE(SUM(gross_amount) FILTER (WHERE LOWER(verification_status) = 'confirmed'), 0) AS total,
            COALESCE(SUM(gross_amount) FILTER (WHERE LOWER(verification_status) = 'confirmed' AND DATE(verified_at) = CURRENT_DATE), 0) AS today,
            COALESCE(SUM(gross_amount) FILTER (WHERE LOWER(verification_status) = 'confirmed' AND DATE(verified_at) = CURRENT_DATE - INTERVAL '1 day'), 0) AS yesterday
        FROM payments
    ")->fetch(PDO::FETCH_ASSOC);
    $revTrend = calcTrend((float)$revRow['today'], (float)$revRow['yesterday']);

    // 4. KPI: Pending Payment & Tren
    $pendRow = $pdo->query("
        SELECT 
            COUNT(*) FILTER (WHERE LOWER(verification_status) IN ('pending', 'check')) AS total,
            COUNT(*) FILTER (WHERE LOWER(verification_status) IN ('pending', 'check') AND DATE(uploaded_at) = CURRENT_DATE) AS today,
            COUNT(*) FILTER (WHERE LOWER(verification_status) IN ('pending', 'check') AND DATE(uploaded_at) = CURRENT_DATE - INTERVAL '1 day') AS yesterday
        FROM payments
    ")->fetch(PDO::FETCH_ASSOC);
    $pendTrend = calcTrend((float)$pendRow['today'], (float)$pendRow['yesterday']);

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

    // 6. Data Tabel Bookings
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
                'pct'   => $bookTrend['pct'],
                'dir'   => $bookTrend['dir']
            ],
            'total_revenue' => [
                'value' => 'Rp ' . number_format((float)$revRow['total'], 0, ',', '.'),
                'pct'   => $revTrend['pct'],
                'dir'   => $revTrend['dir']
            ],
            'pending_payment' => [
                'value' => (string) $pendRow['total'],
                'pct'   => $pendTrend['pct'],
                'dir'   => $pendTrend['dir']
            ]
        ],
        'bookings' => $bookingsMap,
        'chart' => [
            'default' => date('F'),
            'months'  => $monthsData
        ]
    ];
}