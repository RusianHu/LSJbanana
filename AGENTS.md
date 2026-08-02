# 项目要点

- 项目名称：老司机的香蕉 / LSJbanana，PHP 图片生成与编辑站点。
- 技术基准：Gemini 原生图片接口、OpenAI 兼容接口与 OpenAI Images 接口；具体以适配器和部署配置为准。
- 图片模型：由 `active_image_model` 和 `image_api_provider` 动态选择，当前支持 Gemini 图片模型与 `gpt-image-2` 等 OpenAI Images 模型。
- 配置：使用 `config.php` [文件](/config.php) 存储敏感信息与开关，并维护 `config.php.example` [文件](/config.php.example) 作为示例。
- API Key、数据库密码、支付密钥及管理员密钥只允许保存在被 Git 忽略的 `config.php` 或服务器环境变量中，不得写入版本库。
- 约束：除非必要不新增子目录；尽量避免在代码与文档中使用表情符号。
- 代码要求：优先并发/异步与稳健错误处理；移动端响应式。
- 实现 i18n 的相关规范与功能
- 不要使用任何 重定向/302 方法，可以使用引导页等方法跳转。
- 项目部署于 "https://yanshanlaosiji.top/LSJbanana"，这是 nginx 二级目录，根目录下还有其他项目不能被破坏；VPS 项目已建立 Git 并关联相同的 GitHub，可通过 `C:\Users\admin\Desktop\VPS\yanshanlaosiji_ssh_root.ps1` 维护。
- 在维护和更新 `README.md` 时，同时维护 `README.EN.md` 以保持同步。
- 默认思考与交流语言：简体中文。

## VPS Git 更新

项目根目录的 `git-sync.sh` 固定适配本项目，默认更新 `/www/wwwroot/yanshanlaosiji.top/LSJbanana` 的 `origin/main`：

```bash
cd /www/wwwroot/yanshanlaosiji.top/LSJbanana
bash git-sync.sh
```

脚本会先将生产 `config.php` 备份到 `/www/backup/LSJbanana/`，把未提交改动保存到 Git stash，再通过 `git merge --ff-only` 更新并运行 PHP/HTTP 检查。禁止改回 `git reset --hard`；同步产生的 stash 必须在人工确认后再删除。

# API 提供商配置

文本/提示词接口通过 `config.php` 中 `api_provider` 切换，图片生成与编辑可通过 `image_api_provider` 独立切换：

| 提供商 | 配置值 | 说明 |
| --- | --- | --- |
| Gemini 原生 | `native` | 直连 Google API，使用 `api_key` |
| OpenAI 兼容中转站 | `openai_compatible` | 国内可用，使用 `openai_compatible` 配置块 |
| Gemini 原生格式代理 | `gemini_proxy` | 使用 Gemini 请求格式和 SSE 响应的代理 |
| OpenAI Images | `openai_images` | 仅用于图片生成/编辑，调用 `/v1/images/generations` 与 `/v1/images/edits` |

**中转站配置**：
- 地址：`https://cli.yanshanlaosiji.top`
- Key：仅存放于部署环境的 `config.php`
- 接口规范：OpenAI `/v1/chat/completions`
- 特性：支持 `reasoning_content` 思考内容、`images` 字段返回图片

**模型名称示例**：
| 功能 | 模型 |
| --- | --- |
| 图片生成/编辑 | `gemini-3.1-flash-image`、`gemini-2.5-flash-image`、`gpt-image-2` |
| 提示词优化 | 由 `prompt_optimize_model` 配置 |

**适配器文件**：
- `openai_adapter.php`：OpenAI Chat Completions 兼容渠道。
- `openai_images_adapter.php`：OpenAI Images 图片生成与编辑渠道。
- `gemini_proxy_adapter.php`：Gemini 原生格式代理渠道。

# 公告系统配置

- 支持三种展示模式：Banner (顶部横幅), Modal (弹窗), Inline (内嵌卡片)
- 支持四种消息类型：Info (信息), Warning (警告), Success (成功), Important (重要)
- 管理后台可创建、编辑、删除、启用/禁用公告
- 前端自动根据配置展示公告，支持用户关闭（登录用户持久化，访客本地存储）
- 配置文件 `config.php` 中的 `$announcementConfig` 可控制系统开关及参数

# 用户与支付配置

