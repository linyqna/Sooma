<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'Purchase Package';
$bodyClass = 'package-purchase-page';
$headerType = 'customer';
$headerLogo = 'black';
$basePath = '../assets/';

require_once __DIR__ . '/../includes/header.php';

if (!function_exists('formatPackagePrice')) {
    function formatPackagePrice($price)
    {
        return 'Rp' . number_format((int) $price, 0, ',', '.');
    }
}

// function getQueryParam(string $key, string $default, int $maxLength = 80): string
// {
//     $value = $_GET[$key] ?? '';
//     if (!is_string($value)) {
//         return $default;
//     }
//     $value = trim((string) preg_replace('/\p{C}+/u', '', $value));
//     return ($value === '' || mb_strlen($value) > $maxLength) ? $default : $value;
// }

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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

$packagesMap = [
    // Group Class
    'group-class-reformer-pilates-3x-session' => [
        'package'  => 'Reformer Pilates',
        'category' => 'Group Class',
        'sessions' => '3x Session',
        'price'    => 250000,
    ],
    'group-class-reformer-pilates-5x-session' => [
        'package'  => 'Reformer Pilates',
        'category' => 'Group Class',
        'sessions' => '5x Session',
        'price'    => 750000,
    ],
    'group-class-reformer-pilates-10x-session' => [
        'package'  => 'Reformer Pilates',
        'category' => 'Group Class',
        'sessions' => '10x Session',
        'price'    => 1250000,
    ],

    // Private Class
    'private-class-regular-yoga-4x-session' => [
        'package'  => 'Regular Yoga',
        'category' => 'Private Class',
        'sessions' => '4x Session',
        'price'    => 200000,
    ],
    'private-class-reformer-pilates-5x-session' => [
        'package'  => 'Reformer Pilates',
        'category' => 'Private Class',
        'sessions' => '5x Session',
        'price'    => 1650000,
    ],
    'private-class-reformer-pilates-10x-session' => [
        'package'  => 'Reformer Pilates',
        'category' => 'Private Class',
        'sessions' => '10x Session',
        'price'    => 3250000,
    ],
    'private-class-prenatal-yoga-1x-session' => [
        'package'  => 'Prenatal Yoga',
        'category' => 'Private Class',
        'sessions' => '1x Session',
        'price'    => 250000,
    ],

    // Private Group Class
    'private-group-class-reformer-pilates-5x-session' => [
        'package'  => 'Reformer Pilates',
        'category' => 'Private Group Class',
        'sessions' => '5x Session',
        'price'    => 550000,
    ],
    'private-group-class-reformer-pilates-10x-session' => [
        'package'  => 'Reformer Pilates',
        'category' => 'Private Group Class',
        'sessions' => '10x Session',
        'price'    => 900000,
    ],
    'private-group-class-prenatal-yoga-1x-session' => [
        'package'  => 'Prenatal Yoga',
        'category' => 'Private Group Class',
        'sessions' => '1x Session',
        'price'    => 400000,
    ],
];

// $packageRaw = getQueryParam('package',  'Reformer Pilates');
// $package    = htmlspecialchars($packageRaw, ENT_QUOTES, 'UTF-8');
// $sessions   = htmlspecialchars(getQueryParam('sessions', '5x Session', 40), ENT_QUOTES, 'UTF-8');
// $category   = htmlspecialchars(getQueryParam('category', 'Group Class', 40), ENT_QUOTES, 'UTF-8');

$pkgSlug  = isset($_GET['pkg']) && is_string($_GET['pkg']) ? trim($_GET['pkg']) : '';
$selected = $packagesMap[$pkgSlug] ?? $packagesMap['group-class-reformer-pilates-3x-session'];

$packageRaw = $selected['package'];
$package    = e($selected['package']);
$sessions   = e($selected['sessions']);
$category   = e($selected['category']);
$price      = (int) $selected['price'];

$packageImages = [
    'reformer pilates' => '../assets/images/class-pilates.jpg',
    'yoga'             => '../assets/images/class-yoga.jpg',
    'prenatal yoga'    => '../assets/images/class-prenatalyoga.jpg',
    'mat pilates'      => '../assets/images/class-matpilates.jpg',
];
$bannerImage = $basePath . ($packageImages[strtolower($packageRaw)] ?? $packageImages['reformer pilates']);

$user = $_SESSION['user'] ?? [
    'name'  => 'Emilia Bonnita',
    'phone' => '082765xxxxxx',
    'email' => 'emilia.bonnie@gmail.com',
];

$specs = [
    ['icon' => 'sessions', 'label' => 'Total sessions', 'value' => $sessions,                  'bold' => true],
    ['icon' => 'category', 'label' => 'Class category', 'value' => $category,                  'bold' => true],
    ['icon' => 'validity', 'label' => 'Validity',       'value' => 'Valid from first session', 'bold' => false],
    ['icon' => 'policy',   'label' => 'Policy',         'value' => 'One-time rescheduling',    'bold' => false],
    [
        'icon' => 'location',
        'label' => 'Location',
        'value' => 'Sooma Wellness',
        'bold' => true,
        'detail' => 'Jl. Ciliwung no. 62, Blitar'
    ],
];

$terms = [
    'Packages are valid only for the account that purchased them and cannot be transferred.',
    'The package validity period starts from the date of the first session you attend.',
    'Each package includes one-time rescheduling, requested at least 12 hours before the class starts.',
    'Cancellations made less than 12 hours before the class start time are considered late and the session credit will not be refunded.',
    'Payments are non-refundable once the booking has been confirmed.',
];

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Package — Sooma Wellness</title>
    <meta name="robots" content="noindex">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Montserrat+Alternates:wght@400&family=Poppins:wght@400;500;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="stylesheet" href="../assets/css/header.css">
    <link rel="stylesheet" href="../assets/css/packages-purchase.css">
    <link rel="stylesheet" href="../assets/css/footer.css">
</head>

<body>

    <?php include __DIR__ . '/../includes/header.php'; ?>

    <main class="purchase-page">

        <a href="packages.php" class="back-link">
            <i class="ph-fill ph-arrow-circle-left" aria-hidden="true"></i>
            <span>Packages</span>
        </a>

        <h1 class="purchase-title">Purchase Class Packages</h1>

        <div class="purchase-grid">

            <form class="purchase-form" id="purchaseForm" action="payment.php" method="POST">
                <input type="hidden" name="pkg_slug" value="<?= e($pkgSlug) ?>">
                <input type="hidden" name="package" value="<?= $package ?>">
                <input type="hidden" name="sessions" value="<?= $sessions ?>">
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