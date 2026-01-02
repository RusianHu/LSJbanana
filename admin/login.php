<?php
ob_start();

/**
 * 管理员登录页面
 */

require_once __DIR__ . '/../admin_auth.php';
require_once __DIR__ . '/../security_utils.php';

$error = '';
$lockoutTime = 0;
$initError = false; // 标记是否为初始化错误

// 自动触发初始化引导页（当管理员表缺失且启用引导时）
try {
    $configFile = __DIR__ . '/../config.php';
    if (file_exists($configFile)) {
        $fullConfig = require $configFile;
        $setupConfig = $fullConfig['admin_setup'] ?? [];
        if (!empty($setupConfig['enabled'])) {
            require_once __DIR__ . '/../admin_setup_service.php';
            $setupService = new AdminSetupService($fullConfig);
            $setupStatus = $setupService->getStatus();
            if ($setupStatus['enabled'] && $setupStatus['ip_allowed'] && !empty($setupStatus['missing_tables']) && !isset($_GET['skip_setup'])) {
                renderActionPage(
                    '需要初始化管理员系统',
                    '检测到管理员表缺失，请先完成初始化引导。',
                    [
                        [
                            'label' => '开始初始化',
                            'href' => url('setup_admin.php?from=admin_login'),
                            'primary' => true
                        ],
                        [
                            'label' => '返回首页',
                            'href' => url('index.php')
                        ]
                    ]
                );
            }
        }
    }
} catch (Throwable $e) {
    // 初始化引导检测失败时忽略，避免影响登录流程
}

// 尝试加载管理员认证系统
try {
    $adminAuth = getAdminAuth();
    $captcha = getCaptcha();

    // 优先处理登出请求（在 requireAuth 之前）
    if (isset($_GET['action']) && $_GET['action'] === 'logout') {
        $adminAuth->logout();
        // logout() 方法内部会 exit，不会执行到这里
    }

    // 如果已登录,提示进入管理后台
    if ($adminAuth->requireAuth(false)) {
        renderActionPage(
            '已登录',
            '您已登录管理员账户，可以继续访问后台。',
            [
                [
                    'label' => '进入后台',
                    'href' => url('admin/index.php'),
                    'primary' => true
                ],
                [
                    'label' => '返回首页',
                    'href' => url('index.php')
                ]
            ]
        );
    }
} catch (Exception $e) {
    // 捕获初始化错误
    $initError = true;
    $errorMsg = $e->getMessage();

    // 判断错误类型并给出友好提示
    if (strpos($errorMsg, '初始化失败') !== false) {
        $error = '⚠️ 管理员系统初始化失败';
        $errorDetail = htmlspecialchars($errorMsg);
    } elseif (strpos($errorMsg, '配置缺失') !== false) {
        $error = '⚠️ 管理员配置缺失';
        $errorDetail = '请检查 config.php 中的 admin 配置项是否正确设置';
    } elseif (strpos($errorMsg, '数据库连接失败') !== false) {
        $error = '⚠️ 数据库连接失败';
        $errorDetail = '请检查数据库文件是否存在且有正确的读写权限';
    } else {
        $error = '⚠️ 系统错误';
        $errorDetail = htmlspecialchars($errorMsg);
    }
}

// 处理快速登录请求
if (!$initError && isset($_GET['quick_login']) && $_GET['quick_login'] === '1') {
    $timestamp = isset($_GET['t']) ? (int)$_GET['t'] : 0;
    $signature = isset($_GET['sig']) ? trim($_GET['sig']) : '';

    if ($timestamp > 0 && $signature !== '') {
        try {
            $quickLoginResult = $adminAuth->quickLogin($timestamp, $signature);

            if ($quickLoginResult['success']) {
                renderActionPage(
                    '登录成功',
                    '管理员快速登录已完成，请继续进入后台。',
                    [
                        [
                            'label' => '进入后台',
                            'href' => url('admin/index.php'),
                            'primary' => true
                        ],
                        [
                            'label' => '返回首页',
                            'href' => url('index.php')
                        ]
                    ]
                );
            } else {
                $error = $quickLoginResult['message'];
            }
        } catch (Exception $e) {
            $error = '快速登录失败: ' . $e->getMessage();
        }
    } else {
        $error = '无效的快速登录链接';
    }
}

// 检查是否有过期或已登出的提示
if (!$initError) {
    if (isset($_GET['expired'])) {
        $error = '会话已过期,请重新登录';
    } elseif (isset($_GET['logout'])) {
        $error = '已安全登出';
    }
}

// 处理登录请求
if (!$initError && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = $_POST['key'] ?? '';
    $captchaInput = trim($_POST['captcha'] ?? '');

    try {
        $result = $adminAuth->login($key, $captchaInput);

        if ($result['success']) {
            renderActionPage(
                '登录成功',
                '管理员登录已完成，请继续进入后台。',
                [
                    [
                        'label' => '进入后台',
                        'href' => url('admin/index.php'),
                        'primary' => true
                    ],
                    [
                        'label' => '返回首页',
                        'href' => url('index.php')
                    ]
                ]
            );
        } else {
            $error = $result['message'];
            $lockoutTime = $result['lockout_time'] ?? 0;
        }
    } catch (Exception $e) {
        $error = '登录失败: ' . $e->getMessage();
    }
}

