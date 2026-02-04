<?php
ob_start();

/**
 * 管理员登录页面
 */

require_once __DIR__ . '/../admin_auth.php';
require_once __DIR__ . '/../security_utils.php';
require_once __DIR__ . '/../i18n/I18n.php';

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
                    __('admin.need_init'),
                    __('admin.need_init_desc'),
                    [
                        [
                            'label' => __('admin.start_init'),
                            'href' => url('setup_admin.php?from=admin_login'),
                            'primary' => true
                        ],
                        [
                            'label' => __('nav.back_home'),
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
            __('auth.already_logged_in'),
            __('admin.already_logged_in_desc'),
            [
                [
                    'label' => __('admin.enter_admin'),
                    'href' => url('admin/index.php'),
                    'primary' => true
                ],
                [
                    'label' => __('nav.back_home'),
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
        $error = '⚠️ ' . __('error.init_failed');
        $errorDetail = htmlspecialchars($errorMsg);
    } elseif (strpos($errorMsg, '配置缺失') !== false) {
        $error = '⚠️ ' . __('error.config_missing');
        $errorDetail = __('error.action_check_config');
    } elseif (strpos($errorMsg, '数据库连接失败') !== false) {
        $error = '⚠️ ' . __('error.db_connection_failed');
        $errorDetail = __('error.action_check_db');
    } else {
        $error = '⚠️ ' . __('error.system');
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
                    __('admin.login_success'),
                    __('admin.quick_login_success'),
                    [
                        [
                            'label' => __('admin.enter_admin'),
                            'href' => url('admin/index.php'),
                            'primary' => true
                        ],
                        [
                            'label' => __('nav.back_home'),
                            'href' => url('index.php')
                        ]
                    ]
                );
            } else {
                $error = $quickLoginResult['message'];
            }
        } catch (Exception $e) {
            $error = __('auth.quick_login_failed') . ': ' . $e->getMessage();
        }
    } else {
        $error = __('auth.quick_login_invalid');
    }
}

// 检查是否有过期或已登出的提示
if (!$initError) {
    if (isset($_GET['expired'])) {
        $error = __('auth.session_expired');
    } elseif (isset($_GET['logout'])) {
        $error = __('auth.safe_logout');
    }
}

// 处理登录请求
if (!$initError && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = $_POST['key'] ?? '';
    $captchaInput = trim($_POST['captcha'] ?? '');

    try {
        $result = $adminAuth->loginWithCode($key, $captchaInput);

        if ($result['success']) {
            renderActionPage(
                __('admin.login_success'),
                __('admin.login_success_desc'),
                [
                    [
                        'label' => __('admin.enter_admin'),
                        'href' => url('admin/index.php'),
                        'primary' => true
                    ],
                    [
                        'label' => __('nav.back_home'),
                        'href' => url('index.php')
                    ]
                ]
            );
        } else {
            $error = $result['message'];
            $errorCode = $result['code'] ?? '';
            $lockoutTime = $result['lockout_time'] ?? 0;
            
            // 根据错误码进行特殊处理
            if ($errorCode === 'IP_LOCKED' && $lockoutTime > 0) {
                // 已通过 $lockoutTime 变量在下方显示锁定界面
            }
        }
    } catch (Exception $e) {
        $error = __('auth.error.username_or_password') . ': ' . $e->getMessage();
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
<html lang="<?php echo i18n()->getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php _e('admin.login_title'); ?> - <?php _e('site.title'); ?></title>
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
            position: relative;
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
            <div class="language-switcher" style="position: absolute; top: 15px; right: 15px;">
                <a href="?lang=zh-CN" class="<?php echo isZhCN() ? 'active' : ''; ?>" style="text-decoration: none; margin-right: 8px; color: #666; font-size: 0.9em;">CN</a>
                <a href="?lang=en" class="<?php echo isEn() ? 'active' : ''; ?>" style="text-decoration: none; color: #666; font-size: 0.9em;">EN</a>
            </div>
            <div class="auth-header">
                <div class="admin-icon">
                    <i class="fas fa-user-shield"></i>
                </div>
                <h1><?php _e('admin.login_title'); ?></h1>
                <p><?php _e('admin.login_subtitle'); ?></p>
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
                    <i class="fas fa-clock"></i> <?php _e('admin.ip_locked', ['minutes' => ceil($lockoutTime / 60)]); ?>
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
                        <i class="fas fa-key"></i> <?php _e('admin.admin_key'); ?>
                    </label>
                    <input
                        type="password"
                        id="key"
                        name="key"
                        required
                        autofocus
                        placeholder="<?php _e('admin.admin_key_placeholder'); ?>"
                        <?php echo $lockoutTime > 0 ? 'disabled' : ''; ?>
                    >
                </div>

                <?php if ($captcha->isLoginEnabled()): ?>
                <div class="form-group">
                    <label for="captcha">
                        <i class="fas fa-shield-alt"></i> <?php _e('form.captcha'); ?>
                    </label>
                    <div class="captcha-group">
                        <input
                            type="text"
                            id="captcha"
                            name="captcha"
                            required
                            placeholder="<?php _e('form.captcha_placeholder'); ?>"
                            maxlength="4"
                            <?php echo $lockoutTime > 0 ? 'disabled' : ''; ?>
                        >
                        <img
                            src="../captcha_svg.php?t=<?php echo time(); ?>"
                            alt="<?php _e('form.captcha'); ?>"
                            id="captchaImg"
                            onclick="this.src='../captcha_svg.php?t=' + Date.now()"
                            title="<?php _e('form.captcha_refresh'); ?>"
                        >
                    </div>
                </div>
                <?php endif; ?>

                <button
                    type="submit"
                    class="btn-primary"
                    <?php echo $lockoutTime > 0 ? 'disabled' : ''; ?>
                >
                    <i class="fas fa-sign-in-alt"></i> <?php _e('auth.login'); ?>
                </button>
            </form>

            <div class="auth-footer">
                <a href="<?php echo url('/index.php'); ?>">
                    <i class="fas fa-arrow-left"></i> <?php _e('nav.back_home'); ?>
                </a>
            </div>
        </div>
    </div>
</body>
</html>
