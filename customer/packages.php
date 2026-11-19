<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../login-regist/connection.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    try {
        $envPath = __DIR__ . '/../login-regist/.env';
        if (is_file($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v, " \t\n\r\0\x0B\"'");
                $_ENV[$k] = $v;
                putenv("$k=$v");
            }
        }

        $dbUrl = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: '';
        if ($dbUrl !== '') {
            $parts    = parse_url($dbUrl);
            $host     = $parts['host'] ?? '';
            $port     = $parts['port'] ?? 5432;
            $db       = ltrim($parts['path'] ?? '', '/');
            $user     = rawurldecode($parts['user'] ?? '');
            $pass     = rawurldecode($parts['pass'] ?? '');
            $endpoint = preg_replace('/-pooler$/', '', explode('.', $host)[0] ?? '');

            $dsn = "pgsql:host={$host};port={$port};dbname={$db};sslmode=require";
            if ($endpoint !== '' && str_starts_with($endpoint, 'ep-')) {
                $dsn .= ";options=endpoint%3D{$endpoint}";
            }

            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
    } catch (Throwable $ex) {
        error_log('[packages-db] ' . $ex->getMessage());
    }
}

$pageTitle  = 'Packages';
$bodyClass  = 'packages-page';
$headerType = 'customer';
$headerLogo = 'black';
$basePath   = '../assets/';

require_once __DIR__ . '/../includes/header.php';

function formatPackagePrice($price): string
{
    return 'Rp' . number_format((float) $price, 0, ',', '.');
}

function makeSlug(string $text): string
{
    return trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', strtolower($text)), '-');
}

function detectClassType(string $className, string $packageName): string
{
    $haystack = strtolower($className . ' ' . $packageName);
    return str_contains($haystack, 'yoga') ? 'Yoga' : 'Pilates';
}

function detectCategoryTitle(string $packageName, string $packageCode): string
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

$sectionMeta = [
    'Group Class' => [
        'title'       => 'Group Class',
        'description' => 'Group Class sessions attended by several participants at the same time.',
        'valid_for'   => '1 person',
        'capacity'    => [
            'Pilates class up to 4 random participants',
            'Yoga class up to 10 random participants',
        ],
        'booking'     => 'Drop in class',
        'packages'    => [],
    ],
    'Private Class' => [
        'title'         => 'Private Class',
        'description'   => 'Private Class is an exclusive, one-on-one training session between one participant and one instructor.',
        'valid_for'     => '1 person',
        'capacity'      => [
            'Pilates class only 1 participants',
            'Yoga class only 1 participants',
        ],
        'booking'       => 'Drop in class',
        'extra_booking' => 'Request Private Class',
        'packages'      => [],
    ],
    'Private Group Class' => [
        'title'       => 'Private Group Class',
        'description' => 'Private Group Class is a class for groups where participants bring their own partners. This class requires a minimum participation by 2 people.',
        'valid_for'   => 'a group',
        'capacity'    => [
            'Pilates class at most 4 participants',
            'Yoga class at most 5 participants',
        ],
        'booking'     => 'Request Private Class',
        'packages'    => [],
    ],
];

$loadedFromDb = false;

