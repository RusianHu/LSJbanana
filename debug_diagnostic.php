<?php
/**
 * 调试诊断 API 接口
 *
 * 提供系统配置、状态、用户信息等诊断功能
 * 需要通过管理员密钥认证后才能访问
 *
 * 支持的查询类型（action）:
 * - config: 查看脱敏后的配置信息
 * - status: 系统状态检查
 * - user: 用户信息查询（需要 user_id 或 username 参数）
 * - stats: 系统统计数据
 * - db_health: 数据库健康检查
 * - env: 环境诊断
 *
 * 认证方式（推荐使用签名方式）:
 * 1. 签名认证（推荐）: ?t=时间戳&sig=签名
 *    - 使用 generate_quick_login.php diagnostic 生成带签名的URL
 *    - 签名算法: hash_hmac('sha256', 'diagnostic:' . timestamp, key_hash)
 * 2. 原始密钥（兼容旧方式）:
 *    - Header: X-Debug-Key: 原始密钥
 *    - 参数: debug_key=原始密钥
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security_utils.php';

// 加载配置
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    sendJsonResponse(false, '配置文件不存在', null, 500);
}

try {
    $config = require $configFile;
} catch (Throwable $e) {
    sendJsonResponse(false, '配置文件加载失败', null, 500);
}

// 获取诊断配置（嵌套在 admin 配置块中）
$adminConfig = $config['admin'] ?? [];
$diagnosticConfig = $adminConfig['debug_diagnostic'] ?? [];

// 检查接口是否启用
if (empty($diagnosticConfig['enabled'])) {
    sendJsonResponse(false, '调试诊断接口未启用', null, 403);
}

// 获取客户端 IP
$clientIp = getClientIp();

// IP 白名单验证
$ipWhitelist = $diagnosticConfig['ip_whitelist'] ?? [];
if (!empty($ipWhitelist) && !in_array($clientIp, $ipWhitelist, true)) {
    logDiagnosticRequest($clientIp, 'BLOCKED', 'IP不在白名单中', $diagnosticConfig);
    sendJsonResponse(false, 'IP地址不在白名单中', null, 403);
}

// 认证验证
$authResult = verifyAuthentication($adminConfig);
if (!$authResult['success']) {
    logDiagnosticRequest($clientIp, 'AUTH_FAILED', $authResult['message'], $diagnosticConfig);
    sendJsonResponse(false, $authResult['message'], null, 401);
}

// 获取请求参数
$action = $_GET['action'] ?? $_POST['action'] ?? 'status';
$action = SecurityUtils::sanitizeTextInput($action, 50);

// 记录请求日志
logDiagnosticRequest($clientIp, $action, 'success', $diagnosticConfig);

// 处理不同的 action
try {
    $data = null;
    switch ($action) {
        case 'config':
            $data = handleConfigAction($config);
            break;

        case 'status':
            $data = handleStatusAction($config);
            break;

        case 'user':
            $userId = $_GET['user_id'] ?? $_POST['user_id'] ?? null;
            $username = $_GET['username'] ?? $_POST['username'] ?? null;
            $data = handleUserAction($userId, $username);
            break;

        case 'stats':
            $data = handleStatsAction();
            break;

        case 'db_health':
            $data = handleDbHealthAction();
            break;

        case 'env':
            $data = handleEnvAction();
            break;

        default:
            sendJsonResponse(false, "未知的查询类型: {$action}", null, 400);
    }

    sendJsonResponse(true, null, $data, 200, $action);
} catch (Throwable $e) {
    // 避免泄露堆栈信息
    $errorMessage = '处理请求时发生错误';
    if ($config['debug'] ?? false) {
        $errorMessage .= ': ' . $e->getMessage();
    }
    sendJsonResponse(false, $errorMessage, null, 500);
}

// ============================================================
// 辅助函数
// ============================================================

/**
 * 发送 JSON 响应
 *
 * @param bool $success 是否成功
 * @param string|null $message 错误消息
 * @param mixed $data 响应数据
 * @param int $httpCode HTTP 状态码
 * @param string|null $action 查询类型
 */
