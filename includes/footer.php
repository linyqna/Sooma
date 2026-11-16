<?php 
$hideChrome = $hideChrome ?? false; 
$footerType = strtolower((string) ($footerType ?? $headerType ?? $_SESSION['user']['role_name'] ?? $_SESSION['role'] ?? 'customer'));
$assetBase  = $assetBase ?? (file_exists('assets/css/footer.css') ? 'assets/' : '../assets/');

$footerLogoHref = match ($footerType) {
    'admin'      => 'admin-dashboard.php',
    'instructor' => 'dashboard_instructor.php',
    default      => 'homepage.php',
};

$footerMenus = match ($footerType) {
    'admin' => [
        'admin-dashboard.php'    => 'Home',
        'schedule-class.php'     => 'Schedule & Class',
        'booking-management.php' => 'Booking',
        'instructors.php'        => 'Instructors',
    ],
    'instructor' => [
        'dashboard_instructor.php' => 'Home',
    ],
    default => [
        'homepage.php'   => 'Home',
        'about-us.php'   => 'About Us',
        'class.php'      => 'Class',
        'packages.php'   => 'Package',
        'timetable.php'  => 'Timetable',
    ],
};
?>

<?php if (!$hideChrome): ?>
    <footer class="site-footer site-footer--<?= htmlspecialchars($footerType, ENT_QUOTES, 'UTF-8') ?>">
        <div class="footer-main">

            <div class="footer-brand">
                <a class="brand footer-logo" href="<?= htmlspecialchars($footerLogoHref, ENT_QUOTES, 'UTF-8') ?>">
                    <img src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>images/sooma-logo-white.png" alt="Sooma Wellness">
                </a>

                <p>
                    <strong>Location</strong><br>
                    Jl. Ciliwung no.62, Blitar
                </p>

                <p>
                    <strong>Operating Hours</strong><br>
                    Everyday | 07.00 - 19.00<br>
                </p>
            </div>

            <div class="footer-links">
                <h3>Quick Link</h3>
                <?php foreach ($footerMenus as $href => $label): ?>
                    <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($footerType === 'customer'): ?>
                <div class="footer-cta">
                    <h3>Ready To Start?</h3>
                    <p>Join our community and transform your body and mind into a healthy self.</p>
                    <a class="button button-light" href="packages.php">Book Class Now</a>
                </div>
            <?php endif; ?>

        </div>

        <div class="footer-bottom">
            <span>&copy; <?= date('Y') ?> Sooma Wellness. All rights reserved.</span>

            <div>
                <a href="#privacy">Privacy Policy</a>
                <a href="#terms">Terms of Service</a>
                <a href="#cookies">Cookie Settings</a>
            </div>
        </div>
    </footer>
<?php endif; ?>

</body>
</html>