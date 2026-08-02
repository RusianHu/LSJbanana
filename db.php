<?php
/**
 * 数据库操作类
 *
 * 使用 PDO 提供 SQLite/MySQL 双后端，负责用户、订单、消费记录等 CRUD 操作
 */

class Database {
    private static ?Database $instance = null;
    private ?PDO $pdo = null;
    private array $config;
    private string $driver = 'sqlite';
    
    /**
     * 获取当前本地时间的 ISO 格式字符串
     *
     * 统一由 PHP 生成应用时区时间，避免不同数据库的默认时区不一致。
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
        $this->driver = strtolower((string) ($this->config['driver'] ?? 'sqlite'));
        if (!in_array($this->driver, ['sqlite', 'mysql'], true)) {
            throw new RuntimeException("不支持的数据库驱动：{$this->driver}");
        }

        if (!in_array($this->driver, PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException("PHP 未启用 PDO {$this->driver} 驱动");
        }

        try {
            if ($this->driver === 'mysql') {
                $this->connectMysql();
            } else {
                $this->connectSqlite();
            }

            // 空数据库自动初始化；已有数据库只执行幂等迁移。
            if (!$this->tableExists('users')) {
                $this->initTables();
            }

            // 自动迁移：确保会话表包含 absolute_expires_at 字段
            $this->migrateSessionAbsoluteExpiry();
        } catch (PDOException $e) {
            throw new RuntimeException('数据库连接失败: ' . $e->getMessage());
        }
    }

    /**
     * 连接 SQLite。
     */
    private function connectSqlite(): void {
        $sqliteConfig = is_array($this->config['sqlite'] ?? null) ? $this->config['sqlite'] : [];
        $dbPath = $sqliteConfig['path'] ?? $this->config['path'] ?? __DIR__ . '/database/lsjbanana.db';
        $dbDir = dirname($dbPath);

        if (!is_dir($dbDir) && !mkdir($dbDir, 0755, true) && !is_dir($dbDir)) {
            throw new RuntimeException("无法创建 SQLite 数据库目录：{$dbDir}");
        }

        $this->pdo = new PDO("sqlite:{$dbPath}", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = ' . max(0, (int) ($sqliteConfig['busy_timeout_ms'] ?? 5000)));
    }

    /**
     * 连接 MySQL/MariaDB。
     */
    private function connectMysql(): void {
        $nested = is_array($this->config['mysql'] ?? null) ? $this->config['mysql'] : [];
        $mysqlConfig = array_merge($this->config, $nested);
        $host = (string) ($mysqlConfig['host'] ?? '127.0.0.1');
        $port = max(1, (int) ($mysqlConfig['port'] ?? 3306));
        $database = (string) ($mysqlConfig['database'] ?? $mysqlConfig['dbname'] ?? '');
        $username = (string) ($mysqlConfig['username'] ?? $mysqlConfig['user'] ?? '');
        $password = (string) ($mysqlConfig['password'] ?? '');
        $charset = strtolower((string) ($mysqlConfig['charset'] ?? 'utf8mb4'));

        if ($database === '' || $username === '') {
            throw new RuntimeException('MySQL 配置必须提供 database 和 username');
        }
        if (!preg_match('/^[a-z0-9_]+$/', $charset)) {
            throw new RuntimeException('MySQL charset 配置无效');
        }

        $socket = trim((string) ($mysqlConfig['unix_socket'] ?? ''));
        $dsn = $socket !== ''
            ? "mysql:unix_socket={$socket};dbname={$database};charset={$charset}"
            : "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => max(1, (int) ($mysqlConfig['connect_timeout'] ?? 5)),
        ];

        $sslConfig = is_array($mysqlConfig['ssl'] ?? null) ? $mysqlConfig['ssl'] : [];
        if (!empty($sslConfig['enabled'])) {
            if (!empty($sslConfig['ca'])) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $sslConfig['ca'];
            }
            if (!empty($sslConfig['cert'])) {
                $options[PDO::MYSQL_ATTR_SSL_CERT] = $sslConfig['cert'];
            }
            if (!empty($sslConfig['key'])) {
                $options[PDO::MYSQL_ATTR_SSL_KEY] = $sslConfig['key'];
            }
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = (bool) ($sslConfig['verify_server_cert'] ?? true);
            }
        }

        $this->pdo = new PDO($dsn, $username, $password, $options);
        // 使用固定偏移无需安装 MySQL 时区表，且与 PHP 的应用时区保持一致。
        $this->pdo->exec('SET SESSION time_zone = ' . $this->pdo->quote(date('P')));
    }

    /**
     * 初始化数据库表
     */
    private function initTables(): void {
        $sqlFile = $this->driver === 'mysql'
            ? __DIR__ . '/database/mysql_init.sql'
            : __DIR__ . '/database/init.sql';
        if (!file_exists($sqlFile)) {
            throw new RuntimeException("数据库初始化脚本不存在：{$sqlFile}");
        }

        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            throw new RuntimeException("无法读取数据库初始化脚本：{$sqlFile}");
        }
        $this->executeSqlScript($sql);
    }

    /**
     * 执行由分号分隔的简单 SQL 初始化脚本。
     */
    private function executeSqlScript(string $sql): void {
        $statements = preg_split('/;\s*(?:\r\n|\r|\n|$)/', $sql) ?: [];
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $this->pdo->exec($statement);
            }
        }
    }

    /**
     * 检查表是否存在。
     */
    private function tableExists(string $tableName): bool {
        if ($this->driver === 'mysql') {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1'
            );
            $stmt->execute([':table_name' => $tableName]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table_name LIMIT 1"
            );
            $stmt->execute([':table_name' => $tableName]);
        }

        return (bool) $stmt->fetchColumn();
    }

    /**
     * 检查列是否存在。
     */
    private function columnExists(string $tableName, string $columnName): bool {
        if ($this->driver === 'mysql') {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name LIMIT 1'
            );
            $stmt->execute([
                ':table_name' => $tableName,
                ':column_name' => $columnName,
            ]);
            return (bool) $stmt->fetchColumn();
        }

        $this->assertIdentifier($tableName);
        $stmt = $this->pdo->query("PRAGMA table_info(\"{$tableName}\")");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (($column['name'] ?? null) === $columnName) {
                return true;
            }
        }
        return false;
    }

    /**
     * 幂等创建索引。
     */
    private function ensureIndex(string $indexName, string $tableName, array $columns): void {
        foreach (array_merge([$indexName, $tableName], $columns) as $identifier) {
            $this->assertIdentifier($identifier);
        }

        if ($this->driver === 'mysql') {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table_name AND index_name = :index_name LIMIT 1'
            );
            $stmt->execute([
                ':table_name' => $tableName,
                ':index_name' => $indexName,
            ]);
            if ($stmt->fetchColumn()) {
                return;
            }
            $quotedColumns = implode(', ', array_map(fn(string $column): string => "`{$column}`", $columns));
            $this->pdo->exec("CREATE INDEX `{$indexName}` ON `{$tableName}` ({$quotedColumns})");
            return;
        }

        $quotedColumns = implode(', ', array_map(fn(string $column): string => "\"{$column}\"", $columns));
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS \"{$indexName}\" ON \"{$tableName}\" ({$quotedColumns})");
    }

    private function assertIdentifier(string $identifier): void {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException("无效的数据库标识符：{$identifier}");
        }
    }

    private function getMissingTables(array $tableNames): array {
        $missingTables = [];
        foreach ($tableNames as $tableName) {
            if (!$this->tableExists($tableName)) {
                $missingTables[] = $tableName;
            }
        }
        return $missingTables;
    }

    /**
     * 初始化一组功能表。
     */
    private function initializeTableGroup(array $tableNames, string $label): array {
        $missingBefore = $this->getMissingTables($tableNames);
        if ($missingBefore === []) {
            return [
                'success' => true,
                'created' => [],
                'message' => "所有{$label}表已存在",
            ];
        }

        try {
            $this->initTables();
            $missingAfter = $this->getMissingTables($tableNames);
            $created = array_values(array_diff($missingBefore, $missingAfter));
            return [
                'success' => $missingAfter === [],
                'created' => $created,
                'message' => $missingAfter === []
                    ? '已自动创建 ' . count($created) . " 个{$label}表"
                    : "仍缺少{$label}表：" . implode(', ', $missingAfter),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'created' => [],
                'message' => '初始化失败: ' . $e->getMessage(),
            ];
        }
    }

    private function isUniqueConstraintViolation(PDOException $e): bool {
        return $e->getCode() === '23000'
            || str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || str_contains($e->getMessage(), 'Duplicate entry');
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

        return $this->getMissingTables($requiredTables);
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
            // 执行当前驱动对应的完整初始化脚本，CREATE IF NOT EXISTS 保证幂等。
            $this->initTables();

            $remaining = $this->checkCoreTables();
            $result['repaired'] = array_values(array_diff($missingTables, $remaining));
            $result['success'] = $remaining === [];
            $result['message'] = $remaining === []
                ? '已自动修复 ' . count($result['repaired']) . ' 个核心表'
                : '仍缺少核心表：' . implode(', ', $remaining);
            if ($result['repaired'] !== []) {
                error_log("Database core tables auto-repaired: " . implode(', ', $result['repaired']));
            }

        } catch (Throwable $e) {
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
     * 获取当前数据库驱动。
     */
    public function getDriver(): string {
        return $this->driver;
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
        return $this->initializeTableGroup([
            'admin_sessions',
            'admin_login_attempts',
            'admin_operation_logs',
            'password_reset_tokens',
            'balance_logs',
        ], '管理员系统');
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

        return $this->getMissingTables($requiredTables);
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
            if ($this->isUniqueConstraintViolation($e)) {
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
        
        // 双层派生表同时兼容 SQLite 与 MySQL 的“更新目标表不可直接作为子查询来源”限制。
        $sql = "UPDATE recharge_orders
                SET status = 2
                WHERE id IN (
                    SELECT id FROM (
                        SELECT id FROM recharge_orders
                        WHERE status = 0
                        AND expires_at IS NOT NULL
                        AND expires_at < :now
                        LIMIT :limit
                    ) AS expired_orders
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
            if (!$this->columnExists('recharge_orders', 'expires_at')) {
                $this->pdo->exec("ALTER TABLE recharge_orders ADD COLUMN expires_at DATETIME");
                $this->ensureIndex('idx_recharge_expires_at', 'recharge_orders', ['expires_at']);
                
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
        $expireMinutes = max(0, $expireMinutes);
        $dateExpression = $this->driver === 'mysql'
            ? "DATE_ADD(created_at, INTERVAL {$expireMinutes} MINUTE)"
            : "datetime(created_at, '+{$expireMinutes} minutes')";
        $sql = "UPDATE recharge_orders
                SET expires_at = {$dateExpression}
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
     *
     * 同时写入 consumption_logs（业务消费事件）和 balance_logs（账户流水）。
     * 若调用方已在事务中，则直接执行；否则自动包裹一层事务保证原子性。
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
        $inTransaction = $this->pdo->inTransaction();

        $doInsert = function () use ($userId, $action, $amount, $balanceBefore, $balanceAfter, $imageCount, $modelName, $remark): int {
            $now = $this->now();

            // 1. 写入消费记录
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
                ':created_at' => $now,
            ]);
            $consumptionId = (int) $this->pdo->lastInsertId();

            // 2. 同步写入账户流水（消费扣费）
            $blSql = "INSERT INTO balance_logs (user_id, type, amount, balance_before, balance_after, remark, source_type, source_id, created_at)
                      VALUES (:user_id, 'deduct', :amount, :before, :after, :remark, 'consumption', :source_id, :created_at)";
            $blStmt = $this->pdo->prepare($blSql);
            $blStmt->execute([
                ':user_id' => $userId,
                ':amount' => -$amount,
                ':before' => $balanceBefore,
                ':after' => $balanceAfter,
                ':remark' => ($action === 'generate' ? 'Image Generate' : 'Image Edit') . ($modelName ? " ({$modelName})" : ''),
                ':source_id' => $consumptionId,
                ':created_at' => $now,
            ]);

            return $consumptionId;
        };

        if ($inTransaction) {
            // 已在事务中，直接执行（依赖外层事务的原子性）
            return $doInsert();
        } else {
            // 非事务上下文，自包一层事务保证原子性
            return $this->transaction(function () use ($doInsert) {
                return $doInsert();
            });
        }
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
     *
     * @param int $absoluteExpiresInSeconds 绝对过期时间(秒)，0表示不设置绝对上限
     */
    public function createSession(int $userId, string $tokenHash, int $expiresInSeconds, ?string $ip = null, ?string $userAgent = null, int $absoluteExpiresInSeconds = 0): int {
        $now = $this->now();
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresInSeconds);
        $absoluteExpiresAt = $absoluteExpiresInSeconds > 0
            ? date('Y-m-d H:i:s', time() + $absoluteExpiresInSeconds)
            : null;
        $sql = "INSERT INTO user_sessions (user_id, token_hash, expires_at, absolute_expires_at, ip_address, user_agent, created_at)
                VALUES (:user_id, :token_hash, :expires_at, :absolute_expires_at, :ip, :user_agent, :created_at)";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':token_hash' => $tokenHash,
            ':expires_at' => $expiresAt,
            ':absolute_expires_at' => $absoluteExpiresAt,
            ':ip' => $ip,
            ':user_agent' => $userAgent,
            ':created_at' => $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * 根据 token 哈希获取有效会话
     * 同时检查滑动过期和绝对过期
     */
    public function getValidSession(string $tokenHash): ?array {
        $now = $this->now();
        $sql = "SELECT * FROM user_sessions WHERE token_hash = :token_hash AND expires_at > :now AND (absolute_expires_at IS NULL OR absolute_expires_at > :now2) LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':token_hash' => $tokenHash, ':now' => $now, ':now2' => $now]);
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
     *
     * @param int $absoluteExpiresIn 绝对过期时间(秒)，0表示不设置绝对上限
     */
    public function createAdminSession(string $token, string $ip, ?string $userAgent, int $expiresIn, int $absoluteExpiresIn = 0): int {
        $now = $this->now();
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        $absoluteExpiresAt = $absoluteExpiresIn > 0
            ? date('Y-m-d H:i:s', time() + $absoluteExpiresIn)
            : null;
        $sql = "INSERT INTO admin_sessions (session_token, ip_address, user_agent, expires_at, absolute_expires_at, created_at, last_activity)
                VALUES (:token, :ip, :ua, :expires_at, :absolute_expires_at, :created_at, :last_activity)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':token' => $token,
            ':ip' => $ip,
            ':ua' => $userAgent,
            ':expires_at' => $expiresAt,
            ':absolute_expires_at' => $absoluteExpiresAt,
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
     * 更新管理员活动时间并滑动续期
     *
     * @param int $renewSeconds 续期秒数，>0 时同时延长 expires_at（不超过 absolute_expires_at）
     */
    public function updateAdminActivity(string $token, int $renewSeconds = 0): bool {
        $now = $this->now();
        if ($renewSeconds > 0) {
            $newExpiresAt = date('Y-m-d H:i:s', time() + $renewSeconds);
            $sql = "UPDATE admin_sessions SET last_activity = :last_activity,
                    expires_at = CASE
                        WHEN absolute_expires_at IS NOT NULL AND :new_expires > absolute_expires_at THEN absolute_expires_at
                        ELSE :new_expires2
                    END
                    WHERE session_token = :token";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':last_activity' => $now,
                ':new_expires' => $newExpiresAt,
                ':new_expires2' => $newExpiresAt,
                ':token' => $token,
            ]);
        }
        $sql = "UPDATE admin_sessions SET last_activity = :last_activity WHERE session_token = :token";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':token' => $token, ':last_activity' => $now]);
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
        $sql = "INSERT INTO admin_login_attempts (ip_address, success, attempt_time)
                VALUES (:ip, :success, :attempt_time)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':ip' => $ip,
            ':success' => $success,
            ':attempt_time' => $this->now(),
        ]);
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
        $sql = "INSERT INTO admin_operation_logs (operation_type, target_user_id, details, ip_address, created_at)
                VALUES (:type, :target, :details, :ip, :created_at)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':type' => $opType,
            ':target' => $targetUserId,
            ':details' => json_encode($details, JSON_UNESCAPED_UNICODE),
            ':ip' => $ip,
            ':created_at' => $this->now(),
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
            $sql .= " AND (username LIKE :search_username OR email LIKE :search_email OR id = :id)";
            $params[':search_username'] = '%' . $search . '%';
            $params[':search_email'] = '%' . $search . '%';
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
            $sql .= " AND (username LIKE :search_username OR email LIKE :search_email OR id = :id)";
            $params[':search_username'] = '%' . $search . '%';
            $params[':search_email'] = '%' . $search . '%';
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

    // ============================================================
    // 用户操作记录查询（管理后台）
    // ============================================================

    /**
     * 获取用户登录历史
     *
     * @param int $userId 用户ID
     * @param int $limit 限制数量
     * @param int $offset 偏移量
     * @return array ['logs' => array, 'total' => int]
     */
    public function getUserLoginLogs(int $userId, int $limit = 10, int $offset = 0): array {
        // 获取记录
        $sql = "SELECT * FROM login_logs WHERE user_id = :user_id ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll();

        // 获取总数
        $countSql = "SELECT COUNT(*) as total FROM login_logs WHERE user_id = :user_id";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute([':user_id' => $userId]);
        $total = (int) ($countStmt->fetch()['total'] ?? 0);

        return ['logs' => $logs, 'total' => $total];
    }

    /**
     * 获取用户消费明细（带分页）
     *
     * @param int $userId 用户ID
     * @param int $limit 限制数量
     * @param int $offset 偏移量
     * @return array ['logs' => array, 'total' => int]
     */
    public function getUserConsumptionLogsPaginated(int $userId, int $limit = 10, int $offset = 0): array {
        // 获取记录
        $sql = "SELECT * FROM consumption_logs WHERE user_id = :user_id ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll();

        // 获取总数
        $countSql = "SELECT COUNT(*) as total FROM consumption_logs WHERE user_id = :user_id";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute([':user_id' => $userId]);
        $total = (int) ($countStmt->fetch()['total'] ?? 0);

        return ['logs' => $logs, 'total' => $total];
    }

    /**
     * 获取用户余额变动记录（管理员手动操作）
     *
     * @param int $userId 用户ID
     * @param int $limit 限制数量
     * @param int $offset 偏移量
     * @return array ['logs' => array, 'total' => int]
     */
    public function getUserBalanceLogs(int $userId, int $limit = 10, int $offset = 0): array {
        // 获取记录
        $sql = "SELECT * FROM balance_logs WHERE user_id = :user_id ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll();

        // 获取总数
        $countSql = "SELECT COUNT(*) as total FROM balance_logs WHERE user_id = :user_id";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute([':user_id' => $userId]);
        $total = (int) ($countStmt->fetch()['total'] ?? 0);

        return ['logs' => $logs, 'total' => $total];
    }

    /**
     * 获取用户可见的余额变动记录
     * 用于充值页面展示管理员手动操作的可见记录
     *
     * @param int $userId 用户ID
     * @param int $limit 限制数量
     * @param int $offset 偏移量
     * @return array ['logs' => array, 'total' => int]
     */
    public function getUserVisibleBalanceLogs(int $userId, int $limit = 10, int $offset = 0): array {
        // 获取记录（只获取 visible_to_user = 1 的记录）
        $sql = "SELECT * FROM balance_logs WHERE user_id = :user_id AND visible_to_user = 1 ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll();

        // 获取总数
        $countSql = "SELECT COUNT(*) as total FROM balance_logs WHERE user_id = :user_id AND visible_to_user = 1";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute([':user_id' => $userId]);
        $total = (int) ($countStmt->fetch()['total'] ?? 0);

        return ['logs' => $logs, 'total' => $total];
    }

    /**
     * 迁移：为 balance_logs 表添加 visible_to_user、user_remark、source_type、source_id 字段
     *
     * @return bool 是否成功
     */
    public function migrateBalanceLogsVisibility(): bool {
        try {
            if (!$this->columnExists('balance_logs', 'visible_to_user')) {
                $visibleType = $this->driver === 'mysql' ? 'TINYINT(1)' : 'INTEGER';
                $this->pdo->exec("ALTER TABLE balance_logs ADD COLUMN visible_to_user {$visibleType} DEFAULT 0");
                $this->ensureIndex('idx_balance_logs_visible', 'balance_logs', ['visible_to_user']);
            }
            
            if (!$this->columnExists('balance_logs', 'user_remark')) {
                $this->pdo->exec("ALTER TABLE balance_logs ADD COLUMN user_remark TEXT");
            }

            // source_type: manual_recharge, manual_deduct, online_recharge, consumption
            if (!$this->columnExists('balance_logs', 'source_type')) {
                $this->pdo->exec("ALTER TABLE balance_logs ADD COLUMN source_type VARCHAR(30) DEFAULT 'manual_recharge'");
            }

            // source_id: 关联的 recharge_orders.id 或 consumption_logs.id
            if (!$this->columnExists('balance_logs', 'source_id')) {
                $sourceIdType = $this->driver === 'mysql' ? 'BIGINT UNSIGNED' : 'INTEGER';
                $this->pdo->exec("ALTER TABLE balance_logs ADD COLUMN source_id {$sourceIdType}");
            }
            
            return true;
        } catch (PDOException $e) {
            error_log('Balance logs migration error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取用户充值订单记录（带分页）
     *
     * @param int $userId 用户ID
     * @param int $limit 限制数量
     * @param int $offset 偏移量
     * @param bool $includeAll 是否包含所有状态（包括已取消）
     * @return array ['orders' => array, 'total' => int]
     */
    public function getUserRechargeOrdersPaginated(int $userId, int $limit = 10, int $offset = 0, bool $includeAll = true): array {
        // 获取记录
        $sql = "SELECT * FROM recharge_orders WHERE user_id = :user_id";
        if (!$includeAll) {
            $sql .= " AND status != 2"; // 排除已取消
        }
        $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $orders = $stmt->fetchAll();

        // 获取总数
        $countSql = "SELECT COUNT(*) as total FROM recharge_orders WHERE user_id = :user_id";
        if (!$includeAll) {
            $countSql .= " AND status != 2";
        }
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute([':user_id' => $userId]);
        $total = (int) ($countStmt->fetch()['total'] ?? 0);

        return ['orders' => $orders, 'total' => $total];
    }

    // ============================================================
    // 公告系统相关操作
    // ============================================================

    /**
     * 检查并初始化公告系统表
     * @return array 返回初始化结果 ['success' => bool, 'created' => array, 'message' => string]
     */
    public function initAnnouncementTables(): array {
        return $this->initializeTableGroup([
            'announcements',
            'announcement_dismissals',
        ], '公告系统');
    }

    /**
     * 检查公告表是否完整
     * @return array 返回缺失的表列表
     */
    public function checkAnnouncementTables(): array {
        $requiredTables = [
            'announcements',
            'announcement_dismissals'
        ];

        return $this->getMissingTables($requiredTables);
    }

    /**
     * 创建公告
     */
    public function createAnnouncement(array $data): ?int {
        $now = $this->now();
        $sql = "INSERT INTO announcements (title, content, type, display_mode, target, priority, is_dismissible, is_active, start_at, end_at, created_at, updated_at, created_by)
                VALUES (:title, :content, :type, :display_mode, :target, :priority, :is_dismissible, :is_active, :start_at, :end_at, :created_at, :updated_at, :created_by)";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':title' => $data['title'],
                ':content' => $data['content'],
                ':type' => $data['type'] ?? 'info',
                ':display_mode' => $data['display_mode'] ?? 'banner',
                ':target' => $data['target'] ?? 'all',
                ':priority' => (int)($data['priority'] ?? 0),
                ':is_dismissible' => (int)($data['is_dismissible'] ?? 1),
                ':is_active' => (int)($data['is_active'] ?? 1),
                ':start_at' => !empty($data['start_at']) ? $data['start_at'] : null,
                ':end_at' => !empty($data['end_at']) ? $data['end_at'] : null,
                ':created_at' => $now,
                ':updated_at' => $now,
                ':created_by' => $data['created_by'] ?? 'admin',
            ]);
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log('Create announcement failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 更新公告
     */
    public function updateAnnouncement(int $id, array $data): bool {
        $now = $this->now();
        $sql = "UPDATE announcements SET
                title = :title,
                content = :content,
                type = :type,
                display_mode = :display_mode,
                target = :target,
                priority = :priority,
                is_dismissible = :is_dismissible,
                is_active = :is_active,
                start_at = :start_at,
                end_at = :end_at,
                updated_at = :updated_at
                WHERE id = :id";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':id' => $id,
                ':title' => $data['title'],
                ':content' => $data['content'],
                ':type' => $data['type'] ?? 'info',
                ':display_mode' => $data['display_mode'] ?? 'banner',
                ':target' => $data['target'] ?? 'all',
                ':priority' => (int)($data['priority'] ?? 0),
                ':is_dismissible' => (int)($data['is_dismissible'] ?? 1),
                ':is_active' => (int)($data['is_active'] ?? 1),
                ':start_at' => !empty($data['start_at']) ? $data['start_at'] : null,
                ':end_at' => !empty($data['end_at']) ? $data['end_at'] : null,
                ':updated_at' => $now,
            ]);
        } catch (PDOException $e) {
            error_log('Update announcement failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 删除公告
     */
    public function deleteAnnouncement(int $id): bool {
        $sql = "DELETE FROM announcements WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }

    /**
     * 获取单个公告
     */
    public function getAnnouncementById(int $id): ?array {
        $sql = "SELECT * FROM announcements WHERE id = :id LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $announcement = $stmt->fetch();
        return $announcement ?: null;
    }

    /**
     * 切换公告启用状态
     */
    public function toggleAnnouncementStatus(int $id): bool {
        $sql = "UPDATE announcements SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END,
                updated_at = :updated_at WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id' => $id, ':updated_at' => $this->now()]);
    }

    /**
     * 获取公告列表（管理后台用，支持分页、筛选、搜索）
     */
    public function getAnnouncements(int $limit = 20, int $offset = 0, array $filters = []): array {
        $sql = "SELECT * FROM announcements WHERE 1=1";
        $params = [];

        // 状态筛选
        if (isset($filters['status'])) {
            if ($filters['status'] === 'active') {
                $sql .= " AND is_active = 1";
            } elseif ($filters['status'] === 'inactive') {
                $sql .= " AND is_active = 0";
            }
        }

        // 类型筛选
        if (!empty($filters['type'])) {
            $sql .= " AND type = :type";
            $params[':type'] = $filters['type'];
        }

        // 展示模式筛选
        if (!empty($filters['display_mode'])) {
            $sql .= " AND display_mode = :display_mode";
            $params[':display_mode'] = $filters['display_mode'];
        }

        // 搜索
        if (!empty($filters['search'])) {
            $sql .= " AND (title LIKE :search_title OR content LIKE :search_content)";
            $params[':search_title'] = '%' . $filters['search'] . '%';
            $params[':search_content'] = '%' . $filters['search'] . '%';
        }

        $sql .= " ORDER BY priority DESC, created_at DESC LIMIT :limit OFFSET :offset";

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
     * 获取公告总数
     */
    public function getAnnouncementCount(array $filters = []): int {
        $sql = "SELECT COUNT(*) as count FROM announcements WHERE 1=1";
        $params = [];

        if (isset($filters['status'])) {
            if ($filters['status'] === 'active') {
                $sql .= " AND is_active = 1";
            } elseif ($filters['status'] === 'inactive') {
                $sql .= " AND is_active = 0";
            }
        }

        if (!empty($filters['type'])) {
            $sql .= " AND type = :type";
            $params[':type'] = $filters['type'];
        }

        if (!empty($filters['display_mode'])) {
            $sql .= " AND display_mode = :display_mode";
            $params[':display_mode'] = $filters['display_mode'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (title LIKE :search_title OR content LIKE :search_content)";
            $params[':search_title'] = '%' . $filters['search'] . '%';
            $params[':search_content'] = '%' . $filters['search'] . '%';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();

        return (int) ($result['count'] ?? 0);
    }

    /**
     * 获取当前有效公告（前端展示用）
     *
     * @param bool $isLoggedIn 用户是否已登录
     * @param int|null $userId 用户ID（已登录时提供）
     * @param array $dismissedIds 已关闭的公告ID列表（访客从localStorage获取）
     * @return array 按展示模式分组的公告列表
     */
    public function getActiveAnnouncements(bool $isLoggedIn = false, ?int $userId = null, array $dismissedIds = []): array {
        $now = $this->now();
        
        // 构建基础查询
        $sql = "SELECT * FROM announcements
                WHERE is_active = 1
                AND (start_at IS NULL OR start_at <= :now1)
                AND (end_at IS NULL OR end_at >= :now2)";
        
        $params = [
            ':now1' => $now,
            ':now2' => $now,
        ];
        
        // 目标用户筛选
        if ($isLoggedIn) {
            $sql .= " AND target IN ('all', 'logged_in')";
        } else {
            $sql .= " AND target IN ('all', 'guest')";
        }
        
        $sql .= " ORDER BY priority DESC, created_at DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $announcements = $stmt->fetchAll();
        
        // 获取已登录用户的关闭记录
        $userDismissedIds = [];
        if ($isLoggedIn && $userId) {
            $dismissSql = "SELECT announcement_id FROM announcement_dismissals WHERE user_id = :user_id";
            $dismissStmt = $this->pdo->prepare($dismissSql);
            $dismissStmt->execute([':user_id' => $userId]);
            $userDismissedIds = array_column($dismissStmt->fetchAll(), 'announcement_id');
        }
        
        // 合并关闭记录
        $allDismissedIds = array_unique(array_merge($dismissedIds, $userDismissedIds));
        
        // 过滤已关闭的公告，并按展示模式分组
        $result = [
            'banners' => [],
            'modals' => [],
            'inlines' => []
        ];
        
        foreach ($announcements as $announcement) {
            // 跳过已关闭的公告
            if (in_array($announcement['id'], $allDismissedIds)) {
                continue;
            }
            
            $item = [
                'id' => (int)$announcement['id'],
                'title' => $announcement['title'],
                'content' => $announcement['content'],
                'type' => $announcement['type'],
                'is_dismissible' => (bool)$announcement['is_dismissible'],
            ];
            
            switch ($announcement['display_mode']) {
                case 'banner':
                    $result['banners'][] = $item;
                    break;
                case 'modal':
                    $result['modals'][] = $item;
                    break;
                case 'inline':
                    $result['inlines'][] = $item;
                    break;
            }
        }
        
        return $result;
    }

    /**
     * 记录用户关闭公告
     */
    public function dismissAnnouncement(int $announcementId, int $userId): bool {
        $sql = ($this->driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE')
            . " INTO announcement_dismissals (announcement_id, user_id, dismissed_at)
                VALUES (:announcement_id, :user_id, :dismissed_at)";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':announcement_id' => $announcementId,
                ':user_id' => $userId,
                ':dismissed_at' => $this->now(),
            ]);
        } catch (PDOException $e) {
            error_log('Dismiss announcement failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取用户已关闭的公告ID列表
     */
    public function getUserDismissedAnnouncementIds(int $userId): array {
        $sql = "SELECT announcement_id FROM announcement_dismissals WHERE user_id = :user_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        return array_column($stmt->fetchAll(), 'announcement_id');
    }

    /**
     * 清理过期的关闭记录（针对已删除的公告）
     */
    public function cleanupDismissals(): int {
        $sql = "DELETE FROM announcement_dismissals
                WHERE announcement_id NOT IN (SELECT id FROM announcements)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->rowCount();
    }

    // ============================================================
    // 会话滑动续期
    // ============================================================

    /**
     * 续期用户会话（Remember Me token）
     * 延长 expires_at 但不超过 absolute_expires_at
     *
     * @param string $tokenHash token 哈希
     * @param int $renewSeconds 续期秒数
     * @return bool
     */
    public function renewUserSession(string $tokenHash, int $renewSeconds): bool {
        $newExpiresAt = date('Y-m-d H:i:s', time() + $renewSeconds);
        $sql = "UPDATE user_sessions SET expires_at = CASE
                    WHEN absolute_expires_at IS NOT NULL AND :new_expires > absolute_expires_at THEN absolute_expires_at
                    ELSE :new_expires2
                END
                WHERE token_hash = :token_hash";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':new_expires' => $newExpiresAt,
            ':new_expires2' => $newExpiresAt,
            ':token_hash' => $tokenHash,
        ]);
    }

    // ============================================================
    // 会话表结构迁移
    // ============================================================

    /**
     * 迁移：为 user_sessions 和 admin_sessions 表添加 absolute_expires_at 字段
     *
     * @return bool
     */
    public function migrateSessionAbsoluteExpiry(): bool {
        try {
            foreach (['user_sessions', 'admin_sessions'] as $tableName) {
                if ($this->tableExists($tableName) && !$this->columnExists($tableName, 'absolute_expires_at')) {
                    $this->assertIdentifier($tableName);
                    $this->pdo->exec("ALTER TABLE {$tableName} ADD COLUMN absolute_expires_at DATETIME");
                }
            }

            return true;
        } catch (PDOException $e) {
            error_log('Session absolute expiry migration error: ' . $e->getMessage());
            return false;
        }
    }
}
