-- LSJbanana 用户系统数据库结构
-- 数据库类型: SQLite3
-- 编码: UTF-8

-- 用户表
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    balance DECIMAL(10, 2) DEFAULT 0.00,
    status INTEGER DEFAULT 1,  -- 1:正常, 0:禁用
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME,
    last_login_ip VARCHAR(45)
);

-- 用户名索引
CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);
-- 邮箱索引
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
-- 状态索引
CREATE INDEX IF NOT EXISTS idx_users_status ON users(status);

-- 充值订单表
CREATE TABLE IF NOT EXISTS recharge_orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    out_trade_no VARCHAR(64) NOT NULL UNIQUE,  -- 商户订单号
    trade_no VARCHAR(64),                       -- 支付平台订单号
    amount DECIMAL(10, 2) NOT NULL,             -- 充值金额
    pay_type VARCHAR(20),                       -- 支付方式: alipay/wxpay/qqpay
    status INTEGER DEFAULT 0,                   -- 0:待支付, 1:已支付, 2:已取消, 3:已退款
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME,                        -- 订单过期时间
    paid_at DATETIME,
    notify_data TEXT,                           -- 支付回调原始数据 (JSON)
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- 订单号索引
CREATE INDEX IF NOT EXISTS idx_recharge_out_trade_no ON recharge_orders(out_trade_no);
CREATE INDEX IF NOT EXISTS idx_recharge_trade_no ON recharge_orders(trade_no);
-- 用户订单索引
CREATE INDEX IF NOT EXISTS idx_recharge_user_id ON recharge_orders(user_id);
-- 状态索引
CREATE INDEX IF NOT EXISTS idx_recharge_status ON recharge_orders(status);
-- 过期时间索引
CREATE INDEX IF NOT EXISTS idx_recharge_expires_at ON recharge_orders(expires_at);

-- 余额变动日志表 (管理员手动充值/扣款)
CREATE TABLE IF NOT EXISTS balance_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    type VARCHAR(20) NOT NULL,                   -- recharge/deduct
    amount DECIMAL(10, 2) NOT NULL,
    balance_before DECIMAL(10, 2) NOT NULL,
    balance_after DECIMAL(10, 2) NOT NULL,
    remark TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_balance_logs_user_id ON balance_logs(user_id);
CREATE INDEX IF NOT EXISTS idx_balance_logs_created_at ON balance_logs(created_at);

-- 消费记录表 (图片生成扣费)
CREATE TABLE IF NOT EXISTS consumption_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    action VARCHAR(20) NOT NULL,                -- generate:生成, edit:编辑
    amount DECIMAL(10, 4) NOT NULL,             -- 扣费金额
    balance_before DECIMAL(10, 2) NOT NULL,     -- 扣费前余额
    balance_after DECIMAL(10, 2) NOT NULL,      -- 扣费后余额
    image_count INTEGER DEFAULT 1,              -- 生成图片数量
    model_name VARCHAR(100),                    -- 使用的模型
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    remark TEXT,                                -- 备注 (提示词等)
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- 用户消费索引
CREATE INDEX IF NOT EXISTS idx_consumption_user_id ON consumption_logs(user_id);
-- 时间索引
CREATE INDEX IF NOT EXISTS idx_consumption_created_at ON consumption_logs(created_at);
-- 操作类型索引
CREATE INDEX IF NOT EXISTS idx_consumption_action ON consumption_logs(action);

-- 登录日志表 (可选，用于安全审计)
CREATE TABLE IF NOT EXISTS login_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    login_type VARCHAR(20) DEFAULT 'password',  -- password/token
    status INTEGER DEFAULT 1,                    -- 1:成功, 0:失败
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- 用户登录索引
CREATE INDEX IF NOT EXISTS idx_login_user_id ON login_logs(user_id);
-- 时间索引
CREATE INDEX IF NOT EXISTS idx_login_created_at ON login_logs(created_at);

-- 用户会话表 (用于 Remember Me 功能)
CREATE TABLE IF NOT EXISTS user_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    user_agent TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- 会话token索引
CREATE INDEX IF NOT EXISTS idx_session_token ON user_sessions(token_hash);
-- 用户会话索引
CREATE INDEX IF NOT EXISTS idx_session_user_id ON user_sessions(user_id);
-- 过期时间索引
CREATE INDEX IF NOT EXISTS idx_session_expires ON user_sessions(expires_at);

-- ============================================================
-- 管理员系统表
-- ============================================================

-- 管理员会话表
CREATE TABLE IF NOT EXISTS admin_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_token VARCHAR(255) NOT NULL UNIQUE,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    last_activity DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 管理员会话索引
CREATE INDEX IF NOT EXISTS idx_admin_session_token ON admin_sessions(session_token);
CREATE INDEX IF NOT EXISTS idx_admin_expires ON admin_sessions(expires_at);

-- 管理员登录尝试记录表 (防暴力破解)
CREATE TABLE IF NOT EXISTS admin_login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_address VARCHAR(45) NOT NULL,
    attempt_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    success INTEGER DEFAULT 0  -- 0:失败, 1:成功
);

-- 登录尝试索引
CREATE INDEX IF NOT EXISTS idx_admin_attempts_ip ON admin_login_attempts(ip_address);
CREATE INDEX IF NOT EXISTS idx_admin_attempts_time ON admin_login_attempts(attempt_time);

-- 管理员操作日志表
CREATE TABLE IF NOT EXISTS admin_operation_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    operation_type VARCHAR(50) NOT NULL,  -- user_edit, balance_add, balance_deduct, user_disable, user_enable, password_reset
    target_user_id INTEGER,
    details TEXT,  -- JSON格式存储详细信息
    ip_address VARCHAR(45),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 操作日志索引
CREATE INDEX IF NOT EXISTS idx_admin_ops_type ON admin_operation_logs(operation_type);
CREATE INDEX IF NOT EXISTS idx_admin_ops_target ON admin_operation_logs(target_user_id);
CREATE INDEX IF NOT EXISTS idx_admin_ops_time ON admin_operation_logs(created_at);

-- 密码重置令牌表
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    used INTEGER DEFAULT 0,  -- 0:未使用, 1:已使用
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- 重置令牌索引
CREATE INDEX IF NOT EXISTS idx_reset_token ON password_reset_tokens(token_hash);
CREATE INDEX IF NOT EXISTS idx_reset_user ON password_reset_tokens(user_id);