- 接口文档参考 [老司机聚合支付V1.md](/老司机聚合支付V1.md) ，其中的支付url地址等信息真实可用。
- 演示商户号与支付密钥只允许保存在 `config.php`，不得提交到 Git。
- 生产环境使用 MySQL/MariaDB 持久化；SQLite 数据库 [lsjbanana.db](/database/lsjbanana.db) 仅作为兼容回退和迁移源。
- 启用SVG验证码功能，可在配置文件中配置开关。
- 提供管理员控制面板，使用专用的管理员密钥。

# 调试用的接口

调试环境下可通过管理员密钥的哈希值进行一些列快速调试操作。

## 管理员快速登录

**配置方法** (`config.php` 中 `$adminConfig['debug_quick_login']`)：

```php
'debug_quick_login' => [
    'enabled' => true,                              // 启用开关（生产环境必须为 false）
    'ip_whitelist' => ['127.0.0.1', '::1'],        // IP 白名单（空数组表示不限制）
    'expires_seconds' => 300,                       // 链接有效期（秒）
],
```

**生成管理员快速登录 URL**：

```bash
php generate_quick_login.php admin http://127.0.0.1:8080
# 或简写（默认为 admin）：
php generate_quick_login.php http://127.0.0.1:8080
```

## 用户快速登录

用于快速登录固定的测试用户，首次访问时自动创建。

**配置方法** (`config.php` 中 `$userConfig['debug_quick_login']`)：

```php
'debug_quick_login' => [
    'enabled' => true,                              // 启用开关（生产环境必须为 false）
    'ip_whitelist' => ['127.0.0.1', '::1'],        // IP 白名单（空数组表示不限制）
    'expires_seconds' => 300,                       // 链接有效期（秒）
    'test_user' => [
        'username' => 'test_debug_user',            // 测试用户名
        'email' => 'test_debug@example.com',        // 测试邮箱
        'initial_balance' => 100.00,                // 初始余额 (RMB)
    ],
],
```

**生成用户快速登录 URL**：

```bash
php generate_quick_login.php user http://127.0.0.1:8080
```

**说明**：
- 用户快速登录复用管理员密钥哈希 (`admin.key_hash`) 作为签名密钥
- 测试用户首次访问时自动创建，已存在则直接登录
- 生产环境务必将 `enabled` 设为 `false`

# 调试诊断接口

提供系统配置、状态、用户信息等诊断功能的 API 接口，用于开发调试和问题排查。

## 配置方法

在 `config.php` 中 `$adminConfig` 配置块内添加 `debug_diagnostic` 配置（与 `debug_quick_login` 同级）：

```php
$adminConfig = [
    'enabled' => true,
    'key_hash' => '...',
    // ... 其他配置 ...

    'debug_quick_login' => [
        // ...
    ],

    // 调试诊断接口配置
    'debug_diagnostic' => [
        'enabled' => false,                          // 是否启用（生产环境必须为 false）
        'ip_whitelist' => ['127.0.0.1', '::1'],     // IP 白名单（空数组表示不限制）
        'log_requests' => true,                      // 是否记录请求日志
        'expires_seconds' => 300,                    // 签名链接有效期（秒）
    ],
];
```

## 认证方式

复用管理员密钥哈希（`$adminConfig['key_hash']`）进行验证，支持两种方式：

| 方式 | 参数 | 说明 |
| --- | --- | --- |
| 签名认证（推荐） | `?t=时间戳&sig=签名` | 原始密钥不在网络传输，更安全 |
| 原始密钥（兼容） | `?debug_key=密钥` 或 Header `X-Debug-Key` | 原始密钥在请求中传输 |

**签名算法**：`hash_hmac('sha256', 'diagnostic:' . timestamp, key_hash)`

## 生成诊断接口访问 URL

使用命令行工具生成带签名的访问URL（推荐方式）：

```bash
php generate_quick_login.php diagnostic http://127.0.0.1:8080
```

输出示例：
```
基础URL（添加 action 参数使用）:
  http://127.0.0.1:8080/debug_diagnostic.php?t=1736355600&sig=abc123...

常用查询示例:
  状态检查: http://127.0.0.1:8080/debug_diagnostic.php?t=1736355600&sig=abc123...&action=status
  配置信息: http://127.0.0.1:8080/debug_diagnostic.php?t=1736355600&sig=abc123...&action=config
```

## 支持的查询类型

| action | 描述 | 额外参数 |
| --- | --- | --- |
| `config` | 查看脱敏后的配置信息 | - |
| `status` | 系统状态检查（PHP版本、扩展、目录权限、数据库连接） | - |
| `user` | 用户信息查询 | `user_id` 或 `username` |
| `stats` | 系统统计数据（用户数、充值、消费等） | - |
| `db_health` | 数据库健康检查（核心表和管理员表完整性） | - |
| `env` | 环境诊断（PHP配置、扩展、磁盘空间、内存使用） | - |

