<?php
ob_start(); // 启用输出缓冲，避免意外的提前输出

/**
 * 用户登录页面
 */

// 尝试加载必需的依赖，捕获初始化错误
try {
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/i18n/I18n.php';
    $auth = getAuth();
    $captcha = getCaptcha();
} catch (Exception $e) {
    // 配置或数据库初始化失败，显示友好错误页面
    $initError = $e->getMessage();
    $captcha = null; // 设置为 null 避免后续调用失败
    $auth = null;
}

function normalizeRedirectPath(string $redirect, string $default = 'index.php'): string {
    $redirect = trim($redirect);
    if ($redirect === '') {
        return $default;
    }

    if (strpos($redirect, '//') !== false || strpos($redirect, ':') !== false) {
        return $default;
    }

    $redirect = str_replace('\\', '/', $redirect);
    $parts = parse_url($redirect);
    $path = $parts['path'] ?? '';
    $query = $parts['query'] ?? '';
    $fragment = $parts['fragment'] ?? '';

    $basePath = function_exists('getBasePath') ? getBasePath() : '';
    if ($basePath !== '' && $path === $basePath) {
        $path = '';
    } elseif ($basePath !== '' && strpos($path, $basePath . '/') === 0) {
        $path = substr($path, strlen($basePath) + 1);
    } else {
        $path = ltrim($path, '/');
    }

    if ($path === '') {
        $path = $default;
    }

    $normalized = $path;
    if ($query !== '') {
        $normalized .= '?' . $query;
    }
    if ($fragment !== '') {
        $normalized .= '#' . $fragment;
    }

    return $normalized;
}

// 如果已登录，提示前往目标页面
if (isset($auth) && $auth && $auth->isLoggedIn()) {
    $redirect = normalizeRedirectPath($_GET['redirect'] ?? '', 'index.php');
    renderActionPage(
        __('auth.already_logged_in'),
        __('auth.already_logged_in_desc'),
        [
            [
                'label' => __('auth.continue'),
                'href' => url($redirect),
                'primary' => true
            ],
            [
                'label' => __('nav.back_home'),
                'href' => url('index.php')
            ]
        ]
    );
}

$error = '';
$success = '';

