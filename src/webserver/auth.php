<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config.php';

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

if (!isset($_SESSION['auth_user'])) {
    header("Location: login.php");
    exit;
}

// Nạp quyền và danh sách package nếu chưa có trong session
if (!isset($_SESSION['auth_role']) || !isset($_SESSION['auth_package_ids'])) {
    $uName = mysqli_real_escape_string($conn, $_SESSION['auth_user']);
    $uQuery = mysqli_query($conn, "SELECT role, package_ids, status FROM tbl_users WHERE username='$uName' LIMIT 1");
    if ($uRow = mysqli_fetch_assoc($uQuery)) {
        if ($uRow['status'] != 1) {
            session_destroy();
            header("Location: login.php?err=locked");
            exit;
        }
        $_SESSION['auth_role'] = $uRow['role'];
        $_SESSION['auth_package_ids'] = $uRow['package_ids'];
    } else {
        if ($_SESSION['auth_user'] === 'ntamod') {
            $_SESSION['auth_role'] = 'admin';
            $_SESSION['auth_package_ids'] = 'ALL';
        } else {
            session_destroy();
            header("Location: login.php");
            exit;
        }
    }
}

function isAdmin() {
    return isset($_SESSION['auth_role']) && $_SESSION['auth_role'] === 'admin';
}

function isAgency() {
    return isset($_SESSION['auth_role']) && $_SESSION['auth_role'] === 'agency';
}

function requireAdmin() {
    if (!isAdmin()) {
        header("Location: key.php");
        exit;
    }
}

function getAllowedPackageIds() {
    if (isAdmin()) {
        return null; // null biểu thị được xem tất cả
    }
    $raw = trim($_SESSION['auth_package_ids'] ?? '');
    if (empty($raw)) return [-1];
    $parts = explode(',', $raw);
    $ids = [];
    foreach ($parts as $p) {
        $id = intval(trim($p));
        if ($id > 0) $ids[] = $id;
    }
    return empty($ids) ? [-1] : $ids;
}

function hasPackagePermission($projectId) {
    if (isAdmin()) return true;
    $allowed = getAllowedPackageIds();
    return in_array(intval($projectId), $allowed);
}

function logKeyAction($conn, $projectId, $tokenCode, $action, $details = '') {
    $actor = $_SESSION['auth_user'] ?? 'system';
    $role = $_SESSION['auth_role'] ?? 'unknown';
    $pId = intval($projectId);
    $token = mysqli_real_escape_string($conn, $tokenCode);
    $act = mysqli_real_escape_string($conn, $action);
    $det = mysqli_real_escape_string($conn, $details);
    $actUser = mysqli_real_escape_string($conn, $actor);
    $actRole = mysqli_real_escape_string($conn, $role);

    $sql = "INSERT INTO tbl_key_logs (project_id, token_code, action, actor_username, actor_role, details) 
            VALUES ($pId, '$token', '$act', '$actUser', '$actRole', '$det')";
    mysqli_query($conn, $sql);
}
