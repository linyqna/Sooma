<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../login-regist/connection.php';

function jsonResponse(bool $success, string $message, array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

try {
    $pdo->exec("SET search_path TO sooma_wellness, public");

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $isPublicTimetable = (($_GET['public'] ?? '') === '1');
        $whereClause = $isPublicTimetable
            ? "WHERE LOWER(COALESCE(cs.class_type, 'regular')) <> 'private'"
            : '';

        $sql = "SELECT
                    cs.schedule_id,
                    cs.class_id,
                    mc.class_name,
                    mc.price,
                    cs.instructor_id,
                    u.name AS instructor_name,
                    cs.class_type,
                    cs.level,
                    cs.schedule_date,
                    cs.start_time,
                    cs.end_time,
                    cs.member_slot,
                    COALESCE(b.booked_count, 0) AS booked_count
                FROM class_schedules cs
                JOIN master_classes mc ON cs.class_id = mc.class_id
                JOIN instructors i ON cs.instructor_id = i.instructor_id
                JOIN users u ON i.user_id = u.user_id
                LEFT JOIN (
                    SELECT schedule_id, COUNT(*) AS booked_count
                    FROM bookings
                    WHERE LOWER(COALESCE(booking_status, '')) NOT IN ('cancelled', 'expired', 'rejected')
                    GROUP BY schedule_id
                ) b ON cs.schedule_id = b.schedule_id
                {$whereClause}
                ORDER BY cs.schedule_date ASC, cs.start_time ASC";

        $stmt = $pdo->query($sql);
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = array_map(function ($row) {
            $shortClass = stripos($row['class_name'], 'yoga') !== false ? 'Yoga' : 'Pilates';

            return [
                'scheduleId'   => (int) $row['schedule_id'],
                'classId'      => (int) $row['class_id'],
                'className'    => $row['class_name'],
                'shortClass'   => $shortClass,
                'classType'    => $row['class_type'],
                'level'        => $row['level'],
                'instructorId' => (int) $row['instructor_id'],
                'instructor'   => $row['instructor_name'],
                'date'         => $row['schedule_date'],
                'startTime'    => substr((string) $row['start_time'], 0, 5),
                'endTime'      => substr((string) $row['end_time'], 0, 5),
                'price'        => (float) $row['price'],
                'memberSlot'   => (int) $row['member_slot'],
                'bookedCount'  => (int) $row['booked_count']
            ];
        }, $schedules);

        jsonResponse(true, 'Schedules loaded.', $result);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Invalid request method.', [], 405);
    }

    $action = $_POST['action'] ?? 'create';

    if ($action === 'delete') {
        $scheduleId = filter_var($_POST['scheduleId'] ?? null, FILTER_VALIDATE_INT);
        if (!$scheduleId) {
            jsonResponse(false, 'Invalid schedule ID.', [], 400);
        }

        $bookingCheckStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM bookings
            WHERE schedule_id = :schedule_id
              AND LOWER(COALESCE(booking_status, '')) NOT IN ('cancelled', 'expired', 'rejected')
        ");
        $bookingCheckStmt->execute([':schedule_id' => $scheduleId]);

        if ((int)$bookingCheckStmt->fetchColumn() > 0) {
            jsonResponse(false, 'Cannot delete schedule because there are already participants booked.', [], 409);
        }

        $stmt = $pdo->prepare("DELETE FROM class_schedules WHERE schedule_id = :schedule_id");
        $stmt->execute([':schedule_id' => $scheduleId]);

        if ($stmt->rowCount() === 0) {
            jsonResponse(false, 'Schedule not found.', [], 404);
        }

        jsonResponse(true, 'Schedule deleted successfully.');
    }

    $classId      = filter_var($_POST['classId'] ?? null, FILTER_VALIDATE_INT);
    $instructorId = filter_var($_POST['instructorId'] ?? null, FILTER_VALIDATE_INT);
    $classType    = trim($_POST['classType'] ?? '');
    $level        = trim($_POST['level'] ?? '');
    $memberSlot   = filter_var($_POST['memberSlot'] ?? null, FILTER_VALIDATE_INT);
    $date         = $_POST['scheduleDate'] ?? '';
    $startTime    = $_POST['startTime'] ?? '';
    $endTime      = $_POST['endTime'] ?? '';

    if (
        !$classId ||
        !$instructorId ||
        $classType === '' ||
        $level === '' ||
        !$memberSlot || $memberSlot < 1 || $memberSlot > 30 ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ||
        !preg_match('/^\d{2}:\d{2}$/', $startTime) ||
        !preg_match('/^\d{2}:\d{2}$/', $endTime) ||
        $endTime <= $startTime
    ) {
        jsonResponse(false, 'Please check all schedule fields (ensure End Time is after Start Time).', [], 422);
    }

    $scheduleIdForCheck = ($action === 'update') ? (int)($_POST['scheduleId'] ?? 0) : 0;
    $conflictStmt = $pdo->prepare("
        SELECT COUNT(*) FROM class_schedules
        WHERE instructor_id = :instructor_id
          AND schedule_date = :schedule_date
          AND schedule_id != :schedule_id
          AND (:start_time < end_time AND :end_time > start_time)
    ");
    $conflictStmt->execute([
        ':instructor_id' => $instructorId,
        ':schedule_date' => $date,
        ':schedule_id'   => $scheduleIdForCheck,
        ':start_time'    => $startTime,
        ':end_time'      => $endTime
    ]);

    if ((int)$conflictStmt->fetchColumn() > 0) {
        jsonResponse(false, 'Instructor already has another class scheduled at this time.', [], 409);
    }

    if ($action === 'update') {
        $scheduleId = filter_var($_POST['scheduleId'] ?? null, FILTER_VALIDATE_INT);
        if (!$scheduleId) {
            jsonResponse(false, 'Invalid schedule ID.', [], 400);
        }

        $sql = "UPDATE class_schedules SET
                    class_id = :class_id,
                    instructor_id = :instructor_id,
                    class_type = :class_type,
                    level = :level,
                    schedule_date = :schedule_date,
                    start_time = :start_time,
                    end_time = :end_time,
                    member_slot = :member_slot
                WHERE schedule_id = :schedule_id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':class_id'      => $classId,
            ':instructor_id' => $instructorId,
            ':class_type'    => $classType,
            ':level'         => $level,
            ':schedule_date' => $date,
            ':start_time'    => $startTime,
            ':end_time'      => $endTime,
            ':member_slot'   => $memberSlot,
            ':schedule_id'   => $scheduleId
        ]);

        jsonResponse(true, 'Schedule updated successfully!');
    }

    if ($action === 'create') {
        $sql = "INSERT INTO class_schedules (
                    class_id, instructor_id, class_type, level,
                    schedule_date, start_time, end_time, member_slot
                ) VALUES (
                    :class_id, :instructor_id, :class_type, :level,
                    :schedule_date, :start_time, :end_time, :member_slot
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':class_id'      => $classId,
            ':instructor_id' => $instructorId,
            ':class_type'    => $classType,
            ':level'         => $level,
            ':schedule_date' => $date,
            ':start_time'    => $startTime,
            ':end_time'      => $endTime,
            ':member_slot'   => $memberSlot
        ]);

        jsonResponse(true, 'New schedule added successfully!');
    }

    jsonResponse(false, 'Unknown action.', [], 400);
} catch (PDOException $e) {
    error_log('Schedule operation failed: ' . $e->getMessage());
    jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
}