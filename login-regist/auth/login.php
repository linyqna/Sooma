<?php
session_start();
require_once dirname(__DIR__) . '/connection.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php?page=login');
    exit;
}

$email = strtolower(trim($_POST['email'] ?? ''));
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    header('Location: ../index.php?page=login&error=invalid');
    exit;
}

try {
    $stmt = $pdo->prepare(
        "select
            u.user_id,
            u.name,
            u.email,
            u.password,
            u.phone_number,
            u.avatar_url,
            i.photo as instructor_photo,
            r.role_name as role
         from sooma_wellness.users u
         join sooma_wellness.roles r
            on u.role_id = r.role_id
         left join sooma_wellness.instructors i
            on u.user_id = i.user_id
         where lower(u.email) = :email
         limit 1"
    );

    $stmt->execute([
        ':email' => $email
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password'])) {
        header('Location: ../index.php?page=login&error=invalid');
        exit;
    }

    $allowedRoles = ['admin', 'customer', 'instructor'];
    $role = strtolower(trim($user['role'] ?? ''));

    if (!in_array($role, $allowedRoles, true)) {
        header('Location: ../index.php?page=login&error=invalid');
        exit;
    }

    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['name']    = $user['name'];
    $_SESSION['email']   = $user['email'];
    $_SESSION['phone']   = $user['phone_number'];
    $_SESSION['role']    = $user['role'];

    $_SESSION['user'] = [
        'id'               => $user['user_id'],
        'user_id'          => $user['user_id'],
        'full_name'        => $user['name'],
        'name'             => $user['name'],
        'email'            => $user['email'],
        'phone'            => $user['phone_number'],
        'phone_number'     => $user['phone_number'],
        'role'             => $user['role'],
        'avatar_url'       => $user['avatar_url'],
        'instructor_photo' => $user['instructor_photo'] ?? null,
    ];

    switch ($role) {
        case 'admin':
            header('Location: ../../admin/admin-dashboard.php');
            break;

        case 'instructor':
            header('Location: ../../instructors/instructor.php');
            break;

        case 'customer':
            header('Location: /customer/homepage.php');
            exit;
    }
    exit;
} catch (PDOException $e) {
    echo '<pre>';
    echo 'LOGIN ERROR: ' . $e->getMessage();
    echo '</pre>';
    exit;
}