<?php
ob_start();

/**
 * 用户修改密码页面
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/i18n/I18n.php';

$auth = getAuth();

// 要求登录
$auth->requireLogin(true);

$user = $auth->getCurrentUser();
$error = '';
$success = '';

// 处理修改密码请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // 验证新密码确认
    if ($newPassword !== $confirmPassword) {
        $error = __('password.error.mismatch');
    } elseif (empty($currentPassword)) {
        $error = __('password.error.current_required');
    } elseif (empty($newPassword)) {
        $error = __('password.error.new_required');
    } else {
        $result = $auth->changePassword($user['id'], $currentPassword, $newPassword);
        if ($result['success']) {
            $success = $result['message'];
            // 密码修改成功后，要求重新登录
            $auth->logout();
            renderActionPage(
                __('password.changed_success'),
                __('password.changed_success_desc'),
                [
                    [
                        'label' => __('auth.go_login'),
                        'href' => url('login.php'),
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
}

// 获取配置中的密码最小长度
$config = require __DIR__ . '/config.php';
$userConfig = $config['user'] ?? [];
$minPasswordLength = $userConfig['password_min_length'] ?? 6;
?>
<!DOCTYPE html>
<html lang="<?php echo i18n()->getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php _e('password.change_title'); ?> - <?php _e('site.title'); ?></title>
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
        .auth-header .icon {
            font-size: 3rem;
            color: var(--primary-color);
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
        .password-requirements {
            font-size: 0.85rem;
            color: #888;
            margin-top: 6px;
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
        .user-info-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .user-info-card .user-avatar {
            width: 48px;
            height: 48px;
            background: var(--primary-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #333;
        }
        .user-info-card .user-details {
            flex: 1;
        }
        .user-info-card .user-name {
            font-weight: 600;
            color: #333;
        }
        .user-info-card .user-email {
            font-size: 0.85rem;
            color: #666;
        }
        .password-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #aaa;
            transition: color 0.2s;
        }
        .password-toggle:hover {
            color: #666;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <a href="index.php" class="back-link" style="margin-bottom: 0;">
                <i class="fas fa-arrow-left"></i> <?php _e('nav.back_home'); ?>
            </a>
            <div class="language-switcher">
                <a href="?lang=zh-CN" class="<?php echo isZhCN() ? 'active' : ''; ?>" style="text-decoration: none; margin-right: 8px; color: #666;">CN</a>
                <a href="?lang=en" class="<?php echo isEn() ? 'active' : ''; ?>" style="text-decoration: none; color: #666;">EN</a>
            </div>
        </div>

        <div class="auth-box">
            <div class="auth-header">
                <div class="icon"><i class="fas fa-key"></i></div>
                <h1><?php _e('password.change_title'); ?></h1>
                <p><?php _e('password.change_subtitle'); ?></p>
            </div>

            <!-- 当前用户信息 -->
            <div class="user-info-card">
                <div class="user-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <div class="user-details">
                    <div class="user-name"><?php echo htmlspecialchars($user['username']); ?></div>
                    <div class="user-email"><?php echo htmlspecialchars($user['email']); ?></div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                    <br><small><?php _e('password.changed_success_desc'); ?></small>
                </div>
            <?php else: ?>
                <form class="auth-form" method="POST" action="">
                    <div class="form-group">
                        <label for="current_password"><?php _e('password.current_password'); ?></label>
                        <div class="input-icon-wrapper">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="current_password" name="current_password"
                                   placeholder="<?php _e('password.current_placeholder'); ?>"
                                   required autocomplete="current-password">
                            <i class="fas fa-eye password-toggle" onclick="togglePassword('current_password', this)"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="new_password"><?php _e('password.new_password'); ?></label>
                        <div class="input-icon-wrapper">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="new_password" name="new_password"
                                   placeholder="<?php _e('password.new_placeholder'); ?>"
                                   required autocomplete="new-password"
                                   minlength="<?php echo $minPasswordLength; ?>">
                            <i class="fas fa-eye password-toggle" onclick="togglePassword('new_password', this)"></i>
                        </div>
                        <p class="password-requirements"><?php _e('user.password_hint', ['min' => $minPasswordLength]); ?></p>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password"><?php _e('password.confirm_password'); ?></label>
                        <div class="input-icon-wrapper">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="confirm_password" name="confirm_password"
                                   placeholder="<?php _e('password.confirm_placeholder'); ?>"
                                   required autocomplete="new-password"
                                   minlength="<?php echo $minPasswordLength; ?>">
                            <i class="fas fa-eye password-toggle" onclick="togglePassword('confirm_password', this)"></i>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i> <?php _e('password.btn_save'); ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function togglePassword(inputId, iconElement) {
        const input = document.getElementById(inputId);
        if (input.type === 'password') {
            input.type = 'text';
            iconElement.classList.remove('fa-eye');
            iconElement.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            iconElement.classList.remove('fa-eye-slash');
            iconElement.classList.add('fa-eye');
        }
    }

    // 表单验证：确认密码匹配
    document.querySelector('form')?.addEventListener('submit', function(e) {
        const newPassword = document.getElementById('new_password').value;
        const confirmPassword = document.getElementById('confirm_password').value;
        
        if (newPassword !== confirmPassword) {
            e.preventDefault();
            alert('<?php _e('password.error.mismatch'); ?>');
            return false;
        }
    });
    </script>
</body>
</html>