function sendJsonResponse(bool $success, ?string $message, $data, int $httpCode = 200, ?string $action = null): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($httpCode);

    $response = [
        'success' => $success,
        'timestamp' => date('c'), // ISO 8601 格式
    ];

    if ($action !== null) {
        $response['action'] = $action;
    }

    if ($message !== null) {
        $response['message'] = $message;
    }

    if ($data !== null) {
        $response['data'] = $data;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * 获取客户端 IP 地址
 *
 * @return string IP 地址
 */
function getClientIp(): string {
    // 优先使用 Cloudflare 提供的真实 IP
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }

    // 其次使用代理转发的 IP
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }

    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    }

    // 最后使用直连 IP
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * 验证认证
 *
 * 支持两种认证方式：
 * 1. 签名认证（推荐）: 使用 t（时间戳）和 sig（签名）参数
 * 2. 原始密钥认证（兼容旧方式）: 使用 debug_key 参数或 X-Debug-Key Header
 *
 * @param array $adminConfig 管理员配置
 * @return array ['success' => bool, 'message' => string, 'method' => string]
 */
function verifyAuthentication(array $adminConfig): array {
    $keyHash = $adminConfig['key_hash'] ?? '';

    if (empty($keyHash)) {
        return ['success' => false, 'message' => '管理员密钥未配置', 'method' => 'none'];
    }

    // 获取诊断配置
    $diagnosticConfig = $adminConfig['debug_diagnostic'] ?? [];
    $expiresSeconds = $diagnosticConfig['expires_seconds'] ?? 300; // 默认5分钟

    // 优先尝试签名认证方式
    $timestamp = $_GET['t'] ?? $_POST['t'] ?? null;
    $signature = $_GET['sig'] ?? $_POST['sig'] ?? null;

    if ($timestamp !== null && $signature !== null) {
        // 使用签名认证
        return verifySignatureAuth($timestamp, $signature, $keyHash, $expiresSeconds);
    }

    // 降级到原始密钥认证方式
    // 尝试从 Header 获取密钥
    $debugKey = $_SERVER['HTTP_X_DEBUG_KEY'] ?? null;

    // 如果 Header 中没有，尝试从参数获取
    if (empty($debugKey)) {
        $debugKey = $_GET['debug_key'] ?? $_POST['debug_key'] ?? null;
    }

    if (empty($debugKey)) {
        return ['success' => false, 'message' => '缺少认证参数（需要 t+sig 签名或 debug_key）', 'method' => 'none'];
    }

    // 验证密钥
    $providedHash = hash('sha256', $debugKey);
    if (!hash_equals($keyHash, $providedHash)) {
        return ['success' => false, 'message' => '认证密钥无效', 'method' => 'key'];
    }

    return ['success' => true, 'message' => '认证成功（密钥方式）', 'method' => 'key'];
}

/**
 * 验证签名认证
 *
 * @param mixed $timestamp 时间戳
 * @param string $signature 签名
 * @param string $keyHash 密钥哈希
 * @param int $expiresSeconds 有效期（秒）
 * @return array ['success' => bool, 'message' => string, 'method' => string]
 */
function verifySignatureAuth($timestamp, string $signature, string $keyHash, int $expiresSeconds): array {
    // 验证时间戳格式
    if (!is_numeric($timestamp)) {
        return ['success' => false, 'message' => '无效的时间戳格式', 'method' => 'signature'];
    }

    $timestamp = (int) $timestamp;
    $currentTime = time();

    // 检查时间戳是否在未来过远（超过60秒可能是时钟不同步或伪造）
    if ($timestamp > $currentTime + 60) {
        return ['success' => false, 'message' => '时间戳无效（在未来）', 'method' => 'signature'];
    }

    // 检查是否已过期
    if ($currentTime - $timestamp > $expiresSeconds) {
        return ['success' => false, 'message' => '诊断链接已过期', 'method' => 'signature'];
    }

    // 验证签名
    // 签名格式：hash_hmac('sha256', 'diagnostic:' . timestamp, keyHash)
    $expectedSignature = hash_hmac('sha256', 'diagnostic:' . $timestamp, $keyHash);

    if (!hash_equals($expectedSignature, $signature)) {
        return ['success' => false, 'message' => '签名验证失败', 'method' => 'signature'];
    }

    return ['success' => true, 'message' => '认证成功（签名方式）', 'method' => 'signature'];
}

