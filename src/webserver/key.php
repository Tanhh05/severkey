<?php
require_once 'auth.php';
require_once 'config.php';

function genKey($platform = 'ADR', $randomLength = 8) {
    $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomPart = substr(str_shuffle(str_repeat($chars, ceil($randomLength / strlen($chars)))), 1, $randomLength);
    $prefix = ($platform === 'IOS') ? 'NTAMOD-VIP-IOS' : 'NTAMOD-VIP-ADR';
    return $prefix . '-' . $randomPart;
}

$actionError = '';

// Xử lý tạo key mới
if (isset($_POST['create_key'])) {
    $project_id = intval($_POST['project_id']);
    
    // Kiểm tra quyền đối với Package
    if (!hasPackagePermission($project_id)) {
        $actionError = 'Lỗi: Bạn không có quyền tạo key cho Package này!';
    } else {
        $platform = isset($_POST['platform']) && $_POST['platform'] === 'IOS' ? 'IOS' : 'ADR';
        $max_devices = intval($_POST['max_devices']);
        $quantity = isset($_POST['quantity']) ? max(1, min(500, intval($_POST['quantity']))) : 1;
        $duration = 0;
        $expire_date = "NULL";

        if (isAgency()) {
            // Bên đại lý chỉ được tạo key động theo 1 ngày, 7 ngày và 30 ngày (không cho tự nhập số ngày/giờ)
            $type = 'dynamic';
            $max_devices = 1; // Đại lý cố định 1 thiết bị, không được sửa
            $allowedDurations = [1, 7, 30];
            $agencyDuration = isset($_POST['agency_duration']) ? intval($_POST['agency_duration']) : 1;
            if (!in_array($agencyDuration, $allowedDurations)) {
                $agencyDuration = 1;
            }
            $duration = $agencyDuration;
            $expire_date = "NULL";
        } else {
            $type = $_POST['type'] ?? 'dynamic';
            if ($type == 'static') {
                $rawDate = trim($_POST['static_date']);
                $formattedDate = date('Y-m-d H:i:s', strtotime($rawDate));
                $expire_date = "'" . mysqli_real_escape_string($conn, $formattedDate) . "'";
            } else if ($type == 'dynamic_hours') {
                $duration = max(1, intval($_POST['dynamic_hours']));
            } else {
                $duration = max(1, intval($_POST['dynamic_days']));
            }
        }

        $created_keys = [];
        $values = [];
        $creator = mysqli_real_escape_string($conn, $_SESSION['auth_user']);

        for ($i = 0; $i < $quantity; $i++) {
            $token_code = genKey($platform, 8);
            while (in_array($token_code, $created_keys)) {
                $token_code = genKey($platform, 8);
            }
            $created_keys[] = $token_code;
            $values[] = "($project_id, '$token_code', '$type', $duration, $expire_date, $max_devices, '$creator')";
        }

        if (!empty($values)) {
            $sql = "INSERT INTO tbl_tokens (project_id, token_code, type, duration, expire_date, max_devices, creator_username) VALUES " . implode(', ', $values);
            mysqli_query($conn, $sql);

            // Ghi log hành động tạo key
            $durText = ($type === 'dynamic_hours') ? "{$duration} giờ" : (($type === 'dynamic') ? "{$duration} ngày" : "tĩnh");
            foreach ($created_keys as $ck) {
                logKeyAction($conn, $project_id, $ck, 'CREATE', "Tạo key loại $type (thời lượng: $durText, thiết bị: $max_devices)");
            }
        }

        $_SESSION['last_created_keys'] = $created_keys;
        $_SESSION['last_created_info'] = [
            'count' => count($created_keys),
            'platform' => $platform,
            'type' => $type
        ];

        header("Location: key.php");
        exit;
    }
}

// Xử lý xóa key
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $tQuery = mysqli_query($conn, "SELECT project_id, token_code FROM tbl_tokens WHERE id=$id");
    if ($tRow = mysqli_fetch_assoc($tQuery)) {
        if (!hasPackagePermission($tRow['project_id'])) {
            die("Lỗi bảo mật: Bạn không có quyền xóa key thuộc Package này!");
        }
        logKeyAction($conn, $tRow['project_id'], $tRow['token_code'], 'DELETE', "Xóa key khỏi hệ thống");
        mysqli_query($conn, "DELETE FROM tbl_device_history WHERE token_id=$id");
        mysqli_query($conn, "DELETE FROM tbl_tokens WHERE id=$id");
    }
    header("Location: key.php");
    exit;
}

