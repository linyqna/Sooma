<?php
declare(strict_types=1);

/**
 * Layanan booking bersama: koneksi Neon (PostgreSQL), validasi, penguncian slot,
 * dan pemuatan booking. Dipakai oleh:
 *   - customer/booking-detail.php        (render + fallback submit tanpa JavaScript)
 *   - customer/verify-booking-slot.php   (endpoint AJAX "Continue Payment")
 *   - customer/payment.php               (status, countdown, upload bukti bayar)
 */

const BD_HOLD_MINUTES          = 30;   // lama slot ditahan sebelum dibayar
const BD_AUTO_CREATE_SCHEDULE  = true; // true: buat baris jadwal otomatis dari parameter timetable
                                       // (masa transisi selagi timetable masih data JS).
                                       // Setelah jadwal dikelola di database, ubah ke false.

if (!function_exists('e')) {
    function e(?string $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/* ==================================================================
 * KONEKSI DATABASE (Neon / PostgreSQL via PDO pgsql, sslmode=require)
 * ================================================================== */
function bd_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Sudah punya koneksi PDO di includes/db.php? Taruh di $GLOBALS['pdo'] atau ganti isi fungsi ini.
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
    // Neon pooled endpoint (PgBouncer) lama bisa menolak prepared statement native.
    // Jika muncul error "prepared statement ... does not exist", aktifkan baris berikut:
    // $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

    return $pdo;
}

/** Paket aktif user. ▸ GANTI dengan query tabel paket/membership Anda. */
function bd_get_packages(int $userId): array
{
    return [['id' => 1, 'name' => 'Reformer Pilates Bundle', 'type' => 'Group Class', 'remaining' => 2, 'total' => 4]];
}

/* ==================================================================
 * INPUT: parse + validasi (dipakai endpoint AJAX maupun fallback form)
 * ================================================================== */
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

/** @return array<string,string> kunci error sama dengan yang dipakai booking-detail.php */
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
        } elseif ($selected['remaining'] < count($data['attendees'])) {
            $errors['package'] = 'Your package does not have enough remaining sessions.';
        }
    }

    if (!$data['agree']) {
        $errors['agree'] = 'You must agree to the Terms & Conditions.';
    }
    return $errors;
}

/** Parameter kelas dari URL timetable, dipakai hanya untuk tombol "Booking Detail" di payment.php. */
function bd_sanitize_class_query(string $raw): array
{
    parse_str($raw, $q);
    $out = [];
    foreach (['date', 'start', 'end', 'instructor', 'className', 'category', 'level', 'type', 'slot', 'capacity'] as $k) {
        if (isset($q[$k]) && is_scalar($q[$k])) {
            $out[$k] = substr((string) $q[$k], 0, 60);
        }
    }
    return $out;
}

/* ==================================================================
 * JADWAL & KETERSEDIAAN
 * ================================================================== */

/** Kondisi SQL "booking ini menahan slot" (alias tabel: $a). */
function bd_active_hold_sql(string $a = 'b'): string
{
    return "({$a}.status IN ('confirmed', 'waiting_verification')"
        . " OR ({$a}.status = 'waiting_payment' AND {$a}.expires_at > clock_timestamp()))";
}

/**
 * Cari baris jadwal dari parameter timetable. Jika belum ada dan BD_AUTO_CREATE_SCHEDULE = true,
 * buat otomatis. ▸ Kapasitas awal berasal dari URL, jadi seed jadwal asli lewat admin/database
 * lalu matikan auto-create agar kapasitas tidak bisa dimanipulasi.
 */
function bd_find_or_create_schedule(PDO $pdo, array $q): ?array
{
    $select = 'SELECT id, capacity, price FROM class_schedules
               WHERE class_date = CAST(:d AS date) AND start_time = CAST(:s AS time)
                 AND instructor = :i AND category = :c AND level = :l AND class_type = :t';
    $key = [
        ':d' => (string) $q['date'], ':s' => (string) $q['start'], ':i' => (string) $q['instructor'],
        ':c' => (string) $q['category'], ':l' => (string) $q['level'], ':t' => (string) $q['type'],
    ];

    $st = $pdo->prepare($select);
    $st->execute($key);
    $row = $st->fetch();
    if ($row || !BD_AUTO_CREATE_SCHEDULE) {
        return $row ?: null;
    }

    $ins = $pdo->prepare(
        'INSERT INTO class_schedules (class_date, start_time, end_time, instructor, class_name, category, level, class_type, capacity)
         VALUES (CAST(:d AS date), CAST(:s AS time), CAST(:e AS time), :i, :n, :c, :l, :t, :cap)
         ON CONFLICT ON CONSTRAINT class_schedules_natural_key DO NOTHING'
    );
    $ins->execute($key + [':e' => (string) $q['end'], ':n' => (string) $q['className'], ':cap' => (int) $q['capacity']]);

    $st->execute($key);
    return $st->fetch() ?: null;
}

