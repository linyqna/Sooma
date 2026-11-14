<?php
$pageTitle = 'Packages';
$bodyClass = 'packages-page';
$headerType = 'customer';
$headerLogo = 'black';
$basePath = '../assets/';

require_once __DIR__ . '/../includes/header.php';

$packageSections = [
    [
        'title' => 'Group Class',
        'description' => 'Group Class sessions attended by several participants at the same time.',
        'valid_for' => '1 person',
        'capacity' => [
            'Pilates class up to 4 random participants',
            'Yoga class up to 10 random participants'
        ],
        'booking' => 'Drop in class',
        'packages' => [
            ['name' => 'Reformer Pilates', 'type' => 'Pilates', 'sessions' => '3x Session', 'price' => 250000],
            ['name' => 'Reformer Pilates', 'type' => 'Pilates', 'sessions' => '5x Session', 'price' => 750000],
            ['name' => 'Reformer Pilates', 'type' => 'Pilates', 'sessions' => '10x Session', 'price' => 1250000],
        ]
    ],
    [
        'title' => 'Private Class',
        'description' => 'Private Class is an exclusive, one-on-one training session between one participant and one instructor.',
        'valid_for' => '1 person',
        'capacity' => [
            'Pilates class only 1 participants',
            'Yoga class only 1 participants'
        ],
        'booking' => 'Drop in class',
        'extra_booking' => 'Request Private Class',
        'packages' => [
            ['name' => 'Regular Yoga', 'type' => 'Yoga', 'sessions' => '4x Session', 'price' => 200000],
            ['name' => 'Reformer Pilates', 'type' => 'Pilates', 'sessions' => '5x Session', 'price' => 1650000],
            ['name' => 'Reformer Pilates', 'type' => 'Pilates', 'sessions' => '10x Session', 'price' => 3250000],
            ['name' => 'Prenatal Yoga', 'type' => 'Yoga', 'sessions' => '1x Session', 'price' => 250000],
        ]
    ],
    [
        'title' => 'Private Group Class',
        'description' => 'Private Group Class is a class for groups where participants bring their own partners. This class requires a minimum participation by 2 people.',
        'valid_for' => 'a group',
        'capacity' => [
            'Pilates class at most 4 participants',
            'Yoga class at most 5 participants'
        ],
        'booking' => 'Request Private Class',
        'packages' => [
            ['name' => 'Reformer Pilates', 'type' => 'Pilates', 'sessions' => '5x Session', 'price' => 550000],
            ['name' => 'Reformer Pilates', 'type' => 'Pilates', 'sessions' => '10x Session', 'price' => 900000],
            ['name' => 'Prenatal Yoga', 'type' => 'Yoga', 'sessions' => '1x Session', 'price' => 400000],
        ]
    ],
];

function formatPackagePrice($price)
{
    return 'Rp' . number_format($price, 0, ',', '.');
}

function makeSlug($text)
{
    return trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $text)), '-');
}
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

        <?php foreach ($packageSections as $sectionIndex => $section): ?>
            <?php
            $categorySlug = makeSlug($section['title']);
            ?>

            <section class="package-section"
                data-section-category="<?= htmlspecialchars($categorySlug) ?>">

                <div class="package-section-info">
                    <h2><?= htmlspecialchars($section['title']) ?></h2>

                    <p class="section-description">
                        <?= htmlspecialchars($section['description']) ?>
                    </p>

                    <div class="package-section-details">

                        <div class="package-detail-item">
                            <strong>
                                Valid for <?= htmlspecialchars($section['valid_for']) ?>
                            </strong>
                        </div>

                        <div class="package-detail-item">
                            <strong>Capacity per session:</strong>

                            <?php foreach ($section['capacity'] as $capacity): ?>
                                <span><?= htmlspecialchars($capacity) ?></span>
                            <?php endforeach; ?>
                        </div>

                        <div class="package-detail-item">
                            <strong>Booking type:</strong>

                            <span><?= htmlspecialchars($section['booking']) ?></span>

                            <?php if (!empty($section['extra_booking'])): ?>
                                <span><?= htmlspecialchars($section['extra_booking']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="package-cards">
                    <?php foreach ($section['packages'] as $package): ?>
                        <?php
                        $pkgSlug = makeSlug($section['title'] . '-' . $package['name'] . '-' . $package['sessions']);
                        ?>
                        <article class="package-card"
                            data-class-type="<?= strtolower($package['type']) ?>">

                            <div class="package-card-top">
                                <span class="package-type">
                                    <?= htmlspecialchars($package['type']) ?>
                                </span>

                                <h3><?= htmlspecialchars($package['name']) ?></h3>
                                <p><?= htmlspecialchars($package['sessions']) ?></p>
                                <p class="package-price">
                                    <em><?= formatPackagePrice($package['price']) ?></em>
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
                                    <span>Valid from first session</span>
                                </div>
                            </div>

                            <div class="package-card-action">
                                <a href="packages-purchase.php?pkg=<?= urlencode($pkgSlug) ?>" class="purchase-button">
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