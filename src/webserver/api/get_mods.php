<?php
require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

// Nhận tham số qua GET hoặc POST
$key = trim($_REQUEST['key'] ?? '');
$category = trim($_REQUEST['category'] ?? '');

if (empty($key)) {
    echo json_encode([
        'status' => false,
        'message' => 'Thiếu mã Key kích hoạt (key parameter is required)!'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$keyEsc = mysqli_real_escape_string($conn, $key);

// Kiểm tra mã key trong hệ thống
$tokenQuery = mysqli_query($conn, "SELECT t.*, p.name as project_name, p.is_maintenance 
                                   FROM tbl_tokens t 
                                   JOIN tbl_projects p ON t.project_id = p.id 
                                   WHERE t.token_code = '$keyEsc' 
                                   LIMIT 1");

if (mysqli_num_rows($tokenQuery) == 0) {
    echo json_encode([
        'status' => false,
        'message' => 'Mã key không tồn tại trong hệ thống!'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tokenData = mysqli_fetch_assoc($tokenQuery);

// Kiểm tra bảo trì Package
if ($tokenData['is_maintenance'] == 1) {
    echo json_encode([
        'status' => false,
        'message' => 'Hệ thống Package này đang tạm bảo trì, vui lòng quay lại sau!'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Kiểm tra thời hạn key
$nowTime = time();
if (!empty($tokenData['expire_date'])) {
    $expTime = strtotime($tokenData['expire_date']);
    if ($expTime <= $nowTime) {
        echo json_encode([
            'status' => false,
            'message' => 'Mã key của bạn đã hết hạn sử dụng lúc ' . $tokenData['expire_date'] . '!'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$projectId = intval($tokenData['project_id']);

// Lấy danh sách file mod của Package này
$modConditions = ["project_id = $projectId", "status = 1"];
if (!empty($category)) {
    $catEsc = mysqli_real_escape_string($conn, $category);
    $modConditions[] = "mod_category = '$catEsc'";
}
$modWhere = "WHERE " . implode(' AND ', $modConditions);

$modsQuery = mysqli_query($conn, "SELECT id, mod_category, file_display_name, original_file_name, target_sub_path, file_size, file_md5, version, updated_at 
                                  FROM tbl_mod_files 
                                  $modWhere 
                                  ORDER BY id ASC");

// Xác định base URL của server hiện tại
$isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
$protocol = $isHttps ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? '103.74.103.43';
$baseUrl = $protocol . $host;

$files = [];
while ($m = mysqli_fetch_assoc($modsQuery)) {
    $downloadUrl = $baseUrl . '/api/download_mod.php?file_id=' . $m['id'] . '&key=' . urlencode($key);
    $files[] = [
        'id' => intval($m['id']),
        'category' => $m['mod_category'],
        'display_name' => $m['file_display_name'],
        'file_name' => $m['original_file_name'],
        'target_sub_path' => $m['target_sub_path'],
        'file_size' => intval($m['file_size']),
        'file_md5' => $m['file_md5'],
        'version' => $m['version'],
        'download_url' => $downloadUrl,
        'updated_at' => $m['updated_at']
    ];
}

echo json_encode([
    'status' => true,
    'message' => 'Thành công',
    'project_id' => $projectId,
    'project_name' => $tokenData['project_name'],
    'total_files' => count($files),
    'files' => $files
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
