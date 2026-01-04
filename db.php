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
     * 获取当前本地时间的 ISO 格式字符串
     *
     * 注意：SQLite 的 datetime('now') 返回 UTC 时间，
     * 为确保时间显示正确，使用 PHP 的 date() 函数生成本地时间
     *
     * @return string 格式为 'Y-m-d H:i:s' 的时间字符串
     */
    private function now(): string {
        return date('Y-m-d H:i:s');
    }

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
                $configFile = __DIR__ . '/config.php';
                if (!file_exists($configFile)) {
                    throw new RuntimeException('配置文件不存在：config.php。请复制 config.php.example 并根据环境配置。');
                }
                try {
                    $fullConfig = require $configFile;
                } catch (Throwable $e) {
                    throw new RuntimeException('配置文件加载失败：' . $e->getMessage());
                }
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
     * 检查核心表是否完整
     * @return array 返回缺失的表列表
     */
    public function checkCoreTables(): array {
        $requiredTables = [
            'users',
            'recharge_orders',
            'consumption_logs',
            'login_logs',
            'user_sessions'
        ];

        $missingTables = [];

        foreach ($requiredTables as $tableName) {
            $stmt = $this->pdo->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name='$tableName'"
            );

            if (!$stmt->fetch()) {
                $missingTables[] = $tableName;
            }
        }

        return $missingTables;
    }

    /**
     * 自动修复缺失的核心表
     * @return array 返回修复结果 ['success' => bool, 'repaired' => array, 'message' => string]
     */
    public function repairCoreTables(): array {
        $result = [
            'success' => true,
            'repaired' => [],
            'message' => ''
        ];

        // 检查是否有缺失的核心表
        $missingTables = $this->checkCoreTables();

        if (empty($missingTables)) {
            $result['message'] = '所有核心表完整';
            return $result;
        }

        try {
            // 直接执行完整的 init.sql 来修复
            $sqlFile = __DIR__ . '/database/init.sql';
            if (!file_exists($sqlFile)) {
                $result['success'] = false;
                $result['message'] = '初始化脚本 init.sql 不存在';
                return $result;
            }

            $sql = file_get_contents($sqlFile);
            $this->pdo->exec($sql);

            $result['repaired'] = $missingTables;
            $result['message'] = '已自动修复 ' . count($missingTables) . ' 个核心表';
            error_log("Database core tables auto-repaired: " . implode(', ', $missingTables));

        } catch (PDOException $e) {
            $result['success'] = false;
            $result['message'] = '修复失败: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * 获取 PDO 实例
     */
    public function getPdo(): PDO {
        return $this->pdo;
    }

    /**
     * 执行 SQL（用于 INSERT/UPDATE/DELETE）
     */
    public function execute(string $sql, array $params = []): bool {
        if ($this->pdo === null) {
            throw new RuntimeException('数据库未连接');
        }
        $stmt = $this->pdo->prepare($sql);
        $this->bindParams($stmt, $params);
        return $stmt->execute();
    }

    /**
     * 查询并返回结果集
     */
    public function query(string $sql, array $params = []): array {
        if ($this->pdo === null) {
            throw new RuntimeException('数据库未连接');
        }
        $stmt = $this->pdo->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * 绑定参数并处理类型
     */
    private function bindParams(PDOStatement $stmt, array $params): void {
        foreach ($params as $key => $value) {
            $paramKey = $this->normalizeParamKey($key);
            $type = PDO::PARAM_STR;
            if (is_int($value)) {
                $type = PDO::PARAM_INT;
            } elseif (is_bool($value)) {
                $type = PDO::PARAM_BOOL;
            } elseif ($value === null) {
                $type = PDO::PARAM_NULL;
            }
            $stmt->bindValue($paramKey, $value, $type);
        }
    }

    /**
     * 兼容命名参数与位置参数
     */
    private function normalizeParamKey($key) {
        if (is_int($key)) {
            return $key + 1;
        }
        if (is_string($key) && strpos($key, ':') === 0) {
            return $key;
        }
        return ':' . $key;
    }

    /**
     * 检查并初始化管理员系统表
     * @return array 返回初始化结果 ['success' => bool, 'created' => array, 'message' => string]
     */
    public function initAdminTables(): array {
        $result = [
            'success' => true,
            'created' => [],
            'message' => ''
        ];

        $adminTables = [
            'admin_sessions' => "
                CREATE TABLE IF NOT EXISTS admin_sessions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    session_token VARCHAR(255) NOT NULL UNIQUE,
                    ip_address VARCHAR(45) NOT NULL,
                    user_agent TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    expires_at DATETIME NOT NULL,
                    last_activity DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ",
            'admin_login_attempts' => "
                CREATE TABLE IF NOT EXISTS admin_login_attempts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ip_address VARCHAR(45) NOT NULL,
                    attempt_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                    success INTEGER DEFAULT 0
                )
            ",
            'admin_operation_logs' => "
                CREATE TABLE IF NOT EXISTS admin_operation_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    operation_type VARCHAR(50) NOT NULL,
                    target_user_id INTEGER,
                    details TEXT,
                    ip_address VARCHAR(45),
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ",
            'balance_logs' => "
                CREATE TABLE IF NOT EXISTS balance_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    type VARCHAR(20) NOT NULL,
                    amount DECIMAL(10, 2) NOT NULL,
                    balance_before DECIMAL(10, 2) NOT NULL,
                    balance_after DECIMAL(10, 2) NOT NULL,
                    remark TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id)
                )
            ",
            'password_reset_tokens' => "
                CREATE TABLE IF NOT EXISTS password_reset_tokens (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    token_hash VARCHAR(255) NOT NULL UNIQUE,
                    email VARCHAR(100) NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    expires_at DATETIME NOT NULL,
                    used INTEGER DEFAULT 0,
                    FOREIGN KEY (user_id) REFERENCES users(id)
                )
            "
        ];

        $indexes = [
            "CREATE INDEX IF NOT EXISTS idx_admin_session_token ON admin_sessions(session_token)",
            "CREATE INDEX IF NOT EXISTS idx_admin_expires ON admin_sessions(expires_at)",
            "CREATE INDEX IF NOT EXISTS idx_admin_attempts_ip ON admin_login_attempts(ip_address)",
            "CREATE INDEX IF NOT EXISTS idx_admin_attempts_time ON admin_login_attempts(attempt_time)",
            "CREATE INDEX IF NOT EXISTS idx_admin_ops_type ON admin_operation_logs(operation_type)",
            "CREATE INDEX IF NOT EXISTS idx_admin_ops_target ON admin_operation_logs(target_user_id)",
            "CREATE INDEX IF NOT EXISTS idx_admin_ops_time ON admin_operation_logs(created_at)",
            "CREATE INDEX IF NOT EXISTS idx_reset_token ON password_reset_tokens(token_hash)",
            "CREATE INDEX IF NOT EXISTS idx_reset_user ON password_reset_tokens(user_id)",
            "CREATE INDEX IF NOT EXISTS idx_balance_logs_user_id ON balance_logs(user_id)",
            "CREATE INDEX IF NOT EXISTS idx_balance_logs_created_at ON balance_logs(created_at)"
        ];

        try {
            // 检查并创建表
            foreach ($adminTables as $tableName => $createSql) {
                // 检查表是否存在
                $stmt = $this->pdo->query(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name='$tableName'"
                );

                if (!$stmt->fetch()) {
                    // 表不存在，创建它
                    $this->pdo->exec($createSql);
                    $result['created'][] = $tableName;
                }
            }

            // 创建索引
            foreach ($indexes as $indexSql) {
                $this->pdo->exec($indexSql);
            }

            if (!empty($result['created'])) {
                $result['message'] = '已自动创建 ' . count($result['created']) . ' 个管理员系统表';
            } else {
                $result['message'] = '所有管理员系统表已存在';
            }

        } catch (PDOException $e) {
            $result['success'] = false;
            $result['message'] = '初始化失败: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * 检查管理员表是否完整
     * @return array 返回缺失的表列表
     */
    public function checkAdminTables(): array {
        $requiredTables = [
            'admin_sessions',
            'admin_login_attempts',
            'admin_operation_logs',
            'password_reset_tokens',
            'balance_logs'
        ];

        $missingTables = [];

        foreach ($requiredTables as $tableName) {
            $stmt = $this->pdo->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name='$tableName'"
            );

            if (!$stmt->fetch()) {
                $missingTables[] = $tableName;
            }
        }

        return $missingTables;
    }

    // ============================================================
    // 用户相关操作
    // ============================================================

    /**
     * 创建用户
     */
    public function createUser(string $username, string $email, string $passwordHash, float $initialBalance = 0.00): ?int {
        $now = $this->now();
        $sql = "INSERT INTO users (username, email, password_hash, balance, status, created_at, updated_at)
                VALUES (:username, :email, :password_hash, :balance, 1, :created_at, :updated_at)";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':username' => $username,
                ':email' => $email,
                ':password_hash' => $passwordHash,
                ':balance' => $initialBalance,
                ':created_at' => $now,
                ':updated_at' => $now,
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
        $sql = "UPDATE users SET balance = balance + :amount, updated_at = :updated_at WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':amount' => $amount, ':id' => $userId, ':updated_at' => $this->now()]);
    }

    /**
     * 设置用户余额（绝对值）
     */
    public function setUserBalance(int $userId, float $balance): bool {
        $sql = "UPDATE users SET balance = :balance, updated_at = :updated_at WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':balance' => $balance, ':id' => $userId, ':updated_at' => $this->now()]);
    }

    /**
     * 原子扣除余额（带余额检查）
     * 使用 UPDATE ... WHERE balance >= amount 确保原子性，防止竞态条件
     *
     * 此方法在单条 SQL 语句中同时完成余额检查和扣除，确保：
     * 1. 如果余额不足，UPDATE 不会执行（rowCount = 0）
     * 2. 并发请求无法同时通过余额检查
     *
     * 性能优化：先执行 UPDATE，只有在需要返回余额信息时才查询
     *
     * @param int $userId 用户ID
     * @param float $amount 扣除金额
     * @return array ['success' => bool, 'balance_before' => float|null, 'balance_after' => float|null]
     */
    public function atomicDeductBalance(int $userId, float $amount): array {
        // 验证金额必须为正数
        if ($amount <= 0) {
            return [
                'success' => false,
                'balance_before' => null,
                'balance_after' => null,
                'error' => 'INVALID_AMOUNT'
            ];
        }
        
        // 原子扣费：只有当 balance >= amount 时才执行扣除
        // 先执行 UPDATE，避免预查询带来的性能开销
        $sql = "UPDATE users
                SET balance = balance - :amount, updated_at = :updated_at
                WHERE id = :id AND balance >= :amount_check";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':amount' => $amount,
            ':id' => $userId,
            ':amount_check' => $amount,
            ':updated_at' => $this->now()
        ]);
        
        // 检查是否成功扣除（rowCount > 0 表示更新了行）
        if ($stmt->rowCount() === 0) {
            // 扣费失败，可能是余额不足或用户不存在
            // 只有失败时才查询用户信息，用于返回当前余额
            $user = $this->getUserById($userId);
            if ($user === null) {
                return [
                    'success' => false,
                    'balance_before' => null,
                    'balance_after' => null,
                    'error' => 'USER_NOT_FOUND'
                ];
            }
            $currentBalance = (float) ($user['balance'] ?? 0);
            return [
                'success' => false,
                'balance_before' => $currentBalance,
                'balance_after' => $currentBalance,
                'error' => 'INSUFFICIENT_BALANCE'
            ];
        }
        
        // 扣费成功，查询更新后的余额
        $user = $this->getUserById($userId);
        $balanceAfter = $user ? (float) ($user['balance'] ?? 0) : 0;
        $balanceBefore = $balanceAfter + $amount;
        
        return [
            'success' => true,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter
        ];
    }

    /**
     * 原子退还余额
     * 用于生成失败时退款
     *
     * @param int $userId 用户ID
     * @param float $amount 退还金额
     * @return bool 是否成功
     */
    public function atomicRefundBalance(int $userId, float $amount): bool {
        // 验证金额必须为正数
        if ($amount <= 0) {
            return false;
        }
        
        $sql = "UPDATE users
                SET balance = balance + :amount, updated_at = :updated_at
                WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':amount' => $amount, ':id' => $userId, ':updated_at' => $this->now()]);
        return $stmt->rowCount() > 0;
    }

    /**
     * 更新用户登录信息
     */
    public function updateUserLogin(int $userId, string $ip): bool {
        $now = $this->now();
        $sql = "UPDATE users SET last_login_at = :last_login_at, last_login_ip = :ip, updated_at = :updated_at WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':ip' => $ip, ':id' => $userId, ':last_login_at' => $now, ':updated_at' => $now]);
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
     *
     * @param int $userId 用户ID
     * @param string $outTradeNo 商户订单号
     * @param float $amount 金额
     * @param string|null $payType 支付方式
     * @param int $expireMinutes 过期时间（分钟），0表示不过期
     * @return int 订单ID
     */
    public function createRechargeOrder(int $userId, string $outTradeNo, float $amount, ?string $payType = null, int $expireMinutes = 5): int {
        $now = $this->now();
        $expiresAt = $expireMinutes > 0 ? date('Y-m-d H:i:s', time() + $expireMinutes * 60) : null;
        
        $sql = "INSERT INTO recharge_orders (user_id, out_trade_no, amount, pay_type, status, created_at, expires_at)
                VALUES (:user_id, :out_trade_no, :amount, :pay_type, 0, :created_at, :expires_at)";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':out_trade_no' => $outTradeNo,
            ':amount' => $amount,
            ':pay_type' => $payType,
            ':created_at' => $now,
            ':expires_at' => $expiresAt,
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
     * 检查订单是否已过期
     *
     * @param array $order 订单数据
     * @return bool 是否已过期
     */
    public function isOrderExpired(array $order): bool {
        // 只有待支付订单才检查过期
        if ((int)$order['status'] !== 0) {
            return false;
        }
        
        // 如果没有设置过期时间，则不过期
        if (empty($order['expires_at'])) {
            return false;
        }
        
        return strtotime($order['expires_at']) < time();
    }

    /**
     * 获取已过期的待支付订单
     *
     * @param int $limit 限制数量
     * @return array 过期订单列表
     */
    public function getExpiredPendingOrders(int $limit = 100): array {
        $sql = "SELECT * FROM recharge_orders
                WHERE status = 0
                AND expires_at IS NOT NULL
                AND expires_at < :now
                ORDER BY created_at ASC
                LIMIT :limit";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':now', $this->now());
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * 获取过期待支付订单数量
     *
     * @return int 过期订单数量
     */
    public function getExpiredPendingOrderCount(): int {
        $sql = "SELECT COUNT(*) as count FROM recharge_orders
                WHERE status = 0
                AND expires_at IS NOT NULL
                AND expires_at < :now";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':now' => $this->now()]);
        $result = $stmt->fetch();
        return (int)($result['count'] ?? 0);
    }

    /**
     * 批量取消过期订单
     *
     * @param int $limit 每次处理的最大数量
     * @return int 取消的订单数量
     */
    public function cancelExpiredOrders(int $limit = 100): int {
        $sql = "UPDATE recharge_orders
                SET status = 2
                WHERE status = 0
                AND expires_at IS NOT NULL
                AND expires_at < :now
                LIMIT :limit";
        
        // SQLite 不支持 UPDATE ... LIMIT，需要用子查询
        $sql = "UPDATE recharge_orders
                SET status = 2
                WHERE id IN (
                    SELECT id FROM recharge_orders
                    WHERE status = 0
                    AND expires_at IS NOT NULL
                    AND expires_at < :now
                    LIMIT :limit
                )";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':now', $this->now());
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * 取消指定订单
     *
     * @param string $outTradeNo 商户订单号
     * @return bool 是否成功
     */
    public function cancelOrder(string $outTradeNo): bool {
        $sql = "UPDATE recharge_orders SET status = 2 WHERE out_trade_no = :out_trade_no AND status = 0";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':out_trade_no' => $outTradeNo]);
    }

    /**
     * 批量取消订单（按ID列表）
     *
     * @param array $orderIds 订单ID列表
     * @return int 取消的订单数量
     */
    public function cancelOrdersByIds(array $orderIds): int {
        if (empty($orderIds)) {
            return 0;
        }
        
        // 构建占位符
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $sql = "UPDATE recharge_orders SET status = 2 WHERE id IN ($placeholders) AND status = 0";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($orderIds);
        return $stmt->rowCount();
    }

    /**
     * 更新订单为已支付
     */
    public function markOrderPaid(string $outTradeNo, string $tradeNo, string $payType, ?string $notifyData = null): bool {
        $sql = "UPDATE recharge_orders
                SET status = 1, trade_no = :trade_no, pay_type = :pay_type,
                    paid_at = :paid_at, notify_data = :notify_data
                WHERE out_trade_no = :out_trade_no AND status = 0";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':trade_no' => $tradeNo,
            ':pay_type' => $payType,
            ':notify_data' => $notifyData,
            ':out_trade_no' => $outTradeNo,
            ':paid_at' => $this->now(),
        ]);
    }

    /**
     * 获取用户的充值记录
     *
     * @param int $userId 用户ID
     * @param int $limit 限制数量
     * @param int $offset 偏移量
     * @param bool $excludeCancelled 是否排除已取消的订单（默认true）
     * @return array 订单列表
     */
    public function getUserRechargeOrders(int $userId, int $limit = 20, int $offset = 0, bool $excludeCancelled = true): array {
        $sql = "SELECT * FROM recharge_orders WHERE user_id = :user_id";
        if ($excludeCancelled) {
            $sql .= " AND status != 2";
        }
        $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * 迁移：为现有数据库添加 expires_at 字段
     *
     * @return bool 是否成功
     */
    public function migrateAddExpiresAtColumn(): bool {
        try {
            // 检查列是否存在
            $result = $this->pdo->query("PRAGMA table_info(recharge_orders)");
            $columns = $result->fetchAll(PDO::FETCH_ASSOC);
            $hasExpiresAt = false;
            
            foreach ($columns as $column) {
                if ($column['name'] === 'expires_at') {
                    $hasExpiresAt = true;
                    break;
                }
            }
            
            if (!$hasExpiresAt) {
                $this->pdo->exec("ALTER TABLE recharge_orders ADD COLUMN expires_at DATETIME");
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_recharge_expires_at ON recharge_orders(expires_at)");
                
                // 为旧的待支付订单回填过期时间（创建时间 + 5分钟）
                $this->backfillExpiredAtForOldOrders(5);
                
                return true;
            }
            
            return true; // 列已存在
        } catch (PDOException $e) {
            error_log('Migration error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 回填旧订单的过期时间
     *
     * 为没有 expires_at 的待支付订单设置过期时间（创建时间 + 指定分钟数）
     *
     * @param int $expireMinutes 过期时间（分钟）
     * @return int 更新的订单数量
     */
    public function backfillExpiredAtForOldOrders(int $expireMinutes = 5): int {
        // 使用 SQLite 的 datetime 函数计算过期时间
        // 公式: expires_at = created_at + expireMinutes 分钟
        $sql = "UPDATE recharge_orders
                SET expires_at = datetime(created_at, '+{$expireMinutes} minutes')
                WHERE expires_at IS NULL
                AND status = 0";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $count = $stmt->rowCount();
        
        if ($count > 0) {
            error_log("Backfilled expires_at for {$count} old pending orders");
        }
        
        return $count;
    }

    /**
     * 获取没有过期时间的待支付订单数量
     *
     * @return int 订单数量
     */
    public function getPendingOrdersWithoutExpiresAt(): int {
        $sql = "SELECT COUNT(*) as count FROM recharge_orders
                WHERE status = 0 AND expires_at IS NULL";
        
        $stmt = $this->pdo->query($sql);
        $result = $stmt->fetch();
        return (int)($result['count'] ?? 0);
    }

    /**
     * 手动回填所有旧订单的过期时间
     *
     * 可用于管理员手动触发回填操作
     *
     * @param int $expireMinutes 过期时间（分钟）
     * @return array 结果信息
     */
    public function manualBackfillExpiredAt(int $expireMinutes = 5): array {
        $beforeCount = $this->getPendingOrdersWithoutExpiresAt();
        
        if ($beforeCount === 0) {
            return [
                'success' => true,
                'message' => '没有需要回填的订单',
                'updated_count' => 0
            ];
        }
        
        $updatedCount = $this->backfillExpiredAtForOldOrders($expireMinutes);
        
        return [
            'success' => true,
            'message' => "已为 {$updatedCount} 个旧订单回填过期时间",
            'updated_count' => $updatedCount
        ];
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
                VALUES (:user_id, :action, :amount, :balance_before, :balance_after, :image_count, :model_name, :remark, :created_at)";
        
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
            ':created_at' => $this->now(),
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
        $now = $this->now();
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresInSeconds);
        $sql = "INSERT INTO user_sessions (user_id, token_hash, expires_at, ip_address, user_agent, created_at)
                VALUES (:user_id, :token_hash, :expires_at, :ip, :user_agent, :created_at)";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':token_hash' => $tokenHash,
            ':expires_at' => $expiresAt,
            ':ip' => $ip,
            ':user_agent' => $userAgent,
            ':created_at' => $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * 根据 token 哈希获取有效会话
     */
    public function getValidSession(string $tokenHash): ?array {
        $sql = "SELECT * FROM user_sessions WHERE token_hash = :token_hash AND expires_at > :now LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':token_hash' => $tokenHash, ':now' => $this->now()]);
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
        $sql = "DELETE FROM user_sessions WHERE expires_at <= :now";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':now' => $this->now()]);
        return $stmt->rowCount();
    }

    // ============================================================
    // 登录日志相关操作
    // ============================================================

    /**
     * 记录登录日志
     */
    public function logLogin(int $userId, string $ip, ?string $userAgent = null, string $loginType = 'password', int $status = 1): int {
        $sql = "INSERT INTO login_logs (user_id, ip_address, user_agent, login_type, status, created_at)
                VALUES (:user_id, :ip, :user_agent, :login_type, :status, :created_at)";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':ip' => $ip,
            ':user_agent' => $userAgent,
            ':login_type' => $loginType,
            ':status' => $status,
            ':created_at' => $this->now(),
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

    // ============================================================
    // 管理员会话操作
    // ============================================================

    /**
     * 创建管理员会话
     */
    public function createAdminSession(string $token, string $ip, ?string $userAgent, int $expiresIn): int {
        $now = $this->now();
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        $sql = "INSERT INTO admin_sessions (session_token, ip_address, user_agent, expires_at, created_at, last_activity)
                VALUES (:token, :ip, :ua, :expires_at, :created_at, :last_activity)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':token' => $token,
            ':ip' => $ip,
            ':ua' => $userAgent,
            ':expires_at' => $expiresAt,
            ':created_at' => $now,
            ':last_activity' => $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * 获取管理员会话
     */
    public function getAdminSession(string $token): ?array {
        $sql = "SELECT * FROM admin_sessions WHERE session_token = :token LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':token' => $token]);
        $session = $stmt->fetch();
        return $session ?: null;
    }

    /**
     * 更新管理员活动时间
     */
    public function updateAdminActivity(string $token): bool {
        $sql = "UPDATE admin_sessions SET last_activity = :last_activity WHERE session_token = :token";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':token' => $token, ':last_activity' => $this->now()]);
    }

    /**
     * 删除管理员会话
     */
    public function deleteAdminSession(string $token): bool {
        $sql = "DELETE FROM admin_sessions WHERE session_token = :token";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':token' => $token]);
    }

    /**
     * 清理过期的管理员会话
     */
    public function cleanExpiredAdminSessions(): int {
        $sql = "DELETE FROM admin_sessions WHERE expires_at < :now";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':now' => $this->now()]);
        return $stmt->rowCount();
    }

    // ============================================================
    // 管理员登录尝试
    // ============================================================

    /**
     * 记录管理员登录尝试
     */
    public function logAdminAttempt(string $ip, int $success): int {
        $sql = "INSERT INTO admin_login_attempts (ip_address, success) VALUES (:ip, :success)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':ip' => $ip, ':success' => $success]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * 获取最近的登录尝试次数(失败的)
     */
    public function getRecentAdminAttempts(string $ip, int $minutes): int {
        $cutoffTime = date('Y-m-d H:i:s', time() - ($minutes * 60));
        $sql = "SELECT COUNT(*) as count FROM admin_login_attempts
                WHERE ip_address = :ip
                AND success = 0
                AND attempt_time > :cutoff_time";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':ip' => $ip, ':cutoff_time' => $cutoffTime]);
        $result = $stmt->fetch();

        return (int) ($result['count'] ?? 0);
    }

    // ============================================================
    // 管理员操作日志
    // ============================================================

    /**
     * 记录管理员操作
     */
    public function logAdminOperation(string $opType, ?int $targetUserId, array $details, string $ip): int {
        $sql = "INSERT INTO admin_operation_logs (operation_type, target_user_id, details, ip_address)
                VALUES (:type, :target, :details, :ip)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':type' => $opType,
            ':target' => $targetUserId,
            ':details' => json_encode($details, JSON_UNESCAPED_UNICODE),
            ':ip' => $ip,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * 获取管理员操作日志
     */
    public function getAdminOperationLogs(int $limit = 50, int $offset = 0, array $filters = []): array {
        $sql = "SELECT * FROM admin_operation_logs WHERE 1=1";
        $params = [];

        if (!empty($filters['operation_type'])) {
            $sql .= " AND operation_type = :type";
            $params[':type'] = $filters['operation_type'];
        }

        if (!empty($filters['target_user_id'])) {
            $sql .= " AND target_user_id = :user_id";
            $params[':user_id'] = $filters['target_user_id'];
        }

        $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    // ============================================================
    // 用户管理(管理员)
    // ============================================================

    /**
     * 获取所有用户(分页+搜索+筛选)
     */
    public function getAllUsers(int $limit = 20, int $offset = 0, ?string $search = null, ?int $status = null): array {
        $sql = "SELECT * FROM users WHERE 1=1";
        $params = [];

        if ($search !== null && $search !== '') {
            $sql .= " AND (username LIKE :search OR email LIKE :search OR id = :id)";
            $params[':search'] = '%' . $search . '%';
            $params[':id'] = (int) $search;
        }

        if ($status !== null) {
            $sql .= " AND status = :status";
            $params[':status'] = $status;
        }

        $sql .= " ORDER BY id DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * 获取用户总数
     */
    public function getUserCount(?string $search = null, ?int $status = null): int {
        $sql = "SELECT COUNT(*) as count FROM users WHERE 1=1";
        $params = [];

        if ($search !== null && $search !== '') {
            $sql .= " AND (username LIKE :search OR email LIKE :search OR id = :id)";
            $params[':search'] = '%' . $search . '%';
            $params[':id'] = (int) $search;
        }

        if ($status !== null) {
            $sql .= " AND status = :status";
            $params[':status'] = $status;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();

        return (int) ($result['count'] ?? 0);
    }

    /**
     * 更新用户邮箱
     */
    public function updateUserEmail(int $userId, string $email): bool {
        $sql = "UPDATE users SET email = :email, updated_at = :updated_at WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':email' => $email, ':id' => $userId, ':updated_at' => $this->now()]);
    }

    /**
     * 切换用户状态
     */
    public function toggleUserStatus(int $userId): bool {
        $sql = "UPDATE users SET status = CASE WHEN status = 1 THEN 0 ELSE 1 END,
                updated_at = :updated_at WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id' => $userId, ':updated_at' => $this->now()]);
    }

    /**
     * 更新用户密码
     */
    public function updateUserPassword(int $userId, string $passwordHash): bool {
        $sql = "UPDATE users SET password_hash = :hash, updated_at = :updated_at WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':hash' => $passwordHash, ':id' => $userId, ':updated_at' => $this->now()]);
    }

    /**
     * 获取用户充值统计
     */
    public function getUserRechargeStats(int $userId): array {
        // 充值统计
        $sql = "SELECT
                    COALESCE(SUM(amount), 0) as total_recharge,
                    COUNT(*) as order_count
                FROM recharge_orders
                WHERE user_id = :user_id AND status = 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $stats = $stmt->fetch() ?: ['total_recharge' => 0, 'order_count' => 0];

        // 消费统计
        $sql = "SELECT COALESCE(SUM(amount), 0) as total_consumption
                FROM consumption_logs
                WHERE user_id = :user_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $consumption = $stmt->fetch();
        $stats['total_consumption'] = (float)($consumption['total_consumption'] ?? 0);

        // 图片统计
        $sql = "SELECT COALESCE(SUM(image_count), 0) as total_images
                FROM consumption_logs
                WHERE user_id = :user_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $images = $stmt->fetch();
        $stats['total_images'] = (int)($images['total_images'] ?? 0);

        return $stats;
    }

    // ============================================================
    // 统计数据
    // ============================================================

    /**
     * 获取统计数据
     */
    public function getStatistics(): array {
        $stats = [];

        // 总用户数
        $sql = "SELECT COUNT(*) as total FROM users";
        $stmt = $this->pdo->query($sql);
        $stats['total_users'] = (int) $stmt->fetchColumn();

        // 今日新增用户
        $today = date('Y-m-d');
        $sql = "SELECT COUNT(*) as today FROM users WHERE DATE(created_at) = :today";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':today' => $today]);
        $stats['today_new_users'] = (int) $stmt->fetchColumn();

        // 总充值金额
        $sql = "SELECT COALESCE(SUM(amount), 0) as total FROM recharge_orders WHERE status = 1";
        $stmt = $this->pdo->query($sql);
        $stats['total_recharge'] = (float) $stmt->fetchColumn();

        // 今日充值金额
        $sql = "SELECT COALESCE(SUM(amount), 0) as today FROM recharge_orders
                WHERE status = 1 AND DATE(paid_at) = :today";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':today' => $today]);
        $stats['today_recharge'] = (float) $stmt->fetchColumn();

        // 总消费金额
        $sql = "SELECT COALESCE(SUM(amount), 0) as total FROM consumption_logs";
        $stmt = $this->pdo->query($sql);
        $stats['total_consumption'] = (float) $stmt->fetchColumn();

        // 今日消费金额
        $sql = "SELECT COALESCE(SUM(amount), 0) as today FROM consumption_logs
                WHERE DATE(created_at) = :today";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':today' => $today]);
        $stats['today_consumption'] = (float) $stmt->fetchColumn();

        // 总生成图片数
        $sql = "SELECT COALESCE(SUM(image_count), 0) as total FROM consumption_logs";
        $stmt = $this->pdo->query($sql);
        $stats['total_images'] = (int) $stmt->fetchColumn();

        // 今日生成图片数
        $sql = "SELECT COALESCE(SUM(image_count), 0) as today FROM consumption_logs
                WHERE DATE(created_at) = :today";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':today' => $today]);
        $stats['today_images'] = (int) $stmt->fetchColumn();

        return $stats;
    }

    /**
     * 获取最近用户注册列表
     */
    public function getRecentRegistrations(int $limit = 10): array {
        $sql = "SELECT id, username, email, balance, created_at FROM users
                ORDER BY created_at DESC LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * 获取最近充值订单
     */
    public function getRecentRechargeOrders(int $limit = 10): array {
        $sql = "SELECT r.*, u.username
                FROM recharge_orders r
                LEFT JOIN users u ON r.user_id = u.id
                WHERE r.status = 1
                ORDER BY r.paid_at DESC
                LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // ============================================================
    // 密码重置
    // ============================================================

    /**
     * 创建密码重置令牌
     */
    public function createPasswordResetToken(int $userId, string $email, int $expiresIn = 86400): string {
        $token = SecurityUtils::generateSecureToken(32);
        $tokenHash = hash('sha256', $token);
        $now = $this->now();
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);

        $sql = "INSERT INTO password_reset_tokens (user_id, token_hash, email, expires_at, created_at)
                VALUES (:user_id, :token_hash, :email, :expires_at, :created_at)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':token_hash' => $tokenHash,
            ':email' => $email,
            ':expires_at' => $expiresAt,
            ':created_at' => $now,
        ]);

        return $token; // 返回原始token,不是哈希值
    }

    /**
     * 获取密码重置令牌
     */
    public function getPasswordResetToken(string $token): ?array {
        $tokenHash = hash('sha256', $token);

        $sql = "SELECT * FROM password_reset_tokens
                WHERE token_hash = :token_hash
                AND used = 0
                AND expires_at > :now
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':token_hash' => $tokenHash, ':now' => $this->now()]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * 标记令牌为已使用
     */
    public function markTokenUsed(string $token): bool {
        $tokenHash = hash('sha256', $token);

        $sql = "UPDATE password_reset_tokens SET used = 1 WHERE token_hash = :token_hash";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':token_hash' => $tokenHash]);
    }
}
