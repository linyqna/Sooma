<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../login-regist/connection.php';

if (empty($_SESSION['user'])) {
    header('Location: ../login.php');
    exit;
}

$userId = $_SESSION['user']['user_id'] ?? $_SESSION['user']['id'] ?? null;
if (!$userId) {
    header('Location: ../login.php');
    exit;
}

$headerType = 'instructor';
$headerLogo = 'black';
$basePath   = '../assets/';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function format_date(?string $iso): string
{
    $ts = $iso ? strtotime($iso) : false;
    return $ts ? date('j F Y', $ts) : '';
}

$flash_success = $_SESSION['profile_flash_success'] ?? '';
$flash_error   = $_SESSION['profile_flash_error'] ?? '';
unset($_SESSION['profile_flash_success'], $_SESSION['profile_flash_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $_SESSION['profile_flash_error'] = 'Invalid session token. Please try again.';
        header('Location: profile-instructor.php');
        exit;
    }

    $action = $_POST['action'];

    if ($action === 'update_account' && $userId) {
        $field = $_POST['field'] ?? '';

        if ($field === 'email') {
            $newEmail = trim((string) ($_POST['email'] ?? ''));
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['profile_flash_error'] = 'Please enter a valid email address.';
            } else {
                $stmt = $pdo->prepare("UPDATE sooma_wellness.users SET email = :email WHERE user_id = :id");
                $stmt->execute(['email' => $newEmail, 'id' => $userId]);

                $_SESSION['user']['email'] = $newEmail;
                $_SESSION['profile_flash_success'] = 'Email address updated successfully.';
            }
        } elseif ($field === 'phone_number') {
            $newPhone = trim((string) ($_POST['phone_number'] ?? ''));
            if (!preg_match('/^\+?[0-9\s\-]{8,20}$/', $newPhone)) {
                $_SESSION['profile_flash_error'] = 'Please enter a valid phone number.';
            } else {
                $stmt = $pdo->prepare("UPDATE sooma_wellness.users SET phone_number = :phone WHERE user_id = :id");
                $stmt->execute(['phone' => $newPhone, 'id' => $userId]);

                $_SESSION['user']['phone_number'] = $newPhone;
                $_SESSION['profile_flash_success'] = 'Phone number updated successfully.';
            }
        } elseif ($field === 'password') {
            $newPass = (string) ($_POST['password'] ?? '');
            if (strlen($newPass) < 6) {
                $_SESSION['profile_flash_error'] = 'Password must be at least 6 characters.';
            } else {
                $hash = password_hash($newPass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE sooma_wellness.users SET password = :hash WHERE user_id = :id");
                $stmt->execute(['hash' => $hash, 'id' => $userId]);

                $_SESSION['profile_flash_success'] = 'Password updated successfully.';
            }
        }
    }

    if ($action === 'update_personal' && $userId) {
        $name    = trim((string) ($_POST['name'] ?? ''));
        $dob     = trim((string) ($_POST['dob'] ?? ''));
        $gender  = trim((string) ($_POST['gender'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));

        if ($name === '') {
            $_SESSION['profile_flash_error'] = 'Name cannot be empty.';
        } else {
            $stmt = $pdo->prepare("UPDATE sooma_wellness.users SET name = :name, dob = :dob, gender = :gender, address = :address WHERE user_id = :id");
            $stmt->execute([
                'name'    => $name,
                'dob'     => $dob !== '' ? $dob : null,
                'gender'  => $gender !== '' ? $gender : null,
                'address' => $address !== '' ? $address : null,
                'id'      => $userId,
            ]);

            $_SESSION['user']['name']    = $name;
            $_SESSION['user']['dob']     = $dob;
            $_SESSION['user']['gender']  = $gender;
            $_SESSION['user']['address'] = $address;
            $_SESSION['profile_flash_success'] = 'Personal information updated successfully.';
        }
    }

    if ($action === 'update_avatar' && isset($_FILES['avatar']) && $userId) {
        $file = $_FILES['avatar'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];

            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
            } else {
                $imgInfo = @getimagesize($file['tmp_name']);
                $mime    = $imgInfo['mime'] ?? '';
            }

            if (!in_array($mime, $allowedTypes, true)) {
                $_SESSION['profile_flash_error'] = 'Only JPG, PNG, or WEBP images are allowed.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $_SESSION['profile_flash_error'] = 'Image size must be less than 2MB.';
            } else {
                $binaryData = file_get_contents($file['tmp_name']);

                if (function_exists('imagecreatefromstring')) {
                    $srcImg = @imagecreatefromstring($binaryData);
                    if ($srcImg !== false) {
                        $origW  = imagesx($srcImg);
                        $origH  = imagesy($srcImg);
                        $maxDim = 150;

                        if ($origW > $maxDim || $origH > $maxDim) {
                            $ratio  = min($maxDim / $origW, $maxDim / $origH);
                            $newW   = (int) round($origW * $ratio);
                            $newH   = (int) round($origH * $ratio);
                            $dstImg = imagecreatetruecolor($newW, $newH);
                            imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
                            imagedestroy($srcImg);
                            $srcImg = $dstImg;
                        }

                        ob_start();
                        imagejpeg($srcImg, null, 70);
                        $binaryData = ob_get_clean();
                        imagedestroy($srcImg);
                        $mime = 'image/jpeg';
                    }
                }

                $base64DataUri = 'data:' . $mime . ';base64,' . base64_encode($binaryData);

                $stmt = $pdo->prepare("UPDATE sooma_wellness.users SET avatar_url = :avatar WHERE user_id = :id");
                $stmt->execute(['avatar' => $base64DataUri, 'id' => $userId]);

                $_SESSION['user']['avatar_url'] = $base64DataUri;
                $_SESSION['profile_flash_success'] = 'Profile photo updated successfully.';
            }
        }
    }

    header('Location: profile-instructor.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT u.*, i.photo AS instructor_photo
    FROM sooma_wellness.users u
    LEFT JOIN sooma_wellness.instructors i ON u.user_id = i.user_id
    WHERE u.user_id = :id
");
$stmt->execute(['id' => $userId]);
$dbUser = $stmt->fetch(PDO::FETCH_ASSOC);

if ($dbUser) {
    $_SESSION['user'] = array_merge($_SESSION['user'], $dbUser);
}
$user = $_SESSION['user'];

$avatarUrl = trim((string) ($user['avatar_url'] ?: $user['instructor_photo'] ?? ''));
if ($avatarUrl !== '' && !str_starts_with($avatarUrl, 'data:') && !str_starts_with($avatarUrl, 'http') && !str_starts_with($avatarUrl, './') && !str_starts_with($avatarUrl, '../')) {
    $avatarUrl = '../admin/assets/images/' . ltrim($avatarUrl, '/');
}

$account_fields = [
    [
        'key'   => 'email',
        'label' => 'Email address',
        'value' => $user['email'] ?? '',
        'type'  => 'email',
        'raw'   => $user['email'] ?? '',
    ],
    [
        'key'   => 'phone_number',
        'label' => 'Phone number',
        'value' => $user['phone_number'] ?? '',
        'type'  => 'tel',
        'raw'   => $user['phone_number'] ?? '',
    ],
    [
        'key'   => 'password',
        'label' => 'Account Password',
        'value' => '••••••••',
        'type'  => 'password',
        'raw'   => '',
    ],
];

$dobFormatted = format_date($user['dob'] ?? '');
$personal_fields = [
    [
        'label'    => 'Name',
        'value'    => trim((string) ($user['name'] ?? '')),
        'is_empty' => trim((string) ($user['name'] ?? '')) === '',
    ],
    [
        'label'    => 'Date of Birth',
        'value'    => $dobFormatted,
        'is_empty' => $dobFormatted === '',
    ],
    [
        'label'    => 'Gender',
        'value'    => trim((string) ($user['gender'] ?? '')),
        'is_empty' => trim((string) ($user['gender'] ?? '')) === '',
    ],
    [
        'label'    => 'Address',
        'value'    => trim((string) ($user['address'] ?? '')),
        'is_empty' => trim((string) ($user['address'] ?? '')) === '',
    ],
];

$current_page        = basename($_SERVER['SCRIPT_NAME'] ?? 'profile-instructor.php');
$navbar_avatar       = $avatarUrl;
$navbar_profile_href = 'profile-instructor.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instructor Profile — Sooma Wellness</title>
    <meta name="robots" content="noindex">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Caladea:wght@400&family=Montserrat:wght@400;500;600;700&family=Montserrat+Alternates:wght@400&family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="stylesheet" href="../assets/css/header.css">
    <link rel="stylesheet" href="./assets/css/profile-instructor.css">
    <link rel="stylesheet" href="../assets/css/footer.css">
</head>

<body>

    <?php
    $nav_current_backup = $current_page;
    $current_page = 'profile';
    include __DIR__ . '/../includes/header.php';
    $current_page = $nav_current_backup;
    ?>

    <main class="profile-page">

        <?php if ($flash_success !== ''): ?>
            <div class="profile-alert profile-alert--success" role="status">
                <i class="ph-fill ph-check-circle" aria-hidden="true"></i>
                <span><?= e($flash_success) ?></span>
            </div>
        <?php elseif ($flash_error !== ''): ?>
            <div class="profile-alert profile-alert--error" role="alert">
                <i class="ph-fill ph-warning-circle" aria-hidden="true"></i>
                <span><?= e($flash_error) ?></span>
            </div>
        <?php endif; ?>

        <section class="profile-card" aria-labelledby="profile-name">
            <div class="profile-card__avatar-wrap">
                <div class="profile-card__avatar<?= $avatarUrl === '' ? ' is-empty' : '' ?>">
                    <?php if ($avatarUrl !== ''): ?>
                        <img src="<?= e($avatarUrl) ?>" alt="Photo of <?= e($user['name'] ?? 'Instructor') ?>" width="200" height="200">
                    <?php else: ?>
                        <i class="ph-fill ph-user" aria-hidden="true"></i>
                    <?php endif; ?>
                </div>

                <form method="POST" action="profile-instructor.php" enctype="multipart/form-data" id="avatarUploadForm">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="update_avatar">
                    <input type="file" name="avatar" id="avatarInput" accept="image/jpeg,image/png,image/webp" hidden>

                    <button type="button"
                        class="avatar-edit-btn"
                        id="btnEditAvatar"
                        title="Change profile photo"
                        aria-label="Change profile photo">
                        <i class="ph-fill ph-camera" aria-hidden="true"></i>
                    </button>
                </form>
            </div>

            <h1 class="profile-card__name" id="profile-name"><?= e($user['name'] ?? '') ?></h1>
            <p class="profile-card__joined">
                Joined since <time datetime="<?= e($user['joined_date'] ?? '') ?>"><?= e(format_date($user['joined_date'] ?? '')) ?></time>
            </p>

            <hr class="profile-card__divider">

            <dl class="account-grid">
                <?php foreach ($account_fields as $field): ?>
                    <div class="account-item" data-account-field="<?= e($field['key']) ?>">
                        <dt class="account-item__label"><?= e($field['label']) ?></dt>

                        <dd class="account-item__value account-view">
                            <span><?= e($field['value']) ?></span>
                            <button type="button"
                                class="btn-edit-pill js-edit-account"
                                aria-label="Edit <?= e(strtolower($field['label'])) ?>">
                                <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                Edit
                            </button>
                        </dd>

                        <dd class="account-item__edit account-form-wrap" hidden>
                            <form method="POST" action="profile-instructor.php" class="inline-account-form">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="update_account">
                                <input type="hidden" name="field" value="<?= e($field['key']) ?>">

                                <input type="<?= e($field['type']) ?>"
                                    name="<?= e($field['key']) ?>"
                                    class="inline-input"
                                    value="<?= e($field['raw']) ?>"
                                    placeholder="<?= $field['key'] === 'password' ? 'Enter new password' : '' ?>"
                                    required>

                                <div class="inline-actions">
                                    <button type="submit" class="btn-save-sm">Save</button>
                                    <button type="button" class="btn-cancel-sm js-cancel-account">Cancel</button>
                                </div>
                            </form>
                        </dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </section>

        <div class="profile-main profile-main--single">

            <section class="info-card" aria-labelledby="personal-title">
                <div class="info-card__header">
                    <h2 class="info-card__title" id="personal-title">Personal Information</h2>
                    <button type="button" class="btn-edit-pill" id="btnEditPersonal">
                        <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                        Edit
                    </button>
                </div>

                <dl class="info-list" id="personalViewMode">
                    <?php foreach ($personal_fields as $field): ?>
                        <div class="info-list__item">
                            <dt><?= e($field['label']) ?></dt>
                            <dd>
                                <?php if ($field['is_empty']): ?>
                                    <span class="field-warning" title="Please complete this information">
                                        <i class="ph-fill ph-warning-circle" aria-hidden="true"></i>
                                        <span>Not set yet</span>
                                    </span>
                                <?php else: ?>
                                    <?= e($field['value']) ?>
                                <?php endif; ?>
                            </dd>
                        </div>
                    <?php endforeach; ?>
                </dl>

                <form method="POST" action="profile-instructor.php" class="personal-edit-form" id="personalEditMode" hidden>
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="update_personal">

                    <div class="personal-form-grid">
                        <div class="personal-form-group">
                            <label for="inputName">Name</label>
                            <input type="text" id="inputName" name="name" class="inline-input"
                                value="<?= e($user['name'] ?? '') ?>" required>
                        </div>

                        <div class="personal-form-group">
                            <label for="inputDob">Date of Birth</label>
                            <input type="date" id="inputDob" name="dob" class="inline-input"
                                value="<?= e($user['dob'] ?? '') ?>">
                        </div>

                        <div class="personal-form-group">
                            <label for="inputGender">Gender</label>
                            <select id="inputGender" name="gender" class="inline-input">
                                <option value="" <?= ($user['gender'] ?? '') === '' ? 'selected' : '' ?>>-- Select Gender --</option>
                                <option value="Female" <?= ($user['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                                <option value="Male" <?= ($user['gender'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                            </select>
                        </div>

                        <div class="personal-form-group">
                            <label for="inputAddress">Address</label>
                            <input type="text" id="inputAddress" name="address" class="inline-input"
                                value="<?= e($user['address'] ?? '') ?>" placeholder="Enter your address">
                        </div>
                    </div>

                    <div class="personal-form-actions">
                        <button type="submit" class="btn-save-sm">Save Changes</button>
                        <button type="button" class="btn-cancel-sm" id="btnCancelPersonal">Cancel</button>
                    </div>
                </form>
            </section>

        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>

    <script src="./assets/js/profile-instructor.js" defer></script>

</body>

</html>