/**
 * 记录诊断请求日志
 *
 * @param string $ip IP 地址
 * @param string $action 查询类型
 * @param string $result 结果
 * @param array $diagnosticConfig 诊断配置
 */
function logDiagnosticRequest(string $ip, string $action, string $result, array $diagnosticConfig): void {
    if (empty($diagnosticConfig['log_requests'])) {
        return;
    }

    $logFile = __DIR__ . '/logs/debug_diagnostic.log';
    $logDir = dirname($logFile);

    // 确保日志目录存在
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $logEntry = sprintf(
        "[%s] IP: %s | Action: %s | Result: %s\n",
        date('Y-m-d H:i:s'),
        $ip,
        $action,
        $result
    );

    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * 脱敏 API Key（只显示前4位和后4位）
 *
 * @param string $key API Key
 * @return string 脱敏后的 Key
 */
function maskApiKey(string $key): string {
    if (strlen($key) <= 8) {
        return str_repeat('*', strlen($key));
    }
    return substr($key, 0, 4) . '****' . substr($key, -4);
}

/**
 * 脱敏密钥哈希（只显示前8位）
 *
 * @param string $hash 密钥哈希
 * @return string 脱敏后的哈希
 */
function maskKeyHash(string $hash): string {
    if (strlen($hash) <= 8) {
        return $hash;
    }
    return substr($hash, 0, 8) . '...';
}

/**
 * 脱敏邮箱
 *
 * @param string $email 邮箱
 * @return string 脱敏后的邮箱
 */
function maskEmail(string $email): string {
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return '***@***';
    }

    $local = $parts[0];
    $domain = $parts[1];

    if (strlen($local) <= 1) {
        return '*@' . $domain;
    }

    return substr($local, 0, 1) . '***@' . $domain;
}

// ============================================================
// Action 处理函数
// ============================================================

/**
 * 处理 config action - 返回脱敏后的配置信息
 *
 * @param array $config 完整配置
 * @return array 脱敏后的配置
 */
