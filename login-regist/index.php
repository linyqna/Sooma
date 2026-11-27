<?php
session_start();
require_once __DIR__ . '/connection.php';

$page = $_GET['page'] ?? 'login';
$allowedPages = ['login', 'register', 'forgot_password', 'reset_link_sent', 'reset_password'];

if (!in_array($page, $allowedPages, true)) {
    $page = 'login';
}

$isRegister = $page === 'register';
$isForgot   = $page === 'forgot_password';
$isLinkSent = $page === 'reset_link_sent';
$isReset    = $page === 'reset_password';

$titles = [
    'login'           => 'Welcome Back',
    'register'        => 'Create an Account',
    'forgot_password' => 'Forgot Password',
    'reset_link_sent' => 'Check Your Gmail',
    'reset_password'  => 'Set New Password',
];
$pageTitle = $titles[$page];

$error      = $_GET['error'] ?? '';
$success    = $_GET['success'] ?? '';
$isResent   = ($_GET['resent'] ?? '') === '1';
$token      = trim($_GET['token'] ?? '');
$resetEmail = $_SESSION['reset_email'] ?? '';

if ($isLinkSent && $resetEmail === '') {
    header('Location: index.php?page=forgot_password');
    exit;
}

$isValidResetToken = false;
$verifiedResetId   = (int) ($_SESSION['verified_reset_id'] ?? 0);

if ($isReset) {
    try {
        if ($token !== '' && ctype_xdigit($token) && strlen($token) === 64) {
            $tokenHash = hash('sha256', $token);
            $stmt = $pdo->prepare(
                "select reset_id
                 from sooma_wellness.password_resets
                 where token_hash = :token_hash
                   and used_at is null
                   and expires_at > now()
                 limit 1"
            );
            $stmt->execute([':token_hash' => $tokenHash]);
            $resetId = (int) $stmt->fetchColumn();

            if ($resetId > 0) {
                $isValidResetToken = true;
                $_SESSION['verified_reset_id'] = $resetId;

                // Tandai link sudah diklik agar tab awal otomatis auto-refresh/redirect
                $verifyStmt = $pdo->prepare(
                    "update sooma_wellness.password_resets
                     set verified_at = coalesce(verified_at, now())
                     where reset_id = :reset_id"
                );
                $verifyStmt->execute([':reset_id' => $resetId]);
            }
        } elseif ($verifiedResetId > 0) {
            $stmt = $pdo->prepare(
                "select reset_id
                 from sooma_wellness.password_resets
                 where reset_id = :reset_id
                   and verified_at is not null
                   and used_at is null
                   and expires_at > now()
                 limit 1"
            );
            $stmt->execute([':reset_id' => $verifiedResetId]);
            $isValidResetToken = (bool) $stmt->fetchColumn();
        }
    } catch (PDOException $e) {
        error_log('TOKEN CHECK ERROR: ' . $e->getMessage());
        $isValidResetToken = false;
    }
}

$contentClassMap = [
    'login'           => 'login-content',
    'register'        => 'register-content',
    'forgot_password' => 'forgot-content',
    'reset_link_sent' => 'link-sent-content',
    'reset_password'  => 'reset-content',
];
$formContentClass = $contentClassMap[$page];

$hideChrome = true;
$bodyClass  = 'auth-page';

require dirname(__DIR__) . '/includes/header.php';
?>