if ($pdo instanceof PDO) {
    try {
        $stmt = $pdo->query("
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
        ");

        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        foreach ($rows as $row) {
            $categoryTitle = detectCategoryTitle((string) $row['package_name'], (string) $row['package_code']);
            $classType     = detectClassType((string) $row['class_name'], (string) $row['package_name']);
            $sessionLabel  = ((int) $row['total_sessions']) . 'x Session';

            $sectionMeta[$categoryTitle]['packages'][] = [
                'package_id'   => (int) $row['package_id'],
                'package_code' => (string) $row['package_code'],
                'name'         => (string) $row['class_name'],
                'package_name' => (string) $row['package_name'],
                'type'         => $classType,
                'sessions'     => $sessionLabel,
                'active_days'  => (int) $row['active_days'],
                'price'        => (float) $row['package_price'],
            ];
            $loadedFromDb = true;
        }
    } catch (Throwable $ex) {
        error_log('[packages] ' . $ex->getMessage());
    }
}


$packageSections = array_values(array_filter(
    $sectionMeta,
    static fn(array $sec): bool => !empty($sec['packages'])
));
?>

<link rel="stylesheet" href="../assets/css/packages.css">

<main class="packages-main">

    <section class="packages-heading">
        <h1>Packages</h1>
        <p>Bundled class packages for multi-session booking</p>
    </section>

    <section class="packages-filter">
        <h2>Filter</h2>

        <div class="filter-controls">
            <select id="categoryFilter" aria-label="Filter by category">
                <option value="all">Category</option>
                <option value="group-class">Group Class</option>
                <option value="private-class">Private Class</option>
                <option value="private-group-class">Private Group Class</option>
            </select>

            <select id="classTypeFilter" aria-label="Filter by class type">
                <option value="all">Class Type</option>
                <option value="pilates">Pilates</option>
                <option value="yoga">Yoga</option>
            </select>
        </div>
    </section>

    <div class="packages-sections" id="packagesSections">

        <?php foreach ($packageSections as $section): ?>
            <?php $categorySlug = makeSlug($section['title']); ?>

            <section class="package-section"
                data-section-category="<?= htmlspecialchars($categorySlug, ENT_QUOTES, 'UTF-8') ?>">

                <div class="package-section-info">
                    <h2><?= htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8') ?></h2>

                    <p class="section-description">
                        <?= htmlspecialchars($section['description'], ENT_QUOTES, 'UTF-8') ?>
                    </p>

                    <div class="package-section-details">

                        <div class="package-detail-item">
                            <strong>
                                Valid for <?= htmlspecialchars($section['valid_for'], ENT_QUOTES, 'UTF-8') ?>
                            </strong>
                        </div>

                        <div class="package-detail-item">
                            <strong>Capacity per session:</strong>

                            <?php foreach ($section['capacity'] as $capacity): ?>
                                <span><?= htmlspecialchars($capacity, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endforeach; ?>
                        </div>

                        <div class="package-detail-item">
                            <strong>Booking type:</strong>

                            <span><?= htmlspecialchars($section['booking'], ENT_QUOTES, 'UTF-8') ?></span>

                            <?php if (!empty($section['extra_booking'])): ?>
                                <span><?= htmlspecialchars($section['extra_booking'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="package-cards">
                    <?php foreach ($section['packages'] as $package): ?>
                        <?php
                        $pkgSlug = makeSlug($section['title'] . '-' . $package['name'] . '-' . $package['sessions']);
                        $queryParams = ['pkg' => $pkgSlug];
                        if (!empty($package['package_id'])) {
                            $queryParams['id'] = (int) $package['package_id'];
                        }
                        if (!empty($package['package_code'])) {
                            $queryParams['code'] = (string) $package['package_code'];
                        }
                        $purchaseHref = 'packages-purchase.php?' . http_build_query($queryParams);
                        ?>
                        <article class="package-card"
                            data-class-type="<?= htmlspecialchars(strtolower($package['type']), ENT_QUOTES, 'UTF-8') ?>">

                            <div class="package-card-top">
                                <span class="package-type">
                                    <?= htmlspecialchars($package['type'], ENT_QUOTES, 'UTF-8') ?>
                                </span>

                                <h3><?= htmlspecialchars($package['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <p><?= htmlspecialchars($package['sessions'], ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="package-price">
                                    <em><?= formatPackagePrice($package['price']) ?></em>
                                    <?php if ($categorySlug === 'private-group-class'): ?>
                                        <span class="price-unit">/ 1 person</span>
                                    <?php endif; ?>
                                </p>
                            </div>

                            <div class="package-card-benefits">
                                <div class="benefit-item">
                                    <img src="../assets/images/hand-coins.svg" alt="" class="benefit-icon">
                                    <span>One-time payment</span>
                                </div>

                                <div class="benefit-item">
                                    <img src="../assets/images/seal-warning.svg" alt="" class="benefit-icon">
                                    <span>Non-refundable</span>
                                </div>

                                <div class="benefit-item">
                                    <img src="../assets/images/clockwise.svg" alt="" class="benefit-icon">
                                    <span>One-time rescheduling</span>
                                </div>

                                <div class="benefit-item">
                                    <img src="../assets/images/seal-check.svg" alt="" class="benefit-icon">
                                    <span>Valid from first session (<?= (int) ($package['active_days'] ?? 30) ?> days)</span>
                                </div>
                            </div>

                            <div class="package-card-action">
                                <a href="<?= htmlspecialchars($purchaseHref, ENT_QUOTES, 'UTF-8') ?>" class="purchase-button">
                                    Purchase
                                </a>
                            </div>

                        </article>
                    <?php endforeach; ?>
                </div>

            </section>
        <?php endforeach; ?>

        <p class="no-packages-message" id="noPackagesMessage" hidden>
            No packages found for the selected filters.
        </p>

    </div>
</main>

<script src="../assets/js/packages.js"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>