function handleConfigAction(array $config): array {
    $result = [];

    // API 提供商
    $result['api_provider'] = $config['api_provider'] ?? 'native';

    // Gemini 原生 API Key（脱敏）
    if (!empty($config['api_key'])) {
        $result['api_key'] = maskApiKey($config['api_key']);
    }

    // OpenAI 兼容配置（脱敏）
    if (!empty($config['openai_compatible'])) {
        $oc = $config['openai_compatible'];
        $tm = $oc['thinking_mode'] ?? [];
        $result['openai_compatible'] = [
            'base_url' => $oc['base_url'] ?? '',
            'api_key' => !empty($oc['api_key']) ? maskApiKey($oc['api_key']) : '',
            'timeout' => $oc['timeout'] ?? 300,
            'connect_timeout' => $oc['connect_timeout'] ?? 30,
            'enable_thinking' => $oc['enable_thinking'] ?? false,
            'thinking_mode' => [
                'mode' => $tm['mode'] ?? 'auto',
                'level' => $tm['level'] ?? 'high',
                'fallback_cache_ttl' => $tm['fallback_cache_ttl'] ?? 3600,
            ],
        ];
    }

    // Gemini 代理配置（脱敏）
    if (!empty($config['gemini_proxy'])) {
        $gp = $config['gemini_proxy'];
        $result['gemini_proxy'] = [
            'base_url' => $gp['base_url'] ?? '',
            'api_key' => !empty($gp['api_key']) ? maskApiKey($gp['api_key']) : '',
            'timeout' => $gp['timeout'] ?? 300,
            'use_streaming' => $gp['use_streaming'] ?? true,
        ];
    }

    // 模型配置
    $result['model'] = [
        'name' => $config['model_name'] ?? '',
        'display_name' => $config['image_model_display_name'] ?? '',
        'supported_sizes' => $config['image_model_supported_sizes'] ?? [],
        'supports_google_search' => $config['image_model_supports_google_search'] ?? false,
    ];

    // 提示词优化模型
    $result['prompt_optimize_model'] = $config['prompt_optimize_model'] ?? '';

    // 生成配置
    $result['generation_config'] = $config['generation_config'] ?? [];

    // 系统限制
    $result['limits'] = $config['limits'] ?? [];

    // 目录配置
    $result['directories'] = [
        'upload_dir' => $config['upload_dir'] ?? 'uploads/',
        'output_dir' => $config['output_dir'] ?? 'images/',
    ];

    // 调试模式
    $result['debug'] = $config['debug'] ?? false;

    // 计费配置
    if (!empty($config['billing'])) {
        $result['billing'] = $config['billing'];
    }

    // 验证码配置（隐藏字符集）
    if (!empty($config['captcha'])) {
        $captcha = $config['captcha'];
        $result['captcha'] = [
            'enable_login' => $captcha['enable_login'] ?? false,
            'enable_register' => $captcha['enable_register'] ?? false,
            'width' => $captcha['width'] ?? 120,
            'height' => $captcha['height'] ?? 40,
            'expire_time' => $captcha['expire_time'] ?? 300,
        ];
    }

    // 管理员配置（脱敏）
    if (!empty($config['admin'])) {
        $admin = $config['admin'];
        $result['admin'] = [
            'enabled' => $admin['enabled'] ?? false,
            'key_hash' => !empty($admin['key_hash']) ? maskKeyHash($admin['key_hash']) : '',
            'session_lifetime' => $admin['session_lifetime'] ?? 3600,
            'max_attempts' => $admin['max_attempts'] ?? 5,
            'debug_quick_login_enabled' => !empty($admin['debug_quick_login']['enabled']),
        ];
    }

    // 用户系统配置
    if (!empty($config['user'])) {
        $user = $config['user'];
        $result['user'] = [
            'enable_registration' => $user['enable_registration'] ?? true,
            'session_lifetime' => $user['session_lifetime'] ?? 604800,
            'password_min_length' => $user['password_min_length'] ?? 6,
            'debug_quick_login_enabled' => !empty($user['debug_quick_login']['enabled']),
        ];
    }

    // 支付配置（脱敏）
    if (!empty($config['payment'])) {
        $payment = $config['payment'];
        $result['payment'] = [
            'enabled' => $payment['enabled'] ?? false,
            'gateway_url' => $payment['gateway_url'] ?? '',
            'pid' => $payment['pid'] ?? 0,
            'key' => !empty($payment['key']) ? maskApiKey($payment['key']) : '',
        ];
    }

    // 调试诊断配置
    if (!empty($config['debug_diagnostic'])) {
        $diag = $config['debug_diagnostic'];
        $result['debug_diagnostic'] = [
            'enabled' => $diag['enabled'] ?? false,
            'ip_whitelist' => $diag['ip_whitelist'] ?? [],
            'log_requests' => $diag['log_requests'] ?? false,
        ];
    }

    return $result;
}

/**
 * 处理 status action - 系统状态检查
 *
 * @param array $config 完整配置
 * @return array 状态信息
 */
