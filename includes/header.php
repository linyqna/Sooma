<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle  = $pageTitle ?? 'Sooma Wellness';
$bodyClass  = $bodyClass ?? '';
$hideChrome = $hideChrome ?? false;

$userSession = $_SESSION['user'] ?? [];
$headerType  = strtolower((string) ($headerType ?? $userSession['role_name'] ?? $_SESSION['role'] ?? 'customer'));
$headerLogo  = $headerLogo ?? 'black';

$logoFile = $headerLogo === 'black'
    ? 'sooma-logo-black.png'
    : 'sooma-logo-white.png';

$currentPage = basename($_SERVER['PHP_SELF']);

if (!function_exists('header_e')) {
    function header_e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (isset($basePath)) {
    $assetBase = $basePath;
} else {
    $assetBase = file_exists('assets/css/header.css') ? 'assets/' : '../assets/';
}

$pageCssBase = $pageCssBase ?? $assetBase;

$rawAvatar = trim((string) ($navbar_avatar ?? $userSession['avatar_url'] ?? ''));

$logoHref = match ($headerType) {
    'admin'      => 'admin-dashboard.php',
    'instructor' => 'dashboard_instructor.php',
    default      => 'homepage.php',
};

// Menu untuk Customer
$customerMenus = [
    'homepage'   => 'Home',
    'about-us'   => 'About Us',
    'class'      => 'Class',
    'packages'   => 'Packages',
    'timetable'  => 'Timetable',
    'contact-us' => 'Contact Us',
];

// Menu untuk Admin
$adminMenus = [
    'admin-dashboard.php' => 'Home',
    'booking.php'         => 'Booking',
    'schedule-class.php'  => 'Schedule & Class',
    'instructors.php'     => 'Instructors',
];

$isAuthPage = (strpos($_SERVER['REQUEST_URI'], 'login') !== false || strpos($_SERVER['REQUEST_URI'], 'regist') !== false);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= header_e($pageTitle) ?> | Sooma Wellness</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Montserrat+Alternates:wght@400;500;600&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>

    <?php if ($isAuthPage): ?>
        <link rel="stylesheet" href="<?= header_e($assetBase) ?>css/auth.css">
    <?php else: ?>
        <link rel="stylesheet" href="<?= header_e($assetBase) ?>css/header.css">
        <link rel="stylesheet" href="<?= header_e($assetBase) ?>css/footer.css">

        <?php if (!empty($pageCss)): ?>
            <link rel="stylesheet" href="<?= header_e($pageCssBase) ?>css/<?= header_e($pageCss) ?>">
        <?php endif; ?>
    <?php endif; ?>
</head>

<body class="<?= header_e($bodyClass) ?>">

    <?php if (!$hideChrome): ?>
        <header class="site-header" id="site-header">
            <a class="site-header__logo" href="<?= header_e($logoHref) ?>" aria-label="Sooma Wellness — Home">
                <img src="<?= header_e($assetBase) ?>images/<?= header_e($logoFile) ?>" alt="Sooma Wellness">
            </a>

            <?php if ($headerType !== 'instructor'): ?>
                <input class="site-header__toggle" type="checkbox" id="nav-toggle" aria-label="Toggle menu">
                <label class="site-header__burger" for="nav-toggle" aria-hidden="true">
                    <span></span><span></span><span></span>
                </label>
            <?php endif; ?>

            <?php if ($headerType === 'admin'): ?>
                <nav class="site-nav site-nav__admin" aria-label="Admin navigation">
                    <ul class="site-nav__list">
                        <?php foreach ($adminMenus as $href => $label):
                            $isActive = ($currentPage === $href);
                        ?>
                            <li class="site-nav__item">
                                <a class="site-nav__link<?= $isActive ? ' active' : '' ?>"
                                    href="<?= header_e($href) ?>"
                                    <?= $isActive ? 'aria-current="page"' : '' ?>>
                                    <?= header_e($label) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </nav>

            <?php elseif ($headerType === 'instructor'): ?>
                <?php
                $rawCoach = trim((string) ($instructorName ?? $userSession['name'] ?? 'Instructor'));
                $coachName = preg_replace('/^Coach\s+/i', '', $rawCoach);
                ?>
                <div class="site-header__welcome">
                    Welcome, Coach <strong><?= header_e($coachName) ?></strong>
                </div>

            <?php else: ?>
                <nav class="site-nav" aria-label="Main navigation">
                    <ul class="site-nav__list">
                        <?php foreach ($customerMenus as $slug => $label):
                            $href = "{$slug}.php";
                            $isActive = (($_GET['page'] ?? '') === $slug) || ($currentPage === $href);
                        ?>
                            <li class="site-nav__item">
                                <a class="site-nav__link<?= $isActive ? ' active' : '' ?>"
                                    href="<?= header_e($href) ?>"
                                    <?= $isActive ? 'aria-current="page"' : '' ?>>
                                    <?= header_e($label) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
            <?php endif; ?>

            <div class="site-header__actions">
                <?php if ($headerType === 'admin' || $headerType === 'instructor' || $headerType === 'customer'): ?>
                    <a href="../login-regist/auth/logout.php" class="site-header__logout">Logout</a>
                <?php endif; ?>

                <?php if (!empty($userSession)): ?>
                    <a class="site-header__avatar" href="<?= header_e($navbar_profile_href ?? 'profile.php') ?>" aria-label="My profile">
                        <?php if ($rawAvatar !== ''): ?>
                            <img src="<?= header_e($rawAvatar) ?>" alt="<?= header_e($userSession['name'] ?? 'Profile') ?>">
                        <?php else: ?>
                            <i class="ph-fill ph-user" aria-hidden="true"></i>
                        <?php endif; ?>
                    </a>
                <?php else: ?>
                    <a class="site-header__login-btn" href="../login-regist/auth/login.php">Sign In</a>
                <?php endif; ?>
            </div>
        </header>
    <?php endif; ?>