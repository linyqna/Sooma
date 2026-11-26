<?php

declare(strict_types=1);

ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function sendJsonResponse(int $statusCode, array $payload): void
{
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$connFromRoot   = dirname(__DIR__, 2) . '/login-regist/connection.php';
$connFromParent = dirname(__DIR__) . '/login-regist/connection.php';
$connPath       = file_exists($connFromRoot) ? $connFromRoot : $connFromParent;

if (!file_exists($connPath)) {
    sendJsonResponse(500, ['success' => false, 'message' => 'Connection file not found.']);
}

require_once $connPath;

if (!isset($pdo) || !$pdo instanceof PDO) {
    sendJsonResponse(500, ['success' => false, 'message' => 'Database connection not available.']);
}

$userId = (int) ($_SESSION['user_id'] ?? $_SESSION['user']['user_id'] ?? $_SESSION['id'] ?? 0);
if ($userId <= 0) {
    sendJsonResponse(401, ['success' => false, 'message' => 'Please log in before submitting a private class request.']);
}

$classIndexRaw = trim((string) ($_POST['class_index'] ?? ''));
$classIdRaw    = trim((string) ($_POST['class_id'] ?? ''));
$className     = trim((string) ($_POST['class_name'] ?? ''));
$category      = trim((string) ($_POST['category'] ?? ''));
$level         = trim((string) ($_POST['level'] ?? ''));
$date          = trim((string) ($_POST['date'] ?? $_POST['preferred_date'] ?? ''));
$time          = trim((string) ($_POST['time'] ?? $_POST['start_time'] ?? ''));
$coach         = trim((string) ($_POST['coach'] ?? $_POST['preferred_coach'] ?? ''));
$specialNotes  = trim((string) ($_POST['special_notes'] ?? $_POST['notes'] ?? $_POST['session_notes'] ?? ''));

if (($classIdRaw === '' && $className === '') || $date === '' || $time === '') {
    sendJsonResponse(422, ['success' => false, 'message' => 'Please complete class, date, and time fields.']);
}

$attendees = [];
$medicalSummaryParts = [];

foreach ((array) ($_POST['attendees'] ?? []) as $row) {
    if (!is_array($row)) {
        continue;
    }
    $name  = trim((string) ($row['name'] ?? ''));
    $phone = (string) preg_replace('/[^\d+]/', '', (string) ($row['phone'] ?? ''));
    $email = trim((string) ($row['email'] ?? ''));
    $med   = trim((string) ($row['medical'] ?? ''));
    $np    = !empty($row['not_pregnant']) && $row['not_pregnant'] !== '0';

    if ($name === '' || $phone === '' || $email === '') {
        continue;
    }

    $attendees[] = [
        'name'         => $name,
        'phone'        => $phone,
        'email'        => $email,
        'medical'      => $med,
        'not_pregnant' => $np,
    ];

    if ($med !== '') {
        $medicalSummaryParts[] = $med;
    }
}

if (empty($attendees)) {
    sendJsonResponse(422, ['success' => false, 'message' => 'Please fill in at least one valid participant.']);
}

if (count($attendees) > 4) {
    sendJsonResponse(422, ['success' => false, 'message' => 'Maximum 4 participants per private request.']);
}

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('set search_path to sooma_wellness, public');

    $colStmt = $pdo->query("
        select column_name
          from information_schema.columns
         where table_schema = 'sooma_wellness'
           and table_name = 'request_classes'
    ");
    $availableCols = array_flip($colStmt->fetchAll(PDO::FETCH_COLUMN));

    $pdo->beginTransaction();

    // 1. Cari class_id di master_classes
    $masterClass = null;
    $hasExplicitClassId = ($classIdRaw !== '' && ctype_digit($classIdRaw) && $classIdRaw !== $classIndexRaw);

    if ($hasExplicitClassId) {
        $st = $pdo->prepare('select class_id, class_name from sooma_wellness.master_classes where class_id = :cid limit 1');
        $st->execute([':cid' => (int) $classIdRaw]);
        $masterClass = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$masterClass && $className !== '') {
        $normalizedName = trim((string) preg_replace('/^private\s+/i', '', $className));

        $st = $pdo->prepare("
            select class_id, class_name
              from sooma_wellness.master_classes
             where lower(trim(class_name)) in (lower(:exact_name), lower(:norm_name))
             order by
               case when lower(trim(class_name)) = lower(:exact_name) then 0 else 1 end,
               class_id asc
             limit 1
        ");
        $st->execute([
            ':exact_name' => $className,
            ':norm_name'  => $normalizedName,
        ]);
        $masterClass = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$masterClass && $normalizedName !== '') {
            $stLike = $pdo->prepare("
                select class_id, class_name
                  from sooma_wellness.master_classes
                 where lower(class_name) like '%' || lower(:norm_name) || '%'
                    or (:cat <> '' and lower(class_name) like '%' || lower(:cat) || '%')
                 order by class_id asc
                 limit 1
            ");
            $stLike->execute([
                ':norm_name' => $normalizedName,
                ':cat'       => $category,
            ]);
            $masterClass = $stLike->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

    if (!$masterClass) {
        $pdo->rollBack();
        sendJsonResponse(404, [
            'success' => false,
            'message' => 'Selected class (' . ($className !== '' ? $className : $classIdRaw) . ') was not found in master classes.',
        ]);
    }

    // 2. Ambil atau buat customer_id di sooma_wellness.customers
    $stCust = $pdo->prepare('select customer_id from sooma_wellness.customers where user_id = :uid limit 1');
    $stCust->execute([':uid' => $userId]);
    $customerId = $stCust->fetchColumn();

    if ($customerId === false) {
        $insCust = $pdo->prepare('insert into sooma_wellness.customers (user_id) values (:uid) returning customer_id');
        $insCust->execute([':uid' => $userId]);
        $customerId = (int) $insCust->fetchColumn();
    } else {
        $customerId = (int) $customerId;
    }

    // 3. Format tanggal, waktu, dan catatan medis
    $startDt      = new DateTime($date . ' ' . $time);
    $endDt        = (clone $startDt)->modify('+1 hour');
    $medicalNotes = !empty($medicalSummaryParts) ? implode('; ', $medicalSummaryParts) : null;
    $coachText    = $coach !== '' ? $coach : 'Any Available Coach';

    // 4. Simpan ke sooma_wellness.request_classes
    $columns = ['customer_id', 'class_id', 'preferred_date', 'preferred_time', 'medical_notes', 'status'];
    $holders = [':customer_id', ':class_id', 'cast(:preferred_date as date)', 'cast(:preferred_time as time)', ':medical_notes', ':status'];
    $params  = [
        ':customer_id'    => $customerId,
        ':class_id'       => (int) $masterClass['class_id'],
        ':preferred_date' => $startDt->format('Y-m-d'),
        ':preferred_time' => $startDt->format('H:i:s'),
        ':medical_notes'  => $medicalNotes,
        ':status'         => 'pending',
    ];

    if (isset($availableCols['preferred_coach'])) {
        $columns[] = 'preferred_coach';
        $holders[] = ':preferred_coach';
        $params[':preferred_coach'] = $coachText;
    }
    if (isset($availableCols['attendee_count'])) {
        $columns[] = 'attendee_count';
        $holders[] = ':attendee_count';
        $params[':attendee_count'] = count($attendees);
    }
    if (isset($availableCols['attendees_data'])) {
        $columns[] = 'attendees_data';
        $holders[] = 'cast(:attendees_data as jsonb)';
        $params[':attendees_data'] = json_encode($attendees, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    if (isset($availableCols['special_notes'])) {
        $columns[] = 'special_notes';
        $holders[] = ':special_notes';
        $params[':special_notes'] = $specialNotes !== '' ? $specialNotes : null;
    }

    $sql = sprintf(
        'insert into sooma_wellness.request_classes (%s) values (%s) returning request_id',
        implode(', ', $columns),
        implode(', ', $holders)
    );

    $insReq = $pdo->prepare($sql);
    $insReq->execute($params);
    $requestId   = (int) $insReq->fetchColumn();
    $requestCode = sprintf('REQ-%04d', $requestId);

    $pdo->commit();

    // Simpan ringkasan pengajuan terakhir ke session agar tetap tampil saat halaman di-refresh
    $qty = count($attendees);
    $resolvedClassName = $className !== '' ? $className : (string) $masterClass['class_name'];
    $resolvedCategory  = $category !== '' ? $category : trim((string) preg_replace('/^private\s+/i', '', $resolvedClassName));
    $imageMap = [
        'Reformer Pilates' => '../assets/images/class-pilates.jpg',
        'Regular Yoga'     => '../assets/images/class-yoga.jpg',
        'Mat Pilates'      => '../assets/images/class-pilates.jpg',
    ];

    $_SESSION['last_private_request'] = [
        'request_id'    => $requestId,
        'booking_code'  => $requestCode,
        'class_name'    => $resolvedClassName,
        'category'      => $resolvedCategory,
        'image'         => $imageMap[$resolvedCategory] ?? '../assets/images/class-pilates.jpg',
        'date_readable' => $startDt->format('l, j F Y'),
        'time_readable' => $startDt->format('H.i') . ' - ' . $endDt->format('H.i') . ' WIB',
        'coach'         => $coachText,
        'level'         => $level !== '' ? $level : 'Beginner',
        'people_text'   => $qty . ($qty === 1 ? ' Person' : ' People'),
        'participants'  => implode(', ', array_map(static fn(array $a): string => $a['name'], $attendees)),
        'lead_phone'    => $attendees[0]['phone'] ?? '-',
    ];

    sendJsonResponse(200, [
        'success'      => true,
        'request_id'   => $requestId,
        'booking_code' => $requestCode,
        'class_type'   => 'Private',
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sendJsonResponse(500, [
        'success' => false,
        'message' => 'Failed to save private request: ' . $e->getMessage(),
    ]);
}