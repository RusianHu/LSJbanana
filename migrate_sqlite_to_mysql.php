<?php
/**
 * 将现有 SQLite 数据迁移到 config.php 中配置的 MySQL/MariaDB。
 *
 * 用法：
 *   php migrate_sqlite_to_mysql.php
 *   php migrate_sqlite_to_mysql.php --source=database/lsjbanana.db
 *   php migrate_sqlite_to_mysql.php --force
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only run from CLI.\n");
}

$options = getopt('', ['source::', 'config::', 'force', 'help']);
if (isset($options['help'])) {
    echo "SQLite -> MySQL migration\n\n";
    echo "Options:\n";
    echo "  --source=PATH   SQLite source file (defaults to database.path)\n";
    echo "  --config=PATH   Config file (defaults to ./config.php)\n";
    echo "  --force         Clear managed target tables before importing\n";
    exit(0);
}

$rootDir = __DIR__;
$configPath = isset($options['config'])
    ? resolvePath((string) $options['config'], getcwd() ?: $rootDir)
    : $rootDir . '/config.php';

if (!is_file($configPath)) {
    fail("配置文件不存在：{$configPath}");
}

$config = require $configPath;
$databaseConfig = is_array($config['database'] ?? null) ? $config['database'] : [];
$sqliteConfig = is_array($databaseConfig['sqlite'] ?? null) ? $databaseConfig['sqlite'] : [];
$sourcePath = isset($options['source'])
    ? resolvePath((string) $options['source'], getcwd() ?: $rootDir)
    : (string) ($sqliteConfig['path'] ?? $databaseConfig['path'] ?? $rootDir . '/database/lsjbanana.db');

if (!is_file($sourcePath)) {
    fail("SQLite 源文件不存在：{$sourcePath}");
}
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fail('PHP 未启用 pdo_sqlite 扩展');
}
if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fail('PHP 未启用 pdo_mysql 扩展');
}

$mysqlConfig = array_merge(
    $databaseConfig,
    is_array($databaseConfig['mysql'] ?? null) ? $databaseConfig['mysql'] : []
);
$targetDatabase = (string) ($mysqlConfig['database'] ?? $mysqlConfig['dbname'] ?? '');
$targetUser = (string) ($mysqlConfig['username'] ?? $mysqlConfig['user'] ?? '');
if ($targetDatabase === '' || $targetUser === '') {
    fail('MySQL 配置必须提供 database 和 username');
}

$tables = [
    'users',
    'recharge_orders',
    'balance_logs',
    'consumption_logs',
    'login_logs',
    'user_sessions',
    'admin_sessions',
    'admin_login_attempts',
    'admin_operation_logs',
    'password_reset_tokens',
    'announcements',
    'announcement_dismissals',
];
$force = array_key_exists('force', $options);

try {
    $source = new PDO('sqlite:' . $sourcePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $source->exec('PRAGMA foreign_keys = ON');
    $source->exec('PRAGMA query_only = ON');

    $target = connectMysql($mysqlConfig);
    executeSqlFile($target, $rootDir . '/database/mysql_init.sql');

    $existingRows = [];
    foreach ($tables as $table) {
        $existingRows[$table] = (int) $target->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }
    $nonEmptyTables = array_filter($existingRows, static fn(int $count): bool => $count > 0);
    if ($nonEmptyTables !== [] && !$force) {
        $details = implode(', ', array_map(
            static fn(string $table, int $count): string => "{$table}={$count}",
            array_keys($nonEmptyTables),
            array_values($nonEmptyTables)
        ));
        fail("目标数据库并非空库（{$details}）。确认覆盖时请添加 --force");
    }

    echo "源数据库：{$sourcePath}\n";
    echo "目标数据库：{$targetDatabase}\n";
    echo $force ? "模式：清空目标业务表后迁移\n\n" : "模式：迁移到空库\n\n";

    $target->exec('SET FOREIGN_KEY_CHECKS = 0');
    $target->beginTransaction();
    if ($force) {
        foreach (array_reverse($tables) as $table) {
            $target->exec("DELETE FROM `{$table}`");
        }
    }

    $summary = [];
    foreach ($tables as $table) {
        if (!sqliteTableExists($source, $table)) {
            $summary[$table] = ['source' => 0, 'target' => 0, 'skipped' => true];
            echo sprintf("%-30s skipped (source table missing)\n", $table);
            continue;
        }

        $sourceColumns = sqliteColumns($source, $table);
        $targetColumns = mysqlColumns($target, $table);
        $columns = array_values(array_intersect($sourceColumns, $targetColumns));
        if ($columns === []) {
            throw new RuntimeException("表 {$table} 没有可迁移的公共字段");
        }

        $quotedColumns = implode(', ', array_map(static fn(string $column): string => "`{$column}`", $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $insert = $target->prepare("INSERT INTO `{$table}` ({$quotedColumns}) VALUES ({$placeholders})");
        $orderBy = in_array('id', $columns, true) ? ' ORDER BY id ASC' : '';
        $rows = $source->query("SELECT * FROM \"{$table}\"{$orderBy}");

        $sourceCount = 0;
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = $row[$column] ?? null;
            }
            $insert->execute($values);
            $sourceCount++;
        }

        $targetCount = (int) $target->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        if ($sourceCount !== $targetCount) {
            throw new RuntimeException("表 {$table} 行数校验失败：SQLite={$sourceCount}, MySQL={$targetCount}");
        }

        $summary[$table] = ['source' => $sourceCount, 'target' => $targetCount, 'skipped' => false];
        echo sprintf("%-30s %d rows\n", $table, $targetCount);
    }
    $target->commit();

    foreach ($tables as $table) {
        if (!in_array('id', mysqlColumns($target, $table), true)) {
            continue;
        }
        $nextId = (int) $target->query("SELECT COALESCE(MAX(id), 0) + 1 FROM `{$table}`")->fetchColumn();
        $nextId = max(1, $nextId);
        $target->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = {$nextId}");
    }

    $target->exec('SET FOREIGN_KEY_CHECKS = 1');
    $foreignKeyIssues = (int) $source->query('SELECT COUNT(*) FROM pragma_foreign_key_check')->fetchColumn();
    if ($foreignKeyIssues > 0) {
        echo "\n警告：SQLite 源库存在 {$foreignKeyIssues} 条外键异常，请人工检查。\n";
    }

    $totalRows = array_sum(array_column($summary, 'target'));
    echo "\n迁移完成：" . count($summary) . " 个表，共 {$totalRows} 行，主键已保留。\n";
} catch (Throwable $e) {
    if (isset($target) && $target instanceof PDO) {
        if ($target->inTransaction()) {
            $target->rollBack();
        }
        try {
            $target->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Throwable) {
        }
    }
    fail('迁移失败：' . $e->getMessage());
}

function connectMysql(array $config): PDO {
    $host = (string) ($config['host'] ?? '127.0.0.1');
    $port = max(1, (int) ($config['port'] ?? 3306));
    $database = (string) ($config['database'] ?? $config['dbname'] ?? '');
    $username = (string) ($config['username'] ?? $config['user'] ?? '');
    $password = (string) ($config['password'] ?? '');
    $charset = strtolower((string) ($config['charset'] ?? 'utf8mb4'));
    $socket = trim((string) ($config['unix_socket'] ?? ''));
    if (!preg_match('/^[a-z0-9_]+$/', $charset)) {
        throw new RuntimeException('MySQL charset 配置无效');
    }

    $dsn = $socket !== ''
        ? "mysql:unix_socket={$socket};dbname={$database};charset={$charset}"
        : "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => max(1, (int) ($config['connect_timeout'] ?? 5)),
    ];

    $ssl = is_array($config['ssl'] ?? null) ? $config['ssl'] : [];
    if (!empty($ssl['enabled'])) {
        if (!empty($ssl['ca'])) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $ssl['ca'];
        }
        if (!empty($ssl['cert'])) {
            $options[PDO::MYSQL_ATTR_SSL_CERT] = $ssl['cert'];
        }
        if (!empty($ssl['key'])) {
            $options[PDO::MYSQL_ATTR_SSL_KEY] = $ssl['key'];
        }
        if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = (bool) ($ssl['verify_server_cert'] ?? true);
        }
    }

    $pdo = new PDO($dsn, $username, $password, $options);
    $pdo->exec('SET SESSION time_zone = ' . $pdo->quote(date('P')));
    return $pdo;
}

function executeSqlFile(PDO $pdo, string $path): void {
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("无法读取 SQL 文件：{$path}");
    }
    $statements = preg_split('/;\s*(?:\r\n|\r|\n|$)/', $sql) ?: [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function sqliteTableExists(PDO $pdo, string $table): bool {
    assertIdentifier($table);
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
    $stmt->execute([':table' => $table]);
    return (bool) $stmt->fetchColumn();
}

function sqliteColumns(PDO $pdo, string $table): array {
    assertIdentifier($table);
    $rows = $pdo->query("PRAGMA table_info(\"{$table}\")")->fetchAll(PDO::FETCH_ASSOC);
    return array_values(array_map(static fn(array $row): string => (string) $row['name'], $rows));
}

function mysqlColumns(PDO $pdo, string $table): array {
    assertIdentifier($table);
    $stmt = $pdo->prepare(
        'SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table ORDER BY ordinal_position'
    );
    $stmt->execute([':table' => $table]);
    return array_values(array_map(static fn(array $row): string => (string) $row['column_name'], $stmt->fetchAll()));
}

function assertIdentifier(string $identifier): void {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
        throw new InvalidArgumentException("无效的数据库标识符：{$identifier}");
    }
}

function resolvePath(string $path, string $baseDir): string {
    if (preg_match('/^[A-Za-z]:[\\\\\/]/', $path) || str_starts_with($path, '/') || str_starts_with($path, '\\\\')) {
        return $path;
    }
    return rtrim($baseDir, '/\\\\') . DIRECTORY_SEPARATOR . $path;
}

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
