<?php
ob_start(); // 启用输出缓冲，确保 header() 跳转正常工作

/**
 * 用户登录页面
 */

// 尝试加载必需的依赖，捕获初始化错误
try {
    require_once __DIR__ . '/auth.php';
    $auth = getAuth();
    $captcha = getCaptcha();
} catch (Exception $e) {
    // 配置或数据库初始化失败，显示友好错误页面
    $initError = $e->getMessage();
    $captcha = null; // 设置为 null 避免后续调用失败
    $auth = null;
}

// 如果已登录，跳转到首页或指定页面
if (isset($auth) && $auth && $auth->isLoggedIn()) {
    $redirect = !empty($_GET['redirect']) ? $_GET['redirect'] : 'index.php';
    // 防止开放重定向攻击
    if (strpos($redirect, '//') !== false || strpos($redirect, ':') !== false) {
        $redirect = 'index.php';
    }
    header('Location: ' . $redirect);
    exit;
}

$error = '';
$success = '';

// 处理登录请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($auth) && $auth) {
    $usernameOrEmail = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    $captchaInput = trim($_POST['captcha'] ?? '');

    $result = $auth->login($usernameOrEmail, $password, $remember, $captchaInput);
    if ($result['success']) {
        $redirect = !empty($_POST['redirect']) ? $_POST['redirect'] : 'index.php';
        // 防止开放重定向攻击
        if (strpos($redirect, '//') !== false || strpos($redirect, ':') !== false) {
            $redirect = 'index.php';
        }
        header('Location: ' . $redirect);
        exit;
    } else {
        $error = $result['message'];
    }
}

