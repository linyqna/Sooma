<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$requireLogin = true;
$loginPage    = '/login-regist/index.php?page=login';
$timetableUrl = 'timetable.php';
$paymentUrl   = 'payment.php';
$packagesUrl  = 'packages.php';
$termsUrl     = '#';

require __DIR__ . '/../includes/booking-service.php';

function bd_class_from_request(array $q): ?array
{
    $str = static fn(string $k): string => trim((string) ($q[$k] ?? ''));

    $date = DateTime::createFromFormat('!Y-m-d', $str('date'));
    if (!$date || $date->format('Y-m-d') !== $str('date')) {
        return null;
    }

    foreach (['start', 'end'] as $k) {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $str($k))) {
            return null;
        }
    }

    if ($str('end') <= $str('start')) {
        return null;
    }

    foreach (['instructor', 'className', 'category', 'level'] as $k) {
        if ($str($k) === '' || strlen($str($k)) > 60) {
            return null;
        }
    }

    $capacity = filter_var(
        $q['capacity'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 20]]
    );

    $taken = filter_var(
        $q['slot'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]
    );

    if ($capacity === false || $taken === false) {
        return null;
    }

    $classImages = [
        'Reformer Pilates' => '../assets/images/class-pilates.jpg',
        'Regular Yoga'     => '../assets/images/class-yoga.jpg',
    ];

    $defaultImage = '../assets/images/class-pilates.jpg';
    $defaultPrice = 120000;
    $className    = $str('className');

    return [
        'category'    => $str('category'),
        'class_name'  => $className,
        'image'       => $classImages[$str('category')] ?? $defaultImage,
        'date'        => $date->format('l, j F Y'),
        'time'        => str_replace(':', '.', $str('start'))
            . ' - '
            . str_replace(':', '.', $str('end'))
            . ' WIB',
        'type'        => $str('type'),
        'level'       => $str('level'),
        'coach'       => $str('instructor'),
        'venue'       => 'Sooma Wellness',
        'address'     => 'Jl Ciliwung no. 62, Blitar',
        'slots_left'  => max(0, $capacity - $taken),
        'slots_total' => $capacity,
        'price'       => $defaultPrice,

        'query' => [
            'scheduleId' => (int) ($q['scheduleId'] ?? 0),
            'date'       => $str('date'),
            'start'      => $str('start'),
            'end'        => $str('end'),
            'instructor' => $str('instructor'),
            'className'  => $className,
            'category'   => $str('category'),
            'level'      => $str('level'),
            'type'       => $str('type'),
            'slot'       => $taken,
            'capacity'   => $capacity,
        ],
    ];
}

function bd_attendee_card(
    $idx,
    array $a,
    array $errors,
    bool $removable,
    array $currentUser = []
): void {
    $i = (string) $idx;
    $p = "attendees.{$i}.";
    if ((string) $idx === '0') {
        $userName = trim((string) ($currentUser['name'] ?? ''));
        $title = $userName !== '' ? $userName : 'Attendee 1';
    } else {
        $number = ctype_digit((string) $idx) ? (int) $idx + 1 : '';
        $title = $number ? 'Attendee ' . $number : 'Attendee';
    }
    $bodyId = "bd-attendee-body-{$i}";
    $err    = static fn(string $f): ?string => $errors[$p . $f] ?? null;
?>
    <div class="bd-attendee" data-attendee>
        <div class="bd-attendee__head">
            <button type="button" class="bd-attendee__toggle" data-toggle-attendee
                aria-expanded="true" aria-controls="<?= e($bodyId) ?>">
                <i class="ph ph-user"></i>
                <span class="js-attendee-title"><?= e($title) ?></span>
            </button>
            <?php if ($removable): ?>
                <button type="button" class="bd-attendee__remove" data-remove-attendee aria-label="Remove attendee">
                    <i class="bi bi-x-lg"></i>
                </button>
            <?php endif; ?>
        </div>

        <div id="<?= e($bodyId) ?>" class="bd-attendee__panel">
            <div class="bd-attendee__body">
                <div class="bd-field">
                    <label class="bd-label" for="a<?= e($i) ?>-name">Name</label>
                    <input type="text" class="bd-input <?= $err('name') ? 'is-invalid' : '' ?>"
                        id="a<?= e($i) ?>-name" name="attendees[<?= e($i) ?>][name]"
                        value="<?= e($a['name'] ?? '') ?>" required data-attendee-name>
                    <?php if ($err('name')): ?><div class="bd-error"><?= e($err('name')) ?></div><?php endif; ?>
                </div>
                <div class="bd-field">
                    <label class="bd-label" for="a<?= e($i) ?>-phone">Phone number</label>
                    <input type="tel" inputmode="tel" class="bd-input <?= $err('phone') ? 'is-invalid' : '' ?>"
                        id="a<?= e($i) ?>-phone" name="attendees[<?= e($i) ?>][phone]"
                        value="<?= e($a['phone'] ?? '') ?>" placeholder="08xxxxxxxxxx" required>
                    <?php if ($err('phone')): ?><div class="bd-error"><?= e($err('phone')) ?></div><?php endif; ?>
                </div>
                <div class="bd-field">
                    <label class="bd-label" for="a<?= e($i) ?>-email">Email</label>
                    <input type="email" class="bd-input <?= $err('email') ? 'is-invalid' : '' ?>"
                        id="a<?= e($i) ?>-email" name="attendees[<?= e($i) ?>][email]"
                        value="<?= e($a['email'] ?? '') ?>" required>
                    <?php if ($err('email')): ?><div class="bd-error"><?= e($err('email')) ?></div><?php endif; ?>
                </div>
                <div class="bd-field">
                    <label class="bd-label" for="a<?= e($i) ?>-medical">Medical Condition</label>
                    <textarea class="bd-input bd-input--area" id="a<?= e($i) ?>-medical"
                        name="attendees[<?= e($i) ?>][medical]" placeholder="(Optional)"><?= e($a['medical'] ?? '') ?></textarea>
                </div>
                <label class="bd-check">
                    <input
                        type="checkbox"
                        name="attendees[<?= e($i) ?>][not_pregnant]"
                        value="1"
                        <?= !empty($a['not_pregnant']) ? 'checked' : '' ?>>

                    <span class="bd-check__box" aria-hidden="false">
                        <i class="ph ph-square"></i>
                        <i class="ph ph-check-square"></i>
                    </span>

                    <span>Participant is <strong>not</strong> currently pregnant</span>
                </label>
            </div>
        </div>
    </div>
<?php
}

