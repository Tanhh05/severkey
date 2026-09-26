<?php
require_once 'auth.php';
require_once 'config.php';
requireAdmin();

// Lọc theo Package, Đại lý, Hành động, Thời hạn
$selectedPkg = isset($_GET['pkg_id']) ? intval($_GET['pkg_id']) : 0;
$selectedAgent = isset($_GET['agent']) ? trim($_GET['agent']) : '';
$selectedAction = isset($_GET['action']) ? trim($_GET['action']) : '';
$selectedDur = isset($_GET['dur']) ? trim($_GET['dur']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Lấy danh sách Package
$allProjectsQuery = mysqli_query($conn, "SELECT * FROM tbl_projects ORDER BY id DESC");
$allProjects = [];
while ($pr = mysqli_fetch_assoc($allProjectsQuery)) {
    $allProjects[$pr['id']] = $pr;
}

// Lấy danh sách Đại lý cho filter
$agenciesQuery = mysqli_query($conn, "SELECT username, note FROM tbl_users WHERE role='agency' ORDER BY username ASC");
$allAgencies = [];
while ($ag = mysqli_fetch_assoc($agenciesQuery)) {
    $allAgencies[] = $ag;
}

// Thống kê theo Package đã chọn (hoặc tất cả)
$pkgStatWhere = ($selectedPkg > 0) ? " WHERE project_id = $selectedPkg" : "";
$totalKeys = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM tbl_tokens $pkgStatWhere"))['c'] ?? 0;
$activeKeys = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM tbl_tokens " . ($pkgStatWhere ? "$pkgStatWhere AND" : "WHERE") . " (expire_date IS NULL OR expire_date > NOW())"))['c'] ?? 0;
$expiredKeys = $totalKeys - $activeKeys;

// Thống kê log của đại lý
$agencyLogCount = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM tbl_key_logs " . ($selectedPkg > 0 ? "WHERE project_id=$selectedPkg AND" : "WHERE") . " actor_role='agency'"))['c'] ?? 0;

// Xây dựng câu truy vấn LOG CHI TIẾT
$logConditions = [];
if ($selectedPkg > 0) {
    $logConditions[] = "l.project_id = $selectedPkg";
}
if (!empty($selectedAgent)) {
    $agEsc = mysqli_real_escape_string($conn, $selectedAgent);
    $logConditions[] = "l.actor_username = '$agEsc'";
}
if (!empty($selectedAction)) {
    $actEsc = mysqli_real_escape_string($conn, $selectedAction);
    $logConditions[] = "l.action = '$actEsc'";
}
if (!empty($search)) {
    $sEsc = mysqli_real_escape_string($conn, $search);
    $logConditions[] = "(l.token_code LIKE '%$sEsc%' OR l.details LIKE '%$sEsc%')";
}
if (!empty($selectedDur)) {
    $durInt = intval($selectedDur);
    if ($durInt === 1) {
        $logConditions[] = "(l.details LIKE '%1 ngày%' OR l.details LIKE '%thời lượng: 1,%' OR EXISTS (SELECT 1 FROM tbl_tokens tk WHERE tk.token_code = l.token_code AND tk.duration = 1))";
    } else if ($durInt === 7) {
        $logConditions[] = "(l.details LIKE '%7 ngày%' OR l.details LIKE '%thời lượng: 7,%' OR EXISTS (SELECT 1 FROM tbl_tokens tk WHERE tk.token_code = l.token_code AND tk.duration = 7))";
    } else if ($durInt === 30) {
        $logConditions[] = "(l.details LIKE '%30 ngày%' OR l.details LIKE '%thời lượng: 30,%' OR EXISTS (SELECT 1 FROM tbl_tokens tk WHERE tk.token_code = l.token_code AND tk.duration = 30))";
    }
}
$logWhere = !empty($logConditions) ? " WHERE " . implode(' AND ', $logConditions) : "";

$logLimit = 15;
$logPage = isset($_GET['log_page']) ? max(1, intval($_GET['log_page'])) : 1;
$logOffset = ($logPage - 1) * $logLimit;

$countLogsQuery = mysqli_query($conn, "SELECT COUNT(*) as total FROM tbl_key_logs l $logWhere");
$totalLogs = intval(mysqli_fetch_assoc($countLogsQuery)['total'] ?? 0);
$totalLogPages = max(1, ceil($totalLogs / $logLimit));

$logsSql = "SELECT l.*, p.name as pname 
            FROM tbl_key_logs l 
            LEFT JOIN tbl_projects p ON l.project_id = p.id 
            $logWhere 
            ORDER BY l.id DESC 
            LIMIT $logLimit OFFSET $logOffset";
$logsResult = mysqli_query($conn, $logsSql);

// Xây dựng câu truy vấn DANH SÁCH KEY HIỆN TẠI THEO PACKAGE
$keyConditions = [];
if ($selectedPkg > 0) {
    $keyConditions[] = "t.project_id = $selectedPkg";
}
if (!empty($selectedAgent)) {
    $agEsc = mysqli_real_escape_string($conn, $selectedAgent);
    $keyConditions[] = "t.creator_username = '$agEsc'";
}
if (!empty($search)) {
    $sEsc = mysqli_real_escape_string($conn, $search);
    $keyConditions[] = "(t.token_code LIKE '%$sEsc%' OR EXISTS (
        SELECT 1 FROM tbl_device_history dh WHERE dh.token_id = t.id AND dh.device_uuid LIKE '%$sEsc%'
    ))";
}
if (!empty($selectedDur)) {
    $durInt = intval($selectedDur);
    if (in_array($durInt, [1, 7, 30])) {
        $keyConditions[] = "t.type = 'dynamic' AND t.duration = $durInt";
    }
}
$keyWhere = !empty($keyConditions) ? " WHERE " . implode(' AND ', $keyConditions) : "";

