<?php
require_once 'auth.php';
require_once 'config.php';
requireAdmin();

$msg = '';
$err = '';

// Thêm đại lý mới
if (isset($_POST['add_agency'])) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $selectedPackages = isset($_POST['package_ids']) && is_array($_POST['package_ids']) ? $_POST['package_ids'] : [];

    if (empty($username) || empty($password)) {
        $err = 'Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu!';
    } elseif (empty($selectedPackages)) {
        $err = 'Vui lòng chọn ít nhất một Package để cấp quyền cho đại lý!';
    } else {
        $uEsc = mysqli_real_escape_string($conn, $username);
        $check = mysqli_query($conn, "SELECT id FROM tbl_users WHERE username='$uEsc' LIMIT 1");
        if (mysqli_num_rows($check) > 0) {
            $err = 'Tên tài khoản đại lý này đã tồn tại!';
        } else {
            $pkgIdsStr = implode(',', array_map('intval', $selectedPackages));
            $pEsc = mysqli_real_escape_string($conn, $password);
            $nEsc = mysqli_real_escape_string($conn, $note);
            $sql = "INSERT INTO tbl_users (username, password, role, package_ids, note, status) 
                    VALUES ('$uEsc', '$pEsc', 'agency', '$pkgIdsStr', '$nEsc', 1)";
            if (mysqli_query($conn, $sql)) {
                $msg = "Đã thêm đại lý <strong>" . htmlspecialchars($username) . "</strong> thành công!";
            } else {
                $err = 'Lỗi hệ thống khi thêm đại lý: ' . mysqli_error($conn);
            }
        }
    }
}

