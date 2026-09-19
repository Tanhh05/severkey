<?php
require_once 'auth.php';
require_once 'config.php';

function genKey($length = 16) {
    return substr(str_shuffle(str_repeat($x='0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length/strlen($x)) )),1,$length);
}

if (isset($_POST['create_key'])) {
    $project_id = intval($_POST['project_id']);
    $type = $_POST['type'];
    $max_devices = intval($_POST['max_devices']);
    $token_code = genKey(16);
    $duration = 0;
    $expire_date = "NULL";

    if ($type == 'static') {
        $rawDate = trim($_POST['static_date']);
        $formattedDate = date('Y-m-d H:i:s', strtotime($rawDate));
        $expire_date = "'" . mysqli_real_escape_string($conn, $formattedDate) . "'";
    } else {
        $duration = intval($_POST['dynamic_days']);
    }

    mysqli_query($conn, "INSERT INTO tbl_tokens (project_id, token_code, type, duration, expire_date, max_devices) VALUES ($project_id, '$token_code', '$type', $duration, $expire_date, $max_devices)");
    header("Location: key.php");
}

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    mysqli_query($conn, "DELETE FROM tbl_device_history WHERE token_id=$id");
    mysqli_query($conn, "DELETE FROM tbl_tokens WHERE id=$id");
    header("Location: key.php");
}

if (isset($_GET['reset_devices'])) {
    $id = intval($_GET['reset_devices']);
    mysqli_query($conn, "DELETE FROM tbl_device_history WHERE token_id=$id");
    header("Location: key.php");
}

$keys = mysqli_query($conn, "SELECT t.*, p.name as pname FROM tbl_tokens t JOIN tbl_projects p ON t.project_id = p.id ORDER BY t.id DESC");
$projects = mysqli_query($conn, "SELECT * FROM tbl_projects");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Quản lý Key</title>
    <link rel="stylesheet" href="theme/admin.css">
    <script src="theme/main.js"></script>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">API PANEL</div>
        <div class="sidebar-menu">
            <a href="index.php">Dashboard</a>
            <a href="package.php">Quản lý Package</a>
            <a href="key.php" class="active">Quản lý Key</a>
            <a href="auth.php?logout=1" style="color: #e74c3c; margin-top: 30px;">Đăng Xuất (<?= htmlspecialchars($_SESSION['auth_user']) ?>)</a>
        </div>
    </div>
    <div class="main-content">
        <h2 class="page-title">Quản lý Key</h2>
        <div class="card">
            <form method="POST">
                <div class="form-group">
                    <label>Chọn Package</label>
                    <select name="project_id" class="form-control" required>
                        <?php while ($p = mysqli_fetch_assoc($projects)): ?>
                            <option value="<?= $p['id'] ?>"><?= $p['name'] ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Loại Key</label>
                    <select name="type" id="keyType" onchange="toggleType()" class="form-control" required>
                        <option value="static">Key Tĩnh (Ngày cố định)</option>
                        <option value="dynamic">Key Động (Tính từ lúc kích hoạt)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Số thiết bị tối đa</label>
                    <input type="number" name="max_devices" class="form-control" value="1" required>
                </div>
                <div id="staticInput" class="form-group">
                    <label>Ngày hết hạn</label>
                    <input type="datetime-local" name="static_date" class="form-control">
                </div>
                <div id="dynamicInput" class="form-group" style="display:none;">
                    <label>Số ngày sử dụng</label>
                    <input type="number" name="dynamic_days" class="form-control" placeholder="30">
                </div>
                <button type="submit" name="create_key" class="btn btn-primary">Tạo Key</button>
            </form>
        </div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Key</th>
                        <th>Package</th>
                        <th>Type</th>
                        <th>Expiry/Duration</th>
                        <th>Status</th>
                        <th>Devices</th>
                        <th>Android ID</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $nowTimestamp = time();
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
                        <td><span class="code-box"><?= $row['token_code'] ?></span></td>
                        <td><?= htmlspecialchars($row['pname']) ?></td>
                        <td><?= $row['type'] ?></td>
                        <td><?= ($row['type'] == 'static') ? $row['expire_date'] : ($row['expire_date'] ? $row['expire_date'] : $row['duration'].' days (Pending)') ?></td>
                        <td>
                            <?php if ($isExpired): ?>
                                <span style="background: #e74c3c; color: white; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: bold;">Hết hạn</span>
                            <?php else: ?>
                                <span style="background: #2ecc71; color: white; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: bold;">Hoạt động</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $used ?> / <?= $row['max_devices'] ?></td>
                        <td>
                            <?php if (!empty($devicesList)): ?>
                                <?php foreach ($devicesList as $devId): ?>
                                    <span class="code-box" style="color: #2980b9; background: #ebf5fb; font-size: 11px; padding: 2px 6px; border-radius: 3px; display: inline-block; margin: 1px 0;" title="<?= htmlspecialchars($devId) ?>">
                                        <?= htmlspecialchars($devId) ?>
                                    </span><br>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span style="color: #95a5a6; font-size: 11px; font-style: italic;">Chưa có</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($used > 0): ?>
                                <a href="?reset_devices=<?= $row['id'] ?>" class="btn btn-sm" style="background: #f39c12; color: white; margin-right: 4px;" onclick="return confirm('Bạn có chắc muốn Reset thiết bị cho key này?');">Reset Thiết Bị</a>
                            <?php endif; ?>
                            <a href="?delete=<?= $row['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Bạn có chắc muốn xóa key này?');">Xóa</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>