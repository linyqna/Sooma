<?php
declare(strict_types=1);

const BD_HOLD_MINUTES = 30;

if (!function_exists('e')) {
    function e(?string $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

function bd_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
    } else {
        $url = (string) (getenv('DATABASE_URL') ?: ($_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? ''));

        if ($url !== '') {
            $p = parse_url($url);
            if ($p === false || empty($p['host'])) {
                throw new RuntimeException('DATABASE_URL is invalid.');
            }
            parse_str($p['query'] ?? '', $q);
            $host = $p['host'];
            $port = (int) ($p['port'] ?? 5432);
            $name = ltrim((string) ($p['path'] ?? ''), '/');
            $user = urldecode((string) ($p['user'] ?? ''));
            $pass = urldecode((string) ($p['pass'] ?? ''));
            $ssl  = (string) ($q['sslmode'] ?? 'require');
        } else {
            $host = (string) getenv('PGHOST');
            $port = (int) (getenv('PGPORT') ?: 5432);
            $name = (string) getenv('PGDATABASE');
            $user = (string) getenv('PGUSER');
            $pass = (string) getenv('PGPASSWORD');
            $ssl  = (string) (getenv('PGSSLMODE') ?: 'require');
        }
        if ($host === '' || $name === '') {
            throw new RuntimeException('Database is not configured (set DATABASE_URL).');
        }

        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $host, $port, $name, $ssl),
            $user,
            $pass
        );
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('SET search_path TO sooma_wellness, public');

    return $pdo;
}

function bd_get_or_create_customer_id(PDO $pdo, int $userId): int
{
    $st = $pdo->prepare('SELECT customer_id FROM customers WHERE user_id = :uid LIMIT 1');
    $st->execute([':uid' => $userId]);
    $cid = $st->fetchColumn();
    if ($cid !== false) {
        return (int) $cid;
    }

    $ins = $pdo->prepare('INSERT INTO customers (user_id) VALUES (:uid) RETURNING customer_id');
    $ins->execute([':uid' => $userId]);
    return (int) $ins->fetchColumn();
}

function bd_get_packages(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    try {
        $pdo = bd_pdo();
        $st = $pdo->prepare(
            "SELECT up.user_package_id AS id, p.package_name AS name,
                    COALESCE(p.class_type, 'Group Class') AS type,
                    up.remaining_sessions AS remaining, p.total_sessions AS total
               FROM user_packages up
               JOIN packages p ON p.package_id = up.package_id
              WHERE up.user_id = :uid AND up.remaining_sessions > 0
              ORDER BY up.user_package_id DESC"
        );
        $st->execute([':uid' => $userId]);
        $rows = $st->fetchAll();
        if ($rows) {
            return $rows;
        }
    } catch (Throwable $e) {
        // Fallback jika struktur kolom user_packages berbeda
    }
    return [];
}