// Cập nhật đại lý
if (isset($_POST['edit_agency'])) {
    $agencyId = intval($_POST['agency_id']);
    $password = trim($_POST['password'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $selectedPackages = isset($_POST['package_ids']) && is_array($_POST['package_ids']) ? $_POST['package_ids'] : [];

    if (empty($selectedPackages)) {
        $err = 'Đại lý phải được cấp quyền ít nhất một Package!';
    } else {
        $pkgIdsStr = implode(',', array_map('intval', $selectedPackages));
        $nEsc = mysqli_real_escape_string($conn, $note);
        $updateSql = "UPDATE tbl_users SET package_ids='$pkgIdsStr', note='$nEsc'";
        if (!empty($password)) {
            $pEsc = mysqli_real_escape_string($conn, $password);
            $updateSql .= ", password='$pEsc'";
        }
        $updateSql .= " WHERE id=$agencyId AND role='agency'";
        if (mysqli_query($conn, $updateSql)) {
            $msg = "Đã cập nhật thông tin đại lý thành công!";
        } else {
            $err = 'Lỗi khi cập nhật đại lý!';
        }
    }
}

// Khóa / Mở khóa đại lý
if (isset($_GET['toggle_status'])) {
    $id = intval($_GET['toggle_status']);
    $s = intval($_GET['s']);
    $newStatus = ($s == 1) ? 0 : 1;
    mysqli_query($conn, "UPDATE tbl_users SET status=$newStatus WHERE id=$id AND role='agency'");
    header("Location: agency.php");
    exit;
}

// Xóa đại lý
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    mysqli_query($conn, "DELETE FROM tbl_users WHERE id=$id AND role='agency'");
    header("Location: agency.php");
    exit;
}

$allProjectsQuery = mysqli_query($conn, "SELECT * FROM tbl_projects ORDER BY id DESC");
$allProjects = [];
while ($pr = mysqli_fetch_assoc($allProjectsQuery)) {
    $allProjects[$pr['id']] = $pr;
}

$agenciesQuery = mysqli_query($conn, "SELECT * FROM tbl_users WHERE role='agency' ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Đại lý (Agency)</title>
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
    .field-label {
        font-size: 11.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #64748b;
        margin-bottom: 7px;
        display: block;
    }
    .field-input {
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
    }
    .field-input:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }
    .pkg-checkbox-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 10px;
        background: #f8fafc;
        padding: 14px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
    }
    .pkg-checkbox-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        color: #1e293b;
        font-weight: 500;
        cursor: pointer;
    }
    .pkg-checkbox-item input {
        width: 16px;
        height: 16px;
        accent-color: #2563eb;
        cursor: pointer;
    }
    .btn-primary-custom {
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
        transition: background 0.15s;
    }
    .btn-primary-custom:hover { background: #1d4ed8; }
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
        padding: 14px;
        font-size: 13px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
        color: #1e293b;
    }
    .badge-status-on {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: #ecfdf5;
        color: #059669;
        border: 1px solid #d1fae5;
        padding: 3px 9px;
        border-radius: 9999px;
        font-size: 11.5px;
        font-weight: 600;
    }
    .badge-status-off {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: #fef2f2;
        color: #dc2626;
        border: 1px solid #fee2e2;
        padding: 3px 9px;
        border-radius: 9999px;
        font-size: 11.5px;
        font-weight: 600;
    }
    .pkg-tag {
        display: inline-block;
        background: #eff6ff;
        color: #2563eb;
        border: 1px solid #bfdbfe;
        padding: 2px 7px;
        border-radius: 4px;
        font-size: 11.5px;
        font-weight: 600;
        margin: 2px;
    }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">API PANEL</div>
        <div class="sidebar-menu">
            <a href="index.php">Dashboard</a>
            <a href="package.php">Quản lý Package</a>
            <a href="agency.php" class="active">Quản lý Đại lý</a>
            <a href="key.php">Quản lý Key</a>
            <a href="package_keys.php">Key theo Package & Log</a>
            <a href="mod_files.php">📁 Quản lý File Mod</a>
            <a href="auth.php?logout=1" style="color: #ef4444; margin-top: 30px;">Đăng Xuất (<?= htmlspecialchars($_SESSION['auth_user']) ?>)</a>
        </div>
    </div>

    <div class="main-content">
        <h1 class="page-title">Quản lý Đại lý (Agency)</h1>
        <p class="page-subtitle">Phân quyền đại lý quản lý và tạo / xóa key theo từng Package cụ thể</p>

        <?php if (!empty($msg)): ?>
            <div style="background: #f0fdf4; border-left: 4px solid #16a34a; color: #166534; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 13.5px;">
                <?= $msg ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($err)): ?>
            <div style="background: #fef2f2; border-left: 4px solid #dc2626; color: #991b1b; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 13.5px;">
                <?= $err ?>
            </div>
        <?php endif; ?>

        <!-- Form tạo đại lý mới -->
        <div class="card-modern">
            <h3 style="margin: 0 0 18px 0; font-size: 16px; font-weight: 700; color: #0f172a;">
                ➕ Cấp tài khoản Đại lý mới
            </h3>
            <form method="POST">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 18px; margin-bottom: 18px;">
                    <div>
                        <label class="field-label">Tên đăng nhập (Username)</label>
                        <input type="text" name="username" class="field-input" placeholder="VD: daily_mienbac" required autocomplete="off">
                    </div>
                    <div>
                        <label class="field-label">Mật khẩu (Password)</label>
                        <input type="text" name="password" class="field-input" placeholder="Nhập mật khẩu" required autocomplete="off">
                    </div>
                    <div>
                        <label class="field-label">Ghi chú / Tên đại diện</label>
                        <input type="text" name="note" class="field-input" placeholder="VD: Đại lý Tuấn Anh - Zalo 09xx">
                    </div>
                </div>

                <div style="margin-bottom: 18px;">
                    <label class="field-label">Cấp quyền Package được phép quản lý (Tạo & Xóa Key)</label>
                    <div class="pkg-checkbox-grid">
                        <?php foreach ($allProjects as $pkgId => $pkg): ?>
                            <label class="pkg-checkbox-item">
                                <input type="checkbox" name="package_ids[]" value="<?= $pkgId ?>">
                                <span><?= htmlspecialchars($pkg['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <button type="submit" name="add_agency" class="btn-primary-custom">
                    Tạo tài khoản Đại lý
                </button>
            </form>
        </div>

        <!-- Bảng danh sách đại lý -->
        <div class="card-modern">
            <h3 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 700; color: #0f172a;">
                📋 Danh Sách Đại Lý Hiện Có
            </h3>
            <div style="overflow-x: auto;">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>TÀI KHOẢN</th>
                            <th>MẬT KHẨU</th>
                            <th>PACKAGE ĐƯỢC CẤP QUYỀN</th>
                            <th>GHI CHÚ</th>
                            <th>KEY ĐÃ TẠO</th>
                            <th>TRẠNG THÁI</th>
                            <th>NGÀY TẠO</th>
                            <th style="text-align: right;">THAO TÁC</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (mysqli_num_rows($agenciesQuery) == 0): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; color: #94a3b8; padding: 30px;">
                                Chưa có tài khoản đại lý nào. Hãy thêm đại lý ở form trên!
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php while ($ag = mysqli_fetch_assoc($agenciesQuery)): 
                            $agPkgIds = array_map('intval', explode(',', $ag['package_ids'] ?? ''));
                            
                            // Đếm key đã tạo bởi đại lý này
                            $uEsc = mysqli_real_escape_string($conn, $ag['username']);
                            $cntRes = mysqli_query($conn, "SELECT COUNT(*) as c FROM tbl_tokens WHERE creator_username='$uEsc'");
                            $keyCount = mysqli_fetch_assoc($cntRes)['c'] ?? 0;
                        ?>
                        <tr>
                            <td style="font-weight: 600; color: #64748b;">#<?= $ag['id'] ?></td>
                            <td>
                                <strong style="color: #0f172a; font-size: 14px;"><?= htmlspecialchars($ag['username']) ?></strong>
                            </td>
                            <td>
                                <code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-size: 12px;"><?= htmlspecialchars($ag['password']) ?></code>
                            </td>
                            <td>
                                <?php 
                                $hasAnyPkg = false;
                                foreach ($agPkgIds as $pId): 
                                    if (isset($allProjects[$pId])):
                                        $hasAnyPkg = true;
                                        echo '<span class="pkg-tag">' . htmlspecialchars($allProjects[$pId]['name']) . '</span>';
                                    endif;
                                endforeach;
                                if (!$hasAnyPkg):
                                    echo '<span style="color: #ef4444; font-size: 12px;">Chưa gán Package</span>';
                                endif;
                                ?>
                            </td>
                            <td style="color: #64748b;"><?= htmlspecialchars($ag['note'] ?? '') ?></td>
                            <td>
                                <a href="package_keys.php?agent=<?= urlencode($ag['username']) ?>" style="font-weight: 700; color: #2563eb; text-decoration: none;">
                                    <?= $keyCount ?> key
                                </a>
                            </td>
                            <td>
                                <?php if ($ag['status'] == 1): ?>
                                    <span class="badge-status-on">Hoạt động</span>
                                <?php else: ?>
                                    <span class="badge-status-off">Bị khóa</span>
                                <?php endif; ?>
                            </td>
                            <td style="color: #64748b; font-size: 12px;"><?= $ag['created_at'] ?></td>
                            <td style="text-align: right; white-space: nowrap;">
                                <?php if ($ag['status'] == 1): ?>
                                    <a href="?toggle_status=<?= $ag['id'] ?>&s=1" class="btn btn-sm" style="background: #f59e0b; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11.5px; text-decoration: none;" onclick="return confirm('Khóa tài khoản đại lý này?');">Khóa</a>
                                <?php else: ?>
                                    <a href="?toggle_status=<?= $ag['id'] ?>&s=0" class="btn btn-sm" style="background: #10b981; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11.5px; text-decoration: none;">Mở</a>
                                <?php endif; ?>

                                <button type="button" onclick="openEditModal(<?= htmlspecialchars(json_encode($ag)) ?>)" class="btn btn-sm" style="background: #3b82f6; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11.5px; border: none; cursor: pointer;">Sửa</button>
                                <a href="?delete=<?= $ag['id'] ?>" class="btn btn-sm btn-danger" style="padding: 4px 8px; border-radius: 4px; font-size: 11.5px; text-decoration: none;" onclick="return confirm('Bạn có chắc chắn muốn xóa đại lý này?');">Xóa</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal sửa đại lý -->
    <div id="editModal" style="display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center; padding: 20px;">
        <div style="background: #fff; border-radius: 12px; width: 100%; max-width: 520px; padding: 24px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #0f172a;">Sửa thông tin Đại lý: <span id="modalUsername"></span></h3>
                <button type="button" onclick="closeEditModal()" style="border: none; background: transparent; font-size: 18px; cursor: pointer;">✕</button>
            </div>
            <form method="POST">
                <input type="hidden" name="agency_id" id="modalAgencyId">
                <div style="margin-bottom: 14px;">
                    <label class="field-label">Mật khẩu mới (Để trống nếu không đổi)</label>
                    <input type="text" name="password" id="modalPassword" class="field-input" placeholder="Nhập mật khẩu mới">
                </div>
                <div style="margin-bottom: 14px;">
                    <label class="field-label">Ghi chú</label>
                    <input type="text" name="note" id="modalNote" class="field-input">
                </div>
                <div style="margin-bottom: 18px;">
                    <label class="field-label">Cấp quyền Package</label>
                    <div class="pkg-checkbox-grid">
                        <?php foreach ($allProjects as $pkgId => $pkg): ?>
                            <label class="pkg-checkbox-item">
                                <input type="checkbox" name="package_ids[]" value="<?= $pkgId ?>" class="edit-pkg-check" id="check_pkg_<?= $pkgId ?>">
                                <span><?= htmlspecialchars($pkg['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 8px;">
                    <button type="button" onclick="closeEditModal()" class="btn btn-sm" style="background: #e2e8f0; color: #475569; padding: 8px 16px; border-radius: 6px; border: none; cursor: pointer;">Hủy</button>
                    <button type="submit" name="edit_agency" class="btn-primary-custom" style="padding: 8px 20px;">Lưu Thay Đổi</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openEditModal(ag) {
        document.getElementById('modalAgencyId').value = ag.id;
        document.getElementById('modalUsername').innerText = ag.username;
        document.getElementById('modalPassword').value = '';
        document.getElementById('modalNote').value = ag.note || '';

        var currentPkgs = (ag.package_ids || '').split(',').map(function(s){ return s.trim(); });
        var checks = document.querySelectorAll('.edit-pkg-check');
        checks.forEach(function(c) {
            c.checked = currentPkgs.indexOf(c.value) !== -1;
        });

        document.getElementById('editModal').style.display = 'flex';
    }

    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }
    </script>
</body>
</html>
