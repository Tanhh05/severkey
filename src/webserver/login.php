<?php
session_start();

$VALID_USER = 'ntamod';
$VALID_PASS = '2005';

$error = '';

if (isset($_SESSION['auth_user'])) {
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === $VALID_USER && $password === $VALID_PASS) {
        $_SESSION['auth_user'] = $username;
        header("Location: index.php");
        exit;
    } else {
        $error = 'Tài khoản hoặc mật khẩu không chính xác!';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng Nhập Quản Trị - NTA Mod</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        }
        body {
            background: #0f141c;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            color: #fff;
        }
        .login-card {
            background: #171d27;
            border: 1px solid #283244;
            border-radius: 16px;
            padding: 40px 35px;
            width: 360px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.5);
            text-align: center;
        }
        .logo-box {
            width: 60px;
            height: 60px;
            background: #232c3d;
            border: 1px solid #3b4860;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0 auto 20px;
            color: #3498db;
            font-size: 26px;
            font-weight: bold;
        }
        h2 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 8px;
            color: #ffffff;
            letter-spacing: 0.5px;
        }
        p.subtitle {
            font-size: 13px;
            color: #8c9ba5;
            margin-bottom: 25px;
        }
        .form-group {
            margin-bottom: 18px;
            text-align: left;
        }
        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #94a3b8;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .form-control {
            width: 100%;
            padding: 12px 14px;
            background: #0d1117;
            border: 1px solid #283244;
            border-radius: 8px;
            color: #fff;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }
        .form-control:focus {
            border-color: #3498db;
        }
        .btn-submit {
            width: 100%;
            padding: 13px;
            background: #3498db;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 10px;
            transition: background 0.2s;
        }
        .btn-submit:hover {
            background: #2980b9;
        }
        .alert-error {
            background: rgba(231, 76, 60, 0.15);
            border: 1px solid #e74c3c;
            color: #ff6b6b;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo-box">⚡</div>
        <h2>NTA MOD ADMIN</h2>
        <p class="subtitle">Đăng nhập hệ thống quản lý Server Key</p>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="form-group">
                <label>Tài khoản</label>
                <input type="text" name="username" class="form-control" placeholder="Nhập tài khoản" required autofocus>
            </div>
            <div class="form-group">
                <label>Mật khẩu</label>
                <input type="password" name="password" class="form-control" placeholder="Nhập mật khẩu" required>
            </div>
            <button type="submit" class="btn-submit">ĐĂNG NHẬP</button>
        </form>
    </div>
</body>
</html>
