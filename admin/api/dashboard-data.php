<?php
session_start();
header('Content-Type: application/json');
require_once dirname(__DIR__, 2) . '/login-regist/connection.php';
require_once dirname(__DIR__, 2) . '/includes/dashboard_service.php';

$csrf = $_SESSION['csrf_token'] ?? '';
echo json_encode(getDashboardPayload($pdo, $csrf));