## 使用示例

**推荐方式（使用签名URL）**：

```bash
# 先生成签名URL
php generate_quick_login.php diagnostic http://127.0.0.1:8080

# 然后使用生成的URL添加action参数访问
curl "http://127.0.0.1:8080/debug_diagnostic.php?t=时间戳&sig=签名&action=status"
```

**兼容方式（使用原始密钥）**：

```bash
# 使用参数认证
curl "http://127.0.0.1:8080/debug_diagnostic.php?action=status&debug_key=你的密钥"

# 使用 Header 认证
curl -H "X-Debug-Key: 你的密钥" "http://127.0.0.1:8080/debug_diagnostic.php?action=config"

# 查询用户信息
curl "http://127.0.0.1:8080/debug_diagnostic.php?action=user&user_id=1&debug_key=你的密钥"
```

## 响应格式

```json
{
    "success": true,
    "action": "status",
    "timestamp": "2026-01-08T18:15:00+08:00",
    "data": {
        "php_version": "8.2.10",
        "extensions": { "curl": true, "openssl": true, ... },
        "directories": { "uploads": { "exists": true, "writable": true }, ... },
        "database": { "connected": true, "type": "mysql" },
        "api_provider": "openai_compatible",
        "image_api_provider": "openai_images"
    }
}
```

## 安全说明

- 敏感信息自动脱敏：API Key 只显示前4位和后4位，密钥哈希只显示前8位，邮箱显示为 `a***@example.com` 格式
- 请求日志记录到 `logs/debug_diagnostic.log`（可通过配置关闭）
- 生产环境务必将 `enabled` 设为 `false`

# 本地调试

## Windows 便携式 MariaDB

项目根目录的 `portable_mariadb.ps1` 用于安装和管理本机调试数据库，默认只监听 `127.0.0.1:3307`，运行文件与下载缓存均位于被 Git 忽略的 `database/` 子目录。

```powershell
# 首次使用：下载、校验、初始化并启动
.\portable_mariadb.ps1 install

# 日常启动、状态检查和停止
.\portable_mariadb.ps1 start
.\portable_mariadb.ps1 status
.\portable_mariadb.ps1 stop
```

如需覆盖调试端口、库名或账号，使用参数显式传入；不要把生产密码写入脚本：

```powershell
.\portable_mariadb.ps1 install `
  -Port 3307 `
  -Database lsjbanana `
  -AppUser lsjbanana `
  -AppPassword '仅限本机调试的密码' `
  -RootPassword '仅限本机调试的 root 密码'
```

从 SQLite 迁移已有数据时，先在 `config.php` 配置 MySQL/MariaDB 目标库，再执行：

```powershell
.\php\php.exe .\migrate_sqlite_to_mysql.php
```

迁移器默认拒绝写入非空目标库；只有确认要清空本项目管理的目标表并重新迁移时才使用 `--force`。生产 VPS 使用独立 MariaDB 服务，不使用 Windows 便携运行时。

Windows PHP 本地环境：
1. 下载 PHP NTS x64 解压到项目目录。
2. 复制 `php.ini-development` 为 `php.ini`。
3. 启用下方扩展。
4. 下载 `https://curl.se/ca/cacert.pem` 到 PHP 目录。
5. 设置 `php.ini`：`curl.cainfo` 与 `openssl.cafile` 指向 `cacert.pem` 绝对路径。
6. 启动：`.\php\php.exe -S 127.0.0.1:端口`。
- 本项目下有便携式 php 环境 [PHP](/php/php.exe) ，可用于本地调试等。
- 使用 playwright 集成工具进行测试结束时，不要直接关闭你调试用的浏览器页面。

必需扩展（随时更新）：

| 扩展名 | 用途 | 使用位置 |
| --- | --- | --- |
| curl | HTTP 请求 | api.php |
| openssl | 安全连接与令牌生成 | security_utils.php |
| mbstring | UTF-8 处理 | security_utils.php |
| fileinfo | MIME 检测 | api.php, security_utils.php |
| pdo_mysql | MySQL/MariaDB 数据持久化 | db.php, migrate_sqlite_to_mysql.php |
| pdo_sqlite | SQLite 兼容回退与迁移源 | db.php, migrate_sqlite_to_mysql.php |