function handleStatusAction(array $config): array {
    $result = [];

    // PHP 版本
    $result['php_version'] = PHP_VERSION;

    // 必需扩展状态
    $requiredExtensions = ['curl', 'openssl', 'mbstring', 'fileinfo', 'pdo_sqlite'];
    $result['extensions'] = [];
    foreach ($requiredExtensions as $ext) {
        $result['extensions'][$ext] = extension_loaded($ext);
    }

    // 目录状态
    $uploadDir = __DIR__ . '/' . ($config['upload_dir'] ?? 'uploads/');
    $outputDir = __DIR__ . '/' . ($config['output_dir'] ?? 'images/');
    $logsDir = __DIR__ . '/logs/';

    $result['directories'] = [
        'uploads' => [
            'path' => $config['upload_dir'] ?? 'uploads/',
            'exists' => is_dir($uploadDir),
            'writable' => is_dir($uploadDir) && is_writable($uploadDir),
        ],
        'images' => [
            'path' => $config['output_dir'] ?? 'images/',
            'exists' => is_dir($outputDir),
            'writable' => is_dir($outputDir) && is_writable($outputDir),
        ],
        'logs' => [
            'path' => 'logs/',
            'exists' => is_dir($logsDir),
            'writable' => is_dir($logsDir) && is_writable($logsDir),
        ],
    ];

    // 数据库连接状态
    try {
        $db = Database::getInstance();
        $result['database'] = [
            'connected' => true,
            'type' => 'sqlite',
        ];
    } catch (Throwable $e) {
        $result['database'] = [
            'connected' => false,
            'type' => 'sqlite',
            'error' => $e->getMessage(),
        ];
    }

    // API 提供商配置
    $result['api_provider'] = $config['api_provider'] ?? 'native';

    // 当前时间和时区
    $result['server_time'] = date('c');
    $result['timezone'] = date_default_timezone_get();

    return $result;
}

/**
 * 处理 user action - 用户信息查询
 *
 * @param mixed $userId 用户 ID
 * @param mixed $username 用户名
 * @return array 用户信息
 */
function handleUserAction($userId, $username): array {
    if (empty($userId) && empty($username)) {
        sendJsonResponse(false, '需要提供 user_id 或 username 参数', null, 400);
    }

    try {
        $db = Database::getInstance();
        $user = null;

        if (!empty($userId)) {
            $userId = (int) $userId;
            $user = $db->getUserById($userId);
        } elseif (!empty($username)) {
            $username = SecurityUtils::sanitizeTextInput($username, 50);
            $user = $db->getUserByUsername($username);
        }

        if ($user === null) {
            sendJsonResponse(false, '用户不存在', null, 404);
        }

        // 获取用户统计信息
        $stats = $db->getUserRechargeStats($user['id']);
        $consumptionStats = $db->getUserConsumptionStats($user['id']);

        // 构建响应（脱敏处理）
        return [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'email' => maskEmail($user['email']),
            'balance' => (float) ($user['balance'] ?? 0),
            'status' => (int) ($user['status'] ?? 0),
            'status_text' => (int) ($user['status'] ?? 0) === 1 ? '正常' : '禁用',
            'created_at' => $user['created_at'] ?? null,
            'last_login_at' => $user['last_login_at'] ?? null,
            'last_login_ip' => $user['last_login_ip'] ?? null,
            'statistics' => [
                'total_recharge' => (float) ($stats['total_recharge'] ?? 0),
                'recharge_order_count' => (int) ($stats['order_count'] ?? 0),
                'total_consumption' => (float) ($stats['total_consumption'] ?? 0),
                'total_images' => (int) ($stats['total_images'] ?? 0),
                'consumption_count' => (int) ($consumptionStats['total_count'] ?? 0),
            ],
        ];
    } catch (Throwable $e) {
        sendJsonResponse(false, '查询用户信息失败', null, 500);
    }

    return []; // 不会执行到这里
}

/**
 * 处理 stats action - 系统统计数据
 *
 * @return array 统计数据
 */
function handleStatsAction(): array {
    try {
        $db = Database::getInstance();
        return $db->getStatistics();
    } catch (Throwable $e) {
        sendJsonResponse(false, '获取统计数据失败', null, 500);
    }

    return []; // 不会执行到这里
}

/**
 * 处理 db_health action - 数据库健康检查
 *
 * @return array 健康检查结果
 */
