<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../login-regist/connection.php';

$sessionUser = $_SESSION['user'] ?? [];
$userId      = (int) ($sessionUser['user_id'] ?? $sessionUser['id'] ?? $_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    header('Location: ../login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $packageId     = filter_input(INPUT_POST, 'package_id', FILTER_VALIDATE_INT);
    $paymentMethod = trim((string) ($_POST['payment_method'] ?? 'Transfer Bank'));

    if (!$packageId || $packageId <= 0) {
        header('Location: profile-package.php');
        exit;
    }

    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('set search_path to sooma_wellness, public');
        $pdo->beginTransaction();

        // 1. Ambil atau buat customer_id milik user
        $custStmt = $pdo->prepare('select customer_id from sooma_wellness.customers where user_id = :user_id limit 1');
        $custStmt->execute([':user_id' => $userId]);
        $customerId = (int) $custStmt->fetchColumn();

        if ($customerId <= 0) {
            $insCust = $pdo->prepare('insert into sooma_wellness.customers (user_id) values (:user_id) returning customer_id');
            $insCust->execute([':user_id' => $userId]);
            $customerId = (int) $insCust->fetchColumn();
        }

        // 2. Ambil data paket asli dari tabel packages
        $pkgStmt = $pdo->prepare('
            select package_id, package_code, package_price, total_sessions, active_days
              from sooma_wellness.packages
             where package_id = :package_id
             limit 1
        ');
        $pkgStmt->execute([':package_id' => $packageId]);
        $pkg = $pkgStmt->fetch(PDO::FETCH_ASSOC);

        if (!$pkg) {
            throw new RuntimeException('Package tidak ditemukan.');
        }

        // 3. Catat pembayaran ke tabel payments
        $payStmt = $pdo->prepare("
            insert into sooma_wellness.payments (
                gross_amount,
                payment_method,
                verification_status
            ) values (
                :gross_amount,
                :payment_method,
                'pending'
            )
            returning payment_id
        ");
        $payStmt->execute([
            ':gross_amount'   => $pkg['package_price'],
            ':payment_method' => $paymentMethod,
        ]);
        $paymentId = (int) $payStmt->fetchColumn();

        // 4. Generate kode redeem unik & catat kepemilikan ke user_packages
        $redeemCode = strtoupper($pkg['package_code']) . '-' . strtoupper(bin2hex(random_bytes(3)));

        $upStmt = $pdo->prepare("
            insert into sooma_wellness.user_packages (
                customer_id,
                package_id,
                remaining_sessions,
                purchased_at,
                expired_at,
                redeem_code
            ) values (
                :customer_id,
                :package_id,
                :remaining_sessions,
                clock_timestamp(),
                clock_timestamp() + (:active_days || ' days')::interval,
                :redeem_code
            )
        ");
        $upStmt->execute([
            ':customer_id'        => $customerId,
            ':package_id'         => (int) $pkg['package_id'],
            ':remaining_sessions' => (int) $pkg['total_sessions'],
            ':active_days'        => (int) $pkg['active_days'],
            ':redeem_code'        => $redeemCode,
        ]);

        $pdo->commit();

        header('Location: profile-package.php?tab=active');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Purchase package error: ' . $e->getMessage());
    }
}