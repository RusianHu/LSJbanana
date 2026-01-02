<?php
/**
 * 用户认证类
 * 
 * 处理用户注册、登录、会话管理等功能
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security_utils.php';
require_once __DIR__ . '/captcha_utils.php';

class Auth {
    private Database $db;
    private array $config;
    private array $fullConfig;
    private ?array $currentUser = null;
    private CaptchaUtils $captcha;

    // Cookie 名称
    private const COOKIE_SESSION = 'lsj_session';
    private const COOKIE_REMEMBER = 'lsj_remember';

    public function __construct(?array $config = null) {
        $configFile = __DIR__ . '/config.php';
        if (!file_exists($configFile)) {
            throw new Exception('配置文件不存在：config.php。请复制 config.php.example 并根据环境配置。');
        }
        try {
            $this->fullConfig = require $configFile;
        } catch (Throwable $e) {
            throw new Exception('配置文件加载失败：' . $e->getMessage());
        }

        if ($config === null) {
            $config = $this->fullConfig['user'] ?? [];
        }
        $this->config = $config;
        $this->db = Database::getInstance();
        $this->captcha = getCaptcha();
    }

    /**
     * 启动会话
     */
    public function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            // 设置会话 cookie 参数
            session_set_cookie_params([
                'lifetime' => $this->config['session_lifetime'] ?? 604800,
                'path' => '/',
                'secure' => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    /**
     * 用户注册
     */
    public function register(string $username, string $email, string $password, ?string $captcha = null): array {
        // 验证是否开放注册
        if (!($this->config['enable_registration'] ?? true)) {
            return ['success' => false, 'message' => '当前不开放注册'];
        }

        // 验证码验证（如果启用）
        if ($this->captcha->isRegisterEnabled()) {
            if (empty($captcha)) {
                return ['success' => false, 'message' => '请输入验证码'];
            }
            if (!$this->captcha->verify($captcha)) {
                return ['success' => false, 'message' => '验证码错误或已过期'];
            }
        }

        // 验证用户名
        $usernameValidation = $this->validateUsername($username);
        if (!$usernameValidation['valid']) {
            return ['success' => false, 'message' => $usernameValidation['message']];
        }

        // 验证邮箱
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => '邮箱格式不正确'];
        }

        // 验证密码
        $passwordValidation = $this->validatePassword($password);
        if (!$passwordValidation['valid']) {
            return ['success' => false, 'message' => $passwordValidation['message']];
        }

        // 检查用户名是否已存在
        if ($this->db->usernameExists($username)) {
            return ['success' => false, 'message' => '用户名已被使用'];
        }

        // 检查邮箱是否已存在
        if ($this->db->emailExists($email)) {
            return ['success' => false, 'message' => '邮箱已被注册'];
        }

        // 创建用户
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $userId = $this->db->createUser($username, $email, $passwordHash);

        if ($userId === null) {
            return ['success' => false, 'message' => '注册失败，请稍后重试'];
        }

        // 获取用户信息
        $user = $this->db->getUserById($userId);

        return [
            'success' => true,
            'message' => '注册成功',
            'user' => $this->sanitizeUser($user),
        ];
    }

    /**
     * 用户登录
     */
    public function login(string $usernameOrEmail, string $password, bool $remember = false, ?string $captcha = null): array {
        // 验证码验证（如果启用）
        if ($this->captcha->isLoginEnabled()) {
            if (empty($captcha)) {
                return ['success' => false, 'message' => '请输入验证码'];
            }
            if (!$this->captcha->verify($captcha)) {
                return ['success' => false, 'message' => '验证码错误或已过期'];
            }
        }

        // 查找用户（支持用户名或邮箱登录）
        $user = null;
        if (filter_var($usernameOrEmail, FILTER_VALIDATE_EMAIL)) {
            $user = $this->db->getUserByEmail($usernameOrEmail);
        } else {
            $user = $this->db->getUserByUsername($usernameOrEmail);
        }

        if ($user === null) {
            return ['success' => false, 'message' => '用户名或密码错误'];
        }

        // 检查用户状态
        if (($user['status'] ?? 0) !== 1) {
            return ['success' => false, 'message' => '账号已被禁用'];
        }

        // 验证密码
        if (!password_verify($password, $user['password_hash'])) {
            // 记录失败的登录尝试
            $this->db->logLogin($user['id'], $this->getClientIp(), $_SERVER['HTTP_USER_AGENT'] ?? null, 'password', 0);
            return ['success' => false, 'message' => '用户名或密码错误'];
        }

        // 登录成功
        $this->startSession();

        // 更新会话
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['login_time'] = time();

        // 更新用户登录信息
        $this->db->updateUserLogin($user['id'], $this->getClientIp());

        // 记录登录日志
        $this->db->logLogin($user['id'], $this->getClientIp(), $_SERVER['HTTP_USER_AGENT'] ?? null, 'password', 1);

        // 如果勾选"记住我"，创建持久会话
        if ($remember) {
            $this->createRememberToken($user['id']);
        }

        $this->currentUser = $user;

        return [
            'success' => true,
            'message' => '登录成功',
            'user' => $this->sanitizeUser($user),
        ];
    }

    /**
     * 用户登出
     */
    public function logout(): void {
        $this->startSession();

        // 删除记住我的 token
        if (isset($_COOKIE[self::COOKIE_REMEMBER])) {
            $tokenHash = hash('sha256', $_COOKIE[self::COOKIE_REMEMBER]);
            $this->db->deleteSession($tokenHash);
            setcookie(self::COOKIE_REMEMBER, '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
        }

        // 清除会话
        $_SESSION = [];

        // 销毁会话 cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        $this->currentUser = null;
    }

    /**
     * 检查用户是否已登录
     */
    public function isLoggedIn(): bool {
        $this->startSession();

        // 首先检查会话
        if (isset($_SESSION['user_id'])) {
            return true;
        }

        // 检查记住我 token
        if (isset($_COOKIE[self::COOKIE_REMEMBER])) {
            return $this->loginWithRememberToken($_COOKIE[self::COOKIE_REMEMBER]);
        }

        return false;
    }

    /**
     * 获取当前登录用户
     *
     * 注意：此方法会实时验证用户状态，如果用户被禁用会自动登出
     */
    public function getCurrentUser(): ?array {
        if ($this->currentUser !== null) {
            // 已缓存用户信息，但仍需验证状态
            // 如果状态已被管理员修改，需要重新验证
            if (($this->currentUser['status'] ?? 0) !== 1) {
                $this->logout();
                return null;
            }
            return $this->currentUser;
        }

        if (!$this->isLoggedIn()) {
            return null;
        }

        $userId = $_SESSION['user_id'] ?? null;
        if ($userId === null) {
            return null;
        }

        $user = $this->db->getUserById((int) $userId);
        if ($user === null) {
            $this->logout();
            return null;
        }

        // 检查用户状态：被禁用的用户不允许继续操作
        if (($user['status'] ?? 0) !== 1) {
            $this->logout();
            return null;
        }

        $this->currentUser = $user;
        return $user;
    }

    /**
     * 获取当前用户ID
     */
    public function getCurrentUserId(): ?int {
        $user = $this->getCurrentUser();
        return $user ? (int) $user['id'] : null;
    }

    /**
     * 获取当前用户余额
     */
    public function getCurrentUserBalance(): float {
        $user = $this->getCurrentUser();
        return $user ? (float) ($user['balance'] ?? 0) : 0.0;
    }

    /**
     * 刷新当前用户数据
     */
    public function refreshCurrentUser(): ?array {
        $this->currentUser = null;
        return $this->getCurrentUser();
    }

    /**
     * 创建"记住我" token
     */
    private function createRememberToken(int $userId): void {
        try {
            $token = SecurityUtils::generateSecureToken(64);
        } catch (Exception $e) {
            return;
        }

        $tokenHash = hash('sha256', $token);
        $expiresIn = $this->config['remember_me_lifetime'] ?? 2592000;

        $this->db->createSession(
            $userId,
            $tokenHash,
            $expiresIn,
            $this->getClientIp(),
            $_SERVER['HTTP_USER_AGENT'] ?? null
        );

        setcookie(
            self::COOKIE_REMEMBER,
            $token,
            time() + $expiresIn,
            '/',
            '',
            isset($_SERVER['HTTPS']),
            true
        );
    }

    /**
     * 使用记住我 token 登录
     */
    private function loginWithRememberToken(string $token): bool {
        $tokenHash = hash('sha256', $token);
        $session = $this->db->getValidSession($tokenHash);

        if ($session === null) {
            // Token 无效，清除 cookie
            setcookie(self::COOKIE_REMEMBER, '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
            return false;
        }

        $user = $this->db->getUserById((int) $session['user_id']);
        if ($user === null || ($user['status'] ?? 0) !== 1) {
            $this->db->deleteSession($tokenHash);
            setcookie(self::COOKIE_REMEMBER, '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
            return false;
        }

        // 恢复会话
        $this->startSession();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['login_time'] = time();

        // 记录登录日志
        $this->db->logLogin($user['id'], $this->getClientIp(), $_SERVER['HTTP_USER_AGENT'] ?? null, 'token', 1);

        $this->currentUser = $user;
        return true;
    }

    /**
     * 验证用户名
     */
    private function validateUsername(string $username): array {
        $minLength = $this->config['username_min_length'] ?? 3;
        $maxLength = $this->config['username_max_length'] ?? 20;

        if (mb_strlen($username) < $minLength) {
            return ['valid' => false, 'message' => "用户名至少需要 {$minLength} 个字符"];
        }

        if (mb_strlen($username) > $maxLength) {
            return ['valid' => false, 'message' => "用户名最多 {$maxLength} 个字符"];
        }

        // 只允许字母、数字、下划线和中文
        if (!preg_match('/^[\p{L}\p{N}_]+$/u', $username)) {
            return ['valid' => false, 'message' => '用户名只能包含字母、数字、下划线和中文'];
        }

        return ['valid' => true];
    }

    /**
     * 验证密码
     */
    private function validatePassword(string $password): array {
        $minLength = $this->config['password_min_length'] ?? 6;

        if (strlen($password) < $minLength) {
            return ['valid' => false, 'message' => "密码至少需要 {$minLength} 个字符"];
        }

        return ['valid' => true];
    }

    /**
     * 清理用户数据（移除敏感信息）
     */
    private function sanitizeUser(?array $user): ?array {
        if ($user === null) {
            return null;
        }

        unset($user['password_hash']);
        return $user;
    }

    /**
     * 获取客户端 IP
     */
    private function getClientIp(): string {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // X-Forwarded-For 可能包含多个 IP，取第一个
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Build a relative redirect target.
     */
    private function buildRelativeRedirect(string $requestUri, string $default = 'index.php'): string {
        $requestUri = trim($requestUri);
        if ($requestUri === '') {
            return $default;
        }

        $parts = parse_url($requestUri);
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? '';
        $fragment = $parts['fragment'] ?? '';

        $path = str_replace('\\', '/', $path);
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

        $relative = $path;
        if ($query !== '') {
            $relative .= '?' . $query;
        }
        if ($fragment !== '') {
            $relative .= '#' . $fragment;
        }

        return $relative;
    }

    /**
     * 要求登录（如果未登录则提示并终止）
     */
    public function requireLogin(bool $redirect = true): bool {
        if ($this->isLoggedIn()) {
            return true;
        }

        if ($redirect) {
            $redirectTarget = $this->buildRelativeRedirect($_SERVER['REQUEST_URI'] ?? '', 'index.php');
            $loginUrl = 'login.php?redirect=' . urlencode($redirectTarget);
            renderActionPage(
                '需要登录',
                '请先登录后继续访问该页面。',
                [
                    [
                        'label' => '立即登录',
                        'href' => url($loginUrl),
                        'primary' => true
                    ],
                    [
                        'label' => '返回首页',
                        'href' => url('index.php')
                    ]
                ],
                401
            );
        }

        return false;
    }

    /**
     * 要求登录（API 版本，返回 JSON 错误）
     */
    public function requireLoginApi(): bool {
        if ($this->isLoggedIn()) {
            return true;
        }

        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => '请先登录', 'code' => 'UNAUTHORIZED']);
        exit;
    }

    /**
     * 检查用户余额是否足够
     */
    public function checkBalance(float $required): bool {
        return $this->getCurrentUserBalance() >= $required;
    }

    /**
     * 扣除用户余额
     */
    public function deductBalance(float $amount, string $action, int $imageCount = 1, ?string $modelName = null, ?string $remark = null): array {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return ['success' => false, 'message' => '用户未登录'];
        }

        $currentBalance = (float) ($user['balance'] ?? 0);
        if ($currentBalance < $amount) {
            return ['success' => false, 'message' => '余额不足', 'balance' => $currentBalance, 'required' => $amount];
        }

        $newBalance = $currentBalance - $amount;

        // 使用事务确保一致性
        return $this->db->transaction(function ($db) use ($user, $amount, $newBalance, $currentBalance, $action, $imageCount, $modelName, $remark) {
            // 更新余额
            $db->setUserBalance($user['id'], $newBalance);

            // 记录消费
            $db->logConsumption(
                $user['id'],
                $action,
                $amount,
                $currentBalance,
                $newBalance,
                $imageCount,
                $modelName,
                $remark
            );

            // 刷新用户数据
            $this->currentUser = null;

            return [
                'success' => true,
                'message' => '扣费成功',
                'balance_before' => $currentBalance,
                'balance_after' => $newBalance,
                'amount' => $amount,
            ];
        });
    }

    /**
     * 增加用户余额（充值）
     */
    public function addBalance(int $userId, float $amount): bool {
        return $this->db->updateUserBalance($userId, $amount);
    }

    // ============================================================
    // 密码管理
    // ============================================================

    /**
     * 用户修改密码
     *
     * @param int $userId 用户ID
     * @param string $oldPassword 旧密码
     * @param string $newPassword 新密码
     * @return array
     */
    public function changePassword(int $userId, string $oldPassword, string $newPassword): array {
        // 获取用户信息
        $user = $this->db->getUserById($userId);
        if (!$user) {
            return ['success' => false, 'message' => '用户不存在'];
        }

        // 验证旧密码
        if (!password_verify($oldPassword, $user['password_hash'])) {
            return ['success' => false, 'message' => '旧密码错误'];
        }

        // 验证新密码
        $passwordValidation = $this->validatePassword($newPassword);
        if (!$passwordValidation['valid']) {
            return ['success' => false, 'message' => $passwordValidation['message']];
        }

        // 更新密码
        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $success = $this->db->updateUserPassword($userId, $newPasswordHash);

        if ($success) {
            // 清除所有会话(强制重新登录)
            $this->db->deleteUserSessions($userId);

            return ['success' => true, 'message' => '密码修改成功,请重新登录'];
        }

        return ['success' => false, 'message' => '密码修改失败,请稍后重试'];
    }

    /**
     * 管理员重置用户密码
     *
     * @param int $userId 用户ID
     * @param string $newPassword 新密码
     * @return array
     */
    public function resetPasswordByAdmin(int $userId, string $newPassword): array {
        // 获取用户信息
        $user = $this->db->getUserById($userId);
        if (!$user) {
            return ['success' => false, 'message' => '用户不存在'];
        }

        // 验证新密码
        $passwordValidation = $this->validatePassword($newPassword);
        if (!$passwordValidation['valid']) {
            return ['success' => false, 'message' => $passwordValidation['message']];
        }

        // 更新密码
        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $success = $this->db->updateUserPassword($userId, $newPasswordHash);

        if ($success) {
            // 清除用户所有会话
            $this->db->deleteUserSessions($userId);

            return [
                'success' => true,
                'message' => '密码重置成功',
                'username' => $user['username']
            ];
        }

        return ['success' => false, 'message' => '密码重置失败'];
    }

    /**
     * 生成临时密码
     *
     * @param int $length 密码长度
     * @return string
     */
    public function generateTempPassword(int $length = 8): string {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $password;
    }

    /**
     * 发送密码重置邮件(可选功能,需要邮件配置)
     *
     * @param string $email 用户邮箱
     * @return array
     */
    public function sendPasswordResetEmail(string $email): array {
        // 查找用户
        $user = $this->db->getUserByEmail($email);
        if (!$user) {
            // 为了安全,不透露用户是否存在
            return ['success' => true, 'message' => '如果该邮箱已注册,重置链接已发送'];
        }

        // 生成重置令牌(24小时有效)
        $token = $this->db->createPasswordResetToken($user['id'], $email, 86400);

        // 构造重置链接
        $resetUrl = $this->getBaseUrl() . "/reset_password.php?token=" . urlencode($token);

        // TODO: 发送邮件
        // 这里需要配置邮件服务才能实际发送
        // 暂时返回成功,实际项目中需要集成邮件功能

        return [
            'success' => true,
            'message' => '重置链接已发送到您的邮箱',
            'reset_url' => $resetUrl // 仅用于测试,生产环境不应返回
        ];
    }

    /**
     * 通过令牌重置密码
     *
     * @param string $token 重置令牌
     * @param string $newPassword 新密码
     * @return array
     */
    public function resetPasswordByToken(string $token, string $newPassword): array {
        // 验证令牌
        $resetToken = $this->db->getPasswordResetToken($token);
        if (!$resetToken) {
            return ['success' => false, 'message' => '重置链接无效或已过期'];
        }

        // 验证新密码
        $passwordValidation = $this->validatePassword($newPassword);
        if (!$passwordValidation['valid']) {
            return ['success' => false, 'message' => $passwordValidation['message']];
        }

        // 更新密码
        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $success = $this->db->updateUserPassword($resetToken['user_id'], $newPasswordHash);

        if ($success) {
            // 标记令牌为已使用
            $this->db->markTokenUsed($token);

            // 清除用户所有会话
            $this->db->deleteUserSessions($resetToken['user_id']);

            return ['success' => true, 'message' => '密码重置成功,请重新登录'];
        }

        return ['success' => false, 'message' => '密码重置失败'];
    }

    /**
     * 获取网站基础URL (包含子目录路径)
     */
    private function getBaseUrl(): string {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = getBasePath(); // 包含子目录路径,例如 /LSJbanana
        return $protocol . '://' . $host . $basePath;
    }

    // ============================================================
    // 调试快速登录
    // ============================================================

    /**
     * 检查调试快速登录是否启用
     */
    public function isQuickLoginEnabled(): bool {
        $quickLoginConfig = $this->config['debug_quick_login'] ?? [];
        // 需要同时检查用户配置启用和管理员密钥存在
        $adminKeyHash = $this->fullConfig['admin']['key_hash'] ?? '';
        return !empty($quickLoginConfig['enabled']) && !empty($adminKeyHash);
    }

    /**
     * 获取快速登录配置
     */
    private function getQuickLoginConfig(): array {
        return $this->config['debug_quick_login'] ?? [];
    }

    /**
     * 获取管理员密钥哈希 (用作签名密钥)
     */
    private function getAdminKeyHash(): string {
        return $this->fullConfig['admin']['key_hash'] ?? '';
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
            throw new Exception('用户调试快速登录未启用');
        }

        $quickLoginConfig = $this->getQuickLoginConfig();
        $keyHash = $this->getAdminKeyHash();
        $expiresSeconds = $quickLoginConfig['expires_seconds'] ?? 300;

        // 生成时间戳
        $timestamp = time();

        // 生成签名: HMAC-SHA256(key_hash, "user:" + timestamp)
        // 使用 "user:" 前缀区分于管理员快速登录
        $signature = hash_hmac('sha256', 'user:' . $timestamp, $keyHash);

        // 构建URL
        $url = rtrim($baseUrl, '/') . '/login.php?quick_login=1'
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
            return ['success' => false, 'message' => '用户调试快速登录未启用'];
        }

        $quickLoginConfig = $this->getQuickLoginConfig();
        $keyHash = $this->getAdminKeyHash();
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

        // 验证签名 (使用 "user:" 前缀)
        $expectedSignature = hash_hmac('sha256', 'user:' . $timestamp, $keyHash);
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
     * @return array 登录结果 ['success' => bool, 'message' => string, 'user' => ?array]
     */
    public function quickLogin(int $timestamp, string $signature): array {
        // 验证快速登录请求
        $verifyResult = $this->verifyQuickLogin($timestamp, $signature);
        if (!$verifyResult['success']) {
            return $verifyResult;
        }

        $quickLoginConfig = $this->getQuickLoginConfig();
        $testUserConfig = $quickLoginConfig['test_user'] ?? [];

        $username = $testUserConfig['username'] ?? 'test_debug_user';
        $email = $testUserConfig['email'] ?? 'test_debug@example.com';
        $initialBalance = (float)($testUserConfig['initial_balance'] ?? 100.00);

        // 查找或创建测试用户
        $user = $this->db->getUserByUsername($username);

        if ($user === null) {
            // 创建测试用户
            $passwordHash = password_hash('debug_password_' . time(), PASSWORD_DEFAULT);
            $userId = $this->db->createUser($username, $email, $passwordHash, $initialBalance);

            if ($userId === null) {
                // 可能是邮箱冲突，尝试用邮箱查找
                $user = $this->db->getUserByEmail($email);
                if ($user === null) {
                    return ['success' => false, 'message' => '创建测试用户失败'];
                }
            } else {
                $user = $this->db->getUserById($userId);
            }
        }

        if ($user === null) {
            return ['success' => false, 'message' => '获取测试用户失败'];
        }

        // 检查用户状态
        if (($user['status'] ?? 0) !== 1) {
            return ['success' => false, 'message' => '测试用户已被禁用'];
        }

        $ip = $this->getClientIp();

        // 创建会话
        $this->startSession();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['login_time'] = time();

        // 更新用户登录信息
        $this->db->updateUserLogin($user['id'], $ip);

        // 记录登录日志
        $this->db->logLogin($user['id'], $ip, $_SERVER['HTTP_USER_AGENT'] ?? null, 'quick_login', 1);

        // 记录成功的快速登录
        $this->logQuickLoginAttempt($ip, true, 'User quick login successful for: ' . $username);

        $this->currentUser = $user;

        return [
            'success' => true,
            'message' => '快速登录成功',
            'user' => $this->sanitizeUser($user),
        ];
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
            "[%s] Type: USER | IP: %s | Success: %s | Details: %s\n",
            date('Y-m-d H:i:s'),
            $ip,
            $success ? 'YES' : 'NO',
            $details
        );

        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}

/**
 * 获取全局 Auth 实例
 */
function getAuth(): Auth {
    static $auth = null;
    if ($auth === null) {
        $auth = new Auth();
    }
    return $auth;
}
