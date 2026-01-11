<?php
/**
 * 管理员认证类
 *
 * 处理管理员登录、会话管理、权限验证等功能
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security_utils.php';
require_once __DIR__ . '/captcha_utils.php';
require_once __DIR__ . '/i18n/I18n.php';

class AdminAuth {
    private Database $db;
    private array $config;
    private CaptchaUtils $captcha;

    // Cookie 名称
    private const COOKIE_ADMIN_SESSION = 'admin_session';

    public function __construct(?array $config = null) {
        if ($config === null) {
            $configFile = __DIR__ . '/config.php';
            if (!file_exists($configFile)) {
                throw new Exception('配置文件不存在：config.php。请复制 config.php.example 并根据环境配置。');
            }
            try {
                $fullConfig = require $configFile;
            } catch (Throwable $e) {
                throw new Exception('配置文件加载失败：' . $e->getMessage());
            }
            $config = $fullConfig['admin'] ?? [];
        }

        // 验证配置完整性
        if (empty($config)) {
            throw new Exception(__('error.config_missing'));
        }

        $this->config = $config;
        $this->db = Database::getInstance();
        $this->captcha = getCaptcha();

        // 检查必需的数据库表是否存在
        $this->checkRequiredTables();
    }

    /**
     * 检查必需的数据库表是否存在
     * 如果表缺失，自动尝试创建
     */
    private function checkRequiredTables(): void {
        try {
            // 检查哪些表缺失
            $missingTables = $this->db->checkAdminTables();

            if (!empty($missingTables)) {
                // 尝试自动初始化
                $initResult = $this->db->initAdminTables();

                if (!$initResult['success']) {
                    throw new Exception(
                        __('error.init_failed') . ": " . $initResult['message']
                    );
                }

                // 初始化成功，记录日志（如果需要）
                if (!empty($initResult['created'])) {
                    error_log("Admin tables auto-initialized: " . implode(', ', $initResult['created']));
                }
            }
        } catch (PDOException $e) {
            throw new Exception(__('error.db_connection_failed') . ': ' . $e->getMessage());
        }
    }

    /**
     * 检查IP是否被锁定
     */
    private function isIpLocked(string $ip): bool {
        $maxAttempts = $this->config['max_attempts'] ?? 5;
        $lockoutDuration = $this->config['lockout_duration'] ?? 900; // 15分钟

        $attempts = $this->db->getRecentAdminAttempts($ip, (int)($lockoutDuration / 60));

        return $attempts >= $maxAttempts;
    }

    /**
     * 获取IP锁定剩余时间(秒)
     */
    public function getLockoutTime(string $ip): int {
        $lockoutDuration = $this->config['lockout_duration'] ?? 900;

        $sql = "SELECT attempt_time FROM admin_login_attempts
                WHERE ip_address = :ip AND success = 0
                ORDER BY attempt_time DESC LIMIT 1";

        $stmt = $this->db->getPdo()->prepare($sql);
        $stmt->execute([':ip' => $ip]);
        $result = $stmt->fetch();

        if (!$result) {
            return 0;
        }

        // SQLite 的 datetime() 返回 UTC 时间，需要明确指定时区
        $lastAttemptTime = strtotime($result['attempt_time'] . ' UTC');
        $unlockTime = $lastAttemptTime + $lockoutDuration;
        $remaining = $unlockTime - time();

        return max(0, $remaining);
    }

    /**
     * 管理员登录
     */
    public function login(string $key, string $captcha): array {
        $ip = $this->getClientIp();

        // 检查管理员功能是否启用
        if (!($this->config['enabled'] ?? true)) {
            return ['success' => false, 'message' => __('admin.feature_disabled')];
        }

        // 检查IP锁定
        if ($this->isIpLocked($ip)) {
            $lockoutTime = $this->getLockoutTime($ip);
            $minutes = ceil($lockoutTime / 60);
            return [
                'success' => false,
                'message' => __('admin.ip_locked', ['minutes' => $minutes]),
                'lockout_time' => $lockoutTime
            ];
        }

        // 验证码验证
        if ($this->captcha->isLoginEnabled() && !$this->captcha->verify($captcha)) {
            return ['success' => false, 'message' => __('auth.error.captcha_invalid')];
        }

        // 验证密钥
        $keyHash = hash('sha256', $key);
        $configKeyHash = $this->config['key_hash'] ?? '';

        if (empty($configKeyHash)) {
            return ['success' => false, 'message' => __('error.config_missing')];
        }

        if ($keyHash !== $configKeyHash) {
            // 记录失败尝试
            $this->db->logAdminAttempt($ip, 0);

            $attempts = $this->db->getRecentAdminAttempts($ip, 15);
            $maxAttempts = $this->config['max_attempts'] ?? 5;
            $remaining = $maxAttempts - $attempts;

            if ($remaining > 0) {
                return [
                    'success' => false,
                    'message' => __('auth.error.username_or_password')
                ];
            } else {
                return [
                    'success' => false,
                    'message' => __('admin.ip_locked', ['minutes' => 15])
                ];
            }
        }

        // 记录成功登录
        $this->db->logAdminAttempt($ip, 1);

        // 创建Session
        $token = SecurityUtils::generateSecureToken(64);
        $sessionLifetime = $this->config['session_lifetime'] ?? 3600;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $this->db->createAdminSession($token, $ip, $userAgent, $sessionLifetime);

        // 设置Cookie
        $cookieLifetime = time() + $sessionLifetime;
        $cookiePath = '/';
        $cookieSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $cookieHttpOnly = true;

        setcookie(
            self::COOKIE_ADMIN_SESSION,
            $token,
            $cookieLifetime,
            $cookiePath,
            '',
            $cookieSecure,
            $cookieHttpOnly
        );

        return ['success' => true, 'message' => __('admin.login_success')];
    }

    /**
     * 验证管理员权限(中间件)
     *
     * @param bool $redirect 是否自动显示登录提示页
     * @return bool 是否已认证
     */
    public function requireAuth(bool $redirect = true): bool {
        $token = $_COOKIE[self::COOKIE_ADMIN_SESSION] ?? null;

        if (!$token) {
            if ($redirect) {
                $this->redirectToLogin();
            }
            return false;
        }

        $session = $this->db->getAdminSession($token);

        if (!$session) {
            if ($redirect) {
                $this->clearCookie();
                $this->redirectToLogin('expired');
            }
            return false;
        }

        // 检查是否过期
        // 注意：SQLite 的 datetime() 函数返回 UTC 时间，需要在解析时明确指定时区
        if (strtotime($session['expires_at'] . ' UTC') < time()) {
            $this->db->deleteAdminSession($token);
            if ($redirect) {
                $this->clearCookie();
                $this->redirectToLogin('expired');
            }
            return false;
        }

        // 更新活动时间
        $this->db->updateAdminActivity($token);

        return true;
    }

    /**
     * API 版本的权限验证(返回 JSON)
     */
    public function requireAuthApi(): bool {
        if (!$this->requireAuth(false)) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => __('api.unauthorized'),
                'code' => 'UNAUTHORIZED'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        return true;
    }

    /**
     * 登出
     */
    public function logout(): void {
        $token = $_COOKIE[self::COOKIE_ADMIN_SESSION] ?? null;

        if ($token) {
            $this->db->deleteAdminSession($token);
        }

        $this->clearCookie();
        renderActionPage(
            __('auth.logout_success'),
            __('auth.logout_success_desc'),
            [
                [
                    'label' => __('admin.login_title'),
                    'href' => url('admin/login.php'),
                    'primary' => true
                ],
                [
                    'label' => __('nav.back_home'),
                    'href' => url('index.php')
                ]
            ]
        );
    }

    /**
     * 清除 Cookie
     */
    private function clearCookie(): void {
        setcookie(self::COOKIE_ADMIN_SESSION, '', time() - 3600, '/');
    }

    /**
     * 显示登录提示页
     */
    private function redirectToLogin(string $reason = ''): void {
        $message = __('auth.require_login_desc');
        if ($reason === 'expired') {
            $message = __('auth.session_expired');
        }

        $loginUrl = 'admin/login.php';
        if ($reason) {
            $loginUrl .= '?' . $reason . '=1';
        }

        renderActionPage(
            __('auth.require_login'),
            $message,
            [
                [
                    'label' => __('admin.login_title'),
                    'href' => url($loginUrl),
                    'primary' => true
                ],
                [
                    'label' => __('nav.back_home'),
                    'href' => url('index.php')
                ]
            ],
            401
        );
    }

    /**
     * 获取客户端IP地址
     */
    private function getClientIp(): string {
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
     * 清理过期的管理员会话
     */
    public function cleanExpiredSessions(): int {
        return $this->db->cleanExpiredAdminSessions();
    }

    /**
     * 检查调试快速登录是否启用
     */
    public function isQuickLoginEnabled(): bool {
        $quickLoginConfig = $this->config['debug_quick_login'] ?? [];
        $keyHash = $this->config['key_hash'] ?? '';
        // 需要启用快速登录且配置了管理员密钥哈希
        return !empty($quickLoginConfig['enabled']) && !empty($keyHash);
    }

    /**
     * 生成快速登录URL
     *
     * @param string $baseUrl 基础URL (如 http://127.0.0.1:8080)
     * @return array 包含 url 和 expires_at 的数组
     * @throws Exception 如果快速登录未启用
     */
    public function generateQuickLoginUrl(string $baseUrl): array {
        if (!$this->isQuickLoginEnabled()) {
            throw new Exception('调试快速登录未启用');
        }

        $quickLoginConfig = $this->config['debug_quick_login'];
        $keyHash = $this->config['key_hash']; // 使用管理员密钥哈希作为签名密钥
        $expiresSeconds = $quickLoginConfig['expires_seconds'] ?? 300;

        // 生成时间戳
        $timestamp = time();

        // 生成签名: HMAC-SHA256(key_hash, timestamp)
        $signature = hash_hmac('sha256', (string)$timestamp, $keyHash);

        // 构建URL
        $url = rtrim($baseUrl, '/') . '/admin/login.php?quick_login=1'
             . '&t=' . $timestamp
             . '&sig=' . $signature;

        return [
            'url' => $url,
            'expires_at' => date('Y-m-d H:i:s', $timestamp + $expiresSeconds),
            'expires_seconds' => $expiresSeconds,
        ];
    }

    /**
     * 验证快速登录请求
     *
     * @param int $timestamp 时间戳
     * @param string $signature 签名
     * @return array 验证结果 ['success' => bool, 'message' => string]
     */
    public function verifyQuickLogin(int $timestamp, string $signature): array {
        // 检查是否启用
        if (!$this->isQuickLoginEnabled()) {
            return ['success' => false, 'message' => '调试快速登录未启用'];
        }

        $quickLoginConfig = $this->config['debug_quick_login'];
        $keyHash = $this->config['key_hash']; // 使用管理员密钥哈希作为签名密钥
        $expiresSeconds = $quickLoginConfig['expires_seconds'] ?? 300;
        $ipWhitelist = $quickLoginConfig['ip_whitelist'] ?? [];

        // 验证IP白名单
        if (!empty($ipWhitelist)) {
            $clientIp = $this->getClientIp();
            if (!in_array($clientIp, $ipWhitelist, true)) {
                $this->logQuickLoginAttempt($clientIp, false, 'IP not in whitelist');
                return ['success' => false, 'message' => 'IP地址不在白名单中'];
            }
        }

        // 验证时间戳是否过期
        $currentTime = time();
        if ($timestamp > $currentTime + 60) {
            // 时间戳在未来超过60秒，可能是时钟不同步或伪造
            return ['success' => false, 'message' => '无效的时间戳'];
        }

        if ($currentTime - $timestamp > $expiresSeconds) {
            return ['success' => false, 'message' => '快速登录链接已过期'];
        }

        // 验证签名
        $expectedSignature = hash_hmac('sha256', (string)$timestamp, $keyHash);
        if (!hash_equals($expectedSignature, $signature)) {
            $this->logQuickLoginAttempt($this->getClientIp(), false, 'Invalid signature');
            return ['success' => false, 'message' => '签名验证失败'];
        }

        return ['success' => true, 'message' => '验证成功'];
    }

    /**
     * 执行快速登录
     *
     * @param int $timestamp 时间戳
     * @param string $signature 签名
     * @return array 登录结果 ['success' => bool, 'message' => string]
     */
    public function quickLogin(int $timestamp, string $signature): array {
        // 验证快速登录请求
        $verifyResult = $this->verifyQuickLogin($timestamp, $signature);
        if (!$verifyResult['success']) {
            return $verifyResult;
        }

        $ip = $this->getClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        // 创建Session
        $sessionToken = SecurityUtils::generateSecureToken(64);
        $sessionLifetime = $this->config['session_lifetime'] ?? 3600;

        $this->db->createAdminSession($sessionToken, $ip, $userAgent, $sessionLifetime);

        // 设置Cookie
        $cookieLifetime = time() + $sessionLifetime;
        $cookiePath = '/';
        $cookieSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $cookieHttpOnly = true;

        setcookie(
            self::COOKIE_ADMIN_SESSION,
            $sessionToken,
            $cookieLifetime,
            $cookiePath,
            '',
            $cookieSecure,
            $cookieHttpOnly
        );

        // 记录成功的快速登录
        $this->logQuickLoginAttempt($ip, true, 'Quick login successful');

        return ['success' => true, 'message' => '快速登录成功'];
    }

    /**
     * 记录快速登录尝试到日志
     *
     * @param string $ip IP地址
     * @param bool $success 是否成功
     * @param string $details 详细信息
     */
    private function logQuickLoginAttempt(string $ip, bool $success, string $details): void {
        $logFile = __DIR__ . '/logs/quick_login.log';
        $logDir = dirname($logFile);

        // 确保日志目录存在
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logEntry = sprintf(
            "[%s] IP: %s | Success: %s | Details: %s\n",
            date('Y-m-d H:i:s'),
            $ip,
            $success ? 'YES' : 'NO',
            $details
        );

        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}

/**
 * 获取 AdminAuth 实例(单例模式)
 */
function getAdminAuth(): AdminAuth {
    static $adminAuth = null;
    if ($adminAuth === null) {
        $adminAuth = new AdminAuth();
    }
    return $adminAuth;
}
