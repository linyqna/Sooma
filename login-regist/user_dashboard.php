<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?page=login');
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Customer - Sooma Wellness</title>
</head>
<body>
    <h1>Halo, <?= htmlspecialchars($_SESSION['name']) ?>!</h1>
    <p>Selamat datang di Sooma Wellness. Kamu login sebagai <strong><?= htmlspecialchars($_SESSION['role']) ?></strong>.</p>

    <a href="auth/logout.php">Logout</a>
</body>
</html>