/** Sisa slot saat ini. $excludeBookingId = hold milik user sendiri (agar tidak menghalangi dirinya). */
function bd_schedule_availability(PDO $pdo, int $scheduleId, int $excludeBookingId = 0): ?array
{
    $st = $pdo->prepare(
        'SELECT s.capacity, s.price,
                COALESCE((SELECT SUM(b.attendee_count) FROM bookings b
                           WHERE b.schedule_id = s.id AND b.id <> :ex AND ' . bd_active_hold_sql('b') . '), 0) AS used
           FROM class_schedules s WHERE s.id = :id'
    );
    $st->execute([':ex' => $excludeBookingId, ':id' => $scheduleId]);
    $r = $st->fetch();
    if (!$r) {
        return null;
    }
    $capacity = (int) $r['capacity'];
    $used     = (int) $r['used'];
    return ['capacity' => $capacity, 'price' => (int) $r['price'], 'used' => $used, 'left' => max(0, $capacity - $used)];
}

/* ==================================================================
 * INTI ANTI-BENTROK: kunci jadwal, hitung slot, tahan slot
 * ================================================================== */

/**
 * @return array{status:string, http?:int, message?:string, slots_left?:int, booking?:array}
 *   status: 'available' | 'existing' | 'full' | 'error'
 */