function bd_parse_submission(array $post): array
{
    $attendees = [];
    foreach ((array) ($post['attendees'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $attendees[] = [
            'name'         => trim((string) ($row['name'] ?? '')),
            'phone'        => (string) preg_replace('/[^\d+]/', '', (string) ($row['phone'] ?? '')),
            'email'        => trim((string) ($row['email'] ?? '')),
            'medical'      => trim((string) ($row['medical'] ?? '')),
            'not_pregnant' => !empty($row['not_pregnant']) ? '1' : '',
        ];
        if (count($attendees) >= 20) {
            break;
        }
    }

    $method = in_array($post['payment_method'] ?? '', ['dropin', 'package'], true)
        ? (string) $post['payment_method'] : 'dropin';

    return [
        'attendees'      => $attendees,
        'payment_method' => $method,
        'package_id'     => (string) ($post['package_id'] ?? ''),
        'agree'          => !empty($post['agree']),
    ];
}

function bd_validate_submission(array $data, array $packages): array
{
    $errors = [];

    if (!$data['attendees']) {
        $errors['form'] = 'Please add at least one attendee.';
    }
    foreach ($data['attendees'] as $i => $a) {
        if ($a['name'] === '' || strlen($a['name']) > 150) {
            $errors["attendees.$i.name"] = 'Please enter the attendee name.';
        }
        if (!preg_match('/^(\+62|62|0)8\d{7,12}$/', $a['phone'])) {
            $errors["attendees.$i.phone"] = 'Enter a valid Indonesian phone number.';
        }
        if (!filter_var($a['email'], FILTER_VALIDATE_EMAIL) || strlen($a['email']) > 190) {
            $errors["attendees.$i.email"] = 'Enter a valid email address.';
        }
    }

    if ($data['payment_method'] === 'package') {
        $selected = null;
        foreach ($packages as $pkg) {
            if ((string) $pkg['id'] === $data['package_id']) {
                $selected = $pkg;
            }
        }
        if (!$selected) {
            $errors['package'] = 'Please choose a package.';
        } elseif ((int) $selected['remaining'] < count($data['attendees'])) {
            $errors['package'] = 'Your package does not have enough remaining sessions.';
        }
    }

    if (!$data['agree']) {
        $errors['agree'] = 'You must agree to the Terms & Conditions.';
    }
    return $errors;
}

function bd_sanitize_class_query(string $raw): array
{
    parse_str($raw, $q);
    $out = [];
    foreach (['schedule_id', 'date', 'start', 'end', 'instructor', 'className', 'category', 'level', 'type', 'slot', 'capacity'] as $k) {
        if (isset($q[$k]) && is_scalar($q[$k])) {
            $out[$k] = substr((string) $q[$k], 0, 60);
        }
    }
    return $out;
}

function bd_active_hold_sql(string $a = 'b'): string
{
    return "({$a}.booking_status IN ('confirmed', 'waiting_verification')"
        . " OR ({$a}.booking_status = 'waiting_payment' AND ({$a}.expires_at IS NULL OR {$a}.expires_at > clock_timestamp())))";
}

function bd_find_or_create_schedule(PDO $pdo, array $q): ?array
{
    // 1. Jika URL sudah membawa schedule_id langsung dari database
    if (!empty($q['schedule_id']) && ctype_digit((string) $q['schedule_id'])) {
        $st = $pdo->prepare(
            'SELECT cs.schedule_id AS id, cs.member_slot AS capacity, COALESCE(mc.price, 120000)::int AS price
               FROM class_schedules cs
               JOIN master_classes mc ON mc.class_id = cs.class_id
              WHERE cs.schedule_id = :sid LIMIT 1'
        );
        $st->execute([':sid' => (int) $q['schedule_id']]);
        if ($row = $st->fetch()) {
            return $row;
        }
    }

    // 2. Cari jadwal berdasarkan tanggal, jam mulai, nama instruktur, dan nama kelas/kategori
    $st = $pdo->prepare(
        'SELECT cs.schedule_id AS id, cs.member_slot AS capacity, COALESCE(mc.price, 120000)::int AS price
           FROM class_schedules cs
           JOIN master_classes mc ON mc.class_id = cs.class_id
           JOIN instructors inst ON inst.instructor_id = cs.instructor_id
           JOIN users u ON u.user_id = inst.user_id
          WHERE cs.schedule_date = CAST(:d AS date)
            AND cs.start_time = CAST(:s AS time)
            AND LOWER(u.name) = LOWER(:inst)
            AND (LOWER(mc.class_name) = LOWER(:cat) OR LOWER(mc.class_name) = LOWER(:cls))
          LIMIT 1'
    );
    $st->execute([
        ':d'    => (string) ($q['date'] ?? ''),
        ':s'    => (string) ($q['start'] ?? ''),
        ':inst' => (string) ($q['instructor'] ?? ''),
        ':cat'  => (string) ($q['category'] ?? ''),
        ':cls'  => (string) ($q['className'] ?? ''),
    ]);

    return $st->fetch() ?: null;
}

function bd_schedule_availability(PDO $pdo, int $scheduleId, int $excludeBookingId = 0): ?array
{
    $st = $pdo->prepare(
        'SELECT cs.member_slot AS capacity,
                COALESCE(mc.price, 120000)::int AS price,
                COALESCE((
                    SELECT COUNT(ba.attendee_id)
                      FROM booking_attendees ba
                      JOIN bookings b ON b.booking_id = ba.booking_id
                     WHERE b.schedule_id = cs.schedule_id
                       AND b.booking_id <> :ex
                       AND ' . bd_active_hold_sql('b') . '
                ), 0) AS used
           FROM class_schedules cs
           JOIN master_classes mc ON mc.class_id = cs.class_id
          WHERE cs.schedule_id = :id'
    );
    $st->execute([':ex' => $excludeBookingId, ':id' => $scheduleId]);
    $r = $st->fetch();
    if (!$r) {
        return null;
    }

    $capacity = (int) $r['capacity'];
    $used     = (int) $r['used'];
    return [
        'capacity' => $capacity,
        'price'    => (int) $r['price'],
        'used'     => $used,
        'left'     => max(0, $capacity - $used),
    ];
}

function bd_reserve_slot(PDO $pdo, int $scheduleId, int $userId, array $data, array $classQuery, int $sessionBookingId = 0): array
{
    $count     = count($data['attendees']);
    $isPackage = $data['payment_method'] === 'package';
    $holdExpr  = sprintf("clock_timestamp() + interval '%d minutes'", BD_HOLD_MINUTES);
    $remaining = "GREATEST(0, CEIL(EXTRACT(EPOCH FROM (expires_at - clock_timestamp()))))::int";

    $pdo->beginTransaction();
    try {
        // Kunci baris jadwal agar tidak terjadi race condition / double booking
        $st = $pdo->prepare(
            'SELECT cs.schedule_id AS id, cs.member_slot AS capacity, COALESCE(mc.price, 120000)::int AS price
               FROM class_schedules cs
               JOIN master_classes mc ON mc.class_id = cs.class_id
              WHERE cs.schedule_id = :id FOR UPDATE OF cs'
        );
        $st->execute([':id' => $scheduleId]);
        $schedule = $st->fetch();
        if (!$schedule) {
            $pdo->rollBack();
            return ['status' => 'error', 'http' => 404, 'message' => 'This class schedule could not be found.'];
        }

        // Lepaskan hold yang sudah kedaluwarsa (> 30 menit)
        $pdo->prepare(
            "UPDATE bookings SET booking_status = 'expired', updated_at = clock_timestamp()
              WHERE schedule_id = :sid AND booking_status = 'waiting_payment' AND expires_at <= clock_timestamp()"
        )->execute([':sid' => $scheduleId]);

        $customerId = bd_get_or_create_customer_id($pdo, $userId);

        // Cek apakah customer sudah punya draft/booking aktif di jadwal ini
        $st = $pdo->prepare(
            "SELECT booking_id AS id, booking_code, booking_status AS status
               FROM bookings
              WHERE schedule_id = :sid
                AND booking_status IN ('waiting_payment', 'waiting_verification', 'confirmed')
                AND (booking_id = :sess OR customer_id = :cid)
              ORDER BY booking_id DESC LIMIT 1 FOR UPDATE"
        );
        $st->execute([':sid' => $scheduleId, ':sess' => $sessionBookingId, ':cid' => $customerId]);
        $existing = $st->fetch() ?: null;

        if ($existing && $existing['status'] !== 'waiting_payment') {
            $pdo->commit();
            return [
                'status'  => 'existing',
                'booking' => ['id' => (int) $existing['id'], 'code' => $existing['booking_code'] ?: sprintf('BK-%04d', $existing['id'])]
            ];
        }
        $excludeId = $existing ? (int) $existing['id'] : 0;

        // Hitung total attendee yang sudah menempati slot di jadwal ini
        $st = $pdo->prepare(
            'SELECT COALESCE(COUNT(ba.attendee_id), 0)
               FROM booking_attendees ba
               JOIN bookings b ON b.booking_id = ba.booking_id
              WHERE b.schedule_id = :sid AND b.booking_id <> :ex AND ' . bd_active_hold_sql('b')
        );
        $st->execute([':sid' => $scheduleId, ':ex' => $excludeId]);
        $used = (int) $st->fetchColumn();
        $left = (int) $schedule['capacity'] - $used;

        if ($left < $count) {
            $pdo->commit();
            return [
                'status'     => 'full',
                'http'       => 409,
                'slots_left' => max(0, $left),
                'message'    => $left <= 0
                    ? 'Sorry, the last slot for this class has just been taken by another participant.'
                    : sprintf('Only %d slot%s left, but you selected %d attendees.', $left, $left === 1 ? ' is' : 's are', $count),
            ];
        }

        $total = $isPackage ? 0 : (int) $schedule['price'] * $count;

        if ($existing) {
            $bookingId = (int) $existing['id'];
            $st = $pdo->prepare(
                "UPDATE bookings
                    SET payment_type_choice = :m, updated_at = clock_timestamp()
                  WHERE booking_id = :id
              RETURNING COALESCE(booking_code, 'BK-' || LPAD(booking_id::text, 4, '0')) AS booking_code,
                        {$remaining} AS remaining"
            );
            $st->execute([':m' => $data['payment_method'], ':id' => $bookingId]);
            $row = $st->fetch();

            // Hapus daftar attendee lama jika user mengedit ulang form sebelum bayar
            $pdo->prepare('DELETE FROM booking_attendees WHERE booking_id = :id')->execute([':id' => $bookingId]);
        } else {
            $st = $pdo->prepare(
                "INSERT INTO bookings (customer_id, schedule_id, payment_type_choice, booking_status, expires_at)
                 VALUES (:cid, :sid, :m, 'waiting_payment', {$holdExpr})
                 RETURNING booking_id, {$remaining} AS remaining"
            );
            $st->execute([
                ':cid' => $customerId,
                ':sid' => $scheduleId,
                ':m'   => $data['payment_method'],
            ]);
            $row       = $st->fetch();
            $bookingId = (int) $row['booking_id'];
            $code      = sprintf('BK-%04d', $bookingId);

            $pdo->prepare('UPDATE bookings SET booking_code = :code WHERE booking_id = :id')
                ->execute([':code' => $code, ':id' => $bookingId]);
            $row['booking_code'] = $code;
        }

        // Simpan seluruh attendee: index 0 (default user) mendapat user_id pemilik akun,
        // attendee tambahan mendapat user_id = NULL (kecuali email-nya juga punya akun di users).
        $findUserByEmail = $pdo->prepare('SELECT user_id FROM users WHERE LOWER(email) = LOWER(:e) LIMIT 1');
        $insAttendee = $pdo->prepare(
            'INSERT INTO booking_attendees (booking_id, user_id, attendee_name, phone, email, medical_notes, not_pregnant)
             VALUES (:bid, :uid, :name, :phone, :email, :med, :np)'
        );

        foreach ($data['attendees'] as $idx => $a) {
            if ($idx === 0 && $userId > 0) {
                $attendeeUserId = $userId;
            } else {
                $findUserByEmail->execute([':e' => $a['email']]);
                $foundUid = $findUserByEmail->fetchColumn();
                $attendeeUserId = $foundUid !== false ? (int) $foundUid : null;
            }

            $insAttendee->bindValue(':bid', $bookingId, PDO::PARAM_INT);
            $insAttendee->bindValue(':uid', $attendeeUserId, $attendeeUserId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $insAttendee->bindValue(':name', $a['name']);
            $insAttendee->bindValue(':phone', $a['phone']);
            $insAttendee->bindValue(':email', $a['email']);
            $insAttendee->bindValue(':med', $a['medical'] !== '' ? $a['medical'] : null, $a['medical'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insAttendee->bindValue(':np', $a['not_pregnant'] === '1', PDO::PARAM_BOOL);
            $insAttendee->execute();
        }

        $pdo->commit();
        return [
            'status'  => 'available',
            'booking' => [
                'id'             => $bookingId,
                'code'           => (string) $row['booking_code'],
                'remaining'      => (int) ($row['remaining'] ?? (BD_HOLD_MINUTES * 60)),
                'total'          => $total,
                'payment_method' => $data['payment_method'],
            ],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function bd_store_booking_session(array $booking, array $classQuery): void
{
    $_SESSION['booking_draft'] = [
        'booking_id' => $booking['code'],
        'db_id'      => (int) $booking['id'],
        'class'      => $classQuery,
    ];
}

function bd_load_booking(PDO $pdo, int $id, int $userId): ?array
{
    $st = $pdo->prepare(
        "SELECT b.*,
                b.booking_id AS id,
                b.booking_status AS status,
                b.payment_type_choice AS payment_method,
                c.user_id,
                (SELECT COUNT(*) FROM booking_attendees ba WHERE ba.booking_id = b.booking_id) AS attendee_count,
                (SELECT COUNT(*) * COALESCE(mc.price, 120000)::int
                   FROM booking_attendees ba
                   JOIN class_schedules cs ON cs.schedule_id = b.schedule_id
                   JOIN master_classes mc ON mc.class_id = cs.class_id
                  WHERE ba.booking_id = b.booking_id) AS total_payment,
                GREATEST(0, CEIL(EXTRACT(EPOCH FROM (b.expires_at - clock_timestamp()))))::int AS remaining
           FROM bookings b
           JOIN customers c ON c.customer_id = b.customer_id
          WHERE b.booking_id = :id"
    );
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    if ((int) $row['user_id'] !== $userId) {
        return null;
    }

    if ($row['status'] === 'waiting_payment' && $row['expires_at'] !== null && (int) $row['remaining'] === 0) {
        $pdo->prepare(
            "UPDATE bookings SET booking_status = 'expired', updated_at = clock_timestamp()
              WHERE booking_id = :id AND booking_status = 'waiting_payment' AND expires_at <= clock_timestamp()"
        )->execute([':id' => $id]);
        $row['status'] = 'expired';
    }
    return $row;
}

function bd_ui_status(string $dbStatus): string
{
    return [
        'waiting_payment'      => 'waiting',
        'waiting_verification' => 'verifying',
        'confirmed'            => 'confirmed',
    ][$dbStatus] ?? 'expired';
}