<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$bookingUrl     = 'booking-detail.php';
$timetableUrl   = 'timetable.php';
$transactionUrl = '../customer/profile-transaction.php?tab=pending';
$qrImage        = '../assets/images/qris-payment.jpeg';
$bankName       = 'BCA Sooma Wellness';
$maxBytes       = 5 * 1024 * 1024;

require __DIR__ . '/../includes/booking-service.php';

function pay_detect_type(string $path): ?array
{
    $h = @file_get_contents($path, false, null, 0, 12);
    if ($h === false || strlen($h) < 4) {
        return null;
    }
    if (strncmp($h, "\xFF\xD8\xFF", 3) === 0) return ['ext' => 'jpg',  'mime' => 'image/jpeg'];
    if (strncmp($h, "\x89PNG", 4) === 0)       return ['ext' => 'png',  'mime' => 'image/png'];
    if (strncmp($h, 'RIFF', 4) === 0 && substr($h, 8, 4) === 'WEBP') return ['ext' => 'webp', 'mime' => 'image/webp'];
    return null;
}

$statusLabels = [
    'waiting'   => 'Waiting Payment',
    'verifying' => 'Waiting Verification',
    'expired'   => 'Payment Expired',
    'confirmed' => 'Booking Confirmed',
];

$sessionUser = $_SESSION['user'] ?? [];
$userId      = (int) ($sessionUser['id'] ?? $sessionUser['user_id'] ?? 0);
$draft       = $_SESSION['booking_draft'] ?? [];
$dbId        = (int) ($_GET['id'] ?? $draft['db_id'] ?? $draft['id'] ?? 0);

if ($dbId <= 0 || $userId <= 0) {
    header('Location: ' . $timetableUrl);
    exit;
}