$sessionUser = $_SESSION['user'] ?? [];

if ($requireLogin && empty($sessionUser)) {
    $redirectUrl = '/customer/booking-detail.php?' . http_build_query($_GET);
    header('Location: ' . $loginPage . '&redirect=' . urlencode($redirectUrl));
    exit;
}

$currentUser = [
    'id'        => (int) ($sessionUser['id'] ?? $sessionUser['user_id'] ?? 0),
    'name'      => (string) ($sessionUser['full_name'] ?? $sessionUser['name'] ?? $sessionUser['nama'] ?? ''),
    'phone'     => (string) ($sessionUser['phone'] ?? $sessionUser['no_hp'] ?? ''),
    'email'     => (string) ($sessionUser['email'] ?? ''),
    'birthdate' => (string) ($sessionUser['birthdate'] ?? $sessionUser['birth_date'] ?? $sessionUser['tanggal_lahir'] ?? ''),
];

$class = bd_class_from_request($_GET);
if (!$class) {
    header('Location: ' . $timetableUrl);
    exit;
}

$packages = bd_get_packages($currentUser['id']);

$scheduleId          = 0;
$classFull           = false;
$slotNotice          = null;
$maxAttendees        = 1;
$heldQty             = 1;
$hasAcceptedPrivate  = false;
$customerBirthdate   = $currentUser['birthdate'];
$maxBookingLimit     = 2;

