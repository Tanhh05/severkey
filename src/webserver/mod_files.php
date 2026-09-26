<?php
require_once 'auth.php';
require_once 'config.php';
requireAdmin();

$msg = '';
$err = '';

// Thư mục lưu trữ file mod trên máy chủ
$storageDir = __DIR__ . '/storage/mods/';
if (!file_exists($storageDir)) {
    @mkdir($storageDir, 0775, true);
}

// Xử lý Upload / Thêm file mod mới
if (isset($_POST['upload_mod'])) {
    $project_id = intval($_POST['project_id'] ?? 0);
    $category = trim($_POST['mod_category'] ?? 'default');
    $displayName = trim($_POST['file_display_name'] ?? '');
    $originalName = trim($_POST['original_file_name'] ?? '');
    $targetSubPath = trim($_POST['target_sub_path'] ?? '');
    $version = trim($_POST['version'] ?? '1.0');
    if (empty($version)) $version = '1.0';

    if ($project_id <= 0) {
        $err = 'Vui lòng chọn Package áp dụng file mod này!';
    } elseif (empty($displayName)) {
        $err = 'Vui lòng nhập tên hiển thị cho file mod!';
    } elseif (!isset($_FILES['mod_file']) || $_FILES['mod_file']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrCode = $_FILES['mod_file']['error'] ?? -1;
        $err = 'Lỗi upload file (Mã lỗi: ' . $uploadErrCode . '). Vui lòng kiểm tra dung lượng file!';
    } else {
        $uploadedFile = $_FILES['mod_file'];
        $uploadedFileName = basename($uploadedFile['name']);

        // Nếu người dùng không chỉ định tên file game, dùng luôn tên file vừa upload
        if (empty($originalName)) {
            $originalName = $uploadedFileName;
        }

        // Tạo tên lưu trữ ngẫu nhiên tránh trùng lặp và bảo mật
        $fileExt = pathinfo($uploadedFileName, PATHINFO_EXTENSION);
        $safeStorageName = 'mod_p' . $project_id . '_' . time() . '_' . substr(md5(uniqid()), 0, 8);
        if (!empty($fileExt)) {
            $safeStorageName .= '.' . $fileExt;
        }
        $destPath = $storageDir . $safeStorageName;

        if (move_uploaded_file($uploadedFile['tmp_name'], $destPath)) {
            $fileSize = filesize($destPath);
            $fileMd5 = md5_file($destPath);

            $pIdEsc = $project_id;
            $catEsc = mysqli_real_escape_string($conn, $category);
            $dispEsc = mysqli_real_escape_string($conn, $displayName);
            $origEsc = mysqli_real_escape_string($conn, $originalName);
            $targetEsc = mysqli_real_escape_string($conn, $targetSubPath);
            $storageEsc = mysqli_real_escape_string($conn, $safeStorageName);
            $verEsc = mysqli_real_escape_string($conn, $version);

            $sql = "INSERT INTO tbl_mod_files 
                    (project_id, mod_category, file_display_name, original_file_name, target_sub_path, storage_filename, file_size, file_md5, version, status) 
                    VALUES ($pIdEsc, '$catEsc', '$dispEsc', '$origEsc', '$targetEsc', '$storageEsc', $fileSize, '$fileMd5', '$verEsc', 1)";
            
            if (mysqli_query($conn, $sql)) {
                $msg = "Đã upload và phát hành file mod <strong>" . htmlspecialchars($displayName) . "</strong> thành công!";
            } else {
                $err = 'Lỗi lưu dữ liệu database: ' . mysqli_error($conn);
                @unlink($destPath);
            }
        } else {
            $err = 'Không thể lưu file vào thư mục lưu trữ máy chủ! Vui lòng kiểm tra quyền thư mục.';
        }
    }
}

