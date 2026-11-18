<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../login-regist/connection.php';

if (!function_exists('formatPackagePrice')) {
    function formatPackagePrice($price): string
    {
        return 'Rp' . number_format((float) $price, 0, ',', '.');
    }
}

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

function specIcon(string $name): string
{
    $paths = [
        'sessions' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4M9 15l2 2 4-4"/>',
        'category' => '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><circle cx="17" cy="9" r="2.5"/><path d="M16 14.2c3 .2 5 2.4 5 5.8"/>',
        'validity' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'policy'   => '<path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/>',
        'location' => '<path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
    ];
    return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

function detectCategoryFromRow(string $packageName, string $packageCode): string
{
    $haystack = strtolower($packageName . ' ' . $packageCode);
    if (str_contains($haystack, 'private group') || str_contains($haystack, 'private-group') || str_contains($haystack, 'prvgrp') || str_contains($haystack, 'pg-')) {
        return 'Private Group Class';
    }
    if (str_contains($haystack, 'private') || str_contains($haystack, '-prv-')) {
        return 'Private Class';
    }
    return 'Group Class';
}

$pkgId   = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$pkgCode = isset($_GET['code']) && is_string($_GET['code']) ? trim($_GET['code']) : '';
$pkgSlug = isset($_GET['pkg']) && is_string($_GET['pkg']) ? trim($_GET['pkg']) : '';

// Ambil detail paket dari database Neon
$selected = null;

if (isset($pdo) && $pdo instanceof PDO) {
    try {
        if ($pkgId > 0 || $pkgCode !== '') {
            $stmtPkg = $pdo->prepare("
                SELECT p.package_id,
                       p.class_id,
                       p.package_code,
                       p.package_name,
                       p.package_price,
                       p.total_sessions,
                       p.active_days,
                       mc.class_name
                  FROM sooma_wellness.packages p
                  JOIN sooma_wellness.master_classes mc ON mc.class_id = p.class_id
                 WHERE (:id > 0 AND p.package_id = :id)
                    OR (:code <> '' AND p.package_code = :code)
                 LIMIT 1
            ");
            $stmtPkg->execute([
                ':id'   => $pkgId,
                ':code' => $pkgCode,
            ]);
            $row = $stmtPkg->fetch(PDO::FETCH_ASSOC);
        } else {
            // Default ambil paket pertama jika halaman dibuka tanpa parameter
            $stmtFirst = $pdo->query("
                SELECT p.package_id,
                       p.class_id,
                       p.package_code,
                       p.package_name,
                       p.package_price,
                       p.total_sessions,
                       p.active_days,
                       mc.class_name
                  FROM sooma_wellness.packages p
                  JOIN sooma_wellness.master_classes mc ON mc.class_id = p.class_id
                 ORDER BY p.package_id ASC
                 LIMIT 1
            ");
            $row = $stmtFirst ? $stmtFirst->fetch(PDO::FETCH_ASSOC) : false;
        }

        if ($row) {
            $selected = [
                'package_id'     => (int) $row['package_id'],
                'package_code'   => (string) $row['package_code'],
                'package'        => (string) $row['class_name'],
                'package_name'   => (string) $row['package_name'],
                'category'       => detectCategoryFromRow((string) $row['package_name'], (string) $row['package_code']),
                'sessions'       => ((int) $row['total_sessions']) . 'x Session',
                'total_sessions' => (int) $row['total_sessions'],
                'active_days'    => (int) $row['active_days'],
                'price'          => (float) $row['package_price'],
            ];
        }
    } catch (Throwable $ex) {
        error_log('[packages-purchase] ' . $ex->getMessage());
    }
}

if (!$selected) {
    header('Location: packages.php');
    exit;
}

$packageRaw    = $selected['package'];
$package       = e($selected['package']);
$sessions      = e($selected['sessions']);
$category      = e($selected['category']);
$price         = (int) round($selected['price']);
$activeDays    = (int) $selected['active_days'];
$packageId     = (int) $selected['package_id'];
$packageCode   = (string) $selected['package_code'];
$totalSessions = (int) $selected['total_sessions'];

$packageImages = [
    'reformer pilates' => 'images/class-pilates.jpg',
    'regular yoga'     => 'images/class-yoga.jpg',
    'yoga'             => 'images/class-yoga.jpg',
    'prenatal yoga'    => 'images/class-prenatalyoga.jpg',
    'mat pilates'      => 'images/class-matpilates.jpg',
];
$basePath    = '../assets/';
$bannerImage = $basePath . ($packageImages[strtolower($packageRaw)] ?? $packageImages['reformer pilates']);

// Ambil profil customer dari session / database
$sessionUser = $_SESSION['user'] ?? [];
$userId      = (int) ($_SESSION['user_id'] ?? $sessionUser['user_id'] ?? $sessionUser['id'] ?? 0);

$user = [
    'name'  => (string) ($sessionUser['name'] ?? $_SESSION['name'] ?? ''),
    'phone' => (string) ($sessionUser['phone'] ?? $sessionUser['phone_number'] ?? ''),
    'email' => (string) ($sessionUser['email'] ?? ''),
];

if ($userId > 0 && isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmtUser = $pdo->prepare("
            SELECT name, email, phone_number
              FROM sooma_wellness.users
             WHERE user_id = :uid
             LIMIT 1
        ");
        $stmtUser->execute([':uid' => $userId]);
        $dbUser = $stmtUser->fetch(PDO::FETCH_ASSOC);
        if ($dbUser) {
            $user['name']  = (string) ($dbUser['name'] ?? $user['name']);
            $user['email'] = (string) ($dbUser['email'] ?? $user['email']);
            $user['phone'] = (string) ($dbUser['phone_number'] ?? $user['phone']);
        }
    } catch (Throwable $ex) {
        error_log('[packages-purchase-user] ' . $ex->getMessage());
    }
}

$specs = [
    ['icon' => 'sessions', 'label' => 'Total sessions', 'value' => $sessions,                                      'bold' => true],
    ['icon' => 'category', 'label' => 'Class category', 'value' => $category,                                      'bold' => true],
    ['icon' => 'validity', 'label' => 'Validity',       'value' => "Valid {$activeDays} days from first session",  'bold' => false],
    ['icon' => 'policy',   'label' => 'Policy',         'value' => 'One-time rescheduling',                        'bold' => false],
    [
        'icon'   => 'location',
        'label'  => 'Location',
        'value'  => 'Sooma Wellness',
        'bold'   => true,
        'detail' => 'Jl. Ciliwung no. 62, Blitar',
    ],
];

$terms = [
    'Packages are valid only for the account that purchased them and cannot be transferred.',
    'The package validity period starts from the date of the first session you attend.',
    'Each package includes one-time rescheduling, requested at least 12 hours before the class starts.',
    'Cancellations made less than 12 hours before the class start time are considered late and the session credit will not be refunded.',
    'Payments are non-refundable once the booking has been confirmed.',
];

$pageTitle  = 'Purchase Package';
$bodyClass  = 'package-purchase-page';
$headerType = 'customer';
$headerLogo = 'black';

require_once __DIR__ . '/../includes/header.php';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Montserrat+Alternates:wght@400&family=Poppins:wght@400;500;700&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<link rel="stylesheet" href="../assets/css/packages-purchase.css">

<main class="purchase-page">

    <a href="packages.php" class="back-link">
        <i class="ph-fill ph-arrow-circle-left" aria-hidden="true"></i>
        <span>Packages</span>
    </a>

    <h1 class="purchase-title">Purchase Class Packages</h1>

    <div class="purchase-grid">

        <form class="purchase-form" id="purchaseForm" action="packages-payment.php" method="POST">
            <input type="hidden" name="package_id" value="<?= $packageId ?>">
            <input type="hidden" name="package_code" value="<?= e($packageCode) ?>">
            <input type="hidden" name="pkg_slug" value="<?= e($pkgSlug) ?>">
            <input type="hidden" name="package" value="<?= $package ?>">
            <input type="hidden" name="sessions" value="<?= $sessions ?>">
            <input type="hidden" name="total_sessions" value="<?= $totalSessions ?>">
            <input type="hidden" name="active_days" value="<?= $activeDays ?>">
            <input type="hidden" name="price" value="<?= $price ?>">
            <input type="hidden" name="category" value="<?= $category ?>">
            <input type="hidden" name="payment_method" value="one_time">

            <section class="form-section" aria-labelledby="profile-heading">
                <h2 class="form-section__title" id="profile-heading">Customer Profile</h2>
                <p class="form-section__hint">You can only add <strong>1</strong> participant in this class</p>

                <div class="field-list">
                    <div class="field">
                        <label class="field__label" for="customerName">Name</label>
                        <input class="field__input" type="text" id="customerName" name="customer_name"
                            value="<?= e($user['name']) ?>" autocomplete="name" required>
                    </div>
                    <div class="field">
                        <label class="field__label" for="customerPhone">Phone number</label>
                        <input class="field__input" type="tel" id="customerPhone" name="customer_phone"
                            value="<?= e($user['phone']) ?>" autocomplete="tel" required>
                    </div>
                    <div class="field">
                        <label class="field__label" for="customerEmail">Email</label>
                        <input class="field__input" type="email" id="customerEmail" name="customer_email"
                            value="<?= e($user['email']) ?>" autocomplete="email" required>
                    </div>
                </div>
            </section>

            <section class="form-section form-section--payment" aria-labelledby="payment-heading">
                <h2 class="form-section__title" id="payment-heading">Payment Method</h2>

                <button class="method-pill is-active" type="button" aria-pressed="true">
                    <i class="ph-fill ph-hand-coins" aria-hidden="true"></i>
                    <span>One Time Payment</span>
                </button>

                <div class="subtotal">
                    <i class="ph-fill ph-money" aria-hidden="true"></i>
                    <span class="subtotal__label">Subtotal</span>
                    <span class="subtotal__amount"><?= formatPackagePrice($price) ?></span>
                </div>
            </section>

            <section class="form-section form-section--terms" aria-labelledby="terms-heading">
                <h2 class="form-section__title" id="terms-heading">Term &amp; Condition</h2>

                <a class="terms-link" href="terms.php" id="openTerms" aria-haspopup="dialog" aria-controls="termsModal">
                    <span>Sooma Wellness Term &amp; Condition</span>
                    <i class="ph-fill ph-arrow-square-out" aria-hidden="true"></i>
                </a>

                <label class="agree" for="agreeTerms">
                    <input type="checkbox" id="agreeTerms" name="agree_terms" value="1" checked required>
                    <span class="agree__box" aria-hidden="true"></span>
                    <span class="agree__text">I agree to the T&amp;C</span>
                </label>
                <p class="agree__error" id="agreeError" role="alert" hidden>
                    Please agree to the Term &amp; Condition to continue.
                </p>
            </section>

            <div class="form-actions">
                <button class="btn-continue" type="submit">
                    <span>Continue Payment</span>
                    <i class="ph-fill ph-arrow-circle-right" aria-hidden="true"></i>
                </button>
            </div>
        </form>

        <aside class="purchase-summary" aria-label="Package summary">
            <article class="summary-card">
                <div class="summary-card__banner">
                    <img src="<?= e($bannerImage) ?>" alt="">
                    <div class="summary-card__heading">
                        <h2 class="summary-card__name"><?= $package ?></h2>
                        <p class="summary-card__category"><?= $category ?></p>
                    </div>
                </div>

                <ul class="spec-list">
                    <?php foreach ($specs as $spec): ?>
                        <li class="spec-list__item">
                            <span class="spec-list__icon"><?= specIcon($spec['icon']) ?></span>
                            <span class="spec-list__text">
                                <span class="visually-hidden"><?= e($spec['label']) ?>: </span>
                                <?php if ($spec['bold']): ?>
                                    <strong><?= $spec['value'] ?></strong>
                                <?php else: ?>
                                    <?= e($spec['value']) ?>
                                <?php endif; ?>
                                <?php if (!empty($spec['detail'])): ?>
                                    <span class="spec-list__detail"><?= e($spec['detail']) ?></span>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <p class="summary-card__badge">One-time payment</p>
            </article>
        </aside>
    </div>
</main>

<div class="terms-overlay" id="termsModal" aria-hidden="true">
    <div class="terms-modal" role="dialog" aria-modal="true" aria-labelledby="termsModalTitle" tabindex="-1">
        <header class="terms-modal__header">
            <h2 id="termsModalTitle">Sooma Wellness Term &amp; Condition</h2>
            <button class="terms-modal__close" type="button" aria-label="Close Term &amp; Condition">&times;</button>
        </header>

        <div class="terms-modal__body" tabindex="0">
            <ol>
                <?php foreach ($terms as $term): ?>
                    <li><?= e($term) ?></li>
                <?php endforeach; ?>
            </ol>
        </div>

        <footer class="terms-modal__footer">
            <button class="terms-modal__done" type="button">I Understand</button>
        </footer>
    </div>
</div>

<script src="../assets/js/packages-purchase.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>