<?php
/**
 * 生成管理员快速登录URL的命令行工具
 *
 * 用法:
 *   php generate_quick_login.php [base_url]
 *
 * 示例:
 *   php generate_quick_login.php http://127.0.0.1:8080
 *   php generate_quick_login.php https://example.com
 *
 * 如果不指定 base_url，默认使用 http://127.0.0.1:8080
 */

// 仅允许在CLI模式下运行
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo '此脚本仅可在命令行中运行';
    exit(1);
}

require_once __DIR__ . '/admin_auth.php';

// 获取基础URL参数
$baseUrl = $argv[1] ?? 'http://127.0.0.1:8080';

// 验证URL格式
if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
    fwrite(STDERR, "错误: 无效的URL格式 - {$baseUrl}\n");
    fwrite(STDERR, "用法: php generate_quick_login.php [base_url]\n");
    fwrite(STDERR, "示例: php generate_quick_login.php http://127.0.0.1:8080\n");
    exit(1);
}

try {
    $adminAuth = getAdminAuth();

    // 检查快速登录是否启用
    if (!$adminAuth->isQuickLoginEnabled()) {
        fwrite(STDERR, "错误: 调试快速登录未启用\n");
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

} catch (Exception $e) {
    fwrite(STDERR, "错误: " . $e->getMessage() . "\n");
    exit(1);
}