$redirect = $_GET['redirect'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - 老司机的香蕉</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .auth-container {
            max-width: 420px;
            margin: 60px auto;
            padding: 0 20px;
        }
        .auth-box {
            background: var(--panel-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 40px 35px;
        }
        .auth-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .auth-header h1 {
            font-size: 1.8rem;
            margin-bottom: 8px;
            color: #333;
        }
        .auth-header p {
            color: #666;
            font-size: 0.95rem;
        }
        .auth-header .logo {
            font-size: 3rem;
            margin-bottom: 15px;
        }
        .auth-form .form-group {
            margin-bottom: 20px;
        }
        .auth-form label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #444;
        }
        .auth-form input[type="text"],
        .auth-form input[type="email"],
        .auth-form input[type="password"] {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .auth-form input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(255, 193, 7, 0.15);
        }
        .auth-form .btn-primary {
            width: 100%;
            padding: 14px;
            font-size: 1.1rem;
            margin-top: 10px;
        }
        .auth-footer {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        .auth-footer a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
        }
        .auth-footer a:hover {
            text-decoration: underline;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 0.95rem;
        }
        .alert-error {
            background: #fee;
            color: #c62828;
            border: 1px solid #fcc;
        }
        .alert-success {
            background: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #666;
            text-decoration: none;
            font-size: 0.9rem;
            margin-bottom: 20px;
        }
        .back-link:hover {
            color: var(--primary-color);
        }
        .input-icon-wrapper {
            position: relative;
        }
        .input-icon-wrapper i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #aaa;
        }
        .input-icon-wrapper input {
            padding-left: 42px;
        }
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 0.95rem;
            color: #555;
        }
        .remember-me input {
            width: auto;
            margin: 0;
        }
        .forgot-password {
            color: #888;
            font-size: 0.9rem;
            text-decoration: none;
        }
        .forgot-password:hover {
            color: var(--primary-color);
        }
        .captcha-group {
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }
        .captcha-input {
            flex: 1;
        }
        .captcha-image-wrapper {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .captcha-image {
            width: 120px;
            height: 40px;
            border: 1px solid #ddd;
            border-radius: 6px;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .captcha-image:hover {
            opacity: 0.8;
        }
        .captcha-refresh {
            font-size: 0.85rem;
            color: var(--primary-color);
            text-decoration: none;
            text-align: center;
        }
        .captcha-refresh:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <?php if (isset($initError)): ?>
        <!-- 初始化错误时显示友好错误页面 -->
        <div class="auth-container">
            <div class="auth-box">
                <div class="auth-header">
                    <div class="logo">&#127820;</div>
                    <h1 style="color: #c62828;">系统初始化失败</h1>
                </div>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>错误信息：</strong><br>
                    <?php echo htmlspecialchars($initError); ?>
                </div>
                <div style="margin-top: 20px; padding: 15px; background: #f5f5f5; border-radius: 6px;">
                    <p style="margin: 0 0 10px 0; font-weight: bold;">可能的原因：</p>
                    <ul style="margin: 0; padding-left: 20px;">
                        <li>配置文件 (config.php) 不存在或格式错误</li>
                        <li>数据库文件损坏或权限不足</li>
                        <li>必需的 PHP 扩展未安装</li>
                    </ul>
                    <p style="margin: 15px 0 0 0; font-weight: bold;">建议操作：</p>
                    <ul style="margin: 0; padding-left: 20px;">
                        <li>检查 config.php.example 并创建正确的 config.php</li>
                        <li>确认 database 目录存在且具有写入权限</li>
                        <li>查看服务器错误日志获取详细信息</li>
                    </ul>
                </div>
                <div style="text-align: center; margin-top: 20px;">
                    <a href="index.php" class="btn-primary" style="display: inline-block; padding: 12px 24px; text-decoration: none;">
                        <i class="fas fa-home"></i> 返回首页
                    </a>
                </div>
            </div>
        </div>
    <?php else: ?>
    <div class="auth-container">
        <a href="index.php" class="back-link">
            <i class="fas fa-arrow-left"></i> 返回首页
        </a>

        <div class="auth-box">
            <div class="auth-header">
                <div class="logo">&#127820;</div>
                <h1>欢迎回来</h1>
                <p>登录您的账号继续使用</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form class="auth-form" method="POST" action="">
                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">

                <div class="form-group">
                    <label for="username">用户名 / 邮箱</label>
                    <div class="input-icon-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" id="username" name="username"
                               placeholder="输入用户名或邮箱"
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                               required autocomplete="username">
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">密码</label>
                    <div class="input-icon-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password"
                               placeholder="输入密码"
                               required autocomplete="current-password">
                    </div>
                </div>

                <?php if (isset($captcha) && $captcha && $captcha->isLoginEnabled()): ?>
                <div class="form-group">
                    <label for="captcha">验证码</label>
                    <div class="captcha-group">
                        <div class="captcha-input">
                            <input type="text" id="captcha" name="captcha"
                                   placeholder="请输入验证码"
                                   maxlength="4"
                                   required autocomplete="off">
                        </div>
                        <div class="captcha-image-wrapper">
                            <img src="captcha_svg.php?t=<?php echo time(); ?>"
                                 alt="验证码"
                                 class="captcha-image"
                                 id="captcha-image"
                                 onclick="refreshCaptcha()">
                            <a href="javascript:void(0)"
                               onclick="refreshCaptcha()"
                               class="captcha-refresh">
                                <i class="fas fa-sync-alt"></i> 换一张
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-options">
                    <label class="remember-me">
                        <input type="checkbox" name="remember" value="1">
                        <span>记住我</span>
                    </label>
                    <!-- <a href="forgot_password.php" class="forgot-password">忘记密码？</a> -->
                </div>

                <button type="submit" class="btn-primary">
                    <i class="fas fa-sign-in-alt"></i> 登录
                </button>
            </form>

            <div class="auth-footer">
                没有账号？ <a href="register.php">立即注册</a>
            </div>
        </div>
    </div>
    <?php endif; // 结束初始化错误检查 ?>

    <script>
    function refreshCaptcha() {
        const img = document.getElementById('captcha-image');
        if (img) {
            img.src = 'captcha_svg.php?t=' + new Date().getTime();
        }
    }
    </script>
</body>
</html>