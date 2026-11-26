<?php

declare(strict_types=1);

ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

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

$csrf = (string) ($_POST['csrf_token'] ?? '');
if (!empty($_SESSION['csrf_token']) && !hash_equals($_SESSION['csrf_token'], $csrf)) {
    sendJson(403, ['success' => false, 'message' => 'Invalid CSRF token.']);
}

require_once dirname(__DIR__, 2) . '/login-regist/connection.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    sendJson(500, ['success' => false, 'message' => 'Database connection unavailable.']);
}

$rawId  = trim((string) ($_POST['booking_id'] ?? ''));
$status = strtolower(trim((string) ($_POST['status'] ?? '')));
$note   = trim((string) ($_POST['note'] ?? ''));
$reason = trim((string) ($_POST['reason'] ?? ''));

if ($rawId === '' || !in_array($status, ['confirmed', 'rejected'], true)) {
    sendJson(422, ['success' => false, 'message' => 'Invalid request parameters.']);
}

if ($status === 'rejected' && $reason === '') {
    sendJson(422, ['success' => false, 'message' => 'Please provide a rejection reason.']);
}

function getTableColumns(PDO $pdo, string $schema, string $table): array
{
    $stmt = $pdo->prepare("
        select column_name
          from information_schema.columns
         where table_schema = :s
           and table_name = :t
    ");
    $stmt->execute([':s' => $schema, ':t' => $table]);
    return array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
}

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('set search_path to sooma_wellness, public');

    // =========================================================================
    // KASUS A: Verifikasi Pengajuan Private Class (ID format: req-<request_id>)
    // =========================================================================
    if (preg_match('/^req-(\d+)$/i', $rawId, $matches)) {
        $requestId = (int) $matches[1];

        try {
            $pdo->exec('alter table sooma_wellness.request_classes add column if not exists admin_note text');
        } catch (Throwable $ddlEx) {
        }

        $reqCols   = getTableColumns($pdo, 'sooma_wellness', 'request_classes');
        $schedCols = getTableColumns($pdo, 'sooma_wellness', 'class_schedules');
        $bookCols  = getTableColumns($pdo, 'sooma_wellness', 'bookings');
        $attCols   = getTableColumns($pdo, 'sooma_wellness', 'booking_attendees');

        $pdo->beginTransaction();

        $stReq = $pdo->prepare("
            select rc.*,
                   c.user_id,
                   u.name as user_name,
                   u.email as user_email,
                   coalesce(to_jsonb(u)->>'phone', '') as user_phone,
                   mc.class_name,
                   coalesce(to_jsonb(mc)->>'level', 'All Level') as class_level
              from sooma_wellness.request_classes rc
              join sooma_wellness.customers c on c.customer_id = rc.customer_id
              join sooma_wellness.users u on u.user_id = c.user_id
              join sooma_wellness.master_classes mc on mc.class_id = rc.class_id
             where rc.request_id = :rid
               for update of rc
        ");
        $stReq->execute([':rid' => $requestId]);
        $req = $stReq->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            $pdo->rollBack();
            sendJson(404, ['success' => false, 'message' => 'Private class request not found.']);
        }

        $currentReqStatus = strtolower(trim((string) ($req['status'] ?? 'pending')));
        if ($currentReqStatus !== 'pending') {
            $pdo->rollBack();
            sendJson(409, ['success' => false, 'message' => 'This request has already been ' . $currentReqStatus . '.']);
        }

        if ($status === 'rejected') {
            if (isset($reqCols['admin_note'])) {
                $upd = $pdo->prepare("
                    update sooma_wellness.request_classes
                       set status = 'rejected',
                           admin_note = :reason
                     where request_id = :rid
                ");
                $upd->execute([':reason' => $reason, ':rid' => $requestId]);
            } else {
                $upd = $pdo->prepare("
                    update sooma_wellness.request_classes
                       set status = 'rejected'
                     where request_id = :rid
                ");
                $upd->execute([':rid' => $requestId]);
            }

            $pdo->commit();
            sendJson(200, [
                'success' => true,
                'message' => 'Private class request rejected.',
            ]);
        }

        $coachRaw = trim((string) ($req['preferred_coach'] ?? ''));
        $coachClean = trim((string) preg_replace('/^coach\s+/i', '', $coachRaw));
        $instructorId = null;

        if ($coachClean !== '' && strcasecmp($coachClean, 'Any Available Coach') !== 0) {
            $stInst = $pdo->prepare("
                select inst.instructor_id
                  from sooma_wellness.instructors inst
                  join sooma_wellness.users u on u.user_id = inst.user_id
                 where lower(trim(u.name)) = lower(:cname)
                 limit 1
            ");
            $stInst->execute([':cname' => $coachClean]);
            $foundInst = $stInst->fetchColumn();
            if ($foundInst !== false) {
                $instructorId = (int) $foundInst;
            }
        }

        if ($instructorId === null) {
            $instructorId = (int) $pdo->query("
                select instructor_id
                  from sooma_wellness.instructors
                 order by instructor_id asc
                 limit 1
            ")->fetchColumn();
        }

        $attendees = [];
        if (!empty($req['attendees_data'])) {
            $decoded = json_decode((string) $req['attendees_data'], true);
            if (is_array($decoded)) {
                $attendees = $decoded;
            }
        }
        if (empty($attendees)) {
            $attendees[] = [
                'name'         => (string) $req['user_name'],
                'phone'        => (string) ($req['user_phone'] ?: '-'),
                'email'        => (string) $req['user_email'],
                'medical'      => (string) ($req['medical_notes'] ?? ''),
                'not_pregnant' => true,
            ];
        }

        $slotCount = max(1, (int) ($req['attendee_count'] ?? count($attendees)));
        $startDt   = new DateTime($req['preferred_date'] . ' ' . $req['preferred_time']);
        $endDt     = (clone $startDt)->modify('+1 hour');

        $sCols = ['class_id', 'instructor_id', 'schedule_date', 'start_time', 'end_time', 'member_slot'];
        $sVals = [':class_id', ':inst_id', 'cast(:sdate as date)', 'cast(:stime as time)', 'cast(:etime as time)', ':slots'];
        $sArgs = [
            ':class_id' => (int) $req['class_id'],
            ':inst_id'  => $instructorId,
            ':sdate'    => $startDt->format('Y-m-d'),
            ':stime'    => $startDt->format('H:i:s'),
            ':etime'    => $endDt->format('H:i:s'),
            ':slots'    => $slotCount,
        ];

        if (isset($schedCols['class_type'])) {
            $sCols[] = 'class_type';
            $sVals[] = ':ctype';
            $sArgs[':ctype'] = 'Private';
        }
        if (isset($schedCols['level'])) {
            $sCols[] = 'level';
            $sVals[] = ':lvl';
            $sArgs[':lvl'] = (string) ($req['class_level'] ?: 'All Level');
        }

        $insSched = $pdo->prepare(sprintf(
            'insert into sooma_wellness.class_schedules (%s) values (%s) returning schedule_id',
            implode(', ', $sCols),
            implode(', ', $sVals)
        ));
        $insSched->execute($sArgs);
        $scheduleId = (int) $insSched->fetchColumn();

        $bCols = ['customer_id', 'schedule_id', 'payment_type_choice', 'booking_status'];
        $bVals = [':cid', ':sid', "'dropin'", "'waiting_payment'"];
        $bArgs = [
            ':cid' => (int) $req['customer_id'],
            ':sid' => $scheduleId,
        ];

        if (isset($bookCols['class_type'])) {
            $bCols[] = 'class_type';
            $bVals[] = ':bctype';
            $bArgs[':bctype'] = 'Private';
        }

        $insBook = $pdo->prepare(sprintf(
            'insert into sooma_wellness.bookings (%s) values (%s) returning booking_id',
            implode(', ', $bCols),
            implode(', ', $bVals)
        ));
        $insBook->execute($bArgs);
        $bookingId   = (int) $insBook->fetchColumn();
        $bookingCode = sprintf('BK-%04d', $bookingId);

        if (isset($bookCols['booking_code'])) {
            $pdo->prepare('update sooma_wellness.bookings set booking_code = :code where booking_id = :bid')
                ->execute([':code' => $bookingCode, ':bid' => $bookingId]);
        }

        $findUser = $pdo->prepare('select user_id from sooma_wellness.users where lower(email) = lower(:e) limit 1');
        foreach ($attendees as $idx => $att) {
            if ($idx === 0) {
                $attUid = (int) $req['user_id'];
            } else {
                $findUser->execute([':e' => trim((string) ($att['email'] ?? ''))]);
                $found  = $findUser->fetchColumn();
                $attUid = $found !== false ? (int) $found : null;
            }

            $aCols = ['booking_id', 'attendee_name', 'phone', 'email', 'medical_notes'];
            $aVals = [':bid', ':name', ':phone', ':email', ':med'];
            if (isset($attCols['user_id'])) {
                $aCols[] = 'user_id';
                $aVals[] = ':uid';
            }
            if (isset($attCols['not_pregnant'])) {
                $aCols[] = 'not_pregnant';
                $aVals[] = ':np';
            }

            $insAtt = $pdo->prepare(sprintf(
                'insert into sooma_wellness.booking_attendees (%s) values (%s)',
                implode(', ', $aCols),
                implode(', ', $aVals)
            ));

            $medVal = trim((string) ($att['medical'] ?? ''));
            $insAtt->bindValue(':bid', $bookingId, PDO::PARAM_INT);
            $insAtt->bindValue(':name', trim((string) ($att['name'] ?? $req['user_name'])));
            $insAtt->bindValue(':phone', trim((string) ($att['phone'] ?? '-')));
            $insAtt->bindValue(':email', trim((string) ($att['email'] ?? $req['user_email'])));
            $insAtt->bindValue(':med', $medVal !== '' ? $medVal : null, $medVal !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);

            if (isset($attCols['user_id'])) {
                $insAtt->bindValue(':uid', $attUid, $attUid !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            }
            if (isset($attCols['not_pregnant'])) {
                $npBool = !empty($att['not_pregnant']);
                $insAtt->bindValue(':np', $npBool ? 'true' : 'false', PDO::PARAM_STR);
            }
            $insAtt->execute();
        }

        if (isset($reqCols['admin_note']) && $note !== '') {
            $updReq = $pdo->prepare("
                update sooma_wellness.request_classes
                   set status = 'approved',
                       admin_note = :note
                 where request_id = :rid
            ");
            $updReq->execute([':note' => $note, ':rid' => $requestId]);
        } else {
            $updReq = $pdo->prepare("
                update sooma_wellness.request_classes
                   set status = 'approved'
                 where request_id = :rid
            ");
            $updReq->execute([':rid' => $requestId]);
        }

        $pdo->commit();
        sendJson(200, [
            'success'      => true,
            'booking_id'   => $bookingId,
            'booking_code' => $bookingCode,
            'message'      => 'Private request approved. Customer can now proceed with payment.',
        ]);
    }

    if (!ctype_digit($rawId)) {
        sendJson(422, ['success' => false, 'message' => 'Invalid booking ID.']);
    }

    $bookingId = (int) $rawId;
    $pdo->beginTransaction();

    $stBook = $pdo->prepare('
        select booking_id, payment_id, booking_status
          from sooma_wellness.bookings
         where booking_id = :bid
           for update
    ');
    $stBook->execute([':bid' => $bookingId]);
    $booking = $stBook->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        $pdo->rollBack();
        sendJson(404, ['success' => false, 'message' => 'Booking not found.']);
    }

    $paymentId = $booking['payment_id'] !== null ? (int) $booking['payment_id'] : null;

    if ($status === 'confirmed') {
        $pdo->prepare("
            update sooma_wellness.bookings
               set booking_status = 'confirmed',
                   updated_at = clock_timestamp()
             where booking_id = :bid
        ")->execute([':bid' => $bookingId]);

        if ($paymentId !== null) {
            $pdo->prepare("
                update sooma_wellness.payments
                   set verification_status = 'verified',
                       verified_at = clock_timestamp(),
                       admin_note = coalesce(nullif(:note, ''), admin_note)
                 where payment_id = :pid
            ")->execute([':note' => $note, ':pid' => $paymentId]);
        }
    } else {
        $pdo->prepare("
            update sooma_wellness.bookings
               set booking_status = 'rejected',
                   updated_at = clock_timestamp()
             where booking_id = :bid
        ")->execute([':bid' => $bookingId]);

        if ($paymentId !== null) {
            $pdo->prepare("
                update sooma_wellness.payments
                   set verification_status = 'rejected',
                       admin_note = :reason
                 where payment_id = :pid
            ")->execute([':reason' => $reason, ':pid' => $paymentId]);
        }
    }

    $pdo->commit();
    sendJson(200, ['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sendJson(500, ['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}