try {
    $pdo = bd_pdo();
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec('SET search_path TO sooma_wellness, public');

    $st = $pdo->prepare("
        SELECT b.booking_id AS id,
               COALESCE(b.booking_code, 'BK-' || LPAD(b.booking_id::text, 4, '0')) AS booking_code,
               b.booking_status AS status,
               b.payment_type_choice AS payment_method,
               b.payment_id,
               b.expires_at,
               c.user_id,
               p.payment_proof,
               (SELECT COUNT(*) FROM sooma_wellness.booking_attendees ba WHERE ba.booking_id = b.booking_id) AS attendee_count,
               (
                   (SELECT COUNT(*) FROM sooma_wellness.booking_attendees ba WHERE ba.booking_id = b.booking_id)
                   * COALESCE((
                       SELECT mc.price
                         FROM sooma_wellness.class_schedules cs
                         JOIN sooma_wellness.master_classes mc ON mc.class_id = cs.class_id
                        WHERE cs.schedule_id = b.schedule_id
                        LIMIT 1
                   ), 120000)::int
               ) AS total_payment,
               GREATEST(0, CEIL(EXTRACT(EPOCH FROM (b.expires_at - clock_timestamp()))))::int AS remaining
          FROM sooma_wellness.bookings b
          JOIN sooma_wellness.customers c ON c.customer_id = b.customer_id
          LEFT JOIN sooma_wellness.payments p ON p.payment_id = b.payment_id
         WHERE b.booking_id = :id
         LIMIT 1
    ");
    $st->execute([':id' => $dbId]);
    $booking = $st->fetch() ?: null;

    $appliedVoucher = null;
    if ($booking && $booking['payment_method'] === 'dropin') {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS sooma_wellness.booking_vouchers (
                    booking_id      INT PRIMARY KEY,
                    voucher_code    VARCHAR(32) NOT NULL,
                    discount_amount INT NOT NULL DEFAULT 0,
                    final_total     INT NOT NULL,
                    created_at      TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
                )
            ");
            $stVoucher = $pdo->prepare("
                SELECT voucher_code, discount_amount, final_total
                  FROM sooma_wellness.booking_vouchers
                 WHERE booking_id = :id
                 LIMIT 1
            ");
            $stVoucher->execute([':id' => $dbId]);
            $vRow = $stVoucher->fetch(PDO::FETCH_ASSOC);
            if ($vRow) {
                $booking['total_payment'] = (int) $vRow['final_total'];
                $appliedVoucher = $vRow;
            } elseif (!empty($_SESSION['booking_vouchers'][$dbId])) {
                $vSess = $_SESSION['booking_vouchers'][$dbId];
                $booking['total_payment'] = (int) $vSess['final_total'];
                $appliedVoucher = $vSess;
            }
        } catch (Throwable $ignore) {
            if (!empty($_SESSION['booking_vouchers'][$dbId])) {
                $vSess = $_SESSION['booking_vouchers'][$dbId];
                $booking['total_payment'] = (int) $vSess['final_total'];
                $appliedVoucher = $vSess;
            }
        }
    }

    if ($booking && (int) $booking['user_id'] !== $userId) {
        $booking = null;
    }

    if ($booking && $booking['status'] === 'waiting_payment' && $booking['expires_at'] !== null && (int) $booking['remaining'] === 0) {
        $pdo->prepare("
            UPDATE sooma_wellness.bookings
               SET booking_status = 'expired',
                   updated_at = clock_timestamp()
             WHERE booking_id = :id
               AND booking_status = 'waiting_payment'
               AND expires_at <= clock_timestamp()
        ")->execute([':id' => $dbId]);
        $booking['status'] = 'expired';
    }
} catch (Throwable $ex) {
    error_log('[payment] ' . $ex->getMessage());
    http_response_code(503);
    exit('Payment error: ' . htmlspecialchars($ex->getMessage()));
}

if (!$booking) {
    unset($_SESSION['booking_draft']);
    header('Location: ' . $timetableUrl);
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

    $reply = function (bool $ok, string $message, int $code = 200) use ($wantsJson, &$booking, $statusLabels, $dbId): void {
        $status = bd_ui_status((string) $booking['status']);
        if ($wantsJson) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'      => $ok,
                'message' => $message,
                'status'  => $status,
                'label'   => $statusLabels[$status] ?? '',
            ]);
            exit;
        }
        $_SESSION['pay_flash'] = ['type' => $ok ? 'success' : 'error', 'message' => $message];
        header('Location: payment.php?id=' . $dbId);
        exit;
    };

    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $reply(false, 'Your session has expired. Please refresh the page.', 400);
    }
    if ($booking['status'] === 'expired' || $booking['status'] === 'cancelled') {
        $reply(false, 'This payment has expired. Please create a new booking.', 410);
    }
    if ($booking['status'] !== 'waiting_payment') {
        $reply(false, 'This booking has already been processed.', 409);
    }

    $action        = (string) ($_POST['action'] ?? '');
    $paymentMethod = (string) ($booking['payment_method'] ?? '');

    if ($action === 'payment_proof' && $paymentMethod === 'dropin') {
        $f = $_FILES['receipt'] ?? null;

        if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
            $reply(false, 'Please choose a payment receipt first.');
        }
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > $maxBytes) {
            $reply(false, 'File is too large. Maximum size is 5 MB.');
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $reply(false, 'Upload failed. Please try again.', 500);
        }

        $detected = pay_detect_type($f['tmp_name']);
        if ($detected === null) {
            $reply(false, 'Only JPG, PNG, or WEBP images are allowed.');
        }

        $binaryData = @file_get_contents($f['tmp_name']);
        if ($binaryData === false) {
            $reply(false, 'Could not read the uploaded file. Please try again.', 500);
        }

        $mime = $detected['mime'];

        if (function_exists('imagecreatefromstring')) {
            $srcImg = @imagecreatefromstring($binaryData);
            if ($srcImg !== false) {
                $origW  = imagesx($srcImg);
                $origH  = imagesy($srcImg);
                $maxDim = 900;

                if ($origW > $maxDim || $origH > $maxDim) {
                    $ratio  = min($maxDim / $origW, $maxDim / $origH);
                    $newW   = (int) round($origW * $ratio);
                    $newH   = (int) round($origH * $ratio);
                    $dstImg = imagecreatetruecolor($newW, $newH);
                    imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
                    imagedestroy($srcImg);
                    $srcImg = $dstImg;
                }

                ob_start();
                imagejpeg($srcImg, null, 78);
                $binaryData = ob_get_clean();
                imagedestroy($srcImg);
                $mime = 'image/jpeg';
            }
        }

        $proofDataUri = 'data:' . $mime . ';base64,' . base64_encode($binaryData);
        $amount       = max(0, (int) $booking['total_payment']);
        $step         = 'init';

        try {
            $pdo->beginTransaction();
            $existingPaymentId = (int) ($booking['payment_id'] ?? 0);

            if ($existingPaymentId > 0) {
                $step = 'update_payment';
                $upPay = $pdo->prepare("
                    UPDATE sooma_wellness.payments
                       SET gross_amount = :amount,
                           payment_method = 'qris',
                           payment_proof = :proof,
                           verification_status = 'pending',
                           uploaded_at = clock_timestamp()
                     WHERE payment_id = :pid
                ");
                $upPay->execute([
                    ':amount' => $amount,
                    ':proof'  => $proofDataUri,
                    ':pid'    => $existingPaymentId,
                ]);
                $paymentId = $existingPaymentId;
            } else {
                $step = 'insert_payment';
                $insPay = $pdo->prepare("
                    INSERT INTO sooma_wellness.payments (
                        gross_amount,
                        payment_method,
                        payment_proof,
                        verification_status,
                        uploaded_at
                    ) VALUES (
                        :amount,
                        'qris',
                        :proof,
                        'pending',
                        clock_timestamp()
                    )
                    RETURNING payment_id
                ");
                $insPay->execute([
                    ':amount' => $amount,
                    ':proof'  => $proofDataUri,
                ]);
                $paymentId = (int) $insPay->fetchColumn();
            }

            $step = 'update_booking';
            $st = $pdo->prepare("
                UPDATE sooma_wellness.bookings
                   SET booking_status = 'waiting_verification',
                       payment_id = :payment_id,
                       updated_at = clock_timestamp()
                 WHERE booking_id = :id
                   AND booking_status = 'waiting_payment'
            ");
            $st->execute([
                ':payment_id' => $paymentId,
                ':id'         => $dbId,
            ]);

            if ($st->rowCount() !== 1) {
                $pdo->rollBack();
                $reply(false, 'This booking has expired or has already been processed. Please refresh the page.', 409);
            }

            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("[payment upload][$step] " . $ex->getMessage());
            $reply(false, "Could not save your receipt [$step]: " . $ex->getMessage(), 500);
        }

        $booking['status']        = 'waiting_verification';
        $booking['payment_proof'] = $proofDataUri;

        $reply(true, 'Receipt uploaded. Click Finish Payment to view your transaction.');
    }

    if ($action === 'confirm_package' && $paymentMethod === 'package') {
        try {
            $stmt = $pdo->prepare("
                UPDATE sooma_wellness.bookings b
                   SET booking_status = 'confirmed',
                       updated_at = clock_timestamp()
                  FROM sooma_wellness.customers c
                 WHERE b.customer_id = c.customer_id
                   AND b.booking_id = :id
                   AND c.user_id = :user_id
                   AND b.payment_type_choice = 'package'
                   AND b.booking_status = 'waiting_payment'
                   AND b.expires_at > clock_timestamp()
            ");
            $stmt->execute([
                ':id'      => $dbId,
                ':user_id' => $userId,
            ]);

            if ($stmt->rowCount() !== 1) {
                $reply(false, 'This booking can no longer be confirmed. Please refresh the page.', 409);
            }

            $booking['status'] = 'confirmed';
            $reply(true, 'Booking confirmed using your package.');
        } catch (Throwable $ex) {
            error_log('[payment package] ' . $ex->getMessage());
            $reply(false, 'Could not confirm this package booking right now: ' . $ex->getMessage(), 500);
        }
    }

    $reply(false, 'Invalid request.', 400);
}

$status      = bd_ui_status((string) $booking['status']);
$isPackage   = $booking['payment_method'] === 'package';
$total       = (int) $booking['total_payment'];
$bookingId   = (string) $booking['booking_code'];
$remaining   = $status === 'waiting' ? (int) $booking['remaining'] : 0;
$attendeeCnt = (int) $booking['attendee_count'];
$canUpload   = $status === 'waiting';
$receiptFile = (string) ($booking['payment_proof'] ?? '');

if ($receiptFile !== '') {
    $receiptLabel = str_starts_with($receiptFile, 'data:image/')
        ? 'Payment-Receipt-' . $bookingId . '.jpg'
        : basename($receiptFile);
    $receipt = ['original' => $receiptLabel];
} else {
    $receipt = null;
}

$flash = $_SESSION['pay_flash'] ?? null;
unset($_SESSION['pay_flash']);

$formAction = 'payment.php?id=' . $dbId;
$backUrl    = $bookingUrl;
$classQuery = $_SESSION['booking_draft']['class'] ?? [];
if (!empty($classQuery) && is_array($classQuery)) {
    $backUrl .= '?' . http_build_query($classQuery);
}

$steps = [
    ['Take Picture of QR Code',        'Take a picture of the QR code if you are using a different device to complete the payment'],
    ['Open E-Wallet / Bank App',       'Open your E-Wallet app (GoPay, OVO, ShopeePay, DANA, BCA, Mandiri, etc.) and select the Scan/Pay/QRIS menu'],
    ['Upload Picture / Scan QR Code',  'Select the picture you took of the QR Code or scan the QR code displayed directly'],
    ['Confirm & Enter PIN',            'Check the payment amount, confirm the merchant’s name, and then complete the transaction with your PIN'],
    ['Upload Payment Receipt',         'Save the payment receipt, then upload it to the Upload Payment Receipt section within the payment gateway'],
];

$pageTitle  = 'Payment Gateway';
$bodyClass  = 'payment-page';
$headerType = 'customer';
$headerLogo = 'black';
$basePath   = '../assets/';

require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Caladea:ital@0;1&display=swap">
<link rel="stylesheet" href="../assets/css/payment.css">
<noscript>
    <style>
        .pay-submit[hidden] {
            display: inline-flex !important
        }
    </style>
</noscript>

<main class="pay-page" id="pay-root"
    data-status="<?= e($status) ?>" data-remaining="<?= $remaining ?>"
    data-max="<?= $maxBytes ?>" data-mode="<?= $isPackage ? 'package' : 'qr' ?>">

    <a href="<?= e($backUrl) ?>" class="pay-back">
        <span class="pay-back__icon"><i class="ph ph-caret-left"></i></span>
        <span>Booking Detail</span>
    </a>

    <h1 class="pay-title">Payment Gateway</h1>

    <div class="pay-layout">

        <section class="pay-left" aria-label="Payment QR code">
            <?php if ($isPackage): ?>
                <div class="pay-package-box">
                    <i class="ph ph-package"></i>
                    <h2>Pay with Package Plan</h2>
                    <p>
                        <?= $attendeeCnt ?> session(s) will be deducted from your package.
                    </p>
                    <form method="post" action="<?= e($formAction) ?>">
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="confirm_package">
                        <button type="submit" class="pay-upload-btn" <?= $canUpload ? '' : 'disabled' ?>>
                            <i class="ph ph-check-circle"></i><span>Confirm Booking</span>
                        </button>
                    </form>
                    <p class="pay-message <?= $flash ? 'is-' . e($flash['type']) : '' ?>" id="pay-message" role="status" aria-live="polite"><?= e($flash['message'] ?? '') ?></p>
                </div>
            <?php else: ?>
                <p class="pay-hint"><i class="ph ph-scan"></i> Scan or Screenshot QR Code to Pay</p>

                <div class="pay-qr">
                    <img src="<?= e($qrImage) ?>" alt="QR code for payment of <?= e('Rp' . number_format($total, 0, '.', ',')) ?>"
                        onerror="this.hidden=true;this.nextElementSibling.hidden=false;">
                    <div class="pay-qr__fallback" hidden>QR code image not found.<br><small><?= e($qrImage) ?></small></div>
                </div>

                <form id="pay-upload-form" class="pay-upload" method="post" action="<?= e($formAction) ?>"
                    enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="payment_proof">
                    <input type="file" id="pay-receipt" name="receipt" class="pay-file"
                        accept="image/jpeg,image/png,image/webp" <?= $canUpload ? '' : 'disabled' ?>>

                    <label for="pay-receipt" id="pay-upload-btn"
                        class="pay-upload-btn <?= $canUpload ? '' : 'is-disabled' ?>"
                        role="button" tabindex="<?= $canUpload ? '0' : '-1' ?>" aria-disabled="<?= $canUpload ? 'false' : 'true' ?>">
                        <i class="ph ph-upload-simple"></i><span>Upload Payment Receipt</span>
                    </label>

                    <div class="pay-preview" id="pay-preview" <?= $receipt ? '' : 'hidden' ?>>
                        <div class="pay-preview__thumb" id="pay-thumb"><i class="ph ph-file"></i></div>
                        <div class="pay-preview__meta">
                            <span class="pay-preview__name" id="pay-file-name"><?= e($receipt['original'] ?? '') ?></span>
                            <span class="pay-preview__size" id="pay-file-size"><?= $receipt ? 'Submitted' : '' ?></span>
                        </div>
                        <button type="button" class="pay-preview__remove" id="pay-remove" aria-label="Remove file" <?= $receipt ? 'hidden' : '' ?>>
                            <i class="ph ph-x"></i>
                        </button>
                    </div>

                    <button type="submit" class="pay-submit" id="pay-submit" hidden>
                        <i class="ph ph-paper-plane-tilt"></i><span>Submit Receipt</span>
                    </button>

                    <p class="pay-message <?= $flash ? 'is-' . e($flash['type']) : '' ?>" id="pay-message" role="status" aria-live="polite"><?= e($flash['message'] ?? '') ?></p>
                </form>
            <?php endif; ?>
        </section>

        <section class="pay-right" aria-label="Payment details">
            <div class="pay-badge-row">
                <span class="pay-badge pay-badge--<?= e($status) ?>" id="pay-badge">
                    <i class="pay-dot" aria-hidden="true"></i>
                    <span id="pay-badge-text"><?= e($statusLabels[$status] ?? 'Waiting Payment') ?></span>
                </span>
            </div>

            <div class="pay-card">
                <div class="pay-card__main">
                    <h2 class="pay-card__bank"><?= e($isPackage ? 'Package Plan' : $bankName) ?></h2>
                    <p class="pay-card__label">Total Payment</p>
                    <p class="pay-card__amount"><em>Rp</em><?= number_format($total, 0, '.', ',') ?></p>
                </div>
                <div class="pay-card__foot">
                    <span>Booking ID</span>
                    <strong>#<?= e($bookingId) ?></strong>
                </div>
            </div>

            <div class="pay-countdown">
                <span class="pay-countdown__label">Payment Countdown</span>
                <span class="pay-countdown__time" id="pay-countdown" aria-live="off">
                    <?php if (in_array($status, ['verifying', 'confirmed'], true)): ?>--:--<?php elseif ($status === 'expired'): ?>00:00<?php else: ?><?= sprintf('%02d:%02d', intdiv($remaining, 60), $remaining % 60) ?><?php endif; ?>
                </span>
            </div>

            <?php $isFinishEnabled = in_array($status, ['verifying', 'confirmed'], true); ?>

            <a class="pay-link <?= $isFinishEnabled ? '' : 'is-disabled' ?>"
                id="pay-done-link"
                href="<?= e($status === 'confirmed' ? '../customer/profile-transaction.php?tab=history' : $transactionUrl) ?>"
                aria-disabled="<?= $isFinishEnabled ? 'false' : 'true' ?>"
                <?= $isFinishEnabled ? '' : 'tabindex="-1"' ?>>
                <span>Finish Payment</span>
                <span class="pay-link__icon" aria-hidden="true"><i class="ph ph-caret-right"></i></span>
            </a>
        </section>
    </div>

    <?php if (!$isPackage): ?>
        <section class="pay-steps" aria-labelledby="pay-steps-title">
            <h2 id="pay-steps-title" class="pay-steps__title">Payment Procedures</h2>
            <ol class="pay-steps__list">
                <?php foreach ($steps as $i => [$title, $text]): ?>
                    <li class="pay-step">
                        <span class="pay-step__num" aria-hidden="true"><?= $i + 1 ?></span>
                        <div>
                            <h3 class="pay-step__title"><?= e($title) ?></h3>
                            <p class="pay-step__text"><?= e($text) ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
<script src="../assets/js/payment.js"></script>