// 处理快速登录请求
if (!isset($initError) && isset($auth) && $auth && isset($_GET['quick_login']) && $_GET['quick_login'] === '1') {
    $timestamp = isset($_GET['t']) ? (int)$_GET['t'] : 0;
    $signature = isset($_GET['sig']) ? trim($_GET['sig']) : '';

    if ($timestamp > 0 && $signature !== '') {
        try {
            $quickLoginResult = $auth->quickLogin($timestamp, $signature);

            if ($quickLoginResult['success']) {
                $redirect = normalizeRedirectPath($_GET['redirect'] ?? '', 'index.php');
                renderActionPage(
                    __('auth.login_success'),
                    __('auth.quick_login_success'),
                    [
                        [
                            'label' => __('auth.continue'),
                            'href' => url($redirect),
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

// 处理登录请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($auth) && $auth) {
    $usernameOrEmail = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    $captchaInput = trim($_POST['captcha'] ?? '');

    $result = $auth->login($usernameOrEmail, $password, $remember, $captchaInput);
    if ($result['success']) {
        $redirect = normalizeRedirectPath($_POST['redirect'] ?? '', 'index.php');
        renderActionPage(
            __('auth.login_success'),
            __('auth.login_success_desc'),
            [
                [
                    'label' => __('auth.continue'),
                    'href' => url($redirect),
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
    }
}

$redirect = normalizeRedirectPath($_GET['redirect'] ?? '', '');
?>
<!DOCTYPE html>
<html lang="<?php echo i18n()->getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php _e('auth.login'); ?> - <?php _e('site.title'); ?></title>
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
                <h1 style="color: #c62828;"><?php _e('error.init_failed'); ?></h1>
            </div>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Error:</strong><br>
                <?php echo htmlspecialchars($initError); ?>
            </div>
            <div style="margin-top: 20px; padding: 15px; background: #f5f5f5; border-radius: 6px;">
                <p style="margin: 0 0 10px 0; font-weight: bold;"><?php _e('error.possible_causes'); ?>:</p>
                <ul style="margin: 0; padding-left: 20px;">
                    <li><?php _e('error.cause_config'); ?></li>
                    <li><?php _e('error.cause_db'); ?></li>
                    <li><?php _e('error.cause_extension'); ?></li>
                </ul>
                <p style="margin: 15px 0 0 0; font-weight: bold;"><?php _e('error.suggested_actions'); ?>:</p>
                <ul style="margin: 0; padding-left: 20px;">
                    <li><?php _e('error.action_check_config'); ?></li>
                    <li><?php _e('error.action_check_db'); ?></li>
                    <li><?php _e('error.action_check_logs'); ?></li>
                </ul>
            </div>
            <div style="text-align: center; margin-top: 20px;">
                <a href="index.php" class="btn-primary" style="display: inline-block; padding: 12px 24px; text-decoration: none;">
                    <i class="fas fa-home"></i> <?php _e('nav.back_home'); ?>
                </a>
            </div>
        </div>
    </div>
<?php else: ?>
<div class="auth-container">
    <a href="index.php" class="back-link">
        <i class="fas fa-arrow-left"></i> <?php _e('nav.back_home'); ?>
    </a>

    <div class="language-switcher" style="position: absolute; top: 20px; right: 20px;">
        <a href="?lang=zh-CN" class="<?php echo isZhCN() ? 'active' : ''; ?>" style="text-decoration: none; margin-right: 10px; color: #666;">中文</a>
        <a href="?lang=en" class="<?php echo isEn() ? 'active' : ''; ?>" style="text-decoration: none; color: #666;">English</a>
    </div>

    <div class="auth-box">
        <div class="auth-header">
            <div class="logo">&#127820;</div>
            <h1><?php _e('auth.login_title'); ?></h1>
            <p><?php _e('auth.login_subtitle'); ?></p>
        </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form class="auth-form" method="POST" action="">
                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">

                <div class="form-group">
                    <label for="username"><?php _e('user.username'); ?> / <?php _e('user.email'); ?></label>
                    <div class="input-icon-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" id="username" name="username"
                               placeholder="<?php _e('user.username_placeholder'); ?>"
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                               required autocomplete="username">
                    </div>
                </div>

                <div class="form-group">
                    <label for="password"><?php _e('user.password'); ?></label>
                    <div class="input-icon-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password"
                               placeholder="<?php _e('user.password_placeholder'); ?>"
                               required autocomplete="current-password">
                    </div>
                </div>

                <?php if (isset($captcha) && $captcha && $captcha->isLoginEnabled()): ?>
                <div class="form-group">
                    <label for="captcha"><?php _e('form.captcha'); ?></label>
                    <div class="captcha-group">
                        <div class="captcha-input">
                            <input type="text" id="captcha" name="captcha"
                                   placeholder="<?php _e('form.captcha_placeholder'); ?>"
                                   maxlength="4"
                                   required autocomplete="off">
                        </div>
                        <div class="captcha-image-wrapper">
                            <img src="captcha_svg.php?t=<?php echo time(); ?>"
                                 alt="<?php _e('form.captcha'); ?>"
                                 class="captcha-image"
                                 id="captcha-image"
                                 onclick="refreshCaptcha()">
                            <a href="javascript:void(0)"
                               onclick="refreshCaptcha()"
                               class="captcha-refresh">
                                <i class="fas fa-sync-alt"></i> <?php _e('form.captcha_refresh'); ?>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-options">
                    <label class="remember-me">
                        <input type="checkbox" name="remember" value="1">
                        <span><?php _e('auth.remember_me'); ?></span>
                    </label>
                    <!-- <a href="forgot_password.php" class="forgot-password"><?php _e('auth.forgot_password'); ?></a> -->
                </div>

                <button type="submit" class="btn-primary">
                    <i class="fas fa-sign-in-alt"></i> <?php _e('auth.login'); ?>
                </button>
            </form>

            <div class="auth-footer">
                <?php _e('auth.no_account'); ?> <a href="register.php"><?php _e('auth.register_now'); ?></a>
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
