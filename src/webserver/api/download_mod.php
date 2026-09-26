<?php
require_once '../config.php';

// Nhận tham số
$fileId = intval($_GET['file_id'] ?? 0);
$key = trim($_GET['key'] ?? '');

if ($fileId <= 0 || empty($key)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => false, 'message' => 'Thiếu thông tin file_id hoặc key!'], JSON_UNESCAPED_UNICODE);
    exit;
}

$keyEsc = mysqli_real_escape_string($conn, $key);

// 1. Kiểm tra mã key
$tokenQuery = mysqli_query($conn, "SELECT t.*, p.name as project_name, p.is_maintenance 
                                   FROM tbl_tokens t 
                                   JOIN tbl_projects p ON t.project_id = p.id 
                                   WHERE t.token_code = '$keyEsc' 
                                   LIMIT 1");

if (mysqli_num_rows($tokenQuery) == 0) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => false, 'message' => 'Khóa kích hoạt không hợp lệ!'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tokenData = mysqli_fetch_assoc($tokenQuery);

// Kiểm tra bảo trì
if ($tokenData['is_maintenance'] == 1) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => false, 'message' => 'Hệ thống đang tạm bảo trì!'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Kiểm tra hạn sử dụng
if (!empty($tokenData['expire_date'])) {
    if (strtotime($tokenData['expire_date']) <= time()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => false, 'message' => 'Khóa kích hoạt đã hết hạn sử dụng!'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 2. Kiểm tra file mod trong hệ thống
$modQuery = mysqli_query($conn, "SELECT * FROM tbl_mod_files WHERE id = $fileId AND status = 1 LIMIT 1");
if (mysqli_num_rows($modQuery) == 0) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => false, 'message' => 'File mod không tồn tại hoặc đã tạm khóa!'], JSON_UNESCAPED_UNICODE);
    exit;
}

$modData = mysqli_fetch_assoc($modQuery);

// Kiểm tra quyền: Key có đúng Package của file mod này không
if ($modData['project_id'] != $tokenData['project_id']) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => false, 'message' => 'Khóa của bạn không có quyền truy cập file mod này!'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. Kiểm tra file vật lý trên ổ cứng
$storageDir = __DIR__ . '/../storage/mods/';
$filePath = $storageDir . $modData['storage_filename'];

if (!file_exists($filePath) || !is_readable($filePath)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => false, 'message' => 'Lỗi máy chủ: File vật lý bị thiếu!'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 4. Cập nhật lượt tải & ghi log
mysqli_query($conn, "UPDATE tbl_mod_files SET download_count = download_count + 1 WHERE id = $fileId");

// Ghi log hành động tải file
$pId = intval($tokenData['project_id']);
$fNameEsc = mysqli_real_escape_string($conn, $modData['original_file_name']);
$actor = mysqli_real_escape_string($conn, $tokenData['creator_username'] ?? 'client');
$logSql = "INSERT INTO tbl_key_logs (project_id, token_code, actor_username, actor_role, action, details) 
           VALUES ($pId, '$keyEsc', '$actor', 'client', 'DOWNLOAD_MOD', 'Tải file mod: $fNameEsc (ID: $fileId)')";
@mysqli_query($conn, $logSql);

// 5. Stream file nhị phân về app khách an toàn
$fileSize = filesize($filePath);
$downloadFileName = $modData['original_file_name'];

// Xóa output buffer trước khi stream
if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . rawurlencode($downloadFileName) . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Content-Length: ' . $fileSize);

// Stream file theo chunk 64KB tránh tràn RAM
$handle = fopen($filePath, 'rb');
if ($handle !== false) {
    while (!feof($handle)) {
        echo fread($handle, 65536);
        flush();
    }
    fclose($handle);
}
exit;
