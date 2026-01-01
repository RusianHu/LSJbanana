<?php
/**
 * 生成快速登录URL的命令行工具
 *
 * 用法:
 *   php generate_quick_login.php [type] [base_url]
 *
 * 参数:
 *   type     - 登录类型: admin (管理员) 或 user (普通用户)，默认 admin
 *   base_url - 基础URL，默认 http://127.0.0.1:8080
 *
 * 示例:
 *   php generate_quick_login.php admin http://127.0.0.1:8080
 *   php generate_quick_login.php user http://127.0.0.1:8080
 *   php generate_quick_login.php user
 *   php generate_quick_login.php
 */

// 仅允许在CLI模式下运行
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo '此脚本仅可在命令行中运行';
    exit(1);
}

require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/auth.php';

// 解析参数
$type = 'admin';
$baseUrl = 'http://127.0.0.1:8080';

if (isset($argv[1])) {
    // 检查第一个参数是类型还是URL
    if (in_array(strtolower($argv[1]), ['admin', 'user'], true)) {
        $type = strtolower($argv[1]);
        $baseUrl = $argv[2] ?? 'http://127.0.0.1:8080';
    } elseif (filter_var($argv[1], FILTER_VALIDATE_URL)) {
        // 兼容旧格式：仅指定URL，默认为admin
        $baseUrl = $argv[1];
    } else {
        fwrite(STDERR, "错误: 无效的参数 - {$argv[1]}\n");
        fwrite(STDERR, "用法: php generate_quick_login.php [type] [base_url]\n");
        fwrite(STDERR, "  type: admin 或 user\n");
        fwrite(STDERR, "示例:\n");
        fwrite(STDERR, "  php generate_quick_login.php admin http://127.0.0.1:8080\n");
        fwrite(STDERR, "  php generate_quick_login.php user http://127.0.0.1:8080\n");
        exit(1);
    }
}

// 验证URL格式
if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
    fwrite(STDERR, "错误: 无效的URL格式 - {$baseUrl}\n");
    fwrite(STDERR, "用法: php generate_quick_login.php [type] [base_url]\n");
    fwrite(STDERR, "示例: php generate_quick_login.php user http://127.0.0.1:8080\n");
    exit(1);
}

try {
    if ($type === 'admin') {
        // 管理员快速登录
        $adminAuth = getAdminAuth();

        // 检查快速登录是否启用
        if (!$adminAuth->isQuickLoginEnabled()) {
            fwrite(STDERR, "错误: 管理员调试快速登录未启用\n");
            fwrite(STDERR, "请在 config.php 中设置 admin.debug_quick_login.enabled = true\n");
            exit(1);
        }

        // 生成快速登录URL
        $result = $adminAuth->generateQuickLoginUrl($baseUrl);

        // 输出结果
        echo "\n";
        echo "========================================\n";
        echo "    管理员快速登录URL生成成功\n";
        echo "========================================\n";
        echo "\n";
        echo "URL: {$result['url']}\n";
        echo "\n";
        echo "过期时间: {$result['expires_at']}\n";
        echo "有效期: {$result['expires_seconds']} 秒\n";
        echo "\n";
        echo "----------------------------------------\n";
        echo "安全提示:\n";
        echo "  1. 此链接仅用于开发调试环境\n";
        echo "  2. 生产环境请务必关闭快速登录功能\n";
        echo "  3. 请勿将此链接分享给他人\n";
        echo "----------------------------------------\n";
        echo "\n";

    } else {
        // 用户快速登录
        $auth = getAuth();

        // 检查快速登录是否启用
        if (!$auth->isQuickLoginEnabled()) {
            fwrite(STDERR, "错误: 用户调试快速登录未启用\n");
            fwrite(STDERR, "请在 config.php 中设置:\n");
            fwrite(STDERR, "  1. user.debug_quick_login.enabled = true\n");
            fwrite(STDERR, "  2. admin.key_hash 必须已配置\n");
            exit(1);
        }

        // 生成快速登录URL
        $result = $auth->generateQuickLoginUrl($baseUrl);

        // 获取测试用户配置
        $configFile = __DIR__ . '/config.php';
        $fullConfig = require $configFile;
        $testUser = $fullConfig['user']['debug_quick_login']['test_user'] ?? [];
        $username = $testUser['username'] ?? 'test_debug_user';
        $email = $testUser['email'] ?? 'test_debug@example.com';
        $balance = $testUser['initial_balance'] ?? 100.00;

        // 输出结果
        echo "\n";
        echo "========================================\n";
        echo "    用户快速登录URL生成成功\n";
        echo "========================================\n";
        echo "\n";
        echo "URL: {$result['url']}\n";
        echo "\n";
        echo "测试用户信息:\n";
        echo "  用户名: {$username}\n";
        echo "  邮箱:   {$email}\n";
        echo "  初始余额: {$balance} 元\n";
        echo "\n";
        echo "过期时间: {$result['expires_at']}\n";
        echo "有效期: {$result['expires_seconds']} 秒\n";
        echo "\n";
        echo "----------------------------------------\n";
        echo "安全提示:\n";
        echo "  1. 此链接仅用于开发调试环境\n";
        echo "  2. 生产环境请务必关闭快速登录功能\n";
        echo "  3. 请勿将此链接分享给他人\n";
        echo "  4. 测试用户首次访问时自动创建\n";
        echo "----------------------------------------\n";
        echo "\n";
    }

} catch (Exception $e) {
    fwrite(STDERR, "错误: " . $e->getMessage() . "\n");
    exit(1);
}