// Xử lý Cập nhật / Upload đè file nhị phân mới
if (isset($_POST['update_mod'])) {
    $modId = intval($_POST['mod_id'] ?? 0);
    $category = trim($_POST['mod_category'] ?? 'default');
    $displayName = trim($_POST['file_display_name'] ?? '');
    $originalName = trim($_POST['original_file_name'] ?? '');
    $targetSubPath = trim($_POST['target_sub_path'] ?? '');
    $version = trim($_POST['version'] ?? '1.0');

    $chk = mysqli_query($conn, "SELECT * FROM tbl_mod_files WHERE id=$modId LIMIT 1");
    if ($oldMod = mysqli_fetch_assoc($chk)) {
        $catEsc = mysqli_real_escape_string($conn, $category);
        $dispEsc = mysqli_real_escape_string($conn, $displayName);
        $origEsc = mysqli_real_escape_string($conn, $originalName);
        $targetEsc = mysqli_real_escape_string($conn, $targetSubPath);
        $verEsc = mysqli_real_escape_string($conn, $version);

        $updateFields = [
            "mod_category='$catEsc'",
            "file_display_name='$dispEsc'",
            "original_file_name='$origEsc'",
            "target_sub_path='$targetEsc'",
            "version='$verEsc'"
        ];

        // Nếu có chọn file mới để ghi đè
        if (isset($_FILES['mod_file']) && $_FILES['mod_file']['error'] === UPLOAD_ERR_OK) {
            $uploadedFile = $_FILES['mod_file'];
            $fileExt = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
            $safeStorageName = 'mod_p' . $oldMod['project_id'] . '_' . time() . '_' . substr(md5(uniqid()), 0, 8);
            if (!empty($fileExt)) {
                $safeStorageName .= '.' . $fileExt;
            }
            $destPath = $storageDir . $safeStorageName;

            if (move_uploaded_file($uploadedFile['tmp_name'], $destPath)) {
                // Xóa file cũ
                if (!empty($oldMod['storage_filename']) && file_exists($storageDir . $oldMod['storage_filename'])) {
                    @unlink($storageDir . $oldMod['storage_filename']);
                }

                $fileSize = filesize($destPath);
                $fileMd5 = md5_file($destPath);
                $updateFields[] = "storage_filename='" . mysqli_real_escape_string($conn, $safeStorageName) . "'";
                $updateFields[] = "file_size=$fileSize";
                $updateFields[] = "file_md5='$fileMd5'";
            }
        }

        $sql = "UPDATE tbl_mod_files SET " . implode(', ', $updateFields) . " WHERE id=$modId";
        if (mysqli_query($conn, $sql)) {
            $msg = "Đã cập nhật thông tin file mod thành công!";
        } else {
            $err = 'Lỗi cập nhật: ' . mysqli_error($conn);
        }
    }
}

// Bật / Tắt trạng thái file mod
if (isset($_GET['toggle'])) {
    $tId = intval($_GET['toggle']);
    mysqli_query($conn, "UPDATE tbl_mod_files SET status = IF(status=1, 0, 1) WHERE id=$tId");
    header("Location: mod_files.php");
    exit;
}

// Xóa file mod
if (isset($_GET['delete'])) {
    $delId = intval($_GET['delete']);
    $chk = mysqli_query($conn, "SELECT storage_filename FROM tbl_mod_files WHERE id=$delId LIMIT 1");
    if ($row = mysqli_fetch_assoc($chk)) {
        if (!empty($row['storage_filename']) && file_exists($storageDir . $row['storage_filename'])) {
            @unlink($storageDir . $row['storage_filename']);
        }
        mysqli_query($conn, "DELETE FROM tbl_mod_files WHERE id=$delId");
        $msg = "Đã xóa file mod khỏi hệ thống!";
    }
    header("Location: mod_files.php");
    exit;
}

// Tải file trực tiếp từ Admin (Test kiểm tra)
if (isset($_GET['admin_dl'])) {
    $dlId = intval($_GET['admin_dl']);
    $chk = mysqli_query($conn, "SELECT * FROM tbl_mod_files WHERE id=$dlId LIMIT 1");
    if ($row = mysqli_fetch_assoc($chk)) {
        $filePath = $storageDir . $row['storage_filename'];
        if (file_exists($filePath)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $row['original_file_name'] . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;
        } else {
            $err = "File vật lý không còn tồn tại trên máy chủ!";
        }
    }
}

// Lấy danh sách Package
$allProjectsQuery = mysqli_query($conn, "SELECT * FROM tbl_projects ORDER BY id DESC");
$allProjects = [];
while ($p = mysqli_fetch_assoc($allProjectsQuery)) {
    $allProjects[$p['id']] = $p;
}