function bd_reserve_slot(PDO $pdo, int $scheduleId, int $userId, array $data, array $classQuery, int $sessionBookingId = 0): array
{
    $count     = count($data['attendees']);
    $isPackage = $data['payment_method'] === 'package';
    $holdExpr  = sprintf("clock_timestamp() + interval '%d minutes'", BD_HOLD_MINUTES);
    $remaining = "GREATEST(0, CEIL(EXTRACT(EPOCH FROM (expires_at - clock_timestamp()))))::int";

    $pdo->beginTransaction();
    try {
        // 1) KUNCI baris jadwal. Request lain untuk jadwal yang sama menunggu di sini sampai COMMIT/ROLLBACK,
        //    lalu membaca data terbaru (READ COMMITTED: tiap statement memakai snapshot baru).
        $st = $pdo->prepare('SELECT id, capacity, price FROM class_schedules WHERE id = :id FOR UPDATE');
        $st->execute([':id' => $scheduleId]);
        $schedule = $st->fetch();
        if (!$schedule) {
            $pdo->rollBack();
            return ['status' => 'error', 'http' => 404, 'message' => 'This class schedule could not be found.'];
        }

        // 2) Lepaskan hold yang sudah lewat 30 menit.
        $pdo->prepare(
            "UPDATE bookings SET status = 'expired', updated_at = clock_timestamp()
              WHERE schedule_id = :sid AND status = 'waiting_payment' AND expires_at <= clock_timestamp()"
        )->execute([':sid' => $scheduleId]);

        // 3) Booking milik user ini untuk jadwal ini (hindari hitung ganda saat Back lalu Continue lagi).
        $conds  = ['id = :sess'];
        $params = [':sid' => $scheduleId, ':sess' => $sessionBookingId];
        if ($userId > 0) {
            $conds[]        = 'user_id = :uid';
            $params[':uid'] = $userId;
        }
        $st = $pdo->prepare(
            "SELECT id, booking_code, status FROM bookings
              WHERE schedule_id = :sid AND status IN ('waiting_payment', 'waiting_verification', 'confirmed')
                AND (" . implode(' OR ', $conds) . ')
              ORDER BY id DESC LIMIT 1 FOR UPDATE'
        );
        $st->execute($params);
        $existing = $st->fetch() ?: null;

        if ($existing && $existing['status'] !== 'waiting_payment') {
            $pdo->commit();   // sudah dibayar / menunggu verifikasi: arahkan saja ke payment.php
            return ['status' => 'existing', 'booking' => ['id' => (int) $existing['id'], 'code' => $existing['booking_code']]];
        }
        $excludeId = $existing ? (int) $existing['id'] : 0;

        // 4) Slot terpakai = confirmed + waiting_verification + waiting_payment yang belum kedaluwarsa.
        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(b.attendee_count), 0) FROM bookings b
              WHERE b.schedule_id = :sid AND b.id <> :ex AND ' . bd_active_hold_sql('b')
        );
        $st->execute([':sid' => $scheduleId, ':ex' => $excludeId]);
        $used = (int) $st->fetchColumn();
        $left = (int) $schedule['capacity'] - $used;

        if ($left < $count) {
            $pdo->commit();   // simpan pelepasan hold kedaluwarsa dari langkah 2
            return [
                'status'     => 'full',
                'http'       => 409,
                'slots_left' => max(0, $left),
                'message'    => $left <= 0
                    ? 'Sorry, the last slot for this class has just been taken by another participant.'
                    : sprintf('Only %d slot%s left, but you selected %d attendees.', $left, $left === 1 ? ' is' : 's are', $count),
            ];
        }

        // 5) Tahan slot: simpan booking waiting_payment + expires_at (+30 menit).
        $total     = $isPackage ? 0 : (int) $schedule['price'] * $count;
        $packageId = $isPackage && $data['package_id'] !== '' ? (int) $data['package_id'] : null;

        if ($existing) {
            $bookingId = (int) $existing['id'];
            $st = $pdo->prepare(
                "UPDATE bookings SET attendee_count = :c, payment_method = :m, package_id = :p,
                        total_payment = :t, updated_at = clock_timestamp()
                  WHERE id = :id RETURNING booking_code, {$remaining} AS remaining"
            );
            $st->execute([':c' => $count, ':m' => $data['payment_method'], ':p' => $packageId, ':t' => $total, ':id' => $bookingId]);
            $pdo->prepare('DELETE FROM booking_attendees WHERE booking_id = :id')->execute([':id' => $bookingId]);
        } else {
            $bookingId = (int) $pdo->query("SELECT nextval('bookings_id_seq')")->fetchColumn();
            $st = $pdo->prepare(
                "INSERT INTO bookings (id, booking_code, schedule_id, user_id, attendee_count, payment_method,
                                       package_id, total_payment, status, expires_at)
                 VALUES (:id, :code, :sid, :uid, :c, :m, :p, :t, 'waiting_payment', {$holdExpr})
                 RETURNING booking_code, {$remaining} AS remaining"
            );
            $st->execute([
                ':id' => $bookingId, ':code' => sprintf('BK-%04d', $bookingId), ':sid' => $scheduleId,
                ':uid' => $userId > 0 ? $userId : null, ':c' => $count, ':m' => $data['payment_method'],
                ':p' => $packageId, ':t' => $total,
            ]);
        }
        $row = $st->fetch();

        $ins = $pdo->prepare(
            'INSERT INTO booking_attendees (booking_id, name, phone, email, medical, not_pregnant)
             VALUES (:b, :n, :p, :e, :m, :np)'
        );
        foreach ($data['attendees'] as $a) {
            $ins->bindValue(':b', $bookingId, PDO::PARAM_INT);
            $ins->bindValue(':n', $a['name']);
            $ins->bindValue(':p', $a['phone']);
            $ins->bindValue(':e', $a['email']);
            $ins->bindValue(':m', $a['medical'] !== '' ? $a['medical'] : null, $a['medical'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $ins->bindValue(':np', $a['not_pregnant'] === '1', PDO::PARAM_BOOL);
            $ins->execute();
        }

        $pdo->commit();   // lepas kunci: request berikutnya sudah melihat slot ini terpakai
        return [
            'status'  => 'available',
            'booking' => [
                'id' => $bookingId, 'code' => (string) $row['booking_code'], 'remaining' => (int) $row['remaining'],
                'total' => $total, 'payment_method' => $data['payment_method'],
            ],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Simpan referensi booking di session; sumber kebenaran tetap database. */
function bd_store_booking_session(array $booking, array $classQuery): void
{
    $_SESSION['booking_draft'] = [
        'booking_id' => $booking['code'],
        'db_id'      => (int) $booking['id'],
        'class'      => $classQuery,
    ];
}

/* ==================================================================
 * PEMUATAN BOOKING (payment.php)
 * ================================================================== */
function bd_load_booking(PDO $pdo, int $id, int $userId): ?array
{
    $st = $pdo->prepare(
        'SELECT b.*, GREATEST(0, CEIL(EXTRACT(EPOCH FROM (b.expires_at - clock_timestamp()))))::int AS remaining
           FROM bookings b WHERE b.id = :id'
    );
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    if ($row['user_id'] !== null && (int) $row['user_id'] !== $userId) {
        return null;
    }

    if ($row['status'] === 'waiting_payment' && (int) $row['remaining'] === 0) {
        $pdo->prepare(
            "UPDATE bookings SET status = 'expired', updated_at = clock_timestamp()
              WHERE id = :id AND status = 'waiting_payment' AND expires_at <= clock_timestamp()"
        )->execute([':id' => $id]);
        $row['status'] = 'expired';
    }
    return $row;
}

/** Status database -> status tampilan di payment.php / payment.js */
function bd_ui_status(string $dbStatus): string
{
    return [
        'waiting_payment'      => 'waiting',
        'waiting_verification' => 'verifying',
        'confirmed'            => 'confirmed',
    ][$dbStatus] ?? 'expired';
}
