-- LSJbanana 用户系统数据库结构
-- 数据库类型: MySQL 5.5+ / MariaDB 10.6+（生产推荐 MySQL 8.0+）
-- 编码: utf8mb4

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    balance DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    status TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME NULL DEFAULT NULL,
    updated_at DATETIME NULL DEFAULT NULL,
    last_login_at DATETIME NULL,
    last_login_ip VARCHAR(45) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recharge_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    out_trade_no VARCHAR(64) NOT NULL,
    trade_no VARCHAR(64) NULL,
    amount DECIMAL(10, 2) NOT NULL,
    pay_type VARCHAR(20) NULL,
    status TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NULL DEFAULT NULL,
    expires_at DATETIME NULL,
    paid_at DATETIME NULL,
    notify_data LONGTEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recharge_out_trade_no (out_trade_no),
    KEY idx_recharge_trade_no (trade_no),
    KEY idx_recharge_user_id (user_id),
    KEY idx_recharge_status (status),
    KEY idx_recharge_expires_at (expires_at),
    CONSTRAINT fk_recharge_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balance_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(20) NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    balance_before DECIMAL(10, 2) NOT NULL,
    balance_after DECIMAL(10, 2) NOT NULL,
    remark TEXT NULL,
    visible_to_user TINYINT NOT NULL DEFAULT 0,
    user_remark TEXT NULL,
    source_type VARCHAR(30) DEFAULT 'manual_recharge',
    source_id BIGINT UNSIGNED NULL,
    created_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_balance_logs_user_id (user_id),
    KEY idx_balance_logs_created_at (created_at),
    KEY idx_balance_logs_visible (visible_to_user),
    CONSTRAINT fk_balance_logs_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consumption_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(20) NOT NULL,
    amount DECIMAL(10, 4) NOT NULL,
    balance_before DECIMAL(10, 2) NOT NULL,
    balance_after DECIMAL(10, 2) NOT NULL,
    image_count INT NOT NULL DEFAULT 1,
    model_name VARCHAR(100) NULL,
    created_at DATETIME NULL DEFAULT NULL,
    remark TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_consumption_user_id (user_id),
    KEY idx_consumption_created_at (created_at),
    KEY idx_consumption_action (action),
    CONSTRAINT fk_consumption_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS generation_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(64) NOT NULL,
    action VARCHAR(20) NOT NULL,
    provider VARCHAR(32) NOT NULL,
    model_name VARCHAR(100) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    request_json LONGTEXT NOT NULL,
    result_json LONGTEXT NULL,
    billing_amount DECIMAL(10, 4) NOT NULL,
    balance_before DECIMAL(10, 2) NOT NULL,
    balance_after DECIMAL(10, 2) NOT NULL,
    billing_state VARCHAR(20) NOT NULL DEFAULT 'deducted',
    attempt_count INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 3,
    next_attempt_at DATETIME NULL,
    worker_id VARCHAR(100) NULL,
    locked_at DATETIME NULL,
    heartbeat_at DATETIME NULL,
    error_code VARCHAR(64) NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_generation_jobs_public_id (public_id),
    UNIQUE KEY uq_generation_jobs_user_idempotency (user_id, idempotency_key),
    KEY idx_generation_jobs_dispatch (status, next_attempt_at, id),
    KEY idx_generation_jobs_user_created (user_id, created_at),
    CONSTRAINT fk_generation_jobs_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS generation_job_inputs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    position INT NOT NULL,
    mime_type VARCHAR(64) NOT NULL,
    image_data MEDIUMBLOB NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_generation_job_inputs_position (job_id, position),
    CONSTRAINT fk_generation_job_inputs_job FOREIGN KEY (job_id) REFERENCES generation_jobs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    login_type VARCHAR(20) DEFAULT 'password',
    status TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_login_user_id (user_id),
    KEY idx_login_created_at (created_at),
    CONSTRAINT fk_login_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    absolute_expires_at DATETIME NULL,
    created_at DATETIME NULL DEFAULT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_sessions_token (token_hash),
    KEY idx_session_user_id (user_id),
    KEY idx_session_expires (expires_at),
    CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_token VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    created_at DATETIME NULL DEFAULT NULL,
    expires_at DATETIME NOT NULL,
    absolute_expires_at DATETIME NULL,
    last_activity DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_sessions_token (session_token),
    KEY idx_admin_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_address VARCHAR(45) NOT NULL,
    attempt_time DATETIME NULL DEFAULT NULL,
    success TINYINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_admin_attempts_ip (ip_address),
    KEY idx_admin_attempts_time (attempt_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_operation_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    operation_type VARCHAR(50) NOT NULL,
    target_user_id BIGINT UNSIGNED NULL,
    details LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_admin_ops_type (operation_type),
    KEY idx_admin_ops_target (target_user_id),
    KEY idx_admin_ops_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    email VARCHAR(100) NOT NULL,
    created_at DATETIME NULL DEFAULT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_reset_token (token_hash),
    KEY idx_reset_user (user_id),
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    content LONGTEXT NOT NULL,
    type VARCHAR(20) DEFAULT 'info',
    display_mode VARCHAR(20) DEFAULT 'banner',
    target VARCHAR(20) DEFAULT 'all',
    priority INT NOT NULL DEFAULT 0,
    is_dismissible TINYINT NOT NULL DEFAULT 1,
    is_active TINYINT NOT NULL DEFAULT 1,
    start_at DATETIME NULL,
    end_at DATETIME NULL,
    created_at DATETIME NULL DEFAULT NULL,
    updated_at DATETIME NULL DEFAULT NULL,
    created_by VARCHAR(50) DEFAULT 'admin',
    PRIMARY KEY (id),
    KEY idx_announcements_active (is_active),
    KEY idx_announcements_priority (priority),
    KEY idx_announcements_dates (start_at, end_at),
    KEY idx_announcements_type (type),
    KEY idx_announcements_display_mode (display_mode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcement_dismissals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    announcement_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    dismissed_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_announcement_dismissal (announcement_id, user_id),
    KEY idx_dismissals_user (user_id),
    KEY idx_dismissals_announcement (announcement_id),
    CONSTRAINT fk_dismissals_announcement FOREIGN KEY (announcement_id) REFERENCES announcements (id) ON DELETE CASCADE,
    CONSTRAINT fk_dismissals_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
