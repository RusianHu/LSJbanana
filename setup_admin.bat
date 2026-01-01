@echo off
echo ========================================
echo 管理员系统数据库表初始化
echo ========================================
echo.

cd /d "%~dp0"

php -r "require 'db.php'; $db = Database::getInstance(); $pdo = $db->getPDO(); echo '开始创建管理员系统表...\n\n'; $pdo->exec('CREATE TABLE IF NOT EXISTS admin_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, session_token VARCHAR(255) NOT NULL UNIQUE, ip_address VARCHAR(45) NOT NULL, user_agent TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, expires_at DATETIME NOT NULL, last_activity DATETIME DEFAULT CURRENT_TIMESTAMP)'); echo '✓ admin_sessions 表创建成功\n'; $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admin_session_token ON admin_sessions(session_token)'); $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admin_expires ON admin_sessions(expires_at)'); $pdo->exec('CREATE TABLE IF NOT EXISTS admin_login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip_address VARCHAR(45) NOT NULL, attempt_time DATETIME DEFAULT CURRENT_TIMESTAMP, success INTEGER DEFAULT 0)'); echo '✓ admin_login_attempts 表创建成功\n'; $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admin_attempts_ip ON admin_login_attempts(ip_address)'); $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admin_attempts_time ON admin_login_attempts(attempt_time)'); $pdo->exec('CREATE TABLE IF NOT EXISTS admin_operation_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, operation_type VARCHAR(50) NOT NULL, target_user_id INTEGER, details TEXT, ip_address VARCHAR(45), created_at DATETIME DEFAULT CURRENT_TIMESTAMP)'); echo '✓ admin_operation_logs 表创建成功\n'; $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admin_ops_type ON admin_operation_logs(operation_type)'); $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admin_ops_target ON admin_operation_logs(target_user_id)'); $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admin_ops_time ON admin_operation_logs(created_at)'); $pdo->exec('CREATE TABLE IF NOT EXISTS password_reset_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash VARCHAR(255) NOT NULL UNIQUE, email VARCHAR(100) NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, expires_at DATETIME NOT NULL, used INTEGER DEFAULT 0, FOREIGN KEY (user_id) REFERENCES users(id))'); echo '✓ password_reset_tokens 表创建成功\n'; $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reset_token ON password_reset_tokens(token_hash)'); $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reset_user ON password_reset_tokens(user_id)'); echo '\n所有管理员系统表创建完成!\n\n'; echo '请访问 http://127.0.0.1:8080/admin/login.php 进行登录\n';"

if %ERRORLEVEL% EQU 0 (
    echo.
    echo ========================================
    echo 初始化成功！
    echo ========================================
) else (
    echo.
    echo ========================================
    echo 初始化失败，请检查错误信息
    echo ========================================
)

echo.
pause
