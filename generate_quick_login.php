<?php
/**
 * 生成快速登录/访问URL的命令行工具
 *
 * 用法:
 *   php generate_quick_login.php [type] [base_url]
 *
 * 参数:
 *   type     - 类型: admin (管理员登录), user (用户登录), diagnostic (诊断接口)
 *              默认 admin
 *   base_url - 基础URL，默认 http://127.0.0.1:8080
 *
 * 示例:
 *   php generate_quick_login.php admin http://127.0.0.1:8080
 *   php generate_quick_login.php user http://127.0.0.1:8080
 *   php generate_quick_login.php diagnostic http://127.0.0.1:8080
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
    if (in_array(strtolower($argv[1]), ['admin', 'user', 'diagnostic'], true)) {
        $type = strtolower($argv[1]);
        $baseUrl = $argv[2] ?? 'http://127.0.0.1:8080';
    } elseif (filter_var($argv[1], FILTER_VALIDATE_URL)) {
        // 兼容旧格式：仅指定URL，默认为admin
        $baseUrl = $argv[1];
    } else {
        fwrite(STDERR, "错误: 无效的参数 - {$argv[1]}\n");
        fwrite(STDERR, "用法: php generate_quick_login.php [type] [base_url]\n");
        fwrite(STDERR, "  type: admin, user 或 diagnostic\n");
        fwrite(STDERR, "示例:\n");
        fwrite(STDERR, "  php generate_quick_login.php admin http://127.0.0.1:8080\n");
        fwrite(STDERR, "  php generate_quick_login.php user http://127.0.0.1:8080\n");
        fwrite(STDERR, "  php generate_quick_login.php diagnostic http://127.0.0.1:8080\n");
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
            fwrite(STDERR, "Error: Admin quick login is not enabled\n");
            fwrite(STDERR, "Please set admin.debug_quick_login.enabled = true in config.php\n");
            exit(1);
        }

        // 生成快速登录URL
        $result = $adminAuth->generateQuickLoginUrl($baseUrl);

        // 输出结果
        echo "\n";
        echo "========================================\n";
        echo "    Admin Quick Login URL Generated\n";
        echo "========================================\n";
        echo "\n";
        echo "URL: {$result['url']}\n";
        echo "\n";
        echo "Expires At: {$result['expires_at']}\n";
        echo "Expires In: {$result['expires_seconds']} seconds\n";
        echo "\n";
        echo "----------------------------------------\n";
        echo "WARNING:\n";
        echo "  1. Debug feature only - disable in production\n";
        echo "  2. Set debug_quick_login.enabled = false\n";
        echo "----------------------------------------\n";
        echo "\n";

    } elseif ($type === 'user') {
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
        echo "    User Quick Login URL Generated\n";
        echo "========================================\n";
        echo "\n";
        echo "URL: {$result['url']}\n";
        echo "\n";
        echo "Test User Info:\n";
        echo "  Username: {$username}\n";
        echo "  Email:    {$email}\n";
        echo "  Balance:  {$balance} CNY\n";
        echo "\n";
        echo "Expires At: {$result['expires_at']}\n";
        echo "Expires In: {$result['expires_seconds']} seconds\n";
        echo "\n";
        echo "----------------------------------------\n";
        echo "WARNING:\n";
        echo "  1. Debug feature only - disable in production\n";
        echo "  2. Set debug_quick_login.enabled = false\n";
        echo "----------------------------------------\n";
        echo "\n";

    } else {
        // 诊断接口访问URL
        // 加载配置
        $configFile = __DIR__ . '/config.php';
        if (!file_exists($configFile)) {
            fwrite(STDERR, "错误: 配置文件不存在 - config.php\n");
            exit(1);
        }

        $fullConfig = require $configFile;
        $adminConfig = $fullConfig['admin'] ?? [];
        $diagnosticConfig = $adminConfig['debug_diagnostic'] ?? [];
        $keyHash = $adminConfig['key_hash'] ?? '';

        // 检查诊断接口是否启用
        if (empty($diagnosticConfig['enabled'])) {
            fwrite(STDERR, "错误: 调试诊断接口未启用\n");
            fwrite(STDERR, "请在 config.php 中设置 admin.debug_diagnostic.enabled = true\n");
            exit(1);
        }

        // 检查管理员密钥是否配置
        if (empty($keyHash)) {
            fwrite(STDERR, "错误: 管理员密钥未配置\n");
            fwrite(STDERR, "请先在 config.php 中配置 admin.key_hash\n");
            exit(1);
        }

        // 获取有效期配置
        $expiresSeconds = $diagnosticConfig['expires_seconds'] ?? 300;

        // 生成时间戳和签名
        $timestamp = time();
        $signature = hash_hmac('sha256', 'diagnostic:' . $timestamp, $keyHash);

        // 构建基础URL（带签名参数）
        $diagnosticUrl = rtrim($baseUrl, '/') . '/debug_diagnostic.php'
            . '?t=' . $timestamp
            . '&sig=' . $signature;

        // 输出结果
        echo "\n";
        echo "========================================\n";
        echo "   Diagnostic URL Generated\n";
        echo "========================================\n";
        echo "\n";
        echo "Base URL:\n";
        echo "  {$diagnosticUrl}\n";
        echo "\n";
        echo "Examples:\n";
        echo "  Status: {$diagnosticUrl}&action=status\n";
        echo "  Config: {$diagnosticUrl}&action=config\n";
        echo "  DB Health: {$diagnosticUrl}&action=db_health\n";
        echo "  Env: {$diagnosticUrl}&action=env\n";
        echo "  Stats: {$diagnosticUrl}&action=stats\n";
        echo "\n";
        echo "Expires At: " . date('Y-m-d H:i:s', $timestamp + $expiresSeconds) . "\n";
        echo "Expires In: {$expiresSeconds} seconds\n";
        echo "\n";
        echo "----------------------------------------\n";
        echo "WARNING:\n";
        echo "  1. Debug feature only - disable in production\n";
        echo "  2. Set debug_diagnostic.enabled = false\n";
        echo "----------------------------------------\n";
        echo "\n";
    }

} catch (Exception $e) {
    fwrite(STDERR, "错误: " . $e->getMessage() . "\n");
    exit(1);
}