try {
    $pdo = bd_pdo();
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec('SET search_path TO sooma_wellness, public');

    // 1. Pastikan tabel sementara slot_holds tersedia (tidak menyentuh tabel bookings)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sooma_wellness.slot_holds (
            hold_id     SERIAL PRIMARY KEY,
            schedule_id INT NOT NULL,
            user_id     INT NOT NULL,
            qty         INT NOT NULL DEFAULT 1,
            expires_at  TIMESTAMPTZ NOT NULL,
            UNIQUE (schedule_id, user_id)
        )
    ");

    // Bersihkan hold sementara & booking waiting_payment yang sudah expired
    $pdo->exec("DELETE FROM sooma_wellness.slot_holds WHERE expires_at <= clock_timestamp()");
    $pdo->exec("
        UPDATE sooma_wellness.bookings
           SET booking_status = 'expired', updated_at = clock_timestamp()
         WHERE booking_status = 'waiting_payment'
           AND expires_at IS NOT NULL
           AND expires_at <= clock_timestamp()
    ");

    $lookupQuery = $class['query'];
    $lookupQuery['schedule_id'] = $lookupQuery['scheduleId'];
    $schedule = bd_find_or_create_schedule($pdo, $lookupQuery);

    if (!$schedule) {
        header('Location: ' . $timetableUrl);
        exit;
    }

    $scheduleId = (int) $schedule['id'];
    $class['query']['scheduleId'] = $scheduleId;

    $customerId = bd_get_or_create_customer_id($pdo, $currentUser['id']);

    // Cek apakah user sudah punya transaksi aktif di jadwal ini
    $stmtExisting = $pdo->prepare("
        SELECT booking_id AS id, booking_code, booking_status AS status
          FROM sooma_wellness.bookings
         WHERE schedule_id = :sid
           AND customer_id = :cid
           AND booking_status IN ('waiting_payment', 'waiting_verification', 'confirmed')
         ORDER BY booking_id DESC
         LIMIT 1
    ");
    $stmtExisting->execute([':sid' => $scheduleId, ':cid' => $customerId]);
    $existing = $stmtExisting->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($existing) {
        $classFull  = true;
        $slotNotice = $existing['status'] === 'waiting_payment'
            ? 'You already have an unpaid booking for this class session. Please complete it in My Transaction.'
            : 'You already have an active booking for this class session.';
    } else {
        // Ambil kapasitas & harga dari database
        $stSched = $pdo->prepare("
            SELECT cs.member_slot, mc.price
              FROM sooma_wellness.class_schedules cs
              JOIN sooma_wellness.master_classes mc ON mc.class_id = cs.class_id
             WHERE cs.schedule_id = :sid
        ");
        $stSched->execute([':sid' => $scheduleId]);
        $schedInfo = $stSched->fetch(PDO::FETCH_ASSOC);

        if ($schedInfo) {
            $class['slots_total'] = (int) $schedInfo['member_slot'];
            $class['price']       = (int) $schedInfo['price'];

            // Hitung slot terpakai dari tabel bookings (aktif)
            $stBooked = $pdo->prepare("
                SELECT COALESCE(COUNT(ba.attendee_id), 0)
                  FROM sooma_wellness.bookings b
                  JOIN sooma_wellness.booking_attendees ba ON ba.booking_id = b.booking_id
                 WHERE b.schedule_id = :sid
                   AND (
                       b.booking_status IN ('confirmed', 'waiting_verification')
                       OR (b.booking_status = 'waiting_payment' AND (b.expires_at IS NULL OR b.expires_at > clock_timestamp()))
                   )
            ");
            $stBooked->execute([':sid' => $scheduleId]);
            $bookedCount = (int) $stBooked->fetchColumn();

            // Hitung slot yang sedang ditahan user LAIN di halaman booking-detail
            $stOtherHolds = $pdo->prepare("
                SELECT COALESCE(SUM(qty), 0)
                  FROM sooma_wellness.slot_holds
                 WHERE schedule_id = :sid
                   AND user_id <> :uid
                   AND expires_at > clock_timestamp()
            ");
            $stOtherHolds->execute([':sid' => $scheduleId, ':uid' => $currentUser['id']]);
            $otherHeldCount = (int) $stOtherHolds->fetchColumn();

            $availableForUser = max(0, $class['slots_total'] - $bookedCount - $otherHeldCount);

            if ($availableForUser < 1) {
                $classFull  = true;
                $slotNotice = 'Oops, sorry! The last spot in your selected class has just been booked. Kindly choose another available class.';
                $class['slots_left'] = 0;
            } else {
                $maxAttendees = min(2, $availableForUser);

                // Cek apakah user ini sudah menahan 1 atau 2 spot sebelumnya
                $stMyHold = $pdo->prepare("
                    SELECT qty FROM sooma_wellness.slot_holds
                     WHERE schedule_id = :sid AND user_id = :uid AND expires_at > clock_timestamp()
                ");
                $stMyHold->execute([':sid' => $scheduleId, ':uid' => $currentUser['id']]);
                $prevQty = (int) $stMyHold->fetchColumn();
                $heldQty = min($maxAttendees, max(1, $prevQty ?: 1));

                // Tahan slot sementara di slot_holds (15 menit), TANPA insert ke bookings
                $pdo->prepare("
                    INSERT INTO sooma_wellness.slot_holds (schedule_id, user_id, qty, expires_at)
                    VALUES (:sid, :uid, :qty, clock_timestamp() + interval '15 minutes')
                    ON CONFLICT (schedule_id, user_id)
                    DO UPDATE SET qty = EXCLUDED.qty, expires_at = EXCLUDED.expires_at
                ")->execute([
                    ':sid' => $scheduleId,
                    ':uid' => $currentUser['id'],
                    ':qty' => $heldQty,
                ]);

                // Sisa slot yang tampil di badge (sudah terpotong 1 spot yang ditahan user ini)
                $class['slots_left'] = max(0, $availableForUser - $heldQty);
            }
        }
    }
} catch (Throwable $ex) {
    error_log('[booking-detail] ' . $ex->getMessage());
    $classFull  = true;
    $slotNotice = 'Booking error: ' . $ex->getMessage();
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$defaultAttendees = [[
    'name'         => $currentUser['name'],
    'phone'        => $currentUser['phone'],
    'email'        => $currentUser['email'],
    'medical'      => '',
    'not_pregnant' => '1',
]];

for ($k = 1; $k < $heldQty; $k++) {
    $defaultAttendees[] = [
        'name'         => '',
        'phone'        => '',
        'email'        => '',
        'medical'      => '',
        'not_pregnant' => '1',
    ];
}

$old = [
    'attendees'      => $defaultAttendees,
    'payment_method' => 'dropin',
    'package_id'     => (string) ($packages[0]['id'] ?? ''),
    'agree'          => '',
];
$errors = [];

/**
 * Validasi kondisi penggunaan voucher di sisi server
 */
function bd_validate_voucher_condition(
    string $voucherCode,
    int $attendeeCount,
    int $unitPrice,
    string $startTime,
    string $birthdate,
    bool $hasAcceptedPrivate
): ?string {
    $code = strtoupper(trim($voucherCode));
    if ($code === '') {
        return null;
    }

    if ($code === 'SUMMER400') {
        if (!$hasAcceptedPrivate) {
            return 'Voucher Summer Day Out hanya dapat digunakan jika Anda sudah melakukan Request Private dan telah di-accept oleh admin.';
        }
        if ($attendeeCount < 4 || ($attendeeCount % 4) !== 0) {
            return 'Voucher Summer Day Out hanya berlaku untuk total peserta (termasuk attendee) berjumlah 4 atau kelipatan 4.';
        }
        return null;
    }

    if ($code === 'GENZ100') {
        $birthYear = 0;
        if (preg_match('/\b(19\d{2}|20\d{2})\b/', trim($birthdate), $m)) {
            $birthYear = (int) $m[1];
        }
        if ($birthYear < 1997 || $birthYear > 2012) {
            return 'Voucher Calling All Gen Z hanya berlaku untuk customer dengan tahun lahir 1997 - 2012.';
        }
        if ($unitPrice < 100000) {
            return 'Voucher Calling All Gen Z hanya berlaku untuk sesi kelas dengan harga Rp100.000 ke atas.';
        }
        return null;
    }

    if ($code === 'HAPPYHOUR135') {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $startTime, $tm)) {
            return 'Waktu mulai sesi kelas tidak valid untuk pengecekan Happy Hour.';
        }
        $startMinutes = ((int) $tm[1]) * 60 + ((int) $tm[2]);
        $inMorningSlot   = ($startMinutes >= 11 * 60 && $startMinutes <= 14 * 60); // 11:00 - 14:00
        $inAfternoonSlot = ($startMinutes >= 15 * 60 && $startMinutes <= 17 * 60); // 15:00 - 17:00
        if (!$inMorningSlot && !$inAfternoonSlot) {
            return 'Voucher Happy Hour hanya dapat digunakan untuk reservasi kelas pada pukul 11.00 - 14.00 atau 15.00 - 17.00.';
        }
        return null;
    }

    return 'Kode voucher tidak valid atau tidak berlaku.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Invalid request token.');
    }

    $data        = bd_parse_submission($_POST);
    $errors      = bd_validate_submission($data, $packages);
    $voucherCode = strtoupper(trim((string) ($_POST['voucher_code'] ?? '')));

    if ($data['payment_method'] === 'dropin' && $voucherCode !== '') {
        $voucherErr = bd_validate_voucher_condition(
            $voucherCode,
            count($data['attendees']),
            (int) $class['price'],
            (string) $class['query']['start'],
            $customerBirthdate,
            $hasAcceptedPrivate
        );
        if ($voucherErr !== null) {
            $errors['form'] = $voucherErr;
        }
    }

    $old = [
        'attendees'      => $data['attendees'] ?: $old['attendees'],
        'payment_method' => $data['payment_method'],
        'package_id'     => $data['package_id'],
        'agree'          => $data['agree'] ? '1' : '',
    ];

    if (!$errors && $scheduleId > 0 && count($data['attendees']) <= $maxBookingLimit) {
        try {
            $result = bd_reserve_slot(
                $pdo,
                $scheduleId,
                $currentUser['id'],
                $data,
                $class['query'],
                (int) ($_SESSION['booking_draft']['db_id'] ?? 0)
            );
        } catch (Throwable $ex) {
            error_log('[booking-detail] ' . $ex->getMessage());
            $result = ['status' => 'error', 'message' => 'We could not verify slot availability right now. Please try again.'];
        }

        if (in_array($result['status'], ['available', 'existing'], true)) {
            bd_store_booking_session($result['booking'], $class['query']);
            header('Location: ' . $paymentUrl);
            exit;
        }
        $errors['form'] = $result['message'] ?? 'Unable to continue. Please try again.';
        if ($result['status'] === 'full') {
            $classFull = true;
        }
    } elseif (count($data['attendees']) > $maxBookingLimit) {
        $errors['form'] = "Maximum {$maxBookingLimit} attendees allowed per booking.";
    } elseif (!$errors) {
        $errors['form'] = $slotNotice ?? 'Booking is temporarily unavailable. Please try again.';
    }
}

$pageTitle  = 'Timetable';
$bodyClass  = 'booking-detail-page';
$headerType = 'customer';
$headerLogo = 'black';
$basePath   = '../assets/';
$slotsText  = $class['slots_left'] . ' / ' . $class['slots_total'] . ' Left';

require __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2.1.2/src/fill/style.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2.1.2/src/regular/style.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Caladea:ital@0;1&display=swap">
<link rel="stylesheet" href="../assets/css/booking-detail.css">

<main class="bd-page">
    <a href="<?= e($timetableUrl) ?>" class="bd-back" id="bd-back-btn">
        <span class="bd-back__icon"><i class="bi bi-chevron-left"></i></span>
        <span>Timetable</span>
    </a>

    <h1 class="bd-title">Book Class Session</h1>

    <form method="post" action="booking-detail.php?<?= e(http_build_query($class['query'])) ?>" id="bd-form" novalidate
        data-price="<?= (int) $class['price'] ?>"
        data-max="<?= $maxAttendees ?>"
        data-limit="<?= $maxBookingLimit ?>"
        data-full="<?= $classFull ? '1' : '0' ?>"
        data-start-time="<?= e($class['query']['start']) ?>"
        data-birthdate="<?= e($customerBirthdate) ?>"
        data-private-accepted="<?= $hasAcceptedPrivate ? '1' : '0' ?>"
        data-endpoint="verify-slot.php">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="schedule_id" value="<?= (int) $scheduleId ?>">
        <input type="hidden" name="class_query" value="<?= e(http_build_query($class['query'])) ?>">
        <input type="hidden" name="voucher_code" id="bd-voucher-code" value="">

        <div class="bd-layout">

            <div class="bd-left">
                <?php if (isset($errors['form'])): ?>
                    <div class="bd-alert"><?= e($errors['form']) ?></div>
                <?php endif; ?>

                <?php if ($slotNotice && !isset($errors['form'])): ?>
                    <div class="bd-alert" role="alert">
                        <?= e($slotNotice) ?>
                        <a href="<?= e($timetableUrl) ?>" class="bd-link-btn">Choose another class</a>
                    </div>
                <?php endif; ?>

                <section class="bd-section">
                    <h2 class="bd-section__title">Attendee List</h2>
                    <p class="bd-section__hint">
                        <?php if ($maxAttendees === 1): ?>
                            Only <strong>1</strong> spot left in this class (reserved for you).
                        <?php else: ?>
                            You can add up to <strong><?= $maxAttendees ?></strong> participants in this class.
                        <?php endif; ?>
                    </p>

                    <div id="bd-attendee-list">
                        <?php foreach ($old['attendees'] as $i => $a) {
                            bd_attendee_card($i, $a, $errors, $i > 0, $currentUser);
                        } ?>
                    </div>

                    <button type="button" class="bd-add" id="bd-add-attendee"
                        <?= ($classFull || count($old['attendees']) >= $maxAttendees) ? 'disabled' : '' ?>>
                        + Add Attendee
                    </button>
                </section>

                <section class="bd-section">
                    <h2 class="bd-section__title">Payment Method</h2>
                    <div class="bd-pay" role="radiogroup" aria-label="Payment method">
                        <label class="bd-pay__opt">
                            <input type="radio" name="payment_method" value="dropin"
                                <?= $old['payment_method'] === 'dropin' ? 'checked' : '' ?>>
                            <span class="bd-pay__card">
                                <svg class="bd-pay__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M11 15h2a2 2 0 1 0 0-4h-3c-.6 0-1.1.2-1.4.6L3 17" />
                                    <path d="m7 21 1.6-1.4c.3-.4.8-.6 1.4-.6h4c1.1 0 2.1-.4 2.8-1.2l4.6-4.4a2 2 0 0 0-2.75-2.91l-4.2 3.9" />
                                    <path d="m2 16 6 6" />
                                    <circle cx="16" cy="9" r="2.9" />
                                    <circle cx="6" cy="5" r="3" />
                                </svg>
                                Drop-in Payment
                            </span>
                        </label>

                        <label class="bd-pay__opt">
                            <input type="radio" name="payment_method" value="package"
                                <?= $old['payment_method'] === 'package' ? 'checked' : '' ?>>
                            <span class="bd-pay__card"><i class="bi bi-inbox-fill bd-pay__icon"></i> Package Plan</span>
                        </label>
                    </div>

                    <div id="bd-packages" class="bd-packages" hidden>
                        <h3 class="bd-packages__title">My Packages</h3>
                        <?php if ($packages): ?>
                            <?php foreach ($packages as $pkg): ?>
                                <label class="bd-package">
                                    <input type="radio" name="package_id" value="<?= (int) $pkg['id'] ?>"
                                        data-remaining="<?= (int) $pkg['remaining'] ?>"
                                        <?= (string) $pkg['id'] === $old['package_id'] ? 'checked' : '' ?>>
                                    <span class="bd-package__card">
                                        <span class="bd-package__info">
                                            <span class="bd-package__name"><?= e($pkg['name']) ?></span>
                                            <span class="bd-package__desc"><?= e($pkg['type']) ?> (<?= (int) $pkg['total'] ?> Session)</span>
                                        </span>
                                        <span class="bd-package__badge">Available<br><?= (int) $pkg['remaining'] ?> / <?= (int) $pkg['total'] ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="bd-package__card bd-package__card--empty">
                                <div class="bd-package__info">
                                    <span class="bd-package__name">You have no package yet</span>
                                    <span class="bd-package__desc">Purchase a class package to book sessions with your plan</span>
                                </div>
                                <a href="<?= e($packagesUrl) ?>" class="bd-package__cta">Buy Packages</a>
                            </div>
                        <?php endif; ?>
                        <div class="bd-error" id="bd-package-error"><?= e($errors['package'] ?? '') ?></div>
                    </div>

                    <div id="bd-total-box" class="bd-total">
                        <div class="bd-total__row bd-total__row--voucher">
                            <label class="bd-total__label" for="bd-voucher-input">Input voucher:</label>
                            <div class="bd-voucher__controls">
                                <input type="text"
                                    class="bd-voucher__input"
                                    id="bd-voucher-input"
                                    placeholder="Kode voucher (cth: GENZ100)"
                                    autocomplete="off">
                                <button type="button" class="bd-voucher__btn" id="bd-apply-voucher">Apply</button>
                                <button type="button" class="bd-voucher__btn bd-voucher__btn--remove" id="bd-remove-voucher" hidden>Hapus</button>
                            </div>
                            <div class="bd-voucher__feedback" id="bd-voucher-feedback" aria-live="polite"></div>
                        </div>

                        <div class="bd-total__row">
                            <span class="bd-total__label">Subtotal</span>
                            <span class="bd-price bd-price--sm" id="bd-subtotal"><em>Rp</em><?= number_format($class['price'] * count($old['attendees']), 0, '.', ',') ?></span>
                        </div>

                        <div class="bd-total__row bd-total__row--applied" id="bd-voucher-applied" hidden>
                            <div class="bd-voucher-applied__info">
                                <span class="bd-voucher-applied__badge" id="bd-voucher-badge"></span>
                                <span class="bd-voucher-applied__desc" id="bd-voucher-desc"></span>
                            </div>
                            <span class="bd-price bd-price--sm bd-voucher-applied__amount" id="bd-voucher-discount"></span>
                        </div>

                        <div class="bd-total__row bd-total__row--grand">
                            <span class="bd-total__label">Total Payment</span>
                            <span class="bd-price bd-price--lg" id="bd-total"><em>Rp</em><?= number_format($class['price'] * count($old['attendees']), 0, '.', ',') ?></span>
                        </div>
                    </div>
                </section>

                <section class="bd-section">
                    <h2 class="bd-section__title">Term &amp; Condition</h2>
                    <a href="#" class="bd-terms" id="bd-open-tnc">
                        Sooma Wellness Term &amp; Condition
                        <i class="bi bi-box-arrow-up-right"></i>
                    </a>
                    <label class="bd-check bd-check--mt">
                        <input
                            type="checkbox"
                            name="agree"
                            value="1"
                            id="bd-agree"
                            <?= $old['agree'] ? 'checked' : '' ?>>

                        <span class="bd-check__box" aria-hidden="true">
                            <i class="ph ph-square"></i>
                            <i class="ph ph-check-square"></i>
                        </span>

                        <span>I agree to the T&amp;C</span>
                    </label>
                    <?php if (isset($errors['agree'])): ?>
                        <div class="bd-error"><?= e($errors['agree']) ?></div>
                    <?php endif; ?>
                </section>

                <div class="bd-actions">
                    <button type="submit" class="bd-continue" id="bd-continue">
                        <span>Continue Payment</span>
                        <i class="bi bi-arrow-right-circle-fill"></i>
                    </button>
                </div>
            </div>

            <aside class="bd-right">
                <div class="bd-summary">
                    <div class="bd-summary__hero"
                        style="background-image: url('<?= e($class['image']) ?>');">
                        <div class="bd-summary__hero-content">
                            <h2><?= e($class['class_name']) ?></h2>
                            <p><?= e($class['category']) ?> Class</p>
                        </div>
                    </div>

                    <ul class="bd-summary__list">
                        <li>
                            <i class="ph-fill ph-clock"></i>
                            <div>
                                <strong><?= e($class['date']) ?></strong><br>
                                <?= e($class['time']) ?>
                            </div>
                        </li>
                        <li>
                            <i class="ph-fill ph-chalkboard-teacher"></i>
                            <div>
                                Coach <strong><?= e($class['coach']) ?></strong>
                            </div>
                        </li>
                        <li>
                            <i class="ph-fill ph-align-bottom"></i>
                            <div>
                                Level <strong><?= e($class['level']) ?></strong>
                            </div>
                        </li>
                        <li>
                            <i class="bi bi-geo-alt-fill"></i>
                            <div>
                                <strong><?= e($class['venue']) ?></strong><br>
                                <?= e($class['address']) ?>
                            </div>
                        </li>
                    </ul>

                    <div class="bd-summary__slots">
                        <span class="bd-badge" id="bd-slots-badge"><?= e($slotsText) ?></span>
                    </div>
                </div>
            </aside>

        </div>
    </form>

    <template id="bd-attendee-template">
        <?php bd_attendee_card('__INDEX__', [], [], true, []); ?>
    </template>
</main>

<div class="tnc-overlay" id="tnc-modal" aria-hidden="true">
    <div class="tnc-modal" role="dialog" aria-modal="true" aria-labelledby="tnc-title">
        <div class="tnc-header">
            <h2 id="tnc-title">Sooma Wellness Terms &amp; Conditions</h2>
            <p>
                Please read and understand the studio's<br>
                rules before proceeding with payment
            </p>
        </div>

        <div class="tnc-content" id="tnc-content">
            <section class="tnc-section">
                <h3>Reservation &amp; Payment</h3>
                <ul>
                    <li>Payment must be made via BCA bank transfer and is non-refundable.</li>
                    <li>This package is for personal use only (non-transferable).</li>
                    <li>Rescheduling must be done at least 12 hours before class (limited to one time).</li>
                </ul>
            </section>

            <section class="tnc-section">
                <h3>Cancellation &amp; Rescheduling</h3>
                <ul>
                    <li>Cancellations made less than 12 hours in advance are considered a no-show, and the session is forfeited.</li>
                    <li>A minimum of 2 participants is required per class. If there are fewer than 2 participants, the studio reserves the right to cancel the class 12 hours in advance.</li>
                </ul>
            </section>

            <section class="tnc-section">
                <h3>Participant Guidelines</h3>
                <ul>
                    <li>Arrive at least 10 minutes before class for preparation.</li>
                    <li>It is mandatory to wear grip socks and comfortable activewear.</li>
                    <li>Participants must inform the instructor if they have any injuries or medical conditions.</li>
                    <li>Bringing or consuming food inside the studio is prohibited.</li>
                </ul>
            </section>

            <section class="tnc-section">
                <h3>Package Validity Period</h3>
                <ul>
                    <li>The package validity period is calculated from the first session, not from the date of payment, except for certain promotions.</li>
                    <li>Packages must be activated within 2 weeks of purchase; otherwise, the package will automatically activate and the validity period will begin.</li>
                </ul>
            </section>
        </div>

        <div class="tnc-footer">
            <button type="button" id="tnc-accept">I Understand</button>
        </div>
    </div>
</div>

<div class="bd-slot-overlay" id="bd-slot-modal" aria-hidden="true" hidden>
    <div class="bd-slot-modal" role="alertdialog" aria-modal="true"
        aria-labelledby="bd-slot-title" aria-describedby="bd-slot-text">
        <div class="bd-slot-modal__icon" aria-hidden="true"><i class="ph ph-warning-circle"></i></div>
        <h2 id="bd-slot-title">This slot is no longer available</h2>
        <p id="bd-slot-text"></p>
        <div class="bd-slot-modal__actions">
            <a class="bd-slot-btn bd-slot-btn--ghost" id="bd-slot-back" href="<?= e($timetableUrl) ?>">Back to Timetable</a>
            <button type="button" class="bd-slot-btn bd-slot-btn--primary" id="bd-slot-refresh">Refresh Availability</button>
        </div>
    </div>
</div>

<script>
    (function() {
        'use strict';
        var form = document.getElementById('bd-form');
        if (!form) return;

        var price = parseInt(form.dataset.price, 10) || 0;
        var bookingLimit = parseInt(form.dataset.limit, 10) || 2;
        var max = Math.min(bookingLimit, parseInt(form.dataset.max, 10) || 1);
        var list = document.getElementById('bd-attendee-list');
        var addBtn = document.getElementById('bd-add-attendee');
        var backBtn = document.getElementById('bd-back-btn');
        var slotsBadge = document.getElementById('bd-slots-badge');
        var totalSlots = <?= (int) $class['slots_total'] ?>;
        var scheduleId = form.querySelector('input[name="schedule_id"]').value;
        var tpl = document.getElementById('bd-attendee-template');
        var subtotalEl = document.getElementById('bd-subtotal');
        var totalEl = document.getElementById('bd-total');
        var totalBox = document.getElementById('bd-total-box');
        var packagesBox = document.getElementById('bd-packages');
        var packageError = document.getElementById('bd-package-error');
        var agree = document.getElementById('bd-agree');
        var continueBtn = document.getElementById('bd-continue');
        var continueLabel = continueBtn.querySelector('span');
        var continueText = continueLabel ? continueLabel.textContent : 'Continue Payment';
        var endpoint = form.dataset.endpoint;
        var checking = false;

        var slotModal = document.getElementById('bd-slot-modal');
        var slotTitle = document.getElementById('bd-slot-title');
        var slotText = document.getElementById('bd-slot-text');
        var slotBack = document.getElementById('bd-slot-back');
        var slotRefresh = document.getElementById('bd-slot-refresh');
        var lastFocus = null;
        var nextIndex = list.querySelectorAll('[data-attendee]').length;

        function updateAttendeeTitles() {
            list.querySelectorAll('[data-attendee]').forEach(function(card, index) {
                var title = card.querySelector('.js-attendee-title');
                var nameInput = card.querySelector('[data-attendee-name]');
                if (title) {
                    var attendeeName = nameInput ? nameInput.value.trim() : '';
                    title.textContent = attendeeName || 'Attendee ' + (index + 1);
                }
            });
        }

        function method() {
            var r = form.querySelector('input[name="payment_method"]:checked');
            return r ? r.value : 'dropin';
        }

        function count() {
            return list.querySelectorAll('[data-attendee]').length;
        }

        function pkgRemaining() {
            var r = form.querySelector('input[name="package_id"]:checked');
            return r ? parseInt(r.dataset.remaining, 10) : 0;
        }

        function hasPackages() {
            return form.querySelectorAll('input[name="package_id"]').length > 0;
        }

        function refresh() {
            var n = count(),
                isPackage = method() === 'package';
            var amount = '<em>Rp</em>' + (price * n).toLocaleString('en-US');
            subtotalEl.innerHTML = amount;
            totalEl.innerHTML = amount;
            totalBox.hidden = isPackage;
            packagesBox.hidden = !isPackage;

            if (typeof window.bdRefreshVoucher === 'function') {
                window.bdRefreshVoucher(price, n, isPackage);
            }

            if (isPackage && hasPackages()) {
                packageError.textContent = pkgRemaining() < n ? 'Your package does not have enough remaining sessions.' : '';
            } else {
                packageError.textContent = '';
            }

            addBtn.disabled = form.dataset.full === '1' || n >= max || n >= bookingLimit;
            if (max === 1) {
                addBtn.title = 'Only 1 spot left in this class';
            } else if (n >= bookingLimit) {
                addBtn.title = 'Maximum ' + bookingLimit + ' participants per booking';
            } else {
                addBtn.title = '';
            }

            var isVoucherInvalid = !isPackage && typeof window.bdIsVoucherValid === 'function' && !window.bdIsVoucherValid();
            continueBtn.disabled = checking || !agree.checked || form.dataset.full === '1' || isVoucherInvalid || (isPackage && pkgRemaining() < n);
        }

        function setOpen(card, open) {
            var btn = card.querySelector('[data-toggle-attendee]');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            document.getElementById(btn.getAttribute('aria-controls')).hidden = !open;
        }

        async function syncHoldQuantity(newQty) {
            var fd = new FormData();
            fd.append('action', 'release');
            fd.append('schedule_id', scheduleId);
            fd.append('qty', String(newQty));

            var res = await fetch('api-hold-slot.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            return await res.json();
        }

        addBtn.addEventListener('click', async function() {
            var nextQty = count() + 1;
            if (count() >= max || nextQty > bookingLimit || addBtn.disabled) return;

            addBtn.disabled = true;
            var origText = addBtn.textContent;
            addBtn.textContent = 'Reserving spot...';

            try {
                var result = await syncHoldQuantity(nextQty);
                if (result.status !== 'ok') {
                    alert(result.message || 'No more spots available in this class.');
                    max = count();
                    addBtn.textContent = origText;
                    refresh();
                    return;
                }

                if (slotsBadge) {
                    slotsBadge.textContent = result.remaining_others + ' / ' + totalSlots + ' Left';
                }

                list.querySelectorAll('[data-attendee]').forEach(function(c) {
                    setOpen(c, false);
                });

                list.insertAdjacentHTML(
                    'beforeend',
                    tpl.innerHTML.split('__INDEX__').join(String(nextIndex++))
                );

                updateAttendeeTitles();
            } catch (err) {
                console.error(err);
                alert('Failed to reserve additional spot. Please try again.');
            } finally {
                addBtn.textContent = origText;
                refresh();
            }
        });

        list.addEventListener('click', async function(e) {
            var toggle = e.target.closest('[data-toggle-attendee]');
            if (toggle) {
                setOpen(toggle.closest('[data-attendee]'), toggle.getAttribute('aria-expanded') !== 'true');
                return;
            }
            var rm = e.target.closest('[data-remove-attendee]');
            if (rm) {
                rm.closest('[data-attendee]').remove();
                updateAttendeeTitles();
                refresh();

                try {
                    var result = await syncHoldQuantity(count());
                    if (result.status === 'ok' && slotsBadge) {
                        slotsBadge.textContent = result.remaining_others + ' / ' + totalSlots + ' Left';
                    }
                } catch (err) {
                    console.error('Failed to release hold:', err);
                }
            }
        });

        if (backBtn) {
            backBtn.addEventListener('click', async function(e) {
                e.preventDefault();
                var targetHref = backBtn.getAttribute('href');
                try {
                    var fd = new FormData();
                    fd.append('action', 'release');
                    await fetch('api-hold-slot.php', {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin',
                        keepalive: true
                    });
                } catch (err) {
                    console.error(err);
                }
                window.location.href = targetHref;
            });
        }

        list.addEventListener('input', function(e) {
            var input = e.target;
            if (input.type === 'tel') {
                input.value = input.value.replace(/[^\d+]/g, '');
            }
            if (input.matches('[data-attendee-name]')) {
                updateAttendeeTitles();
            }
        });

        form.addEventListener('change', function(e) {
            if (e.target.matches('input[name="payment_method"], input[name="package_id"], #bd-agree')) refresh();
        });

        document.addEventListener('bd:voucher-changed', function() {
            refresh();
        });

        function setChecking(on) {
            checking = on;
            continueBtn.classList.toggle('is-loading', on);
            continueBtn.setAttribute('aria-busy', on ? 'true' : 'false');

            var spinner = continueBtn.querySelector('.bd-spinner');
            if (on) {
                if (!spinner) {
                    spinner = document.createElement('span');
                    spinner.className = 'bd-spinner';
                    spinner.setAttribute('aria-hidden', 'true');
                    continueBtn.insertBefore(spinner, continueBtn.firstChild);
                }
                if (continueLabel) continueLabel.textContent = 'Verifying slot & price...';
            } else {
                if (spinner) spinner.remove();
                if (continueLabel) continueLabel.textContent = continueText;
            }
            var isInvalidPackage = method() === 'package' && pkgRemaining() < count();
            var isVoucherInvalid = method() !== 'package' && typeof window.bdIsVoucherValid === 'function' && !window.bdIsVoucherValid();
            continueBtn.disabled = on || !agree.checked || form.dataset.full === '1' || isInvalidPackage || isVoucherInvalid;
        }

        function showSlotModal(mode, text) {
            var isFull = mode === 'full';
            lastFocus = document.activeElement;
            slotTitle.textContent = isFull ? 'This slot is no longer available' : 'We could not verify your booking';
            slotText.textContent = text || (isFull ?
                'Oops, sorry! The last spot in your selected class has just been booked. Kindly choose another available class.' :
                'Please try again in a moment.');
            slotBack.hidden = !isFull;
            slotRefresh.textContent = isFull ? 'Refresh Availability' : 'Try Again';
            slotRefresh.dataset.mode = mode;
            slotModal.hidden = false;
            slotModal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('bd-modal-open');
            slotRefresh.focus();
        }

        function hideSlotModal() {
            slotModal.hidden = true;
            slotModal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('bd-modal-open');
            if (lastFocus && lastFocus.focus) lastFocus.focus();
        }

        slotRefresh.addEventListener('click', function() {
            if (slotRefresh.dataset.mode === 'full') {
                window.location.reload();
            } else {
                hideSlotModal();
            }
        });
        slotModal.addEventListener('click', function(e) {
            if (e.target === slotModal) hideSlotModal();
        });
        document.addEventListener('keydown', function(e) {
            if (slotModal.hidden) return;
            if (e.key === 'Escape') {
                hideSlotModal();
            } else if (e.key === 'Tab') {
                var items = [slotBack, slotRefresh].filter(function(el) {
                    return !el.hidden;
                });
                var first = items[0],
                    last = items[items.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        });

        window.addEventListener('pageshow', function(e) {
            if (e.persisted) setChecking(false);
        });

        function checkSlotAndContinue() {
            if (checking) return;

            if (method() === 'dropin' && typeof window.bdIsVoucherValid === 'function' && !window.bdIsVoucherValid()) {
                return;
            }

            setChecking(true);

            var fd = new FormData(form);
            var n = count();
            var finalTotal = (method() === 'dropin' && typeof window.bdGetFinalTotal === 'function') ?
                window.bdGetFinalTotal(price, n) :
                (price * n);

            fd.append('attendee_count', String(n));
            fd.append('expected_total', String(finalTotal));

            var ctrl = window.AbortController ? new AbortController() : null;
            var timer = setTimeout(function() {
                if (ctrl) ctrl.abort();
            }, 15000);

            fetch(endpoint, {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'fetch',
                        'Accept': 'application/json'
                    },
                    credentials: 'same-origin',
                    signal: ctrl ? ctrl.signal : undefined
                })
                .then(function(res) {
                    return res.json().catch(function() {
                        return {
                            status: 'error'
                        };
                    });
                })
                .then(function(d) {
                    clearTimeout(timer);
                    d = d || {};

                    if (d.status === 'available' && d.redirect) {
                        if (continueLabel) continueLabel.textContent = 'Slot & price verified! Redirecting...';
                        window.location.href = d.redirect;
                        return;
                    }

                    setChecking(false);

                    if (d.status === 'full') {
                        showSlotModal('full', d.message);
                    } else if (d.status === 'unauthenticated' && d.redirect) {
                        window.location.href = d.redirect + (d.redirect.indexOf('?') > -1 ? '&' : '?') +
                            'redirect=' + encodeURIComponent(location.pathname + location.search);
                    } else if (d.status === 'invalid') {
                        form.submit();
                    } else {
                        showSlotModal('error', d.message);
                    }
                })
                .catch(function() {
                    clearTimeout(timer);
                    setChecking(false);
                    showSlotModal('error', 'We could not reach the server. Please check your connection and try again.');
                });
        }

        form.addEventListener('submit', function(e) {
            if (method() === 'package' && pkgRemaining() < count()) {
                e.preventDefault();
                packagesBox.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
                return;
            }
            if (!form.checkValidity()) {
                e.preventDefault();
                list.querySelectorAll('[data-attendee]').forEach(function(c) {
                    if (c.querySelector(':invalid')) setOpen(c, true);
                });
                form.reportValidity();
                return;
            }
            if (!agree.checked) {
                e.preventDefault();
                agree.focus();
                return;
            }

            e.preventDefault();
            if (!window.fetch || !window.FormData) {
                form.submit();
                return;
            }
            checkSlotAndContinue();
        });

        updateAttendeeTitles();
        refresh();
    })();
</script>

<script src="../assets/js/booking-detail.js" defer></script>

<?php require __DIR__ . '/../includes/footer.php'; ?>