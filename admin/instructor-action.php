<?php
declare(strict_types=1);

ini_set('display_errors', '0');

session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function ia_respond(bool $success, string $message, array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(
        ['success' => $success, 'message' => $message, 'data' => (object) $data],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

const IA_PHOTO_DIR       = __DIR__ . '/assets/images/';
const IA_PHOTO_URL_BASE  = './assets/images/';
const IA_MAX_PHOTO_BYTES = 2 * 1024 * 1024;
const IA_ALLOWED_STATUS  = ['active-full', 'active-part', 'inactive'];
const IA_ALLOWED_MIME    = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

if (
    !isset($_SESSION['user_id'])
    || strtolower(trim((string) ($_SESSION['role'] ?? ''))) !== 'admin'
) {
    ia_respond(false, 'Unauthorized. Please log in as admin.', [], 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    ia_respond(false, 'Method not allowed.', [], 405);
}

$sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
$postedToken  = (string) ($_POST['csrf_token'] ?? '');

if ($sessionToken === '' || !hash_equals($sessionToken, $postedToken)) {
    ia_respond(false, 'Invalid security token. Please refresh the page and try again.', [], 403);
}

require_once __DIR__ . '/../login-regist/connection.php';

function ia_clean_name(string $value): string
{
    $value = trim((string) preg_replace('/\s+/u', ' ', $value));
    return trim((string) preg_replace('/^coach\s+/iu', '', $value));
}

function ia_post_string(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function ia_validate_profile(): array
{
    $name       = ia_clean_name(ia_post_string('name'));
    $title      = ia_post_string('title');
    $speciality = ia_post_string('speciality');
    $whatsapp   = ia_post_string('whatsapp');
    $email      = strtolower(ia_post_string('email'));
    $status     = ia_post_string('status');
    $colorName  = ia_post_string('color_name');
    $colorHex   = ia_post_string('color_hex');

    if (
        $name === '' || $title === '' || $speciality === '' || $whatsapp === ''
        || $email === '' || $status === '' || $colorName === '' || $colorHex === ''
    ) {
        return [[], 'Please complete all required fields.'];
    }
    if (mb_strlen($name) > 60 || mb_strlen($title) > 100 || mb_strlen($speciality) > 80 || mb_strlen($colorName) > 24) {
        return [[], 'One or more fields are too long.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
        return [[], 'Enter a valid email address.'];
    }
    if (!preg_match('/^(\+62|62|0)[0-9]{8,13}$/', $whatsapp)) {
        return [[], 'Use an Indonesian WhatsApp number, e.g. 081234567890.'];
    }
    if (!in_array($status, IA_ALLOWED_STATUS, true)) {
        return [[], 'Invalid status.'];
    }
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $colorHex)) {
        return [[], 'Invalid color value.'];
    }

    return [[
        'name'       => $name,
        'title'      => $title,
        'speciality' => $speciality,
        'whatsapp'   => $whatsapp,
        'email'      => $email,
        'status'     => $status,
        'color_name' => $colorName,
        'color_hex'  => strtoupper($colorHex),
    ], null];
}

function ia_email_taken(PDO $pdo, string $email, ?int $exceptUserId = null): bool
{
    $sql    = 'SELECT 1 FROM sooma_wellness.users WHERE LOWER(email) = LOWER(:email)';
    $params = [':email' => $email];
    if ($exceptUserId !== null) {
        $sql .= ' AND user_id <> :uid';
        $params[':uid'] = $exceptUserId;
    }
    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

function ia_generate_coach_code(PDO $pdo): string
{
    $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $max     = strlen($letters) - 1;
    $stmt    = $pdo->prepare('SELECT 1 FROM sooma_wellness.instructors WHERE coach_code = :code LIMIT 1');

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $code = sprintf(
            'CO-%s%02d%s',
            $letters[random_int(0, $max)],
            random_int(0, 99),
            $letters[random_int(0, $max)]
        );
        $stmt->execute([':code' => $code]);
        if (!$stmt->fetchColumn()) {
            return $code;
        }
    }
    throw new RuntimeException('Unable to generate a unique coach code.');
}

function ia_validate_photo(): array
{
    if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
        return [null, null];
    }

    $file  = $_FILES['photo'];
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        return [null, 'Photo is too large. Maximum size is 2 MB.'];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return [null, 'Photo upload failed. Please try again.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return [null, 'Invalid photo upload.'];
    }
    if ((int) ($file['size'] ?? 0) > IA_MAX_PHOTO_BYTES || filesize($tmp) > IA_MAX_PHOTO_BYTES) {
        return [null, 'Photo is too large. Maximum size is 2 MB.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($tmp);
    if (!isset(IA_ALLOWED_MIME[$mime])) {
        return [null, 'Photo must be a JPG, PNG, or WebP image.'];
    }
    if (@getimagesize($tmp) === false) {
        return [null, 'The uploaded file is not a valid image.'];
    }

    return [['tmp' => $tmp, 'ext' => IA_ALLOWED_MIME[$mime]], null];
}

function ia_delete_photo_file(string $filename): void
{
    $filename = basename($filename);
    if ($filename === '' || $filename === '.' || $filename === '..') {
        return;
    }
    $path = IA_PHOTO_DIR . $filename;
    if (is_file($path)) {
        @unlink($path);
    }
}

function ia_find_instructor(PDO $pdo, string $coachCode, bool $lock = false): ?array
{
    $stmt = $pdo->prepare(
        'SELECT i.instructor_id, i.user_id, i.photo
         FROM sooma_wellness.instructors i
         WHERE i.coach_code = :code
         LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->execute([':code' => $coachCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

$action = ia_post_string('action');

switch ($action) {

    case 'create':
        [$profile, $error] = ia_validate_profile();
        if ($error !== null) {
            ia_respond(false, $error, [], 422);
        }

        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 6) {
            ia_respond(false, 'Password must be at least 6 characters.', [], 422);
        }

        [$photoInfo, $photoError] = ia_validate_photo();
        if ($photoError !== null) {
            ia_respond(false, $photoError, [], 422);
        }

        $savedPhotoPath = null;

        try {
            $pdo->beginTransaction();

            if (ia_email_taken($pdo, $profile['email'])) {
                $pdo->rollBack();
                ia_respond(false, 'This email is already registered.', [], 409);
            }

            $roleStmt = $pdo->prepare(
                "SELECT role_id FROM sooma_wellness.roles WHERE LOWER(role_name) = 'instructor' LIMIT 1"
            );
            $roleStmt->execute();
            $roleId = $roleStmt->fetchColumn();
            if ($roleId === false) {
                $pdo->rollBack();
                ia_respond(false, "Role 'instructor' not found. Run alter-instructors.sql first.", [], 500);
            }

            $coachCode = ia_generate_coach_code($pdo);

            $photoFilename = '';
            if ($photoInfo !== null) {
                if (!is_dir(IA_PHOTO_DIR) && !mkdir(IA_PHOTO_DIR, 0755, true) && !is_dir(IA_PHOTO_DIR)) {
                    throw new RuntimeException('Unable to create the upload directory.');
                }
                $photoFilename = 'coach-' . bin2hex(random_bytes(8)) . '.' . $photoInfo['ext'];
                $savedPhotoPath = IA_PHOTO_DIR . $photoFilename;
                if (!move_uploaded_file($photoInfo['tmp'], $savedPhotoPath)) {
                    $savedPhotoPath = null;
                    throw new RuntimeException('Unable to save the uploaded photo.');
                }
                @chmod($savedPhotoPath, 0644);
            }

            $userStmt = $pdo->prepare(
                'INSERT INTO sooma_wellness.users (role_id, name, email, password, phone_number)
                 VALUES (:role_id, :name, :email, :password, :phone)
                 RETURNING user_id'
            );
            $userStmt->execute([
                ':role_id'  => (int) $roleId,
                ':name'     => $profile['name'],
                ':email'    => $profile['email'],
                ':password' => password_hash($password, PASSWORD_DEFAULT),
                ':phone'    => $profile['whatsapp'],
            ]);
            $userId = (int) $userStmt->fetchColumn();

            $instStmt = $pdo->prepare(
                'INSERT INTO sooma_wellness.instructors
                    (user_id, specialization, coach_code, title, color_name, color_hex, status, photo)
                 VALUES
                    (:user_id, :specialization, :coach_code, :title, :color_name, :color_hex, :status, :photo)
                 RETURNING TO_CHAR(joined_date, \'YYYY-MM-DD\') AS joined'
            );
            $instStmt->execute([
                ':user_id'        => $userId,
                ':specialization' => $profile['speciality'],
                ':coach_code'     => $coachCode,
                ':title'          => $profile['title'],
                ':color_name'     => $profile['color_name'],
                ':color_hex'      => $profile['color_hex'],
                ':status'         => $profile['status'],
                ':photo'          => $photoFilename !== '' ? $photoFilename : null,
            ]);
            $joinedDate = (string) $instStmt->fetchColumn();

            $pdo->commit();

            ia_respond(true, 'Instructor added successfully', [
                'id'     => $coachCode,
                'joined' => $joinedDate,
                'photo'  => $photoFilename !== '' ? IA_PHOTO_URL_BASE . $photoFilename : '',
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($savedPhotoPath !== null && is_file($savedPhotoPath)) {
                @unlink($savedPhotoPath);
            }
            error_log('Instructor create failed: ' . $e->getMessage());

            if ($e->getCode() === '23505') {
                if (stripos($e->getMessage(), 'email') !== false) {
                    ia_respond(false, 'This email is already registered.', [], 409);
                }
                ia_respond(false, 'Could not generate a unique coach ID. Please try again.', [], 409);
            }
            ia_respond(false, 'Failed to add the instructor. Please try again.', [], 500);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($savedPhotoPath !== null && is_file($savedPhotoPath)) {
                @unlink($savedPhotoPath);
            }
            error_log('Instructor create failed: ' . $e->getMessage());
            ia_respond(false, 'Failed to add the instructor. Please try again.', [], 500);
        }
        break;

    case 'update':
        $coachCode = ia_post_string('coach_id');
        if ($coachCode === '') {
            ia_respond(false, 'Missing instructor ID.', [], 422);
        }

        [$profile, $error] = ia_validate_profile();
        if ($error !== null) {
            ia_respond(false, $error, [], 422);
        }

        $newPassword = (string) ($_POST['password'] ?? '');
        if ($newPassword !== '' && strlen($newPassword) < 6) {
            ia_respond(false, 'Password must be at least 6 characters.', [], 422);
        }

        try {
            $pdo->beginTransaction();

            $instructor = ia_find_instructor($pdo, $coachCode, true);
            if ($instructor === null) {
                $pdo->rollBack();
                ia_respond(false, 'Instructor not found.', [], 404);
            }
            $userId = (int) $instructor['user_id'];

            if (ia_email_taken($pdo, $profile['email'], $userId)) {
                $pdo->rollBack();
                ia_respond(false, 'This email is already used by another account.', [], 409);
            }

            $params = [
                ':name'    => $profile['name'],
                ':email'   => $profile['email'],
                ':phone'   => $profile['whatsapp'],
                ':user_id' => $userId,
            ];
            $sql = 'UPDATE sooma_wellness.users
                    SET name = :name, email = :email, phone_number = :phone';
            if ($newPassword !== '') {
                $sql .= ', password = :password';
                $params[':password'] = password_hash($newPassword, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE user_id = :user_id';
            $pdo->prepare($sql)->execute($params);

            $pdo->prepare(
                'UPDATE sooma_wellness.instructors
                 SET title = :title,
                     specialization = :specialization,
                     color_name = :color_name,
                     color_hex = :color_hex,
                     status = :status
                 WHERE instructor_id = :instructor_id'
            )->execute([
                ':title'          => $profile['title'],
                ':specialization' => $profile['speciality'],
                ':color_name'     => $profile['color_name'],
                ':color_hex'      => $profile['color_hex'],
                ':status'         => $profile['status'],
                ':instructor_id'  => (int) $instructor['instructor_id'],
            ]);

            $pdo->commit();

            ia_respond(true, 'Profile updated successfully');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Instructor update failed: ' . $e->getMessage());

            if ($e->getCode() === '23505') {
                ia_respond(false, 'This email is already used by another account.', [], 409);
            }
            ia_respond(false, 'Failed to update the profile. Please try again.', [], 500);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Instructor update failed: ' . $e->getMessage());
            ia_respond(false, 'Failed to update the profile. Please try again.', [], 500);
        }
        break;

    case 'delete':
        $coachCode = ia_post_string('coach_id');
        if ($coachCode === '') {
            ia_respond(false, 'Missing instructor ID.', [], 422);
        }

        try {
            $pdo->beginTransaction();

            $instructor = ia_find_instructor($pdo, $coachCode, true);
            if ($instructor === null) {
                $pdo->rollBack();
                ia_respond(false, 'Instructor not found.', [], 404);
            }

            $userId = (int) $instructor['user_id'];
            $photo  = trim((string) ($instructor['photo'] ?? ''));

            $pdo->prepare('DELETE FROM sooma_wellness.users WHERE user_id = :user_id')
                ->execute([':user_id' => $userId]);

            $pdo->commit();

            if ($photo !== '') {
                ia_delete_photo_file($photo);
            }

            ia_respond(true, 'Coach deleted successfully');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Instructor delete failed: ' . $e->getMessage());

            if ($e->getCode() === '23503') {
                ia_respond(
                    false,
                    'This instructor still has scheduled classes. Reassign or remove them first, or set the status to Inactive.',
                    [],
                    409
                );
            }
            ia_respond(false, 'Failed to delete the instructor. Please try again.', [], 500);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Instructor delete failed: ' . $e->getMessage());
            ia_respond(false, 'Failed to delete the instructor. Please try again.', [], 500);
        }
        break;

    default:
        ia_respond(false, 'Unknown action.', [], 400);
}