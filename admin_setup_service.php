<?php
/**
 * 管理员系统初始化服务
 *
 * 提供安全的初始化流程与环境检查
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security_utils.php';

class AdminSetupService {
    private Database $db;
    private array $config;
    private array $adminConfig;
    private string $dbPath;
    private string $csrfKey = 'admin_setup_csrf';
    private int $csrfTtl;

    public function __construct(?array $fullConfig = null) {
        if ($fullConfig === null) {
            $configFile = __DIR__ . '/config.php';
            if (!file_exists($configFile)) {
                throw new RuntimeException('配置文件不存在：config.php。请复制 config.php.example 并根据环境配置。');
            }
            $fullConfig = require $configFile;
        }

        $this->config = $fullConfig['admin_setup'] ?? [];
        $this->adminConfig = $fullConfig['admin'] ?? [];
        $databaseConfig = $fullConfig['database'] ?? [];
        $this->dbPath = $databaseConfig['path'] ?? (__DIR__ . '/database/lsjbanana.db');
        $this->csrfTtl = (int)($this->config['csrf_ttl'] ?? 600);
        $this->db = Database::getInstance($databaseConfig);
    }

    public function isEnabled(): bool {
        return (bool)($this->config['enabled'] ?? false);
    }

    public function requiresAdminKey(): bool {
        return (bool)($this->config['require_admin_key'] ?? true);
    }

    public function isIpAllowed(?string $ip = null): bool {
        $whitelist = $this->config['ip_whitelist'] ?? [];
        if (empty($whitelist)) {
            return true;
        }
        $clientIp = $ip ?? $this->getClientIp();
        return in_array($clientIp, $whitelist, true);
    }

    public function getStatus(): array {
        $missingTables = $this->db->checkAdminTables();
        $dbWritable = $this->isDbWritable();

        return [
            'enabled' => $this->isEnabled(),
            'ip_allowed' => $this->isIpAllowed(),
            'missing_tables' => $missingTables,
            'db_writable' => $dbWritable,
        ];
    }

    public function createCsrfToken(): string {
        $this->startSession();
        $token = SecurityUtils::generateSecureToken(32);
        $_SESSION[$this->csrfKey] = [
            'token' => $token,
            'created_at' => time(),
        ];
        return $token;
    }

    public function validateCsrfToken(string $token): bool {
        $this->startSession();
        $data = $_SESSION[$this->csrfKey] ?? null;
        if (!$data || !isset($data['token'], $data['created_at'])) {
            return false;
        }
        if (!hash_equals($data['token'], $token)) {
            return false;
        }
        if (time() - (int)$data['created_at'] > $this->csrfTtl) {
            return false;
        }
        return true;
    }

    public function runInitialization(string $adminKey, bool $force = false): array {
        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => '初始化引导未启用'];
        }
        if (!$this->isIpAllowed()) {
            return ['success' => false, 'message' => '当前IP不允许执行初始化'];
        }
        if (!$this->requiresAdminKey()) {
            $adminKey = '';
        }
        if ($this->requiresAdminKey()) {
            if (!$this->verifyAdminKey($adminKey)) {
                return ['success' => false, 'message' => '管理员密钥验证失败'];
            }
        }
        if (!$this->isDbWritable()) {
            return ['success' => false, 'message' => '数据库文件或目录不可写'];
        }

        $missingTables = $this->db->checkAdminTables();
        if (empty($missingTables) && !$force) {
            return ['success' => true, 'message' => '管理员表已完整，无需初始化', 'created' => []];
        }

        $result = $this->db->initAdminTables();
        $this->logSetupAction($result['success'], $result['message']);
        return $result;
    }

    private function verifyAdminKey(string $adminKey): bool {
        $keyHash = $this->adminConfig['key_hash'] ?? '';
        if ($keyHash === '') {
            return false;
        }
        return hash_equals($keyHash, hash('sha256', $adminKey));
    }

    private function isDbWritable(): bool {
        $dbDir = dirname($this->dbPath);
        if (file_exists($this->dbPath)) {
            return is_writable($this->dbPath);
        }
        return is_dir($dbDir) && is_writable($dbDir);
    }

    private function logSetupAction(bool $success, string $message): void {
        $logFile = __DIR__ . '/logs/admin_setup.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $entry = sprintf(
            "[%s] IP: %s | Success: %s | Message: %s\n",
            date('Y-m-d H:i:s'),
            $this->getClientIp(),
            $success ? 'YES' : 'NO',
            $message
        );
        file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    private function getClientIp(): string {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private function startSession(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}