// Xử lý reset thiết bị
if (isset($_GET['reset_devices'])) {
    $id = intval($_GET['reset_devices']);
    $tQuery = mysqli_query($conn, "SELECT project_id, token_code FROM tbl_tokens WHERE id=$id");
    if ($tRow = mysqli_fetch_assoc($tQuery)) {
        if (!hasPackagePermission($tRow['project_id'])) {
            die("Lỗi bảo mật: Bạn không có quyền reset thiết bị cho key thuộc Package này!");
        }
        logKeyAction($conn, $tRow['project_id'], $tRow['token_code'], 'RESET', "Reset liên kết thiết bị");
        mysqli_query($conn, "DELETE FROM tbl_device_history WHERE token_id=$id");
    }
    header("Location: key.php");
    exit;
}

// Lấy danh sách Package được phép truy cập
$allowedPkgIds = getAllowedPackageIds();

// Phân trang & Tìm kiếm & Lọc thời hạn
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$selectedDur = isset($_GET['dur']) ? trim($_GET['dur']) : '';
$limit = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

$conditions = [];
if (!isAdmin()) {
    $pkgListStr = implode(',', $allowedPkgIds);
    $conditions[] = "t.project_id IN ($pkgListStr)";
}
if ($search !== '') {
    $searchEscaped = mysqli_real_escape_string($conn, $search);
    $conditions[] = "(t.token_code LIKE '%$searchEscaped%' OR EXISTS (
        SELECT 1 FROM tbl_device_history dh WHERE dh.token_id = t.id AND dh.device_uuid LIKE '%$searchEscaped%'
    ))";
}
if (!empty($selectedDur)) {
    $durInt = intval($selectedDur);
    if (in_array($durInt, [1, 7, 30])) {
        $conditions[] = "t.type = 'dynamic' AND t.duration = $durInt";
    }
}
$whereClause = !empty($conditions) ? " WHERE " . implode(' AND ', $conditions) : "";

$countSql = "SELECT COUNT(*) as total FROM tbl_tokens t" . $whereClause;
$totalQuery = mysqli_query($conn, $countSql);
$totalRow = mysqli_fetch_assoc($totalQuery);
$totalItems = $totalRow ? intval($totalRow['total']) : 0;
$totalPages = ceil($totalItems / $limit);
if ($totalPages < 1) $totalPages = 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $limit;

$sql = "SELECT t.*, p.name as pname FROM tbl_tokens t JOIN tbl_projects p ON t.project_id = p.id $whereClause ORDER BY t.id DESC LIMIT $limit OFFSET $offset";
$keys = mysqli_query($conn, $sql);

