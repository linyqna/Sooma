<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle  = $pageTitle ?? 'Sooma Wellness';
$bodyClass  = $bodyClass ?? '';
$hideChrome = $hideChrome ?? false;

if (!empty($_SESSION['user'])) {
    $hdrUserId = $_SESSION['user']['user_id'] ?? $_SESSION['user']['id'] ?? null;

    if ($hdrUserId && !array_key_exists('avatar_url', $_SESSION['user'])) {
        if (!isset($pdo)) {
            $connFile = __DIR__ . '/../login-regist/connection.php';
            if (file_exists($connFile)) {
                require_once $connFile;
            }
        }

        if (isset($pdo)) {
            $stmtHdr = $pdo->prepare(
                "select u.avatar_url, i.photo as instructor_photo
                 from sooma_wellness.users u
                 left join sooma_wellness.instructors i on u.user_id = i.user_id
                 where u.user_id = :id
                 limit 1"
            );
            $stmtHdr->execute(['id' => $hdrUserId]);
            $rowHdr = $stmtHdr->fetch(PDO::FETCH_ASSOC);

            if ($rowHdr) {
                $_SESSION['user']['avatar_url']       = $rowHdr['avatar_url'];
                $_SESSION['user']['instructor_photo'] = $rowHdr['instructor_photo'];
            }
        }
    }
}

$userSession = $_SESSION['user'] ?? [];
$headerType  = strtolower((string) ($headerType ?? $userSession['role_name'] ?? $userSession['role'] ?? $_SESSION['role'] ?? 'customer'));
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

$sessionAvatar = ($userSession['avatar_url'] ?? '') !== ''
    ? $userSession['avatar_url']
    : ($userSession['instructor_photo'] ?? '');

$rawAvatar = trim((string) ($navbar_avatar ?? $sessionAvatar));

if (
    $rawAvatar !== '' &&
    !str_starts_with($rawAvatar, 'data:') &&
    !str_starts_with($rawAvatar, 'http') &&
    !str_starts_with($rawAvatar, './') &&
    !str_starts_with($rawAvatar, '../')
) {
    $rootPrefix = preg_replace('#assets/?$#', '', $assetBase);
    $rawAvatar  = $rootPrefix . 'admin/assets/images/' . ltrim($rawAvatar, '/');
}

$logoHref = match ($headerType) {
    'admin'      => 'admin-dashboard.php',
    'instructor' => 'instructor.php',
    default      => 'homepage.php',
};

$defaultProfileHref = match ($headerType) {
    'admin'      => 'profile-admin.php',
    'instructor' => 'profile-instructor.php',
    default      => 'profile.php',
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
    'admin-dashboard.php'     => 'Home',
    'booking-management.php'  => 'Booking',
    'packages-management.php' => 'Packages',
    'schedule-class.php'      => 'Schedule & Class',
    'instructor-list.php'     => 'Instructors',
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
                    <a class="site-header__avatar" href="<?= header_e($navbar_profile_href ?? $defaultProfileHref) ?>" aria-label="My profile">
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

        <?php if (!empty($userSession)): ?>
            <script>
                (function () {
                    function updateNavbarAvatar(src) {
                        if (!src) return;
                        document.querySelectorAll('.site-header__avatar').forEach(function (link) {
                            var img = link.querySelector('img');
                            if (img) {
                                img.src = src;
                            } else {
                                var icon = link.querySelector('i');
                                var newImg = document.createElement('img');
                                newImg.src = src;
                                newImg.alt = 'Profile';
                                if (icon) {
                                    icon.replaceWith(newImg);
                                } else {
                                    link.appendChild(newImg);
                                }
                            }
                        });
                    }

                    if ('BroadcastChannel' in window) {
                        var ch = new BroadcastChannel('sooma_avatar_sync');
                        ch.addEventListener('message', function (e) {
                            if (e.data && e.data.avatar_url) updateNavbarAvatar(e.data.avatar_url);
                        });
                    }

                    window.addEventListener('storage', function (e) {
                        if (e.key === 'sooma_avatar_sync' && e.newValue) {
                            try {
                                var payload = JSON.parse(e.newValue);
                                if (payload.avatar_url) updateNavbarAvatar(payload.avatar_url);
                            } catch (_) {}
                        }
                    });
                })();
            </script>
        <?php endif; ?>
    <?php endif; ?>