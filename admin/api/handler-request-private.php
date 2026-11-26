<?php

declare(strict_types=1);

ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

/**
 * @return never
 */
function sendJson(int $code, array $payload): void
{
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$connPath = dirname(__DIR__, 2) . '/login-regist/connection.php';
if (!file_exists($connPath)) {
    sendJson(500, ['success' => false, 'message' => 'Database connection file not found.']);
}

require_once dirname(__DIR__, 2) . '/login-regist/connection.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    sendJson(500, ['success' => false, 'message' => 'PDO connection unavailable.']);
}

/** @var PDO $pdo */

$requestId = (int) ($_POST['request_id'] ?? 0);
$action    = strtolower(trim((string) ($_POST['action'] ?? '')));
$adminNote = trim((string) ($_POST['admin_note'] ?? ''));

if ($requestId <= 0 || !in_array($action, ['accept', 'approve', 'reject'], true)) {
    sendJson(422, ['success' => false, 'message' => 'Invalid request_id or action.']);
}

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('set search_path to sooma_wellness, public');
    $pdo->beginTransaction();

    // Kunci baris request agar tidak diproses ganda secara bersamaan
    $stReq = $pdo->prepare("
        select rc.*,
               mc.class_name,
               c.user_id as customer_user_id,
               u.name as customer_name,
               u.email as customer_email,
               u.phone_number as customer_phone
          from sooma_wellness.request_classes rc
          join sooma_wellness.master_classes mc on mc.class_id = rc.class_id
          join sooma_wellness.customers c on c.customer_id = rc.customer_id
          join sooma_wellness.users u on u.user_id = c.user_id
         where rc.request_id = :rid
         for update
    ");
    $stReq->execute([':rid' => $requestId]);
    $req = $stReq->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        $pdo->rollBack();
        sendJson(404, ['success' => false, 'message' => 'Private class request not found.']);
    }

    if (strtolower((string) $req['status']) !== 'pending') {
        $pdo->rollBack();
        sendJson(409, ['success' => false, 'message' => 'This request has already been processed (' . $req['status'] . ').']);
    }

    // Jika Admin menolak request
    if ($action === 'reject') {
        $stRej = $pdo->prepare("
            update sooma_wellness.request_classes
               set status = 'rejected',
                   admin_note = :note
             where request_id = :rid
        ");
        $stRej->execute([
            ':note' => $adminNote !== '' ? $adminNote : null,
            ':rid'  => $requestId,
        ]);

        $pdo->commit();
        sendJson(200, [
            'success' => true,
            'status'  => 'rejected',
            'message' => 'Private class request rejected.',
        ]);
    }

    // Hitung rentang waktu sesi (durasi 60 menit)
    $prefDate  = (string) $req['preferred_date'];
    $startDt   = new DateTimeImmutable($prefDate . ' ' . $req['preferred_time']);
    $endDt     = $startDt->modify('+1 hour');
    $startTime = $startDt->format('H:i:s');
    $endTime   = $endDt->format('H:i:s');

    $rawCoachChoice  = trim((string) ($req['preferred_coach'] ?? ''));
    $isAnyCoach      = ($rawCoachChoice === '' || strcasecmp($rawCoachChoice, 'Any Available Coach') === 0);
    $assignedCoach   = null;

    if (!$isAnyCoach) {
        // 1A. User memilih coach spesifik: cari & cek bentrok jadwal
        $cleanCoachName = trim((string) preg_replace('/^coach\s+/i', '', $rawCoachChoice));

        $stCoach = $pdo->prepare("
            select i.instructor_id,
                   u.name as coach_name
              from sooma_wellness.instructors i
              join sooma_wellness.users u on u.user_id = i.user_id
             where lower(trim(u.name)) in (lower(:raw_name), lower(:clean_name))
               and coalesce(i.status, 'active') not ilike 'inactive%'
             limit 1
        ");
        $stCoach->execute([
            ':raw_name'   => $rawCoachChoice,
            ':clean_name' => $cleanCoachName,
        ]);
        $coachRow = $stCoach->fetch(PDO::FETCH_ASSOC);

        if (!$coachRow) {
            $pdo->rollBack();
            sendJson(404, [
                'success' => false,
                'message' => 'Requested coach (' . $rawCoachChoice . ') was not found or is inactive.',
            ]);
        }

        // Pastikan coach tidak punya jadwal bentrok pada tanggal & jam tersebut
        $stConflict = $pdo->prepare("
            select count(*)
              from sooma_wellness.class_schedules cs
             where cs.instructor_id = :iid
               and cs.schedule_date = cast(:sdate as date)
               and cs.start_time < cast(:etime as time)
               and cs.end_time > cast(:stime as time)
        ");
        $stConflict->execute([
            ':iid'   => (int) $coachRow['instructor_id'],
            ':sdate' => $prefDate,
            ':stime' => $startTime,
            ':etime' => $endTime,
        ]);

        if ((int) $stConflict->fetchColumn() > 0) {
            $pdo->rollBack();
            sendJson(409, [
                'success' => false,
                'message' => 'Coach ' . $coachRow['coach_name'] . ' already has another class at ' . $startDt->format('H.i') . ' - ' . $endDt->format('H.i') . ' WIB.',
            ]);
        }

        $assignedCoach = $coachRow;
    } else {
        // 1B. Any Available Coach:
        // - Sesuai spesialisasi / riwayat kelas
        // - Tidak bentrok pada tanggal & jam request
        // - Prioritas 1: belum punya class private (private_count = 0)
        // - Prioritas 2: jika semua sudah punya class private, jadwal paling sedikit pada minggu itu
        $normClass = trim((string) preg_replace('/^(private|regular)\s+/i', '', (string) $req['class_name']));
        $parts     = preg_split('/\s+/', $normClass) ?: [];
        $coreWord  = end($parts) ?: $normClass; // mis. "Pilates" atau "Yoga"

        $stAutoCoach = $pdo->prepare("
            with candidate_coaches as (
                select i.instructor_id,
                       u.name as coach_name,
                       case
                           when lower(coalesce(i.specialization, '') || ' ' || coalesce(i.title, '')) like '%' || lower(:norm_class) || '%'
                             or lower(coalesce(i.specialization, '') || ' ' || coalesce(i.title, '')) like '%' || lower(:core_word) || '%'
                             or exists (
                                 select 1
                                   from sooma_wellness.class_schedules cs_hist
                                   join sooma_wellness.master_classes mc_hist on mc_hist.class_id = cs_hist.class_id
                                  where cs_hist.instructor_id = i.instructor_id
                                    and (
                                        cs_hist.class_id = :cid
                                        or lower(mc_hist.class_name) like '%' || lower(:norm_class) || '%'
                                        or lower(mc_hist.class_name) like '%' || lower(:core_word) || '%'
                                    )
                             )
                           then 0
                           else 1
                       end as class_mismatch,
                       (
                           select count(*)
                             from sooma_wellness.class_schedules cs_priv
                            where cs_priv.instructor_id = i.instructor_id
                              and lower(coalesce(cs_priv.class_type, '')) = 'private'
                       ) as private_count,
                       (
                           select count(*)
                             from sooma_wellness.class_schedules cs_week
                            where cs_week.instructor_id = i.instructor_id
                              and cs_week.schedule_date >= date_trunc('week', cast(:sdate as timestamp))::date
                              and cs_week.schedule_date < (date_trunc('week', cast(:sdate as timestamp)) + interval '7 days')::date
                       ) as weekly_schedule_count
                  from sooma_wellness.instructors i
                  join sooma_wellness.users u on u.user_id = i.user_id
                 where coalesce(i.status, 'active') not ilike 'inactive%'
                   and not exists (
                       select 1
                         from sooma_wellness.class_schedules cs_busy
                        where cs_busy.instructor_id = i.instructor_id
                          and cs_busy.schedule_date = cast(:sdate as date)
                          and cs_busy.start_time < cast(:etime as time)
                          and cs_busy.end_time > cast(:stime as time)
                   )
            )
            select instructor_id,
                   coach_name,
                   private_count,
                   weekly_schedule_count
              from candidate_coaches
             where class_mismatch = 0
                or not exists (select 1 from candidate_coaches where class_mismatch = 0)
             order by
                   class_mismatch asc,
                   case when private_count = 0 then 0 else 1 end asc,
                   weekly_schedule_count asc,
                   private_count asc,
                   instructor_id asc
             limit 1
        ");
        $stAutoCoach->execute([
            ':norm_class' => $normClass,
            ':core_word'  => $coreWord,
            ':cid'        => (int) $req['class_id'],
            ':sdate'      => $prefDate,
            ':stime'      => $startTime,
            ':etime'      => $endTime,
        ]);
        $assignedCoach = $stAutoCoach->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$assignedCoach) {
            $pdo->rollBack();
            sendJson(409, [
                'success' => false,
                'message' => 'No available coach found for ' . $req['class_name'] . ' on ' . $startDt->format('d M Y H.i') . ' WIB.',
            ]);
        }
    }

    $assignedInstructorId = (int) $assignedCoach['instructor_id'];
    $assignedCoachName    = (string) $assignedCoach['coach_name'];
    $attendeeCount        = max(1, (int) ($req['attendee_count'] ?? 1));

    // 2. Masukkan otomatis ke class_schedules dengan class_type = 'Private'
    //    (Muncul di Schedule & Class, tidak bocor ke Timetable publik)
    $stInsSched = $pdo->prepare("
        insert into sooma_wellness.class_schedules (
            class_id,
            instructor_id,
            level,
            class_type,
            schedule_date,
            start_time,
            end_time,
            member_slot
        ) values (
            :class_id,
            :instructor_id,
            :level,
            'Private',
            cast(:schedule_date as date),
            cast(:start_time as time),
            cast(:end_time as time),
            :member_slot
        )
        returning schedule_id
    ");
    $stInsSched->execute([
        ':class_id'      => (int) $req['class_id'],
        ':instructor_id' => $assignedInstructorId,
        ':level'         => 'Private',
        ':schedule_date' => $prefDate,
        ':start_time'    => $startTime,
        ':end_time'      => $endTime,
        ':member_slot'   => $attendeeCount,
    ]);
    $scheduleId = (int) $stInsSched->fetchColumn();

    // 3. Buat record booking berstatus 'unpaid' agar user bisa lanjut pembayaran
    $bookingCode = sprintf('PRV-%04d', $requestId);
    $expiresAt   = (new DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s');

    $stInsBooking = $pdo->prepare("
        insert into sooma_wellness.bookings (
            customer_id,
            schedule_id,
            payment_type_choice,
            booking_status,
            booking_code,
            expires_at,
            class_type
        ) values (
            :customer_id,
            :schedule_id,
            'transfer',
            'unpaid',
            :booking_code,
            cast(:expires_at as timestamp),
            'Private'
        )
        returning booking_id
    ");
    $stInsBooking->execute([
        ':customer_id'  => (int) $req['customer_id'],
        ':schedule_id'  => $scheduleId,
        ':booking_code' => $bookingCode,
        ':expires_at'   => $expiresAt,
    ]);
    $bookingId = (int) $stInsBooking->fetchColumn();

    // 4. Salin daftar peserta ke booking_attendees
    $attendeesList = json_decode((string) ($req['attendees_data'] ?? '[]'), true);
    if (!is_array($attendeesList) || empty($attendeesList)) {
        $attendeesList = [[
            'name'         => $req['customer_name'],
            'phone'        => $req['customer_phone'],
            'email'        => $req['customer_email'],
            'medical'      => $req['medical_notes'] ?? '',
            'not_pregnant' => true,
        ]];
    }

    $stInsAtt = $pdo->prepare("
        insert into sooma_wellness.booking_attendees (
            booking_id,
            user_id,
            attendee_name,
            phone,
            email,
            medical_notes,
            not_pregnant
        ) values (
            :booking_id,
            :user_id,
            :attendee_name,
            :phone,
            :email,
            :medical_notes,
            :not_pregnant
        )
    ");

    foreach ($attendeesList as $idx => $att) {
        $stInsAtt->execute([
            ':booking_id'    => $bookingId,
            ':user_id'       => $idx === 0 ? (int) $req['customer_user_id'] : null,
            ':attendee_name' => (string) ($att['name'] ?? $req['customer_name']),
            ':phone'         => (string) ($att['phone'] ?? ''),
            ':email'         => (string) ($att['email'] ?? ''),
            ':medical_notes' => trim((string) ($att['medical'] ?? '')) !== '' ? trim((string) ($att['medical']) ) : null,
            ':not_pregnant'  => !empty($att['not_pregnant']) ? 'true' : 'false',
        ]);
    }

    // 5. Update status request_classes menjadi 'approved' & catat coach yang di-assign
    $stUpdReq = $pdo->prepare("
        update sooma_wellness.request_classes
           set status = 'approved',
               preferred_coach = :coach_name,
               admin_note = :admin_note
         where request_id = :rid
    ");
    $stUpdReq->execute([
        ':coach_name' => $assignedCoachName,
        ':admin_note' => $adminNote !== '' ? $adminNote : null,
        ':rid'        => $requestId,
    ]);

    $pdo->commit();

    sendJson(200, [
        'success'        => true,
        'status'         => 'approved',
        'request_id'     => $requestId,
        'schedule_id'    => $scheduleId,
        'booking_id'     => $bookingId,
        'booking_code'   => $bookingCode,
        'assigned_coach' => $assignedCoachName,
        'message'        => 'Private request approved and assigned to Coach ' . $assignedCoachName . '.',
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sendJson(500, [
        'success' => false,
        'message' => 'Failed to process private request: ' . $e->getMessage(),
    ]);
}