
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
    // GET: mengambil semua jadwal dari database
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $sql = "SELECT
                    schedule_id,
                    class_name,
                    class_type,
                    level,
                    instructor,
                    schedule_date,
                    start_time,
                    end_time,
                    price,
                    member_slot
                FROM classes
                ORDER BY schedule_date, start_time";

        $stmt = $pdo->query($sql);
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = array_map(function ($schedule) {
            return [
                'scheduleId' => (int) $schedule['schedule_id'],
                'className' => $schedule['class_name'],
                'classType' => $schedule['class_type'],
                'level' => $schedule['level'],
                'instructor' => $schedule['instructor'],
                'date' => $schedule['schedule_date'],
                'startTime' => substr((string) $schedule['start_time'], 0, 5),
                'endTime' => substr((string) $schedule['end_time'], 0, 5),
                'price' => $schedule['price'],
                'memberSlot' => (int) $schedule['member_slot'],
                'bookedCount' => 0
            ];
        }, $schedules);

        jsonResponse(true, 'Schedules loaded.', $result);
    }

    // POST: menambah, mengubah, atau menghapus jadwal
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Invalid request method.', [], 405);
    }

    $action = $_POST['action'] ?? '';

    // DELETE
    if ($action === 'delete') {
        $scheduleId = filter_var(
            $_POST['scheduleId'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (!$scheduleId) {
            jsonResponse(false, 'Invalid schedule ID.', [], 400);
        }

        $stmt = $pdo->prepare(
            "DELETE FROM classes WHERE schedule_id = :schedule_id"
        );

        $stmt->execute([
            ':schedule_id' => $scheduleId
        ]);

        if ($stmt->rowCount() === 0) {
            jsonResponse(false, 'Schedule not found.', [], 404);
        }

        jsonResponse(true, 'Schedule deleted successfully.');
    }

    // CREATE / UPDATE
    $className  = trim($_POST['className'] ?? '');
    $classType  = trim($_POST['classType'] ?? '');
    $level      = trim($_POST['level'] ?? '');
    $instructor = trim($_POST['instructor'] ?? '');
    $memberSlot = filter_var(
        $_POST['memberSlot'] ?? null,
        FILTER_VALIDATE_INT
    );
    $date       = $_POST['scheduleDate'] ?? '';
    $startTime  = $_POST['startTime'] ?? '';
    $endTime    = $_POST['endTime'] ?? '';
    $price      = filter_var(
        $_POST['price'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (
        $className === '' ||
        $classType === '' ||
        $level === '' ||
        $instructor === '' ||
        !$memberSlot ||
        $memberSlot < 1 ||
        $memberSlot > 10 ||
        !$price ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ||
        !preg_match('/^\d{2}:\d{2}$/', $startTime) ||
        !preg_match('/^\d{2}:\d{2}$/', $endTime) ||
        $endTime <= $startTime
    ) {
        jsonResponse(false, 'Please check the schedule information.', [], 422);
    }

    if ($action === 'update') {
        $scheduleId = filter_var(
            $_POST['scheduleId'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (!$scheduleId) {
            jsonResponse(false, 'Invalid schedule ID.', [], 400);
        }

        $sql = "UPDATE classes SET
                    class_name = :class_name,
                    class_type = :class_type,
                    level = :level,
                    instructor = :instructor,
                    schedule_date = :schedule_date,
                    start_time = :start_time,
                    end_time = :end_time,
                    price = :price,
                    member_slot = :member_slot
                WHERE schedule_id = :schedule_id";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ':class_name' => $className,
            ':class_type' => $classType,
            ':level' => $level,
            ':instructor' => $instructor,
            ':schedule_date' => $date,
            ':start_time' => $startTime,
            ':end_time' => $endTime,
            ':price' => $price,
            ':member_slot' => $memberSlot,
            ':schedule_id' => $scheduleId
        ]);

        jsonResponse(true, 'Schedule updated successfully.');
    }

    if ($action === 'create') {
        $sql = "INSERT INTO classes (
                    class_name,
                    class_type,
                    level,
                    instructor,
                    schedule_date,
                    start_time,
                    end_time,
                    price,
                    member_slot
                ) VALUES (
                    :class_name,
                    :class_type,
                    :level,
                    :instructor,
                    :schedule_date,
                    :start_time,
                    :end_time,
                    :price,
                    :member_slot
                )";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ':class_name' => $className,
            ':class_type' => $classType,
            ':level' => $level,
            ':instructor' => $instructor,
            ':schedule_date' => $date,
            ':start_time' => $startTime,
            ':end_time' => $endTime,
            ':price' => $price,
            ':member_slot' => $memberSlot
        ]);

        jsonResponse(true, 'New Schedule is Added!');
    }

    jsonResponse(false, 'Unknown action.', [], 400);

} catch (PDOException $e) {
    error_log('Schedule operation failed: ' . $e->getMessage());

    jsonResponse(false, 'Unable to process the schedule. Please try again.', [], 500);
}