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
        "SELECT
        u.user_id,
        u.name,
        u.email,
        u.password,
        u.phone_number,
        r.role_name AS role
     FROM sooma_wellness.users u
     JOIN sooma_wellness.roles r
        ON u.role_id = r.role_id
     WHERE LOWER(u.email) = :email
     LIMIT 1"
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
        'id'        => $user['user_id'],
        'full_name' => $user['name'],
        'name'      => $user['name'],
        'email'     => $user['email'],
        'phone'     => $user['phone_number'],
        'role'      => $user['role']
    ];

    switch ($role) {
        case 'admin':
            header('Location: /admin/admin-dashboard.php');
            break;

        case 'instructor':
            header('Location: ../instructor/index.php');
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