// Lấy danh sách Categories độc nhất để gợi ý
$catQuery = mysqli_query($conn, "SELECT DISTINCT mod_category FROM tbl_mod_files ORDER BY mod_category ASC");
$allCategories = [];
while ($c = mysqli_fetch_assoc($catQuery)) {
    $allCategories[] = $c['mod_category'];
}

// Bộ lọc
$filterPkg = isset($_GET['pkg']) ? intval($_GET['pkg']) : 0;
$filterCat = isset($_GET['cat']) ? trim($_GET['cat']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$whereConds = [];
if ($filterPkg > 0) {
    $whereConds[] = "m.project_id = $filterPkg";
}
if (!empty($filterCat)) {
    $catEsc = mysqli_real_escape_string($conn, $filterCat);
    $whereConds[] = "m.mod_category = '$catEsc'";
}
if (!empty($search)) {
    $sEsc = mysqli_real_escape_string($conn, $search);
    $whereConds[] = "(m.file_display_name LIKE '%$sEsc%' OR m.original_file_name LIKE '%$sEsc%')";
}
$whereSql = !empty($whereConds) ? " WHERE " . implode(' AND ', $whereConds) : "";

// Thống kê tổng quan
$statTotalFiles = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM tbl_mod_files"))['c'] ?? 0;
$statTotalDownloads = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(download_count) as s FROM tbl_mod_files"))['s'] ?? 0;
$statTotalSize = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(file_size) as s FROM tbl_mod_files"))['s'] ?? 0;
$statTotalSizeMB = round($statTotalSize / (1024 * 1024), 2);

// Danh sách file mod
$modListQuery = mysqli_query($conn, "SELECT m.*, p.name as pname 
                                     FROM tbl_mod_files m 
                                     LEFT JOIN tbl_projects p ON m.project_id = p.id 
                                     $whereSql 
                                     ORDER BY m.id DESC");

// Format dung lượng
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý File Mod - NTA Server Key</title>
    <link rel="stylesheet" href="theme/admin.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: 'Plus Jakarta Sans', -apple-system, sans-serif;
        background: #f8fafc;
        display: flex;
        height: 100vh;
        overflow: hidden;
    }
    .main-content {
        flex: 1;
        padding: 32px 40px;
        overflow-y: auto;
    }
    .card-modern {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 24px 28px;
        margin-bottom: 24px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
    }
    .page-title {
        font-size: 24px;
        font-weight: 800;
        color: #0f172a;
        margin: 0 0 6px 0;
    }
    .page-subtitle {
        font-size: 13.5px;
        color: #64748b;
        margin: 0 0 24px 0;
    }
    .stats-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    .stat-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 18px 20px;
    }
    .stat-card-title {
        font-size: 12px;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 6px;
    }
    .stat-card-value {
        font-size: 24px;
        font-weight: 800;
        color: #0f172a;
    }
    .field-label {
        font-size: 11.5px;
        font-weight: 700;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 6px;
        display: block;
    }
    .field-input, .field-select {
        width: 100%;
        height: 42px;
        padding: 0 14px;
        border: 1.5px solid #cbd5e1;
        border-radius: 8px;
        font-size: 13.5px;
        font-family: inherit;
        background: #ffffff;
        color: #0f172a;
        transition: all 0.15s ease;
    }
    .field-input:focus, .field-select:focus {
        outline: none;
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }
    .field-hint {
        font-size: 12px;
        color: #64748b;
        margin-top: 4px;
        display: block;
    }
    .btn-primary {
        background: #2563eb;
        color: #ffffff;
        font-weight: 700;
        font-size: 13.5px;
        padding: 10px 22px;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: background 0.15s ease;
    }
    .btn-primary:hover {
        background: #1d4ed8;
    }
    table.table-modern {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
    }
    table.table-modern th {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #64748b;
        background: #f8fafc;
        padding: 12px 14px;
        border-bottom: 1.5px solid #e2e8f0;
        white-space: nowrap;
    }
    table.table-modern td {
        padding: 13px 14px;
        font-size: 13px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
        color: #1e293b;
    }
    table.table-modern tr:hover td {
        background: #f8fafc;
    }
    .badge-cat {
        background: #eff6ff;
        color: #1d4ed8;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 11.5px;
        font-weight: 700;
        border: 1px solid #bfdbfe;
        display: inline-block;
    }
    .badge-status-on {
        background: #ecfdf5;
        color: #059669;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 11.5px;
        font-weight: 700;
        border: 1px solid #a7f3d0;
    }
    .badge-status-off {
        background: #fef2f2;
        color: #dc2626;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 11.5px;
        font-weight: 700;
        border: 1px solid #fecaca;
    }
    .code-chip {
        font-family: 'JetBrains Mono', monospace;
        font-size: 11.5px;
        background: #f1f5f9;
        padding: 3px 7px;
        border-radius: 4px;
        color: #0f172a;
        border: 1px solid #e2e8f0;
    }
    .alert-success {
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        color: #065f46;
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-size: 13.5px;
    }
    .alert-danger {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b;
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-size: 13.5px;
    }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">API PANEL</div>
        <div class="sidebar-menu">
            <a href="index.php">Dashboard</a>
            <a href="package.php">Quản lý Package</a>
            <a href="agency.php">Quản lý Đại lý</a>
            <a href="key.php">Quản lý Key</a>
            <a href="package_keys.php">Key theo Package & Log</a>
            <a href="mod_files.php" class="active">📁 Quản lý File Mod</a>
            <a href="auth.php?logout=1" style="color: #ef4444; margin-top: 30px;">Đăng Xuất (<?= htmlspecialchars($_SESSION['auth_user']) ?>)</a>
        </div>
    </div>

    <div class="main-content">
        <h1 class="page-title">Quản Lý & Phân Phối File Mod (OTA CDN)</h1>
        <p class="page-subtitle">Upload lưu trữ và phân phối file mod trực tiếp về các ứng dụng khi người dùng bấm kích hoạt</p>

        <?php if (!empty($msg)): ?>
            <div class="alert-success"><?= $msg ?></div>
        <?php endif; ?>
        <?php if (!empty($err)): ?>
            <div class="alert-danger"><?= $err ?></div>
        <?php endif; ?>

        <!-- Stats Overview -->
        <div class="stats-row">
            <div class="stat-card" style="border-left: 4px solid #3b82f6;">
                <div class="stat-card-title">Tổng số File Mod</div>
                <div class="stat-card-value"><?= $statTotalFiles ?></div>
            </div>
            <div class="stat-card" style="border-left: 4px solid #10b981;">
                <div class="stat-card-title">Lượt Tải Về App</div>
                <div class="stat-card-value" style="color: #059669;"><?= $statTotalDownloads ?></div>
            </div>
            <div class="stat-card" style="border-left: 4px solid #8b5cf6;">
                <div class="stat-card-title">Dung Lượng Đang Lưu Trữ</div>
                <div class="stat-card-value" style="color: #7c3aed;"><?= $statTotalSizeMB ?> MB</div>
            </div>
            <div class="stat-card" style="border-left: 4px solid #f59e0b;">
                <div class="stat-card-title">Gói Package Hỗ Trợ</div>
                <div class="stat-card-value" style="color: #d97706;"><?= count($allProjects) ?></div>
            </div>
        </div>

        <!-- Card Upload File Mod Mới -->
        <div class="card-modern" style="border-top: 4px solid #2563eb;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div>
                    <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                        <span>📤</span> Upload & Phát Hành File Mod Mới
                    </h3>
                    <p style="margin: 4px 0 0 0; font-size: 12.5px; color: #64748b;">
                        Hỗ trợ upload mọi định dạng nhị phân (.bytes, .bundle, .json, .so, .zip...). Tự động tính dung lượng và mã băm MD5.
                    </p>
                </div>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 18px; margin-bottom: 18px;">
                    <div>
                        <label class="field-label">GÓI ỨNG DỤNG (PACKAGE) <span style="color:#ef4444;">*</span></label>
                        <select name="project_id" class="field-select" required>
                            <option value="">-- Chọn Package áp dụng --</option>
                            <?php foreach ($allProjects as $pId => $p): ?>
                                <option value="<?= $pId ?>"><?= htmlspecialchars($p['name']) ?> (ID: <?= $pId ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <span class="field-hint">Chỉ key thuộc Package này mới tải được file</span>
                    </div>

                    <div>
                        <label class="field-label">NHÓM TÍNH NĂNG (CATEGORY)</label>
                        <input type="text" name="mod_category" class="field-input" list="catList" placeholder="Ví dụ: aim, dinhvi, patch, antiban..." value="default" required>
                        <datalist id="catList">
                            <option value="aim">Aimbot / Aim Cổ</option>
                            <option value="dinhvi">Định vị / Hologram</option>
                            <option value="antiban">Bảo vệ / Antiban</option>
                            <option value="patch">Patch File Game</option>
                            <option value="skin">Skin / Đồ họa</option>
                        </datalist>
                        <span class="field-hint">Dùng để app lọc tải theo từng tính năng cụ thể</span>
                    </div>

                    <div>
                        <label class="field-label">TÊN HIỂN THỊ (GỢI NHỚ) <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="file_display_name" class="field-input" placeholder="Ví dụ: Patch Aim Cổ V4.2" required>
                        <span class="field-hint">Mô tả tính năng để quản lý</span>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 18px; margin-bottom: 18px;">
                    <div>
                        <label class="field-label">TÊN FILE TRONG GAME (ORIGINAL NAME)</label>
                        <input type="text" name="original_file_name" id="origFileNameInput" class="field-input" placeholder="Ví dụ: Assembly-CSharp-patch.bytes">
                        <span class="field-hint">Để trống sẽ tự lấy theo tên file bạn upload</span>
                    </div>

                    <div>
                        <label class="field-label">THƯ MỤC CON ĐÍCH TRONG GAME (TARGET SUB-PATH)</label>
                        <input type="text" name="target_sub_path" class="field-input" placeholder="Ví dụ: contentcache/Optional/android/gameassetbundles">
                        <span class="field-hint">Để trống nếu dán ngay trong thư mục gốc <code>files/</code></span>
                    </div>

                    <div>
                        <label class="field-label">PHIÊN BẢN (VERSION)</label>
                        <input type="text" name="version" class="field-input" placeholder="1.0" value="1.0">
                        <span class="field-hint">Tăng version khi cập nhật bản mod mới</span>
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label class="field-label">CHỌN FILE MOD TỪ MÁY TÍNH <span style="color:#ef4444;">*</span></label>
                    <div style="border: 2px dashed #cbd5e1; border-radius: 10px; padding: 20px; text-align: center; background: #f8fafc;">
                        <input type="file" name="mod_file" id="filePicker" required style="font-size: 13.5px;" onchange="autoFillOriginalName(this)">
                        <div style="font-size: 12px; color: #64748b; margin-top: 8px;">
                            Hỗ trợ file tối đa <strong>128 MB</strong>. Tự động tính toán dung lượng và mã MD5 bảo mật.
                        </div>
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end;">
                    <button type="submit" name="upload_mod" class="btn-primary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        Upload & Lưu File Mod
                    </button>
                </div>
            </form>
        </div>

        <!-- Bảng Danh Sách File Mod Đang Có -->
        <div class="card-modern">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 14px;">
                <div>
                    <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                        <span>📦</span> Danh Sách File Mod Trên Server
                    </h3>
                    <p style="margin: 4px 0 0 0; font-size: 12.5px; color: #64748b;">
                        Các ứng dụng ngoài sẽ gọi API để đồng bộ và tải các file mod này về máy
                    </p>
                </div>

                <!-- Bộ lọc -->
                <form method="GET" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <select name="pkg" class="field-select" style="height: 38px; font-size: 13px; width: 170px;" onchange="this.form.submit()">
                        <option value="0">-- Tất cả Package --</option>
                        <?php foreach ($allProjects as $pId => $p): ?>
                            <option value="<?= $pId ?>" <?= ($filterPkg == $pId) ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="cat" class="field-select" style="height: 38px; font-size: 13px; width: 150px;" onchange="this.form.submit()">
                        <option value="">-- Tất cả nhóm --</option>
                        <?php foreach ($allCategories as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>" <?= ($filterCat === $c) ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <input type="text" name="search" class="field-input" value="<?= htmlspecialchars($search) ?>" placeholder="Tìm tên file..." style="height: 38px; font-size: 13px; width: 180px;">
                    <button type="submit" style="height: 38px; background: #2563eb; color: #fff; font-weight: 600; font-size: 13px; padding: 0 16px; border-radius: 7px; border: none; cursor: pointer;">Lọc</button>
                    <a href="mod_files.php" style="height: 38px; background: #fff; color: #475569; font-weight: 600; font-size: 13px; padding: 0 12px; border-radius: 7px; border: 1.5px solid #e2e8f0; text-decoration: none; display: inline-flex; align-items: center;">Đặt lại</a>
                </form>
            </div>

            <div style="overflow-x: auto;">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>PACKAGE</th>
                            <th>NHÓM</th>
                            <th>TÊN HIỂN THỊ</th>
                            <th>TÊN FILE GAME</th>
                            <th>VỊ TRÍ DÁN (SUB-PATH)</th>
                            <th>DUNG LƯỢNG</th>
                            <th>MÃ MD5</th>
                            <th>VER</th>
                            <th>LƯỢT TẢI</th>
                            <th>TRẠNG THÁI</th>
                            <th style="text-align: right;">THAO TÁC</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($modListQuery) == 0): ?>
                        <tr>
                            <td colspan="12" style="text-align: center; color: #94a3b8; padding: 36px;">
                                Chưa có file mod nào được upload cho tiêu chí này. Hãy upload file đầu tiên ở form bên trên!
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php while ($m = mysqli_fetch_assoc($modListQuery)): ?>
                        <tr>
                            <td style="font-weight: 700; color: #64748b;">#<?= $m['id'] ?></td>
                            <td style="font-weight: 700; color: #0f172a;"><?= htmlspecialchars($m['pname'] ?? 'Không xác định') ?></td>
                            <td><span class="badge-cat"><?= htmlspecialchars($m['mod_category']) ?></span></td>
                            <td style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($m['file_display_name']) ?></td>
                            <td><span class="code-chip"><?= htmlspecialchars($m['original_file_name']) ?></span></td>
                            <td style="color: #64748b; font-size: 12px;">
                                <?= empty($m['target_sub_path']) ? '<em>(Gốc files/)</em>' : htmlspecialchars($m['target_sub_path']) ?>
                            </td>
                            <td style="font-weight: 600; font-size: 12.5px;"><?= formatBytes($m['file_size']) ?></td>
                            <td><span class="code-chip" title="<?= $m['file_md5'] ?>"><?= substr($m['file_md5'], 0, 10) ?>...</span></td>
                            <td><span style="font-weight: 700; color: #475569;"><?= htmlspecialchars($m['version']) ?></span></td>
                            <td style="font-weight: 700; color: #059669;"><?= $m['download_count'] ?></td>
                            <td>
                                <a href="?toggle=<?= $m['id'] ?>" style="text-decoration: none;" title="Bấm để bật/tắt">
                                    <?php if ($m['status'] == 1): ?>
                                        <span class="badge-status-on">● Hoạt động</span>
                                    <?php else: ?>
                                        <span class="badge-status-off">○ Tạm dừng</span>
                                    <?php endif; ?>
                                </a>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="?admin_dl=<?= $m['id'] ?>" class="btn-primary" style="padding: 4px 9px; font-size: 11.5px; text-decoration: none; border-radius: 6px; background: #0284c7;" title="Tải về máy xem thử">Tải Test</a>
                                <button type="button" class="btn-primary" style="padding: 4px 9px; font-size: 11.5px; border-radius: 6px; background: #475569;" onclick="openEditModal(<?= htmlspecialchars(json_encode($m)) ?>)">Sửa</button>
                                <a href="?delete=<?= $m['id'] ?>" class="btn-primary" style="padding: 4px 9px; font-size: 11.5px; text-decoration: none; border-radius: 6px; background: #dc2626;" onclick="return confirm('Bạn có chắc muốn xóa file mod này khỏi máy chủ?');">Xóa</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Hướng dẫn tích hợp API cho App khác -->
        <div class="card-modern" style="border-top: 4px solid #10b981; background: #f0fdf4;">
            <h3 style="margin: 0 0 8px 0; font-size: 15px; font-weight: 700; color: #166534; display: flex; align-items: center; gap: 8px;">
                <span>🔌</span> Hướng Dẫn Tích Hợp API Cho App Ngoài
            </h3>
            <p style="margin: 0 0 14px 0; font-size: 13px; color: #15803d;">
                Bất kỳ app nào (Flutter, Java, Kotlin, C++) chỉ cần gọi 2 API này là có thể tự động kiểm tra và tải file mod dán vào game:
            </p>

            <div style="background: #ffffff; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px 18px; margin-bottom: 12px;">
                <div style="font-weight: 700; font-size: 13px; color: #0f172a; margin-bottom: 4px;">
                    1. API Lấy Danh Sách File Mod (JSON):
                </div>
                <code style="font-size: 12.5px; color: #047857; font-family: 'JetBrains Mono', monospace; display: block; word-break: break-all;">
                    GET http://103.74.103.43/api/get_mods.php?key=MÃ_KEY_CỦA_KHÁCH
                </code>
                <span style="font-size: 12px; color: #64748b; margin-top: 4px; display: block;">
                    👉 Server tự động kiểm tra key xem còn hạn không và trả về JSON danh sách file mod kèm mã MD5 + link download an toàn.
                </span>
            </div>

            <div style="background: #ffffff; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px 18px;">
                <div style="font-weight: 700; font-size: 13px; color: #0f172a; margin-bottom: 4px;">
                    2. API Tải Xuống File Nhị Phân An Toàn:
                </div>
                <code style="font-size: 12.5px; color: #047857; font-family: 'JetBrains Mono', monospace; display: block; word-break: break-all;">
                    GET http://103.74.103.43/api/download_mod.php?file_id=ID_FILE&key=MÃ_KEY_CỦA_KHÁCH
                </code>
                <span style="font-size: 12px; color: #64748b; margin-top: 4px; display: block;">
                    👉 Server kiểm tra key hợp lệ mới stream file về app, tự động đếm lượt tải và ghi log bảo mật.
                </span>
            </div>
        </div>
    </div>

    <!-- Modal Chỉnh sửa file mod -->
    <div id="editModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
        <div style="background: #fff; border-radius: 14px; width: 90%; max-width: 600px; padding: 24px; box-shadow: 0 10px 25px rgba(0,0,0,0.15);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
                <h3 style="margin: 0; font-size: 16px; font-weight: 700;">Chỉnh Sửa / Upload Đè File Mod</h3>
                <button type="button" onclick="closeEditModal()" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="mod_id" id="editModId">
                <div style="margin-bottom: 12px;">
                    <label class="field-label">Tên hiển thị</label>
                    <input type="text" name="file_display_name" id="editDisplayName" class="field-input" required>
                </div>
                <div style="margin-bottom: 12px;">
                    <label class="field-label">Nhóm tính năng (Category)</label>
                    <input type="text" name="mod_category" id="editCategory" class="field-input" required>
                </div>
                <div style="margin-bottom: 12px;">
                    <label class="field-label">Tên file khi vào game</label>
                    <input type="text" name="original_file_name" id="editOriginalName" class="field-input" required>
                </div>
                <div style="margin-bottom: 12px;">
                    <label class="field-label">Vị trí thư mục con (Sub-Path)</label>
                    <input type="text" name="target_sub_path" id="editTargetSubPath" class="field-input">
                </div>
                <div style="margin-bottom: 12px;">
                    <label class="field-label">Phiên bản (Version)</label>
                    <input type="text" name="version" id="editVersion" class="field-input" required>
                </div>
                <div style="margin-bottom: 16px;">
                    <label class="field-label">Upload file mới (để trống nếu không đổi binary)</label>
                    <input type="file" name="mod_file" class="field-input" style="padding-top: 6px;">
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 8px;">
                    <button type="button" onclick="closeEditModal()" class="btn-primary" style="background: #e2e8f0; color: #475569;">Hủy</button>
                    <button type="submit" name="update_mod" class="btn-primary">Lưu Thay Đổi</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function autoFillOriginalName(input) {
        if (input.files && input.files[0]) {
            var origInput = document.getElementById('origFileNameInput');
            if (origInput && !origInput.value) {
                origInput.value = input.files[0].name;
            }
        }
    }

    function openEditModal(data) {
        document.getElementById('editModId').value = data.id;
        document.getElementById('editDisplayName').value = data.file_display_name;
        document.getElementById('editCategory').value = data.mod_category;
        document.getElementById('editOriginalName').value = data.original_file_name;
        document.getElementById('editTargetSubPath').value = data.target_sub_path;
        document.getElementById('editVersion').value = data.version;
        document.getElementById('editModal').style.display = 'flex';
    }

    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }
    </script>
</body>
</html>