// Lấy danh sách Package cho form
if (isAdmin()) {
    $projects = mysqli_query($conn, "SELECT * FROM tbl_projects ORDER BY id DESC");
} else {
    $pkgListStr = implode(',', $allowedPkgIds);
    $projects = mysqli_query($conn, "SELECT * FROM tbl_projects WHERE id IN ($pkgListStr) ORDER BY id DESC");
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Key <?= isAgency() ? '- Đại lý' : '' ?></title>
    <link rel="stylesheet" href="theme/admin.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
    :root {
        --primary-blue: #1d4ed8;
        --border-color: #e2e8f0;
        --text-dark: #0f172a;
        --text-muted: #64748b;
        --bg-page: #f8fafc;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: 'Plus Jakarta Sans', -apple-system, sans-serif;
        background: var(--bg-page);
        color: var(--text-dark);
        display: flex;
        height: 100vh;
        overflow: hidden;
    }
    .main-content {
        flex: 1;
        padding: 32px 40px;
        overflow-y: auto;
    }
    .page-header-row {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 24px;
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
        margin: 0;
    }
    .card-modern {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 24px 28px;
        margin-bottom: 24px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
    }
    .field-label {
        font-size: 11.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #64748b;
        margin-bottom: 7px;
    }
    .field-input, .field-select {
        width: 100%;
        height: 42px;
        padding: 8px 14px;
        font-size: 13.5px;
        color: #0f172a;
        background: #ffffff;
        border: 1.5px solid #e2e8f0;
        border-radius: 8px;
        outline: none;
        transition: all 0.15s ease;
        font-family: inherit;
    }
    .field-input:focus, .field-select:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }
    .field-hint {
        font-size: 11.5px;
        color: #94a3b8;
        margin-top: 5px;
    }
    .input-suffix-wrapper {
        position: relative;
        display: flex;
        align-items: center;
    }
    .input-suffix-wrapper input {
        padding-right: 70px;
    }
    .input-suffix-text {
        position: absolute;
        right: 14px;
        font-size: 12.5px;
        color: #94a3b8;
        pointer-events: none;
    }
    .btn-create-key {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: #2563eb;
        color: #ffffff;
        font-size: 13.5px;
        font-weight: 600;
        padding: 10px 22px;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        transition: background 0.15s ease;
    }
    .btn-create-key:hover { background: #1d4ed8; }

    /* Tables */
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
    .token-chip {
        font-family: 'JetBrains Mono', monospace;
        font-size: 12px;
        font-weight: 600;
        color: #dc2626;
        background: #fef2f2;
        padding: 3px 8px;
        border-radius: 5px;
        border: 1px solid #fee2e2;
    }
    .btn-copy-chip {
        background: transparent;
        border: none;
        color: #94a3b8;
        cursor: pointer;
        padding: 2px;
        display: inline-flex;
        align-items: center;
    }
    .btn-copy-chip:hover { color: #2563eb; }
    .badge-dynamic-hours {
        background: #faf5ff;
        color: #9333ea;
        border: 1px solid #f3e8ff;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 11.5px;
        font-weight: 600;
    }
    .badge-dynamic-days {
        background: #eff6ff;
        color: #2563eb;
        border: 1px solid #dbeafe;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 11.5px;
        font-weight: 600;
    }
    .badge-static {
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #e2e8f0;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 11.5px;
        font-weight: 600;
    }
    .badge-status-active {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: #ecfdf5;
        color: #059669;
        padding: 3px 9px;
        border-radius: 9999px;
        font-size: 11.5px;
        font-weight: 600;
    }
    .badge-status-active::before {
        content: '';
        width: 6px;
        height: 6px;
        background: #059669;
        border-radius: 50%;
    }
    .badge-status-expired {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: #fef2f2;
        color: #dc2626;
        padding: 3px 9px;
        border-radius: 9999px;
        font-size: 11.5px;
        font-weight: 600;
    }
    .badge-status-expired::before {
        content: '';
        width: 6px;
        height: 6px;
        background: #dc2626;
        border-radius: 50%;
    }
    .btn-hour {
        background: #f8fafc;
        color: #475569;
        font-weight: 600;
        padding: 5px 12px;
        border-radius: 6px;
        border: 1px solid #e2e8f0;
        cursor: pointer;
        font-size: 12px;
    }
    .btn-hour.active-hour {
        background: #eff6ff;
        color: #2563eb;
        border-color: #bfdbfe;
    }
    .page-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 32px;
        height: 32px;
        padding: 0 8px;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 12.5px;
        font-weight: 500;
        color: #475569;
        text-decoration: none;
        background: #ffffff;
    }
    .page-pill.active {
        background: #eff6ff;
        color: #2563eb;
        border-color: #bfdbfe;
        font-weight: 700;
    }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">API PANEL</div>
        <div class="sidebar-menu">
            <?php if (isAdmin()): ?>
                <a href="index.php">Dashboard</a>
                <a href="package.php">Quản lý Package</a>
                <a href="agency.php">Quản lý Đại lý</a>
                <a href="key.php" class="active">Quản lý Key</a>
                <a href="package_keys.php">Key theo Package & Log</a>
                <a href="auth.php?logout=1" style="color: #ef4444; margin-top: 30px;">Đăng Xuất (<?= htmlspecialchars($_SESSION['auth_user']) ?>)</a>
            <?php else: ?>
                <div style="padding: 12px 25px 5px; font-size: 11px; text-transform: uppercase; color: #94a3b8; font-weight: 700;">
                    KÊNH ĐẠI LÝ
                </div>
                <a href="key.php" class="active">Quản lý Key</a>
                <a href="auth.php?logout=1" style="color: #ef4444; margin-top: 30px;">Đăng Xuất (<?= htmlspecialchars($_SESSION['auth_user']) ?>)</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-header-row">
            <div>
                <h1 class="page-title">Quản lý Key <?= isAgency() ? '<span style="font-size: 15px; background: #f5f3ff; color: #7c3aed; padding: 4px 10px; border-radius: 6px; border: 1px solid #ddd6fe; margin-left: 8px;">Đại lý: '.htmlspecialchars($_SESSION['auth_user']).'</span>' : '' ?></h1>
                <p class="page-subtitle">Khởi tạo mã bản quyền, quản lý phân phối và theo dõi thiết bị kích hoạt</p>
            </div>
            <div>
                <span style="display: inline-flex; align-items: center; gap: 6px; background: #f0fdf4; border: 1px solid #bbf7d0; color: #16a34a; padding: 6px 14px; border-radius: 9999px; font-size: 12.5px; font-weight: 600;">
                    <span style="width: 7px; height: 7px; background: #16a34a; border-radius: 50%;"></span>
                    Hệ thống trực tuyến
                </span>
            </div>
        </div>

        <?php if (!empty($actionError)): ?>
            <div style="background: #fef2f2; border-left: 4px solid #dc2626; color: #991b1b; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 13.5px;">
                <?= htmlspecialchars($actionError) ?>
            </div>
        <?php endif; ?>

        <!-- Hộp thông báo key mới tạo -->
        <?php if (!empty($_SESSION['last_created_keys'])): 
            $newKeys = $_SESSION['last_created_keys'];
            $keysText = implode("\n", $newKeys);
            unset($_SESSION['last_created_keys'], $_SESSION['last_created_info']);
        ?>
        <div class="card-modern" style="border-left: 4px solid #16a34a; background: #f0fdf4; margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 10px;">
                <h3 style="margin: 0; color: #166534; font-size: 15px; font-weight: 700;">
                    🎉 Đã tạo thành công <strong><?= count($newKeys) ?> key</strong> mới!
                </h3>
                <div style="display: flex; gap: 8px;">
                    <button type="button" onclick="copyAllCreatedKeys()" class="btn-create-key" style="background: #16a34a; padding: 6px 14px; font-size: 12.5px;">
                        📋 Sao Chép Toàn Bộ (<?= count($newKeys) ?> Key)
                    </button>
                    <button type="button" onclick="this.closest('.card-modern').style.display='none'" style="background: #e2e8f0; color: #475569; padding: 6px 12px; border-radius: 6px; border: none; cursor: pointer; font-size: 12px;">
                        ✕ Đóng
                    </button>
                </div>
            </div>
            <textarea id="createdKeysText" readonly rows="<?= min(6, max(2, count($newKeys))) ?>" style="width: 100%; box-sizing: border-box; font-family: 'JetBrains Mono', monospace; font-size: 12.5px; padding: 10px; border: 1px solid #bbf7d0; border-radius: 6px; background: #ffffff; color: #166534; resize: vertical; line-height: 1.5;"><?= htmlspecialchars($keysText) ?></textarea>
        </div>
        <?php endif; ?>

        <!-- Form tạo key mới -->
        <div class="card-modern">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="display: flex; align-items: center; gap: 8px; font-size: 15px; font-weight: 700; color: #0f172a; margin: 0;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Tạo Key mới
                </h3>
                <span style="font-size: 12px; color: #94a3b8;">
                    <?= isAgency() ? 'Chỉ được tạo cho Package được cấp quyền' : 'Các trường bắt buộc đánh dấu tự động' ?>
                </span>
            </div>

            <form method="POST">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 18px; margin-bottom: 18px;">
                    <div>
                        <label class="field-label">CHỌN PACKAGE</label>
                        <select name="project_id" class="field-select" required>
                            <?php 
                            $hasProjects = false;
                            while ($p = mysqli_fetch_assoc($projects)): 
                                $hasProjects = true;
                            ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                            <?php endwhile; ?>
                            <?php if (!$hasProjects): ?>
                                <option value="">(Không có Package nào được cấp quyền)</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div>
                        <label class="field-label">NỀN TẢNG</label>
                        <select name="platform" id="platformSelect" class="field-select">
                            <option value="ADR">Android (NTAMOD-VIP-ADR)</option>
                            <option value="IOS">iOS (NTAMOD-VIP-IOS)</option>
                        </select>
                    </div>

                    <div>
                        <label class="field-label">LOẠI KEY</label>
                        <?php if (isAgency()): ?>
                            <div style="height: 42px; display: flex; align-items: center; padding: 0 14px; background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; font-weight: 600; color: #1e293b;">
                                <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #10b981; margin-right: 8px;"></span>
                                Key Động (Tính từ lúc kích hoạt)
                            </div>
                            <input type="hidden" name="type" value="dynamic">
                        <?php else: ?>
                            <select name="type" id="keyType" onchange="toggleType()" class="field-select" required>
                                <option value="static">Key Tĩnh (Ngày cố định)</option>
                                <option value="dynamic">Key Động theo Ngày (Tính từ lúc kích hoạt)</option>
                                <option value="dynamic_hours">Key Động theo Giờ (Tính từ lúc kích hoạt)</option>
                            </select>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 18px; margin-bottom: 18px;">
                    <div>
                        <label class="field-label">SỐ LƯỢNG KEY CẦN TẠO</label>
                        <input type="number" name="quantity" id="quantityVal" class="field-input" value="1" min="1" max="500" required placeholder="1">
                        <span class="field-hint">Nhập số lượng key muốn tạo (1 - 500)</span>
                    </div>

                    <div>
                        <label class="field-label">SỐ THIẾT BỊ TỐI ĐA</label>
                        <?php if (isAgency()): ?>
                            <div class="input-suffix-wrapper" style="background: #f8fafc; border-color: #cbd5e1;">
                                <input type="number" name="max_devices" class="field-input" value="1" readonly style="background: #f8fafc; color: #64748b; cursor: not-allowed; font-weight: 700;">
                                <span class="input-suffix-text" style="color: #64748b;">thiết bị</span>
                            </div>
                            <span class="field-hint" style="color: #64748b;">Cố định: 1 thiết bị cho mỗi mã</span>
                        <?php else: ?>
                            <div class="input-suffix-wrapper">
                                <input type="number" name="max_devices" class="field-input" value="1" min="1" required>
                                <span class="input-suffix-text">thiết bị</span>
                            </div>
                            <span class="field-hint">Mặc định: 1 thiết bị cho mỗi mã</span>
                        <?php endif; ?>
                    </div>

                    <div>
                        <?php if (isAgency()): ?>
                            <label class="field-label">GÓI THỜI HẠN (CỐ ĐỊNH)</label>
                            <div style="display: flex; gap: 8px; margin-bottom: 6px;">
                                <button type="button" class="btn-agency-dur" data-val="1" onclick="selectAgencyDur(1, this)" style="flex: 1; padding: 10px 4px; font-weight: 700; font-size: 13.5px; border-radius: 8px; border: 1.5px solid #2563eb; background: #eff6ff; color: #1d4ed8; cursor: pointer; transition: all 0.2s;">1 Ngày</button>
                                <button type="button" class="btn-agency-dur" data-val="7" onclick="selectAgencyDur(7, this)" style="flex: 1; padding: 10px 4px; font-weight: 700; font-size: 13.5px; border-radius: 8px; border: 1.5px solid #cbd5e1; background: #fff; color: #475569; cursor: pointer; transition: all 0.2s;">7 Ngày</button>
                                <button type="button" class="btn-agency-dur" data-val="30" onclick="selectAgencyDur(30, this)" style="flex: 1; padding: 10px 4px; font-weight: 700; font-size: 13.5px; border-radius: 8px; border: 1.5px solid #cbd5e1; background: #fff; color: #475569; cursor: pointer; transition: all 0.2s;">30 Ngày</button>
                            </div>
                            <input type="hidden" name="agency_duration" id="agencyDurationVal" value="1">
                            <span class="field-hint" style="color: #64748b;">Đại lý cố định 3 gói: 1 ngày, 7 ngày hoặc 30 ngày</span>
                        <?php else: ?>
                            <div id="staticInput">
                                <label class="field-label">NGÀY HẾT HẠN / THỜI LƯỢNG</label>
                                <input type="datetime-local" name="static_date" class="field-input">
                                <span class="field-hint">Định dạng 24 giờ, giờ địa phương</span>
                            </div>

                            <div id="dynamicDaysInput" style="display:none;">
                                <label class="field-label">SỐ NGÀY SỬ DỤNG</label>
                                <input type="number" name="dynamic_days" class="field-input" placeholder="30" value="30" min="1">
                                <span class="field-hint">Tính từ lúc máy đầu tiên kích hoạt</span>
                            </div>

                            <div id="dynamicHoursInput" style="display:none;">
                                <label class="field-label">SỐ GIỜ SỬ DỤNG</label>
                                <div style="display: flex; gap: 6px; margin-bottom: 6px; flex-wrap: wrap;">
                                    <button type="button" class="btn-hour active-hour" onclick="selectHour(1, this)">1h</button>
                                    <button type="button" class="btn-hour" onclick="selectHour(2, this)">2h</button>
                                    <button type="button" class="btn-hour" onclick="selectHour(3, this)">3h</button>
                                    <button type="button" class="btn-hour" onclick="selectHour(6, this)">6h</button>
                                    <button type="button" class="btn-hour" onclick="selectHour(12, this)">12h</button>
                                </div>
                                <input type="number" name="dynamic_hours" id="dynamicHoursVal" class="field-input" placeholder="1" value="1" min="1">
                                <span class="field-hint">Tính từ thời điểm kích hoạt</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 14px; padding-top: 12px;">
                    <span style="font-size: 12.5px; color: #94a3b8;">Các mã sau khi tạo sẽ lập tức hiển thị bên dưới bảng điều khiển.</span>
                    <button type="submit" name="create_key" id="submitKeyBtn" class="btn-create-key">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 2l-2 2m-1.5 1.5L16 7l-1.5-1.5-3 3 1.5 1.5-1.5 1.5-2-2-4.5 4.5a3.5 3.5 0 0 0 5 5L15 15l-1.5-1.5 1.5-1.5 1.5 1.5 3-3-1.5-1.5 1.5-1.5 2 2z"/></svg>
                        Tạo Key
                    </button>
                </div>
            </form>
        </div>

        <!-- Bảng danh sách Key -->
        <div class="card-modern">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; flex-wrap: wrap; gap: 14px;">
                <div>
                    <h3 style="font-size: 16px; font-weight: 700; color: #0f172a; margin: 0 0 4px 0;">Danh Sách Key</h3>
                    <p style="font-size: 12.5px; color: #64748b; margin: 0;">Tổng cộng có <strong><?= $totalItems ?></strong> khóa kích hoạt được lưu trữ</p>
                </div>
                <form method="GET" action="key.php" style="display: flex; align-items: center; gap: 8px; max-width: 620px; width: 100%;">
                    <select name="dur" class="field-select" style="height: 38px; font-size: 13px; width: 170px; padding: 0 10px;" onchange="this.form.submit()">
                        <option value="">-- Tất cả thời hạn --</option>
                        <option value="1" <?= ($selectedDur === '1') ? 'selected' : '' ?>>⏱️ 1 Ngày</option>
                        <option value="7" <?= ($selectedDur === '7') ? 'selected' : '' ?>>📅 1 Tuần (7 Ngày)</option>
                        <option value="30" <?= ($selectedDur === '30') ? 'selected' : '' ?>>⭐ 1 Tháng (30 Ngày)</option>
                    </select>
                    <input type="text" name="search" class="field-input" value="<?= htmlspecialchars($search) ?>" placeholder="🔍 Tìm theo mã Key hoặc UID..." style="height: 38px; font-size: 13px;">
                    <button type="submit" style="height: 38px; background: #2563eb; color: #fff; font-weight: 600; font-size: 13px; padding: 0 16px; border-radius: 7px; border: none; cursor: pointer;">Tìm</button>
                    <a href="key.php" style="height: 38px; background: #fff; color: #475569; font-weight: 600; font-size: 13px; padding: 0 14px; border-radius: 7px; border: 1.5px solid #e2e8f0; text-decoration: none; display: inline-flex; align-items: center;">Làm mới</a>
                </form>
            </div>

            <div style="overflow-x: auto;">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>MÃ KEY</th>
                            <th>PACKAGE</th>
                            <th>NGƯỜI TẠO</th>
                            <th>LOẠI</th>
                            <th>HẠN DÙNG</th>
                            <th>TRẠNG THÁI</th>
                            <th>THIẾT BỊ</th>
                            <th>UID THIẾT BỊ</th>
                            <th style="text-align: right;">HÀNH ĐỘNG</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $nowTimestamp = time();
                        if (mysqli_num_rows($keys) == 0): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; color: #94a3b8; padding: 30px;">
                                Không có mã key nào phù hợp.
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php 
                        while ($row = mysqli_fetch_assoc($keys)): 
                            $devQuery = mysqli_query($conn, "SELECT device_uuid FROM tbl_device_history WHERE token_id=".$row['id']);
                            $devicesList = [];
                            while ($dev = mysqli_fetch_assoc($devQuery)) {
                                $devicesList[] = $dev['device_uuid'];
                            }
                            $used = count($devicesList);
                            $isExpired = false;
                            if ($row['expire_date'] && strtotime($row['expire_date']) <= $nowTimestamp) {
                                $isExpired = true;
                            }
                        ?>
                        <tr>
                            <td>
                                <div style="display: inline-flex; align-items: center; gap: 6px;">
                                    <span class="token-chip"><?= htmlspecialchars($row['token_code']) ?></span>
                                    <button type="button" class="btn-copy-chip" onclick="copySingleText('<?= htmlspecialchars($row['token_code']) ?>')" title="Sao chép Key">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                    </button>
                                </div>
                            </td>
                            <td style="font-weight: 600; color: #0f172a;"><?= htmlspecialchars($row['pname']) ?></td>
                            <td>
                                <?php if ($row['creator_username'] === 'ntamod' || empty($row['creator_username'])): ?>
                                    <span style="background: #eff6ff; color: #1d4ed8; padding: 2px 7px; border-radius: 4px; font-size: 11.5px; font-weight: 600;">Admin</span>
                                <?php else: ?>
                                    <span style="background: #f5f3ff; color: #7c3aed; padding: 2px 7px; border-radius: 4px; font-size: 11.5px; font-weight: 600;">Đại lý: <?= htmlspecialchars($row['creator_username']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                if ($row['type'] == 'static') {
                                    echo '<span class="badge-static">Tĩnh</span>';
                                } else if ($row['type'] == 'dynamic_hours') {
                                    echo '<span class="badge-dynamic-hours">Động (Giờ)</span>';
                                } else {
                                    echo '<span class="badge-dynamic-days">Động (Ngày)</span>';
                                }
                                ?>
                            </td>
                            <td style="font-family: 'JetBrains Mono', monospace; font-size: 12px; color: #334155;">
                                <?php 
                                if ($row['type'] == 'static' || $row['expire_date']) {
                                    echo $row['expire_date'];
                                } else if ($row['type'] == 'dynamic_hours') {
                                    echo $row['duration'].' giờ (Chờ kích hoạt)';
                                } else {
                                    echo $row['duration'].' ngày (Chờ kích hoạt)';
                                }
                                ?>
                            </td>
                            <td>
                                <?php if ($isExpired): ?>
                                    <span class="badge-status-expired">Hết hạn</span>
                                <?php else: ?>
                                    <span class="badge-status-active">Hoạt động</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight: 600; color: #475569;"><?= $used ?> / <?= $row['max_devices'] ?></td>
                            <td>
                                <?php if (!empty($devicesList)): ?>
                                    <?php foreach ($devicesList as $devId): ?>
                                        <div style="display: inline-flex; align-items: center; gap: 6px; margin: 2px 0;">
                                            <span style="font-family: 'JetBrains Mono', monospace; font-size: 11.5px; color: #0284c7; background: #f0f9ff; padding: 3px 8px; border-radius: 4px; border: 1px solid #e0f2fe;" title="<?= htmlspecialchars($devId) ?>">
                                                <?= htmlspecialchars($devId) ?>
                                            </span>
                                            <button type="button" class="btn-copy-chip" onclick="copySingleText('<?= htmlspecialchars($devId) ?>')" title="Sao chép UID">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                            </button>
                                        </div><br>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="color: #94a3b8; font-size: 12px; font-style: italic;">Chưa có</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <?php if ($used > 0): ?>
                                    <a href="?reset_devices=<?= $row['id'] ?>" class="btn btn-sm" style="background: #ea580c; color: white; padding: 5px 11px; border-radius: 6px; font-weight: 600; font-size: 12px; text-decoration: none;" onclick="return confirm('Bạn có chắc muốn Reset thiết bị cho key này?');">Reset Thiết Bị</a>
                                <?php endif; ?>
                                <a href="?delete=<?= $row['id'] ?>" class="btn btn-sm" style="background: #dc2626; color: white; padding: 5px 11px; border-radius: 6px; font-weight: 600; font-size: 12px; text-decoration: none;" onclick="return confirm('Bạn có chắc muốn xóa key này?');">Xóa</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Footer: Phân trang -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 18px; padding-top: 14px; border-top: 1px solid #f1f5f9; font-size: 12.5px; color: #64748b;">
                <div>
                    Hiển thị <strong><?= $totalItems > 0 ? ($offset + 1) : 0 ?></strong> đến <strong><?= min($offset + $limit, $totalItems) ?></strong> trong số <strong><?= $totalItems ?></strong> kết quả
                </div>
                <?php if ($totalPages > 1): 
                    $qP = $_GET;
                ?>
                <div style="display: inline-flex; gap: 4px;">
                    <?php if ($page > 1): 
                        $qP['page'] = $page - 1;
                    ?>
                        <a href="?<?= http_build_query($qP) ?>" class="page-pill">Trước</a>
                    <?php else: ?>
                        <span class="page-pill" style="opacity: 0.5; cursor: not-allowed;">Trước</span>
                    <?php endif; ?>

                    <?php 
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    for ($i = $startPage; $i <= $endPage; $i++): 
                        $qP['page'] = $i;
                    ?>
                        <a href="?<?= http_build_query($qP) ?>" class="page-pill <?= ($i == $page) ? 'active' : '' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): 
                        $qP['page'] = $page + 1;
                    ?>
                        <a href="?<?= http_build_query($qP) ?>" class="page-pill">Sau</a>
                    <?php else: ?>
                        <span class="page-pill" style="opacity: 0.5; cursor: not-allowed;">Sau</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="copyToastFloat" style="position: fixed; bottom: 24px; right: 24px; background: #0f172a; color: #ffffff; padding: 10px 18px; border-radius: 8px; font-size: 13px; font-weight: 500; display: none; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 9999;">
        Đã sao chép vào bộ nhớ tạm!
    </div>

    <script>
    function updateSubmitBtn(qty) {
        var btn = document.getElementById('submitKeyBtn');
        if (!btn) return;
        var iconHtml = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 2l-2 2m-1.5 1.5L16 7l-1.5-1.5-3 3 1.5 1.5-1.5 1.5-2-2-4.5 4.5a3.5 3.5 0 0 0 5 5L15 15l-1.5-1.5 1.5-1.5 1.5 1.5 3-3-1.5-1.5 1.5-1.5 2 2z"/></svg> ';
        btn.innerHTML = iconHtml + (qty > 1 ? 'Tạo Hàng Loạt (' + qty + ' Key)' : 'Tạo Key');
    }

    function copySingleText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(showToast);
        } else {
            var input = document.createElement('input');
            input.value = text;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
            showToast();
        }
    }

    function copyAllCreatedKeys() {
        var ta = document.getElementById('createdKeysText');
        if (!ta) return;
        ta.select();
        ta.setSelectionRange(0, 99999);
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(ta.value).then(showToast);
        } else {
            document.execCommand('copy');
            showToast();
        }
    }

    function showToast() {
        var toast = document.getElementById('copyToastFloat');
        if (toast) {
            toast.style.display = 'block';
            setTimeout(function() { toast.style.display = 'none'; }, 2500);
        }
    }

    function selectAgencyDur(dur, btn) {
        var input = document.getElementById('agencyDurationVal');
        if (input) input.value = dur;
        var all = document.querySelectorAll('.btn-agency-dur');
        all.forEach(function(b) {
            b.style.border = '1.5px solid #cbd5e1';
            b.style.background = '#fff';
            b.style.color = '#475569';
        });
        btn.style.border = '1.5px solid #2563eb';
        btn.style.background = '#eff6ff';
        btn.style.color = '#1d4ed8';
    }

    function selectHour(hours, btn) {
        var input = document.getElementById('dynamicHoursVal');
        if (input) input.value = hours;
        var allBtns = document.querySelectorAll('.btn-hour');
        allBtns.forEach(function(b) {
            b.classList.remove('active-hour');
        });
        if (btn) btn.classList.add('active-hour');
    }

    function toggleType() {
        var keyTypeElem = document.getElementById('keyType');
        if (!keyTypeElem) return;
        var type = keyTypeElem.value;
        var staticInput = document.getElementById('staticInput');
        var dynamicDaysInput = document.getElementById('dynamicDaysInput');
        var dynamicHoursInput = document.getElementById('dynamicHoursInput');

        if (staticInput) staticInput.style.display = (type === 'static') ? 'block' : 'none';
        if (dynamicDaysInput) dynamicDaysInput.style.display = (type === 'dynamic') ? 'block' : 'none';
        if (dynamicHoursInput) dynamicHoursInput.style.display = (type === 'dynamic_hours') ? 'block' : 'none';
    }

    document.addEventListener('DOMContentLoaded', function() {
        toggleType();
        var qtyInput = document.getElementById('quantityVal');
        if (qtyInput) {
            qtyInput.addEventListener('input', function() {
                var val = parseInt(this.value) || 1;
                updateSubmitBtn(val);
            });
        }
    });
    window.addEventListener('load', toggleType);
    setTimeout(toggleType, 100);
    </script>
</body>
</html>