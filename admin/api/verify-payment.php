<?php
session_start();
header('Content-Type: application/json');
require_once dirname(__DIR__, 2) . '/login-regist/connection.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false]));
}

$bookingCode = $_POST['booking_id'] ?? '';
$status      = $_POST['status'] ?? ''; // 'confirmed' atau 'rejected'
$note        = $_POST['note'] ?? ($_POST['reason'] ?? '');
$adminUserId = $_SESSION['user_id'] ?? null;

if (!in_array($status, ['confirmed', 'rejected'], true) || empty($bookingCode)) {
    exit(json_encode(['success' => false]));
}

try {
    $pdo->exec("SET search_path TO sooma_wellness, public");
    $pdo->beginTransaction();

    // 1. Ambil payment_id, schedule_id, & booking_id berdasarkan booking_code
    $stmtFind = $pdo->prepare("
        SELECT booking_id, payment_id, schedule_id 
        FROM bookings 
        WHERE booking_code = :code 
        FOR UPDATE
    ");
    $stmtFind->execute(['code' => $bookingCode]);
    $booking = $stmtFind->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception('Booking tidak ditemukan.');
    }

    // 2. Update status di tabel payments
    if (!empty($booking['payment_id'])) {
        $stmtPay = $pdo->prepare("
            UPDATE payments 
            SET verification_status = :status,
                admin_note = :note,
                verified_by_user_id = :admin_id,
                verified_at = NOW()
            WHERE payment_id = :pid
        ");
        $stmtPay->execute([
            'status'   => $status,
            'note'     => $note,
            'admin_id' => $adminUserId,
            'pid'      => $booking['payment_id']
        ]);
    }

    // 3. Update booking_status & updated_at di tabel bookings
    $bookingStatus = ($status === 'confirmed') ? 'confirmed' : 'rejected';
    $stmtBook = $pdo->prepare("
        UPDATE bookings 
        SET booking_status = :b_status, updated_at = NOW() 
        WHERE booking_id = :bid
    ");
    $stmtBook->execute([
        'b_status' => $bookingStatus,
        'bid'      => $booking['booking_id']
    ]);

    // 4. Jika ditolak, kembalikan slot kelas sesuai jumlah attendee
    if ($status === 'rejected') {
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM booking_attendees WHERE booking_id = :bid");
        $stmtCount->execute(['bid' => $booking['booking_id']]);
        $attendeeCount = max(1, (int)$stmtCount->fetchColumn());

        $stmtSlot = $pdo->prepare("
            UPDATE classes 
            SET member_slot = member_slot + :qty 
            WHERE schedule_id = :sid
        ");
        $stmtSlot->execute([
            'qty' => $attendeeCount,
            'sid' => $booking['schedule_id']
        ]);
    }

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}