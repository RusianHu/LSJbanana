<?php
/**
 * 管理员系统初始化引导页
 */

require_once __DIR__ . '/admin_setup_service.php';
require_once __DIR__ . '/security_utils.php';

$fatalError = null;
$initResult = null;
$errors = [];
$status = null;
$requiresKey = true;

try {
    $setupService = new AdminSetupService();
    $status = $setupService->getStatus();
    $requiresKey = $setupService->requiresAdminKey();

    if (!$status['enabled']) {
        $fatalError = '初始化引导未启用，请在 config.php 中启用 admin_setup 配置';
    } elseif (!$status['ip_allowed']) {
        $fatalError = '当前IP不允许访问初始化引导页面';
    }
} catch (Throwable $e) {
    $fatalError = $e->getMessage();
}

if (!$fatalError && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $adminKey = trim($_POST['admin_key'] ?? '');
    $force = isset($_POST['force']) && $_POST['force'] === '1';

    if (!$setupService->validateCsrfToken($csrfToken)) {
        $errors[] = '请求已过期，请刷新页面后重试';
    }
    if ($requiresKey && $adminKey === '') {
        $errors[] = '请填写管理员密钥';
    }

    if (empty($errors)) {
        $initResult = $setupService->runInitialization($adminKey, $force);
        $status = $setupService->getStatus();
    }
}

$csrfToken = (!$fatalError && isset($setupService)) ? $setupService->createCsrfToken() : '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员初始化 - 老司机的香蕉</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            background: linear-gradient(140deg, #f6f3ee, #f8f1e1 45%, #efe7d6);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 16px;
        }
        .setup-card {
            width: 100%;
            max-width: 720px;
            background: var(--panel-bg);
            border-radius: 18px;
            padding: 32px;
            box-shadow: 0 20px 60px rgba(72, 58, 25, 0.2);
        }
        .setup-header h1 {
            margin: 0 0 8px 0;
            font-size: 1.8rem;
            color: #3f3523;
        }
        .setup-header p {
            margin: 0 0 24px 0;
            color: #6b5a3c;
            font-size: 0.95rem;
        }
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .status-item {
            padding: 12px 14px;
            border-radius: 12px;
            background: #f7f2e7;
            color: #3d3423;
            font-size: 0.9rem;
        }
        .status-item strong {
            display: block;
            margin-bottom: 6px;
            color: #8a6b32;
            font-size: 0.8rem;
        }
        .alert-box {
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 16px;
            font-size: 0.9rem;
        }
        .alert-error {
            background: #ffe4e1;
            color: #8a2e2e;
            border: 1px solid #f2b8b3;
        }
        .alert-success {
            background: #e8f6ee;
            color: #1f6b40;
            border: 1px solid #b6e1c7;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #3f3523;
        }
        .form-group input[type="password"] {
            width: 100%;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #d9cbb0;
            font-size: 0.95rem;
        }
        .form-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }
        .btn-init {
            background: #3f3523;
            color: #fff;
            border: none;
            padding: 12px 22px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.95rem;
        }
        .btn-init:disabled {
            background: #9b907d;
            cursor: not-allowed;
        }
        .note {
            font-size: 0.85rem;
            color: #6b5a3c;
        }
        .links {
            margin-top: 20px;
            font-size: 0.9rem;
        }
        .links a {
            color: #8a6b32;
            text-decoration: none;
        }
        .links a:hover {
            text-decoration: underline;
        }
        @media (max-width: 640px) {
            .setup-card {
                padding: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="setup-card">
        <div class="setup-header">
            <h1>管理员系统初始化</h1>
            <p>本页面用于初始化管理员相关数据表。请在受信任环境下操作。</p>
        </div>

        <?php if ($fatalError): ?>
            <div class="alert-box alert-error">
                <?php echo htmlspecialchars($fatalError); ?>
            </div>
        <?php else: ?>
            <div class="status-grid">
                <div class="status-item">
                    <strong>管理员表状态</strong>
                    <?php echo empty($status['missing_tables']) ? '已完整' : '缺失 ' . count($status['missing_tables']) . ' 张表'; ?>
                </div>
                <div class="status-item">
                    <strong>数据库可写</strong>
                    <?php echo $status['db_writable'] ? '可写' : '不可写'; ?>
                </div>
                <div class="status-item">
                    <strong>访问IP</strong>
                    <?php echo htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'unknown'); ?>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert-box alert-error">
                    <?php echo htmlspecialchars(implode('；', $errors)); ?>
                </div>
            <?php endif; ?>

            <?php if ($initResult): ?>
                <div class="alert-box <?php echo $initResult['success'] ? 'alert-success' : 'alert-error'; ?>">
                    <?php echo htmlspecialchars($initResult['message'] ?? '初始化完成'); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($status['missing_tables'])): ?>
                <div class="note" style="margin-bottom: 12px;">
                    将创建的表：<?php echo htmlspecialchars(implode('、', $status['missing_tables'])); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <?php if ($requiresKey): ?>
                <div class="form-group">
                    <label for="admin_key">管理员密钥</label>
                    <input type="password" id="admin_key" name="admin_key" placeholder="请输入管理员密钥" autocomplete="off" required>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="force" value="1">
                        强制重新初始化索引（表已存在时仍会执行索引创建）
                    </label>
                </div>
                <div class="form-actions">
                    <button class="btn-init" type="submit" <?php echo !$status['db_writable'] ? 'disabled' : ''; ?>>
                        开始初始化
                    </button>
                    <div class="note">初始化完成后可前往管理后台登录。</div>
                </div>
            </form>

            <div class="links">
                <a href="<?php echo url('/admin/login.php'); ?>">返回管理员登录</a> |
                <a href="<?php echo url('/index.php'); ?>">返回首页</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