$keyLimit = 10;
$keyPage = isset($_GET['key_page']) ? max(1, intval($_GET['key_page'])) : 1;
$keyOffset = ($keyPage - 1) * $keyLimit;

$countKeysQuery = mysqli_query($conn, "SELECT COUNT(*) as total FROM tbl_tokens t $keyWhere");
$totalCurrentKeys = intval(mysqli_fetch_assoc($countKeysQuery)['total'] ?? 0);
$totalKeyPages = max(1, ceil($totalCurrentKeys / $keyLimit));

$currentKeysSql = "SELECT t.*, p.name as pname 
                   FROM tbl_tokens t 
                   JOIN tbl_projects p ON t.project_id = p.id 
                   $keyWhere 
                   ORDER BY t.id DESC 
                   LIMIT $keyLimit OFFSET $keyOffset";
$currentKeysResult = mysqli_query($conn, $currentKeysSql);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Key theo Package & Lịch Sử Đại Lý</title>
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

    /* Stats Grid */
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
        box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    }
    .stat-card-title {
        font-size: 12px;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 8px;
    }
    .stat-card-value {
        font-size: 26px;
        font-weight: 800;
        color: #0f172a;
    }

    /* Filters */
    .filter-bar {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 20px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 16px 20px;
    }
    .filter-select, .filter-input {
        height: 38px;
        padding: 6px 12px;
        font-size: 13px;
        color: #0f172a;
        background: #ffffff;
        border: 1.5px solid #e2e8f0;
        border-radius: 7px;
        outline: none;
        font-family: inherit;
    }
    .filter-select:focus, .filter-input:focus {
        border-color: #2563eb;
    }
    .btn-filter {
        height: 38px;
        background: #2563eb;
        color: #ffffff;
        font-weight: 600;
        font-size: 13px;
        padding: 0 18px;
        border-radius: 7px;
        border: none;
        cursor: pointer;
    }
    .btn-filter-reset {
        height: 38px;
        background: #f1f5f9;
        color: #475569;
        font-weight: 600;
        font-size: 13px;
        padding: 0 14px;
        border-radius: 7px;
        border: 1px solid #e2e8f0;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
    }

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

    /* Badges */
    .badge-act-create {
        background: #ecfdf5;
        color: #059669;
        border: 1px solid #a7f3d0;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 11.5px;
        font-weight: 700;
    }
    .badge-act-delete {
        background: #fef2f2;
        color: #dc2626;
        border: 1px solid #fecaca;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 11.5px;
        font-weight: 700;
    }
    .badge-act-reset {
        background: #fffbeb;
        color: #d97706;
        border: 1px solid #fde68a;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 11.5px;
        font-weight: 700;
    }
    .badge-agent {
        background: #f5f3ff;
        color: #7c3aed;
        border: 1px solid #ddd6fe;
        padding: 2px 7px;
        border-radius: 4px;
        font-size: 11.5px;
        font-weight: 600;
    }
    .badge-admin {
        background: #eff6ff;
        color: #1d4ed8;
        border: 1px solid #bfdbfe;
        padding: 2px 7px;
        border-radius: 4px;
        font-size: 11.5px;
        font-weight: 600;
    }
    .token-chip {
        font-family: 'JetBrains Mono', monospace;
        font-size: 12px;
        font-weight: 600;
        color: #dc2626;
        background: #fef2f2;
        padding: 3px 7px;
        border-radius: 5px;
        border: 1px solid #fee2e2;
    }
    .pagination-pills {
        display: inline-flex;
        gap: 4px;
    }
    .page-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 30px;
        height: 30px;
        padding: 0 7px;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 12px;
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
            <a href="index.php">Dashboard</a>
            <a href="package.php">Quản lý Package</a>
            <a href="agency.php">Quản lý Đại lý</a>
            <a href="key.php">Quản lý Key</a>
            <a href="package_keys.php" class="active">Key theo Package & Log</a>
            <a href="mod_files.php">📁 Quản lý File Mod</a>
            <a href="auth.php?logout=1" style="color: #ef4444; margin-top: 30px;">Đăng Xuất (<?= htmlspecialchars($_SESSION['auth_user']) ?>)</a>
        </div>
    </div>

    <div class="main-content">
        <h1 class="page-title">Quản lý Key theo Package & Log Đại lý</h1>
        <p class="page-subtitle">Theo dõi tổng quan key theo từng package và nhật ký chi tiết hành động tạo / xóa của các đại lý</p>

        <!-- Thống kê Card -->
        <div class="stats-row">
            <div class="stat-card" style="border-left: 4px solid #3b82f6;">
                <div class="stat-card-title">Tổng số Key</div>
                <div class="stat-card-value"><?= $totalKeys ?></div>
            </div>
            <div class="stat-card" style="border-left: 4px solid #10b981;">
                <div class="stat-card-title">Key Đang Hoạt Động</div>
                <div class="stat-card-value" style="color: #059669;"><?= $activeKeys ?></div>
            </div>
            <div class="stat-card" style="border-left: 4px solid #ef4444;">
                <div class="stat-card-title">Key Đã Hết Hạn</div>
                <div class="stat-card-value" style="color: #dc2626;"><?= $expiredKeys ?></div>
            </div>
            <div class="stat-card" style="border-left: 4px solid #8b5cf6;">
                <div class="stat-card-title">Hành Động Đại Lý</div>
                <div class="stat-card-value" style="color: #7c3aed;"><?= $agencyLogCount ?></div>
            </div>
        </div>

        <!-- Bộ lọc chung -->
        <form method="GET" class="filter-bar">
            <div>
                <label style="font-size: 11px; font-weight: 700; color: #64748b; display: block; margin-bottom: 4px;">CHỌN PACKAGE</label>
                <select name="pkg_id" class="filter-select" onchange="this.form.submit()">
                    <option value="0">-- Tất cả Package --</option>
                    <?php foreach ($allProjects as $pId => $pr): ?>
                        <option value="<?= $pId ?>" <?= ($selectedPkg == $pId) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($pr['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="font-size: 11px; font-weight: 700; color: #64748b; display: block; margin-bottom: 4px;">LỌC THEO ĐẠI LÝ</label>
                <select name="agent" class="filter-select">
                    <option value="">-- Tất cả người tạo / đại lý --</option>
                    <?php foreach ($allAgencies as $ag): ?>
                        <option value="<?= htmlspecialchars($ag['username']) ?>" <?= ($selectedAgent === $ag['username']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ag['username']) ?> <?= !empty($ag['note']) ? '('.htmlspecialchars($ag['note']).')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="font-size: 11px; font-weight: 700; color: #64748b; display: block; margin-bottom: 4px;">HÀNH ĐỘNG (LOG)</label>
                <select name="action" class="filter-select">
                    <option value="">-- Tất cả hành động --</option>
                    <option value="CREATE" <?= ($selectedAction === 'CREATE') ? 'selected' : '' ?>>Tạo Key (CREATE)</option>
                    <option value="DELETE" <?= ($selectedAction === 'DELETE') ? 'selected' : '' ?>>Xóa Key (DELETE)</option>
                    <option value="RESET" <?= ($selectedAction === 'RESET') ? 'selected' : '' ?>>Reset Thiết Bị (RESET)</option>
                </select>
            </div>

            <div>
                <label style="font-size: 11px; font-weight: 700; color: #64748b; display: block; margin-bottom: 4px;">THỜI HẠN KEY</label>
                <select name="dur" class="filter-select">
                    <option value="">-- Tất cả thời hạn --</option>
                    <option value="1" <?= ($selectedDur === '1') ? 'selected' : '' ?>>⏱️ 1 Ngày</option>
                    <option value="7" <?= ($selectedDur === '7') ? 'selected' : '' ?>>📅 1 Tuần (7 Ngày)</option>
                    <option value="30" <?= ($selectedDur === '30') ? 'selected' : '' ?>>⭐ 1 Tháng (30 Ngày)</option>
                </select>
            </div>

            <div style="flex: 1; min-width: 180px;">
                <label style="font-size: 11px; font-weight: 700; color: #64748b; display: block; margin-bottom: 4px;">TÌM KIẾM MÃ KEY / UID</label>
                <input type="text" name="search" class="filter-input" value="<?= htmlspecialchars($search) ?>" placeholder="Nhập mã key..." style="width: 100%;">
            </div>

            <div style="align-self: flex-end;">
                <button type="submit" class="btn-filter">Lọc Dữ Liệu</button>
                <a href="package_keys.php" class="btn-filter-reset">Đặt lại</a>
            </div>
        </form>

        <!-- SECTION 1: LOG CHI TIẾT HÀNH ĐỘNG CỦA ĐẠI LÝ -->
        <div class="card-modern" style="border-top: 4px solid #8b5cf6;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <div>
                    <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                        <span>📜</span> Log Chi Tiết Hành Động Đại Lý
                    </h3>
                    <p style="margin: 4px 0 0 0; font-size: 12.5px; color: #64748b;">
                        Ghi nhận toàn bộ thao tác Tạo key, Xóa key và Reset thiết bị do đại lý thực hiện
                    </p>
                </div>
                <div style="font-size: 13px; color: #64748b;">
                    Tổng số log: <strong><?= $totalLogs ?></strong>
                </div>
            </div>

            <div style="overflow-x: auto;">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>THỜI GIAN</th>
                            <th>NGƯỜI THỰC HIỆN</th>
                            <th>ROLE</th>
                            <th>PACKAGE</th>
                            <th>HÀNH ĐỘNG</th>
                            <th>MÃ KEY</th>
                            <th>CHI TIẾT THAO TÁC</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($logsResult) == 0): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: #94a3b8; padding: 26px;">
                                Chưa có nhật ký log nào phù hợp với bộ lọc hiện tại.
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php while ($log = mysqli_fetch_assoc($logsResult)): ?>
                        <tr>
                            <td style="color: #64748b; font-size: 12px; font-family: 'JetBrains Mono', monospace; white-space: nowrap;">
                                <?= $log['created_at'] ?>
                            </td>
                            <td>
                                <strong style="color: #0f172a;"><?= htmlspecialchars($log['actor_username']) ?></strong>
                            </td>
                            <td>
                                <?php if ($log['actor_role'] === 'agency'): ?>
                                    <span class="badge-agent">Đại lý</span>
                                <?php else: ?>
                                    <span class="badge-admin">Admin</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight: 500; color: #334155;">
                                <?= htmlspecialchars($log['pname'] ?? ('ID: ' . $log['project_id'])) ?>
                            </td>
                            <td>
                                <?php 
                                if ($log['action'] === 'CREATE') {
                                    echo '<span class="badge-act-create">TẠO KEY</span>';
                                } else if ($log['action'] === 'DELETE') {
                                    echo '<span class="badge-act-delete">XÓA KEY</span>';
                                } else if ($log['action'] === 'RESET') {
                                    echo '<span class="badge-act-reset">RESET</span>';
                                } else {
                                    echo '<span class="badge-admin">' . htmlspecialchars($log['action']) . '</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <span class="token-chip"><?= htmlspecialchars($log['token_code']) ?></span>
                            </td>
                            <td style="color: #475569; font-size: 12.5px;">
                                <?= htmlspecialchars($log['details'] ?? '') ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Phân trang Log -->
            <?php if ($totalLogPages > 1): 
                $qParams = $_GET;
            ?>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 16px; padding-top: 12px; border-top: 1px solid #f1f5f9; font-size: 12.5px; color: #64748b;">
                <div>Trang Log <?= $logPage ?> / <?= $totalLogPages ?> (Tổng <?= $totalLogs ?> log)</div>
                <div class="pagination-pills">
                    <?php for ($p = 1; $p <= $totalLogPages; $p++): 
                        $qParams['log_page'] = $p;
                    ?>
                        <a href="?<?= http_build_query($qParams) ?>" class="page-pill <?= ($p == $logPage) ? 'active' : '' ?>">
                            <?= $p ?>
                        </a>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- SECTION 2: DANH SÁCH KEY HIỆN TẠI (KÈM CỘT NGƯỜI TẠO ĐẠI LÝ) -->
        <div class="card-modern" style="border-top: 4px solid #2563eb;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <div>
                    <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                        <span>🔑</span> Danh Sách Key Trong Package Hiện Tại
                    </h3>
                    <p style="margin: 4px 0 0 0; font-size: 12.5px; color: #64748b;">
                        Theo dõi danh sách các key đang tồn tại trên hệ thống kèm thông tin Đại lý đã tạo mã
                    </p>
                </div>
                <div style="font-size: 13px; color: #64748b;">
                    Tổng số: <strong><?= $totalCurrentKeys ?> key</strong>
                </div>
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
                            <th style="text-align: right;">THAO TÁC</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($currentKeysResult) == 0): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; color: #94a3b8; padding: 26px;">
                                Không có key nào theo tiêu chí lọc này.
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php 
                        $now = time();
                        while ($k = mysqli_fetch_assoc($currentKeysResult)): 
                            $devRes = mysqli_query($conn, "SELECT device_uuid FROM tbl_device_history WHERE token_id=".$k['id']);
                            $devs = [];
                            while ($d = mysqli_fetch_assoc($devRes)) { $devs[] = $d['device_uuid']; }
                            $used = count($devs);
                            $isExp = ($k['expire_date'] && strtotime($k['expire_date']) <= $now);
                        ?>
                        <tr>
                            <td>
                                <span class="token-chip"><?= htmlspecialchars($k['token_code']) ?></span>
                            </td>
                            <td style="font-weight: 600; color: #0f172a;"><?= htmlspecialchars($k['pname']) ?></td>
                            <td>
                                <?php if ($k['creator_username'] === 'ntamod' || empty($k['creator_username'])): ?>
                                    <span class="badge-admin">Admin (<?= htmlspecialchars($k['creator_username'] ?? 'ntamod') ?>)</span>
                                <?php else: ?>
                                    <span class="badge-agent">Đại lý: <?= htmlspecialchars($k['creator_username']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                if ($k['type'] == 'static') {
                                    echo '<span style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 11.5px;">Tĩnh</span>';
                                } else if ($k['type'] == 'dynamic_hours') {
                                    echo '<span style="background: #faf5ff; color: #9333ea; padding: 2px 6px; border-radius: 4px; font-size: 11.5px; font-weight: 600;">Động (Giờ)</span>';
                                } else {
                                    echo '<span style="background: #eff6ff; color: #2563eb; padding: 2px 6px; border-radius: 4px; font-size: 11.5px; font-weight: 600;">Động (Ngày)</span>';
                                }
                                ?>
                            </td>
                            <td style="font-size: 12px; font-family: 'JetBrains Mono', monospace; color: #334155;">
                                <?php 
                                if ($k['type'] == 'static' || $k['expire_date']) {
                                    echo $k['expire_date'];
                                } else if ($k['type'] == 'dynamic_hours') {
                                    echo $k['duration'] . ' giờ (Chờ kích hoạt)';
                                } else {
                                    echo $k['duration'] . ' ngày (Chờ kích hoạt)';
                                }
                                ?>
                            </td>
                            <td>
                                <?php if ($isExp): ?>
                                    <span style="color: #dc2626; font-weight: 700; font-size: 11.5px;">● Hết hạn</span>
                                <?php else: ?>
                                    <span style="color: #059669; font-weight: 700; font-size: 11.5px;">● Hoạt động</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight: 600; color: #475569;"><?= $used ?> / <?= $k['max_devices'] ?></td>
                            <td>
                                <?php if (!empty($devs)): ?>
                                    <?php foreach ($devs as $duid): ?>
                                        <code style="background: #f0f9ff; color: #0284c7; padding: 2px 5px; border-radius: 3px; font-size: 11px; display: inline-block; margin: 1px 0;" title="<?= htmlspecialchars($duid) ?>">
                                            <?= htmlspecialchars($duid) ?>
                                        </code><br>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="color: #94a3b8; font-size: 12px; font-style: italic;">Chưa kích hoạt</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <?php if ($used > 0): ?>
                                    <a href="key.php?reset_devices=<?= $k['id'] ?>" class="btn btn-sm" style="background: #ea580c; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11.5px; text-decoration: none;" onclick="return confirm('Reset thiết bị cho key này?');">Reset</a>
                                <?php endif; ?>
                                <a href="key.php?delete=<?= $k['id'] ?>" class="btn btn-sm btn-danger" style="padding: 4px 8px; border-radius: 4px; font-size: 11.5px; text-decoration: none;" onclick="return confirm('Xóa key này?');">Xóa</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Phân trang Key -->
            <?php if ($totalKeyPages > 1): 
                $kParams = $_GET;
            ?>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 16px; padding-top: 12px; border-top: 1px solid #f1f5f9; font-size: 12.5px; color: #64748b;">
                <div>Trang Key <?= $keyPage ?> / <?= $totalKeyPages ?> (Tổng <?= $totalCurrentKeys ?> key)</div>
                <div class="pagination-pills">
                    <?php for ($kp = 1; $kp <= $totalKeyPages; $kp++): 
                        $kParams['key_page'] = $kp;
                    ?>
                        <a href="?<?= http_build_query($kParams) ?>" class="page-pill <?= ($kp == $keyPage) ? 'active' : '' ?>">
                            <?= $kp ?>
                        </a>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
