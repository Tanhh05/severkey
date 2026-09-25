<?php
require_once 'auth.php';
require_once 'config.php';
requireAdmin();

$count_packages = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM tbl_projects"))['c'] ?? 0;
$count_keys = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM tbl_tokens"))['c'] ?? 0;
$count_agencies = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM tbl_users WHERE role='agency'"))['c'] ?? 0;
$count_logs = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM tbl_key_logs"))['c'] ?? 0;

$recentLogs = mysqli_query($conn, "SELECT l.*, p.name as pname FROM tbl_key_logs l LEFT JOIN tbl_projects p ON l.project_id=p.id ORDER BY l.id DESC LIMIT 8");
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Admin - NTA MOD</title>
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
    .card-modern {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 24px 28px;
        margin-bottom: 24px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
    }
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 18px;
        margin-bottom: 28px;
    }
    .stat-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 20px 22px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
    }
    .stat-title {
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #64748b;
        margin-bottom: 8px;
    }
    .stat-val {
        font-size: 30px;
        font-weight: 800;
        color: #0f172a;
        margin: 0;
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
    .token-chip {
        font-family: 'JetBrains Mono', monospace;
        font-size: 12px;
        font-weight: 600;
        color: #dc2626;
        background: #fef2f2;
        padding: 3px 7px;
        border-radius: 5px;
    }
    .badge-act-create {
        background: #ecfdf5;
        color: #059669;
        padding: 2px 7px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 700;
    }
    .badge-act-delete {
        background: #fef2f2;
        color: #dc2626;
        padding: 2px 7px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 700;
    }
    .badge-act-reset {
        background: #fffbeb;
        color: #d97706;
        padding: 2px 7px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 700;
    }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">API PANEL</div>
        <div class="sidebar-menu">
            <a href="index.php" class="active">Dashboard</a>
            <a href="package.php">Quản lý Package</a>
            <a href="agency.php">Quản lý Đại lý</a>
            <a href="key.php">Quản lý Key</a>
            <a href="package_keys.php">Key theo Package & Log</a>
            <a href="auth.php?logout=1" style="color: #ef4444; margin-top: 30px;">Đăng Xuất (<?= htmlspecialchars($_SESSION['auth_user']) ?>)</a>
        </div>
    </div>

    <div class="main-content">
        <h1 class="page-title">Tổng quan Hệ thống</h1>
        <p class="page-subtitle">Bảng điều khiển trung tâm quản trị máy chủ Server Key NTA MOD</p>

        <div class="stats-grid">
            <div class="stat-card" style="border-left: 4px solid #3b82f6;">
                <div class="stat-title">Total Packages</div>
                <div class="stat-val"><?= $count_packages ?></div>
            </div>
            <div class="stat-card" style="border-left: 4px solid #10b981;">
                <div class="stat-title">Total Keys</div>
                <div class="stat-val" style="color: #059669;"><?= $count_keys ?></div>
            </div>
            <div class="stat-card" style="border-left: 4px solid #8b5cf6;">
                <div class="stat-title">Tài khoản Đại lý</div>
                <div class="stat-val" style="color: #7c3aed;"><?= $count_agencies ?></div>
            </div>
            <div class="stat-card" style="border-left: 4px solid #f59e0b;">
                <div class="stat-title">Nhật ký Thao tác</div>
                <div class="stat-val" style="color: #d97706;"><?= $count_logs ?></div>
            </div>
        </div>

        <div class="card-modern">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
                <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #0f172a;">
                    ⚡ Nhật ký thao tác Đại lý gần đây
                </h3>
                <a href="package_keys.php" style="font-size: 13px; font-weight: 600; color: #2563eb; text-decoration: none;">
                    Xem toàn bộ log &rsaquo;
                </a>
            </div>

            <div style="overflow-x: auto;">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>THỜI GIAN</th>
                            <th>NGƯỜI THỰC HIỆN</th>
                            <th>PACKAGE</th>
                            <th>HÀNH ĐỘNG</th>
                            <th>MÃ KEY</th>
                            <th>CHI TIẾT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($recentLogs) == 0): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: #94a3b8; padding: 25px;">
                                Chưa có nhật ký thao tác nào được ghi nhận.
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php while ($rl = mysqli_fetch_assoc($recentLogs)): ?>
                        <tr>
                            <td style="font-size: 12px; font-family: monospace; color: #64748b;"><?= $rl['created_at'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($rl['actor_username']) ?></strong>
                                <span style="font-size: 11px; color: #64748b;">(<?= $rl['actor_role'] ?>)</span>
                            </td>
                            <td style="font-weight: 500;"><?= htmlspecialchars($rl['pname'] ?? ('ID: ' . $rl['project_id'])) ?></td>
                            <td>
                                <?php 
                                if ($rl['action'] === 'CREATE') echo '<span class="badge-act-create">TẠO KEY</span>';
                                else if ($rl['action'] === 'DELETE') echo '<span class="badge-act-delete">XÓA KEY</span>';
                                else if ($rl['action'] === 'RESET') echo '<span class="badge-act-reset">RESET</span>';
                                else echo htmlspecialchars($rl['action']);
                                ?>
                            </td>
                            <td><span class="token-chip"><?= htmlspecialchars($rl['token_code']) ?></span></td>
                            <td style="color: #64748b; font-size: 12.5px;"><?= htmlspecialchars($rl['details'] ?? '') ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>