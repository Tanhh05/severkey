<?php
session_start();
require_once __DIR__ . '/config.php';

$error = '';

if (isset($_GET['err']) && $_GET['err'] === 'locked') {
    $error = 'Tài khoản đại lý đã bị tạm khóa!';
}

if (isset($_SESSION['auth_user'])) {
    if (isset($_SESSION['auth_role']) && $_SESSION['auth_role'] === 'agency') {
        header("Location: key.php");
    } else {
        header("Location: index.php");
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    $uEsc = mysqli_real_escape_string($conn, $username);
    $res = mysqli_query($conn, "SELECT * FROM tbl_users WHERE username='$uEsc' LIMIT 1");
    $user = $res ? mysqli_fetch_assoc($res) : null;

    if ($user && $user['password'] === $password) {
        if ($user['status'] != 1) {
            $error = 'Tài khoản này đã bị tạm khóa! Vui lòng liên hệ Admin.';
        } else {
            $_SESSION['auth_user'] = $user['username'];
            $_SESSION['auth_role'] = $user['role'];
            $_SESSION['auth_package_ids'] = $user['package_ids'];

            if ($user['role'] === 'agency') {
                header("Location: key.php");
            } else {
                header("Location: index.php");
            }
            exit;
        }
    } else if ($username === 'ntamod' && $password === '2005') {
        $_SESSION['auth_user'] = 'ntamod';
        $_SESSION['auth_role'] = 'admin';
        $_SESSION['auth_package_ids'] = 'ALL';
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
    <title>Đăng nhập vào Server Key</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Roboto', 'Segoe UI', Helvetica, Arial, sans-serif;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            background-color: #ffffff;
            color: #1c1e21;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 30px 20px;
        }

        .main-brand-header {
            font-size: 48px;
            font-weight: 900;
            color: #0866ff;
            text-align: center;
            letter-spacing: -0.8px;
            margin-bottom: 24px;
            user-select: none;
            text-transform: uppercase;
        }

        @media (max-width: 480px) {
            .main-brand-header {
                font-size: 36px;
                margin-bottom: 18px;
            }
        }

        .login-wrapper {
            width: 100%;
            max-width: 380px;
            display: flex;
            flex-direction: column;
        }

        .login-title {
            font-size: 20px;
            font-weight: 700;
            color: #1c1e21;
            margin-bottom: 24px;
            letter-spacing: -0.2px;
        }

        .alert-box {
            background: #ffebe8;
            border: 1px solid #dd3c10;
            color: #dd3c10;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13.5px;
            margin-bottom: 16px;
            text-align: left;
        }

        .form-group {
            margin-bottom: 12px;
        }

        .form-input {
            width: 100%;
            height: 52px;
            padding: 14px 16px;
            font-size: 15px;
            background: #ffffff;
            border: 1.5px solid #ccd0d5;
            border-radius: 12px;
            color: #1c1e21;
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .form-input:focus {
            border-color: #0866ff;
            box-shadow: 0 0 0 2px rgba(8, 102, 255, 0.15);
        }

        .form-input::placeholder {
            color: #8a8d91;
        }

        .btn-login {
            width: 100%;
            height: 48px;
            background: #0866ff;
            color: #ffffff;
            font-size: 16px;
            font-weight: 600;
            border: none;
            border-radius: 24px;
            cursor: pointer;
            margin-top: 6px;
            margin-bottom: 14px;
            transition: background 0.15s ease, transform 0.08s ease;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .btn-login:hover {
            background: #005ce6;
        }

        .btn-login:active {
            transform: scale(0.99);
        }

        .btn-create-account {
            width: 100%;
            height: 44px;
            background: transparent;
            color: #0866ff;
            font-size: 14.5px;
            font-weight: 600;
            border: 1.5px solid #0866ff;
            border-radius: 24px;
            cursor: pointer;
            text-decoration: none;
            display: flex;
            justify-content: center;
            align-items: center;
            transition: background 0.15s ease;
        }

        .btn-create-account:hover {
            background: #f0f6ff;
        }

        /* Bottom branding */
        .footer-branding {
            margin-top: auto;
            padding: 24px 0 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            color: #65676b;
            font-size: 13.5px;
            font-weight: 600;
        }

        .footer-branding svg {
            fill: #0866ff;
        }
    </style>
</head>
<body>
    <div class="main-brand-header">NTA MOD PANEL</div>
    <div class="login-wrapper">
        <h1 class="login-title">Đăng nhập vào Server Key</h1>

        <?php if (!empty($error)): ?>
            <div class="alert-box"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="form-group">
                <input type="text" name="username" class="form-input" placeholder="Tên đăng nhập hoặc tài khoản" required autofocus>
            </div>
            <div class="form-group">
                <input type="password" name="password" class="form-input" placeholder="Mật khẩu" required>
            </div>
            <button type="submit" class="btn-login">Đăng nhập</button>
        </form>

        <a href="https://t.me/tanhh05" target="_blank" class="btn-create-account">Liên hệ hỗ trợ Admin</a>
    </div>
</body>
</html>