// 获取客户端IP
function getClientIp(): string {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// 获取当前IP的锁定时间
if (!$initError && !$lockoutTime && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $lockoutTime = $adminAuth->getLockoutTime(getClientIp());
    } catch (Exception $e) {
        // 忽略锁定时间查询错误
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员登录 - 老司机的香蕉</title>
    <link rel="stylesheet" href="<?php echo url('/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .auth-container {
            max-width: 420px;
            width: 100%;
            padding: 0 20px;
        }
        .auth-box {
            background: var(--panel-bg);
            border-radius: var(--border-radius);
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            padding: 40px 35px;
        }
        .auth-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .auth-header .admin-icon {
            font-size: 3.5rem;
            color: #667eea;
            margin-bottom: 15px;
        }
        .auth-header h1 {
            font-size: 1.8rem;
            margin-bottom: 8px;
            color: #333;
        }
        .auth-header p {
            color: #666;
            font-size: 0.9rem;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.95rem;
            transition: all 0.3s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .captcha-group {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        .captcha-group input {
            flex: 1;
        }
        .captcha-group img {
            height: 40px;
            border-radius: 6px;
            cursor: pointer;
            border: 1px solid #ddd;
        }
        .alert {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
        .alert-danger {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .btn-primary {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .btn-primary:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }
        .auth-footer {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        .auth-footer a {
            color: #667eea;
            text-decoration: none;
        }
        .auth-footer a:hover {
            text-decoration: underline;
        }
        .lockout-timer {
            text-align: center;
            font-size: 1.2rem;
            color: #c33;
            font-weight: bold;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-box">
            <div class="auth-header">
                <div class="admin-icon">
                    <i class="fas fa-user-shield"></i>
                </div>
                <h1>管理员登录</h1>
                <p>请输入管理员密钥以继续</p>
            </div>

            <?php if ($error): ?>
                <div class="alert <?php echo $initError ? 'alert-warning' : 'alert-danger'; ?>">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                    <?php if ($initError && isset($errorDetail)): ?>
                        <hr style="margin: 10px 0; border: none; border-top: 1px solid rgba(0,0,0,0.1);">
                        <div style="font-size: 0.85rem; margin-top: 8px;">
                            <?php echo $errorDetail; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($lockoutTime > 0): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-clock"></i> IP已被锁定,请 <span class="lockout-timer" id="lockoutTimer"><?php echo ceil($lockoutTime / 60); ?></span> 分钟后重试
                </div>
                <script>
                    let lockoutSeconds = <?php echo $lockoutTime; ?>;
                    const timerElement = document.getElementById('lockoutTimer');

                    const countdown = setInterval(() => {
                        lockoutSeconds--;
                        if (lockoutSeconds <= 0) {
                            clearInterval(countdown);
                            location.reload();
                        } else {
                            timerElement.textContent = Math.ceil(lockoutSeconds / 60);
                        }
                    }, 1000);
                </script>
            <?php endif; ?>

            <form method="POST" action="" <?php echo $initError ? 'style="display:none;"' : ''; ?>>
                <div class="form-group">
                    <label for="key">
                        <i class="fas fa-key"></i> 管理员密钥
                    </label>
                    <input
                        type="password"
                        id="key"
                        name="key"
                        required
                        autofocus
                        placeholder="请输入管理员密钥"
                        <?php echo $lockoutTime > 0 ? 'disabled' : ''; ?>
                    >
                </div>

                <?php if ($captcha->isLoginEnabled()): ?>
                <div class="form-group">
                    <label for="captcha">
                        <i class="fas fa-shield-alt"></i> 验证码
                    </label>
                    <div class="captcha-group">
                        <input
                            type="text"
                            id="captcha"
                            name="captcha"
                            required
                            placeholder="验证码"
                            maxlength="4"
                            <?php echo $lockoutTime > 0 ? 'disabled' : ''; ?>
                        >
                        <img
                            src="../captcha_svg.php?t=<?php echo time(); ?>"
                            alt="验证码"
                            id="captchaImg"
                            onclick="this.src='../captcha_svg.php?t=' + Date.now()"
                            title="点击刷新验证码"
                        >
                    </div>
                </div>
                <?php endif; ?>

                <button
                    type="submit"
                    class="btn-primary"
                    <?php echo $lockoutTime > 0 ? 'disabled' : ''; ?>
                >
                    <i class="fas fa-sign-in-alt"></i> 登录
                </button>
            </form>

            <div class="auth-footer">
                <a href="<?php echo url('/index.php'); ?>">
                    <i class="fas fa-arrow-left"></i> 返回首页
                </a>
            </div>
        </div>
    </div>
</body>
</html>