function handleDbHealthAction(): array {
    $result = [
        'healthy' => true,
        'core_tables' => [],
        'admin_tables' => [],
        'issues' => [],
    ];

    try {
        $db = Database::getInstance();

        // 检查核心表
        $missingCoreTables = $db->checkCoreTables();
        $coreTableNames = ['users', 'recharge_orders', 'consumption_logs', 'login_logs', 'user_sessions'];

        foreach ($coreTableNames as $table) {
            $result['core_tables'][$table] = !in_array($table, $missingCoreTables);
        }

        if (!empty($missingCoreTables)) {
            $result['healthy'] = false;
            $result['issues'][] = '缺失核心表: ' . implode(', ', $missingCoreTables);
        }

        // 检查管理员表
        $missingAdminTables = $db->checkAdminTables();
        $adminTableNames = ['admin_sessions', 'admin_login_attempts', 'admin_operation_logs', 'password_reset_tokens', 'balance_logs'];

        foreach ($adminTableNames as $table) {
            $result['admin_tables'][$table] = !in_array($table, $missingAdminTables);
        }

        if (!empty($missingAdminTables)) {
            $result['healthy'] = false;
            $result['issues'][] = '缺失管理员表: ' . implode(', ', $missingAdminTables);
        }

        // 数据库文件信息
        $dbPath = __DIR__ . '/database/lsjbanana.db';
        if (file_exists($dbPath)) {
            $result['database_file'] = [
                'exists' => true,
                'size' => filesize($dbPath),
                'size_human' => formatBytes(filesize($dbPath)),
                'writable' => is_writable($dbPath),
            ];
        } else {
            $result['database_file'] = [
                'exists' => false,
            ];
            $result['healthy'] = false;
            $result['issues'][] = '数据库文件不存在';
        }

    } catch (Throwable $e) {
        $result['healthy'] = false;
        $result['issues'][] = '数据库连接失败: ' . $e->getMessage();
    }

    return $result;
}

/**
 * 处理 env action - 环境诊断
 *
 * @return array 环境信息
 */
function handleEnvAction(): array {
    $result = [];

    // PHP 配置
    $result['php'] = [
        'version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'max_input_time' => ini_get('max_input_time'),
        'display_errors' => ini_get('display_errors'),
        'error_reporting' => error_reporting(),
    ];

    // 必需扩展检查
    $requiredExtensions = [
        'curl' => 'HTTP 请求（api.php）',
        'openssl' => '安全连接与令牌生成（security_utils.php）',
        'mbstring' => 'UTF-8 处理（security_utils.php）',
        'fileinfo' => 'MIME 检测（api.php, security_utils.php）',
        'pdo_sqlite' => 'SQLite 数据库（db.php）',
    ];

    $result['required_extensions'] = [];
    $allExtensionsLoaded = true;

    foreach ($requiredExtensions as $ext => $usage) {
        $loaded = extension_loaded($ext);
        $result['required_extensions'][$ext] = [
            'loaded' => $loaded,
            'usage' => $usage,
        ];
        if (!$loaded) {
            $allExtensionsLoaded = false;
        }
    }

    $result['all_extensions_loaded'] = $allExtensionsLoaded;

    // 可选扩展检查
    $optionalExtensions = ['gd', 'imagick', 'zip', 'json'];
    $result['optional_extensions'] = [];

    foreach ($optionalExtensions as $ext) {
        $result['optional_extensions'][$ext] = extension_loaded($ext);
    }

    // 服务器信息
    $result['server'] = [
        'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'os' => PHP_OS,
        'hostname' => gethostname(),
    ];

    // 磁盘空间（项目目录）
    $projectDir = __DIR__;
    $result['disk'] = [
        'free_space' => disk_free_space($projectDir),
        'free_space_human' => formatBytes(disk_free_space($projectDir)),
        'total_space' => disk_total_space($projectDir),
        'total_space_human' => formatBytes(disk_total_space($projectDir)),
    ];

    // 当前内存使用
    $result['memory'] = [
        'current_usage' => memory_get_usage(true),
        'current_usage_human' => formatBytes(memory_get_usage(true)),
        'peak_usage' => memory_get_peak_usage(true),
        'peak_usage_human' => formatBytes(memory_get_peak_usage(true)),
    ];

    return $result;
}

/**
 * 格式化字节数
 *
 * @param float|int $bytes 字节数
 * @return string 格式化后的字符串
 */
function formatBytes($bytes): string {
    $bytes = (float) $bytes;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $value = $bytes;

    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }

    return round($value, 2) . ' ' . $units[$i];
}