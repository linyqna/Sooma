<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$bookingUrl   = 'booking-detail.php';
$timetableUrl = 'timetable.php';
$transactionUrl = '../customer/profile-transaction.php';
$qrImage      = '../assets/images/qris-payment.png';
$bankName     = 'BCA Sooma Wellness';
$uploadDir    = __DIR__ . '/../uploads/receipts';
$maxBytes     = 5 * 1024 * 1024;

if (!function_exists('e')) {
    function e(?string $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

function pay_detect_type(string $path): ?string
{
    $h = @file_get_contents($path, false, null, 0, 12);
    if ($h === false || strlen($h) < 4) {
        return null;
    }
    if (strncmp($h, "\xFF\xD8\xFF", 3) === 0) return 'jpg';
    if (strncmp($h, "\x89PNG", 4) === 0)      return 'png';
    if (strncmp($h, 'RIFF', 4) === 0 && substr($h, 8, 4) === 'WEBP') return 'webp';
    if (strncmp($h, '%PDF', 4) === 0)         return 'pdf';
    return null;
}

$statusLabels = [
    'waiting'   => 'Waiting Payment',
    'verifying' => 'Waiting Verification',
    'expired'   => 'Payment Expired',
    'confirmed' => 'Booking Confirmed',
];


if (empty($_SESSION['booking_draft']['booking_id'])) {
    header('Location: ' . $timetableUrl);
    exit;
}
$d = &$_SESSION['booking_draft'];

if (($d['status'] ?? 'waiting') === 'waiting' && (int) ($d['expires_at'] ?? 0) <= time()) {
    $d['status'] = 'expired';
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

    $reply = function (bool $ok, string $message, int $code = 200) use ($wantsJson, &$d, $statusLabels): void {
        $status = (string) ($d['status'] ?? 'waiting');
        if ($wantsJson) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => $ok,
                'message' => $message,
                'status' => $status,
                'label' => $statusLabels[$status] ?? '',
            ]);
            exit;
        }
        $_SESSION['pay_flash'] = ['type' => $ok ? 'success' : 'error', 'message' => $message];
        header('Location: payment.php');
        exit;
    };

    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $reply(false, 'Your session has expired. Please refresh the page.', 400);
    }
    if (($d['status'] ?? '') === 'expired') {
        $reply(false, 'This payment has expired. Please create a new booking.', 410);
    }
    if (($d['status'] ?? '') !== 'waiting') {
        $reply(false, 'This booking has already been processed.', 409);
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'upload_receipt' && ($d['payment_method'] ?? '') === 'dropin') {
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
        $ext = pay_detect_type($f['tmp_name']);
        if ($ext === null) {
            $reply(false, 'Only JPG, PNG, WEBP or PDF files are allowed.');
        }

        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            $reply(false, 'Server cannot store the file right now.', 500);
        }
        $ht = $uploadDir . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar)$\">\n  Require all denied\n</FilesMatch>\n");
        }

        $safeId   = preg_replace('/[^A-Za-z0-9-]/', '', (string) $d['booking_id']);
        $filename = sprintf('%s_%d_%s.%s', $safeId, time(), bin2hex(random_bytes(4)), $ext);
        if (!move_uploaded_file($f['tmp_name'], $uploadDir . '/' . $filename)) {
            $reply(false, 'Could not save the file. Please try again.', 500);
        }

        $d['receipt'] = [
            'file'        => $filename,
            'original'    => mb_substr_safe((string) $f['name'], 120),
            'uploaded_at' => date('c'),
        ];
        $d['status'] = 'verifying';
        $reply(true, 'Receipt uploaded. We will verify your payment shortly.');
    }

    if ($action === 'confirm_package' && ($d['payment_method'] ?? '') === 'package') {
        $d['status'] = 'confirmed';
        $reply(true, 'Booking confirmed using your package.');
    }

    $reply(false, 'Invalid request.', 400);
}

function mb_substr_safe(string $s, int $len): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]/', '', $s) ?? '';
    return function_exists('mb_substr') ? mb_substr($s, 0, $len) : substr($s, 0, $len);
}

$status      = (string) ($d['status'] ?? 'waiting');
$isPackage   = ($d['payment_method'] ?? 'dropin') === 'package';
$total       = (int) ($d['total_payment'] ?? 0);
$bookingId   = (string) $d['booking_id'];
$remaining   = max(0, (int) $d['expires_at'] - time());
$canUpload   = $status === 'waiting';
$receipt     = $d['receipt'] ?? null;
$flash       = $_SESSION['pay_flash'] ?? null;
unset($_SESSION['pay_flash']);

$backUrl = $bookingUrl;
if (!empty($d['class']) && is_array($d['class'])) {
    $backUrl .= '?' . http_build_query($d['class']);
}

$steps = [
    ['Take Picture of QR Code',        'Take a picture of the QR code if you are using a different device to complete the payment'],
    ['Open E-Wallet / Bank App',       'Open your E-Wallet app (GoPay, OVO, ShopeePay, DANA, BCA, Mandiri, etc.) and select the Scan/Pay/QRIS menu'],
    ['Upload Picture / Scan QR Code',  'Select the picture you took of the QR Code or scan the QR code displayed directly'],
    ['Confirm & Enter PIN',            'Check the payment amount, confirm the merchant’s name, and then complete the transaction with your PIN'],
    ['Upload Payment Receipt',         'Save the payment receipt, then upload it to the Upload Payment Receipt section within the payment gateway'],
];

$pageTitle  = 'Timetable';
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
                        <?= count($d['attendees'] ?? []) ?> session(s) will be deducted from
                        <strong><?= e((string) ($d['package_name'] ?? 'your package')) ?></strong>.
                    </p>
                    <form method="post" action="payment.php">
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

                <form id="pay-upload-form" class="pay-upload" method="post" action="payment.php"
                    enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="upload_receipt">
                    <input type="file" id="pay-receipt" name="receipt" class="pay-file"
                        accept="image/jpeg,image/png,image/webp,application/pdf,.pdf" <?= $canUpload ? '' : 'disabled' ?>>

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
                href="<?= e($transactionUrl) ?>"
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