<main class="auth-layout">
    <section class="visual-panel" aria-label="Sooma Wellness introduction">
        <a class="brand" href="index.php?page=login" aria-label="Sooma Wellness home">
            <span class="brand-name"></span>
            <span class="brand-subtitle"></span>
        </a>

        <div class="visual-copy">
            <?php if ($isRegister): ?>
                <h2>BEGIN YOUR<br>WELLNESS JOURNEY.</h2>
                <p>Join Sooma Wellness to easily book Pilates and yoga classes, enjoy exclusive promotions, and track your workout plans—all in one place.</p>
            <?php else: ?>
                <h2>YOUR MINDFUL<br>JOURNEY CONTINUES.</h2>
                <p>Log in to manage your class schedule, check your remaining sessions, and continue your journey toward a more balanced body.</p>
            <?php endif; ?>
        </div>
    </section>

    <section class="form-panel">
        <div class="form-content <?= $formContentClass ?>">

            <?php if ($isRegister): ?>

                <h1>Create an Account</h1>
                <p class="subtitle">
                    Create your account to start a more integrated<br class="desktop-break">
                    training experience.
                </p>

                <?php if ($error === 'email_exists'): ?>
                    <p class="error-message">This email is already registered.</p>
                <?php elseif ($error === 'invalid'): ?>
                    <p class="error-message">Please check your registration details.</p>
                <?php elseif ($error === 'server'): ?>
                    <p class="error-message">Something went wrong. Please try again later.</p>
                <?php endif; ?>

                <form action="auth/register.php" method="post">
                    <div class="field">
                        <label for="full-name">Full Name</label>
                        <input id="full-name" name="full_name" type="text" placeholder="Enter your full name" autocomplete="name" required>
                    </div>
                    <div class="field">
                        <label for="register-email">Email</label>
                        <input id="register-email" name="email" type="email" placeholder="Enter your email" autocomplete="email" required>
                    </div>
                    <div class="field">
                        <label for="phone">Phone Number</label>
                        <input id="phone" name="phone" type="tel" placeholder="Enter your phone number" autocomplete="tel" required>
                    </div>
                    <div class="field">
                        <label for="register-password">Password</label>
                        <div class="password-input-wrap">
                            <input id="register-password" name="password" type="password" placeholder="Min. 8 Character" minlength="8" autocomplete="new-password" required>
                            <button type="button" class="password-toggle-btn" aria-label="Show password" aria-pressed="false">
                                <i class="ph ph-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <div class="field">
                        <label for="register-password2">Re-enter Password</label>
                        <div class="password-input-wrap">
                            <input id="register-password2" name="password_confirm" type="password" placeholder="Re-enter password" minlength="8" autocomplete="new-password" required>
                            <button type="button" class="password-toggle-btn" aria-label="Show password" aria-pressed="false">
                                <i class="ph ph-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <label class="check-row terms-row">
                        <input type="checkbox" name="terms" required>
                        <span>
                            I agree to Sooma Wellness's Terms &amp; Conditions
                            and Privacy Policy.
                        </span>
                    </label>

                    <button class="primary-button" type="submit">
                        Sign up
                    </button>
                </form>

                <div class="separator"></div>

                <button class="google-button" type="button">
                    <span class="google-mark">G</span>
                    Or sign up with Google
                </button>

                <p class="switch-copy">
                    Already have an account?
                    <a href="index.php?page=login">Sign in now</a>
                </p>

            <?php elseif ($isForgot): ?>

                <h1>Forgot Password ?</h1>
                <p class="subtitle">
                    Your wellness journey awaits. Enter your email to reset your password.
                </p>

                <?php if ($error === 'invalid_email'): ?>
                    <p class="error-message">Please enter a valid email address.</p>
                <?php elseif ($error === 'mail_failed'): ?>
                    <p class="error-message">
                        Failed to send reset email. Please try again later.
                        <?php if (!empty($_SESSION['mail_debug_error'])): ?>
                            <br><small><strong>Debug:</strong> <?= htmlspecialchars($_SESSION['mail_debug_error'], ENT_QUOTES, 'UTF-8') ?></small>
                            <?php unset($_SESSION['mail_debug_error']); ?>
                        <?php endif; ?>
                    </p>
                <?php elseif ($error === 'server'): ?>
                    <p class="error-message">Something went wrong. Please try again later.</p>
                <?php endif; ?>

                <form action="auth/forgot-password.php" method="post" data-submit-loading="Sending link...">
                    <div class="field">
                        <label for="forgot-email">Email</label>
                        <input id="forgot-email" name="email" type="email" placeholder="Enter your email" autocomplete="email" required>
                    </div>

                    <button class="primary-button" type="submit">
                        Send Reset Link
                    </button>
                </form>

                <a href="index.php?page=login" class="back-login-link">
                    <i class="ph ph-arrow-left" aria-hidden="true"></i>
                    <span>Back to login</span>
                </a>

            <?php elseif ($isLinkSent): ?>

                <h1>Check Your Gmail</h1>
                <p class="subtitle">
                    The link is already sent, check your Gmail to verify.
                </p>

                <?php if ($error === 'mail_failed'): ?>
                    <p class="error-message">
                        Failed to resend email. Please try again in a moment.
                        <?php if (!empty($_SESSION['mail_debug_error'])): ?>
                            <br><small><strong>Debug:</strong> <?= htmlspecialchars($_SESSION['mail_debug_error'], ENT_QUOTES, 'UTF-8') ?></small>
                            <?php unset($_SESSION['mail_debug_error']); ?>
                        <?php endif; ?>
                    </p>
                <?php elseif ($error === 'server'): ?>
                    <p class="error-message">Something went wrong. Please try again later.</p>
                <?php elseif ($isResent): ?>
                    <p class="success-message">A new reset link has been sent to your Gmail.</p>
                <?php endif; ?>

                <div id="reset-poll-box" class="verification-status-card" data-poll-url="auth/check-reset-status.php">
                    <div class="status-email-row">
                        <i class="ph ph-envelope-simple" aria-hidden="true"></i>
                        <span>Sent to <strong><?= htmlspecialchars($resetEmail, ENT_QUOTES, 'UTF-8') ?></strong></span>
                    </div>
                    <div class="status-live-row">
                        <span class="pulse-dot" aria-hidden="true"></span>
                        <span id="poll-status-text">Waiting for verification... Auto-redirecting once clicked.</span>
                    </div>
                </div>

                <form action="auth/forgot-password.php" method="post" data-submit-loading="Sending link back...">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($resetEmail, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="resend" value="1">
                    <button id="resend-link-btn" class="primary-button" type="submit">
                        Send link back
                    </button>
                </form>

                <p class="change-email-copy">
                    Wrong email? <a href="index.php?page=forgot_password">Use another email</a>
                </p>

                <a href="index.php?page=login" class="back-login-link">
                    <i class="ph ph-arrow-left" aria-hidden="true"></i>
                    <span>Back to login</span>
                </a>

            <?php elseif ($isReset): ?>

                <h1>Set New Password</h1>
                <p class="subtitle">
                    Please enter your new password below.
                </p>

                <?php if (!$isValidResetToken): ?>

                    <p class="error-message">
                        This password reset link is invalid or has expired. Please request a new one.
                    </p>

                    <a href="index.php?page=forgot_password" class="primary-button button-link">
                        Request New Reset Link
                    </a>

                    <a href="index.php?page=login" class="back-login-link">
                        <i class="ph ph-arrow-left" aria-hidden="true"></i>
                        <span>Back to login</span>
                    </a>

                <?php else: ?>

                    <?php if ($error === 'mismatch'): ?>
                        <p class="error-message">Passwords do not match. Please try again.</p>
                    <?php elseif ($error === 'weak'): ?>
                        <p class="error-message">Password must be at least 8 characters long.</p>
                    <?php elseif ($error === 'expired'): ?>
                        <p class="error-message">Reset link has expired. Please request a new link.</p>
                    <?php elseif ($error === 'server'): ?>
                        <p class="error-message">Something went wrong. Please try again later.</p>
                    <?php endif; ?>

                    <p id="client-reset-error" class="error-message is-hidden" role="alert"></p>

                    <form id="reset-password-form" action="auth/reset-password.php" method="post" data-submit-loading="Resetting...">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

                        <div class="field">
                            <label for="new-password">New Password</label>
                            <div class="password-input-wrap">
                                <input id="new-password" name="password" type="password" placeholder="Min. 8 Character" minlength="8" autocomplete="new-password" required>
                                <button type="button" class="password-toggle-btn" aria-label="Show password" aria-pressed="false">
                                    <i class="ph ph-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <div class="field">
                            <label for="confirm-password">Confirm Password</label>
                            <div class="password-input-wrap">
                                <input id="confirm-password" name="password_confirm" type="password" placeholder="Re-enter your new password" minlength="8" autocomplete="new-password" required>
                                <button type="button" class="password-toggle-btn" aria-label="Show password" aria-pressed="false">
                                    <i class="ph ph-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <button class="primary-button" type="submit">
                            Reset Password
                        </button>
                    </form>

                    <a href="index.php?page=login" class="back-login-link">
                        <i class="ph ph-arrow-left" aria-hidden="true"></i>
                        <span>Back to login</span>
                    </a>

                <?php endif; ?>

            <?php else: ?>

                <h1>Welcome Back !</h1>
                <p class="subtitle">
                    Welcome back! Please enter your details.
                </p>

                <?php if ($error === 'invalid'): ?>
                    <p class="error-message">Invalid email or password.</p>
                <?php elseif ($error === 'server'): ?>
                    <p class="error-message">Something went wrong. Please try again later.</p>
                <?php endif; ?>

                <?php if ($success === 'registered'): ?>
                    <p class="success-message">Account created successfully. Please sign in.</p>
                <?php elseif ($success === 'password_reset'): ?>
                    <p class="success-message">Your password has been reset successfully. Please sign in.</p>
                <?php endif; ?>

                <form action="auth/login.php" method="post">
                    <div class="field">
                        <label for="login-email">Email</label>
                        <input id="login-email" name="email" type="email" placeholder="Enter your email" autocomplete="email" required>
                    </div>

                    <div class="field">
                        <label for="login-password">Password</label>
                        <div class="password-input-wrap">
                            <input id="login-password" name="password" type="password" placeholder="Enter your password" autocomplete="current-password" required>
                            <button type="button" class="password-toggle-btn" aria-label="Show password" aria-pressed="false">
                                <i class="ph ph-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <div class="options-row">
                        <label class="check-row">
                            <input type="checkbox" name="remember">
                            <span>Remember me</span>
                        </label>

                        <a href="index.php?page=forgot_password" class="forgot-link">
                            Forgot password?
                        </a>
                    </div>

                    <button class="primary-button" type="submit">
                        Sign in
                    </button>
                </form>

                <div class="separator"></div>

                <button class="google-button" type="button">
                    <span class="google-mark">G</span>
                    Or sign in with Google
                </button>

                <p class="switch-copy">
                    Dont have an account?
                    <a href="index.php?page=register">Sign up now</a>
                </p>

            <?php endif; ?>

        </div>
    </section>
</main>

<script src="../assets/js/auth.js"></script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>