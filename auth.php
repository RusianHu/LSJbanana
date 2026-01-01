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
    private ?array $currentUser = null;
    private CaptchaUtils $captcha;

    // Cookie 名称
    private const COOKIE_SESSION = 'lsj_session';
    private const COOKIE_REMEMBER = 'lsj_remember';

    public function __construct(?array $config = null) {
        if ($config === null) {
            $fullConfig = require __DIR__ . '/config.php';
            $config = $fullConfig['user'] ?? [];
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
     */
    public function getCurrentUser(): ?array {
        if ($this->currentUser !== null) {
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
     * 要求登录（如果未登录则跳转或返回错误）
     */
    public function requireLogin(bool $redirect = true): bool {
        if ($this->isLoggedIn()) {
            return true;
        }

        if ($redirect) {
            header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
            exit;
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