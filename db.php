<?php
/**
 * 数据库操作类
 * 
 * 使用 SQLite3 作为数据库，提供用户、订单、消费记录的 CRUD 操作
 */

class Database {
    private static ?Database $instance = null;
    private ?PDO $pdo = null;
    private array $config;

    /**
     * 私有构造函数（单例模式）
     */
    private function __construct(array $config) {
        $this->config = $config;
        $this->connect();
    }

    /**
     * 获取数据库实例
     */
    public static function getInstance(?array $config = null): Database {
        if (self::$instance === null) {
            if ($config === null) {
                $fullConfig = require __DIR__ . '/config.php';
                $config = $fullConfig['database'] ?? [];
            }
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * 连接数据库
     */
    private function connect(): void {
        $dbPath = $this->config['path'] ?? __DIR__ . '/database/lsjbanana.db';
        $dbDir = dirname($dbPath);

        // 确保数据库目录存在
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }

        $isNewDb = !file_exists($dbPath);

        try {
            $this->pdo = new PDO("sqlite:{$dbPath}");
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // 启用外键约束
            $this->pdo->exec('PRAGMA foreign_keys = ON');

            // 如果是新数据库，初始化表结构
            if ($isNewDb) {
                $this->initTables();
            }
        } catch (PDOException $e) {
            throw new RuntimeException('数据库连接失败: ' . $e->getMessage());
        }
    }

    /**
     * 初始化数据库表
     */
    private function initTables(): void {
        $sqlFile = __DIR__ . '/database/init.sql';
        if (file_exists($sqlFile)) {
            $sql = file_get_contents($sqlFile);
            $this->pdo->exec($sql);
        }
    }

    /**
     * 获取 PDO 实例
     */
    public function getPdo(): PDO {
        return $this->pdo;
    }

    // ============================================================
    // 用户相关操作
    // ============================================================

    /**
     * 创建用户
     */
    public function createUser(string $username, string $email, string $passwordHash, float $initialBalance = 0.00): ?int {
        $sql = "INSERT INTO users (username, email, password_hash, balance, status, created_at, updated_at)
                VALUES (:username, :email, :password_hash, :balance, 1, datetime('now'), datetime('now'))";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':username' => $username,
                ':email' => $email,
                ':password_hash' => $passwordHash,
                ':balance' => $initialBalance,
            ]);
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            // 用户名或邮箱重复
            if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * 根据用户名查找用户
     */
    public function getUserByUsername(string $username): ?array {
        $sql = "SELECT * FROM users WHERE username = :username LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    /**
     * 根据邮箱查找用户
     */
    public function getUserByEmail(string $email): ?array {
        $sql = "SELECT * FROM users WHERE email = :email LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    /**
     * 根据ID查找用户
     */
    public function getUserById(int $userId): ?array {
        $sql = "SELECT * FROM users WHERE id = :id LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    /**
     * 更新用户余额
     */
    public function updateUserBalance(int $userId, float $amount): bool {
        $sql = "UPDATE users SET balance = balance + :amount, updated_at = datetime('now') WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':amount' => $amount, ':id' => $userId]);
    }

    /**
     * 设置用户余额（绝对值）
     */
    public function setUserBalance(int $userId, float $balance): bool {
        $sql = "UPDATE users SET balance = :balance, updated_at = datetime('now') WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':balance' => $balance, ':id' => $userId]);
    }

    /**
     * 更新用户登录信息
     */
    public function updateUserLogin(int $userId, string $ip): bool {
        $sql = "UPDATE users SET last_login_at = datetime('now'), last_login_ip = :ip, updated_at = datetime('now') WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':ip' => $ip, ':id' => $userId]);
    }

    /**
     * 检查用户名是否存在
     */
    public function usernameExists(string $username): bool {
        $sql = "SELECT 1 FROM users WHERE username = :username LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':username' => $username]);
        return $stmt->fetch() !== false;
    }

    /**
     * 检查邮箱是否存在
     */
    public function emailExists(string $email): bool {
        $sql = "SELECT 1 FROM users WHERE email = :email LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':email' => $email]);
        return $stmt->fetch() !== false;
    }

    // ============================================================
    // 充值订单相关操作
    // ============================================================

    /**
     * 创建充值订单
     */
    public function createRechargeOrder(int $userId, string $outTradeNo, float $amount, ?string $payType = null): int {
        $sql = "INSERT INTO recharge_orders (user_id, out_trade_no, amount, pay_type, status, created_at)
                VALUES (:user_id, :out_trade_no, :amount, :pay_type, 0, datetime('now'))";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':out_trade_no' => $outTradeNo,
            ':amount' => $amount,
            ':pay_type' => $payType,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * 根据商户订单号查找订单
     */
    public function getRechargeOrderByOutTradeNo(string $outTradeNo): ?array {
        $sql = "SELECT * FROM recharge_orders WHERE out_trade_no = :out_trade_no LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':out_trade_no' => $outTradeNo]);
        $order = $stmt->fetch();
        return $order ?: null;
    }

    /**
     * 更新订单为已支付
     */
    public function markOrderPaid(string $outTradeNo, string $tradeNo, string $payType, ?string $notifyData = null): bool {
        $sql = "UPDATE recharge_orders 
                SET status = 1, trade_no = :trade_no, pay_type = :pay_type, 
                    paid_at = datetime('now'), notify_data = :notify_data
                WHERE out_trade_no = :out_trade_no AND status = 0";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':trade_no' => $tradeNo,
            ':pay_type' => $payType,
            ':notify_data' => $notifyData,
            ':out_trade_no' => $outTradeNo,
        ]);
    }

    /**
     * 获取用户的充值记录
     */
    public function getUserRechargeOrders(int $userId, int $limit = 20, int $offset = 0): array {
        $sql = "SELECT * FROM recharge_orders WHERE user_id = :user_id ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // ============================================================
    // 消费记录相关操作
    // ============================================================

    /**
     * 记录消费
     */
    public function logConsumption(
        int $userId,
        string $action,
        float $amount,
        float $balanceBefore,
        float $balanceAfter,
        int $imageCount = 1,
        ?string $modelName = null,
        ?string $remark = null
    ): int {
        $sql = "INSERT INTO consumption_logs 
                (user_id, action, amount, balance_before, balance_after, image_count, model_name, remark, created_at)
                VALUES (:user_id, :action, :amount, :balance_before, :balance_after, :image_count, :model_name, :remark, datetime('now'))";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':amount' => $amount,
            ':balance_before' => $balanceBefore,
            ':balance_after' => $balanceAfter,
            ':image_count' => $imageCount,
            ':model_name' => $modelName,
            ':remark' => $remark,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * 获取用户的消费记录
     */
    public function getUserConsumptionLogs(int $userId, int $limit = 20, int $offset = 0): array {
        $sql = "SELECT * FROM consumption_logs WHERE user_id = :user_id ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * 获取用户消费统计
     */
    public function getUserConsumptionStats(int $userId): array {
        $sql = "SELECT 
                    COUNT(*) as total_count,
                    COALESCE(SUM(amount), 0) as total_amount,
                    COALESCE(SUM(image_count), 0) as total_images
                FROM consumption_logs WHERE user_id = :user_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetch() ?: ['total_count' => 0, 'total_amount' => 0, 'total_images' => 0];
    }

    // ============================================================
    // 会话相关操作
    // ============================================================

    /**
     * 创建用户会话
     */
    public function createSession(int $userId, string $tokenHash, int $expiresInSeconds, ?string $ip = null, ?string $userAgent = null): int {
        $sql = "INSERT INTO user_sessions (user_id, token_hash, expires_at, ip_address, user_agent, created_at)
                VALUES (:user_id, :token_hash, datetime('now', '+' || :expires || ' seconds'), :ip, :user_agent, datetime('now'))";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':token_hash' => $tokenHash,
            ':expires' => $expiresInSeconds,
            ':ip' => $ip,
            ':user_agent' => $userAgent,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * 根据 token 哈希获取有效会话
     */
    public function getValidSession(string $tokenHash): ?array {
        $sql = "SELECT * FROM user_sessions WHERE token_hash = :token_hash AND expires_at > datetime('now') LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':token_hash' => $tokenHash]);
        $session = $stmt->fetch();
        return $session ?: null;
    }

    /**
     * 删除会话
     */
    public function deleteSession(string $tokenHash): bool {
        $sql = "DELETE FROM user_sessions WHERE token_hash = :token_hash";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':token_hash' => $tokenHash]);
    }

    /**
     * 删除用户的所有会话
     */
    public function deleteUserSessions(int $userId): bool {
        $sql = "DELETE FROM user_sessions WHERE user_id = :user_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':user_id' => $userId]);
    }

    /**
     * 清理过期会话
     */
    public function cleanExpiredSessions(): int {
        $sql = "DELETE FROM user_sessions WHERE expires_at <= datetime('now')";
        return $this->pdo->exec($sql);
    }

    // ============================================================
    // 登录日志相关操作
    // ============================================================

    /**
     * 记录登录日志
     */
    public function logLogin(int $userId, string $ip, ?string $userAgent = null, string $loginType = 'password', int $status = 1): int {
        $sql = "INSERT INTO login_logs (user_id, ip_address, user_agent, login_type, status, created_at)
                VALUES (:user_id, :ip, :user_agent, :login_type, :status, datetime('now'))";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':ip' => $ip,
            ':user_agent' => $userAgent,
            ':login_type' => $loginType,
            ':status' => $status,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    // ============================================================
    // 事务操作
    // ============================================================

    /**
     * 开始事务
     */
    public function beginTransaction(): bool {
        return $this->pdo->beginTransaction();
    }

    /**
     * 提交事务
     */
    public function commit(): bool {
        return $this->pdo->commit();
    }

    /**
     * 回滚事务
     */
    public function rollback(): bool {
        return $this->pdo->rollBack();
    }

    /**
     * 在事务中执行回调
     */
    public function transaction(callable $callback) {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }
}