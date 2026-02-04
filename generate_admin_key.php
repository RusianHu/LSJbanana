<?php
/**
 * 生成管理员密钥的 SHA-256 哈希值
 * 使用方法: 在浏览器访问 http://127.0.0.1:8080/generate_admin_key.php
 */

require_once __DIR__ . '/i18n/I18n.php';

// 安全检查:仅在本地环境运行
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost'])) {
    die(__('error.permission_denied'));
}

$key = '';
$hash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = $_POST['key'] ?? '';
    if ($key) {
        $hash = hash('sha256', $key);
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo 'Generate Admin Key Hash'; // 简单标题，不依赖复杂翻译 ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            padding: 40px;
            max-width: 600px;
            width: 100%;
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
            text-align: center;
        }
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .alert-info {
            background: #e3f2fd;
            color: #1565c0;
            border-left: 4px solid #1976d2;
        }
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        input[type="text"],
        input[type="password"],
        textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.95rem;
            font-family: monospace;
        }
        input:focus,
        textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        textarea {
            resize: vertical;
            min-height: 80px;
        }
        button {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .copy-btn {
            margin-top: 10px;
            background: #28a745;
        }
        .copy-btn:hover {
            background: #218838;
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
        .steps {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 6px;
            margin-top: 20px;
        }
        .steps ol {
            margin-left: 20px;
        }
        .steps li {
            margin-bottom: 10px;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 <?php echo '生成管理员密钥哈希'; ?></h1>

        <div class="alert alert-warning">
            <strong>⚠️ <?php echo '安全提醒:'; ?></strong><br>
            1. <?php echo '请设置一个强密码作为管理员密钥'; ?><br>
            2. <?php echo '生成哈希后,请立即复制并保存到 config.php 中'; ?><br>
            3. <?php echo '完成配置后,请删除此文件(generate_admin_key.php)'; ?>
        </div>

        <form method="POST">
            <div class="form-group">
                <label for="key"><?php echo '输入管理员密钥 (建议12位以上)'; ?></label>
                <input
                    type="password"
                    id="key"
                    name="key"
                    placeholder="<?php echo '请输入一个安全的密钥...'; ?>"
                    required
                    value="<?php echo htmlspecialchars($key); ?>"
                >
            </div>
            <button type="submit"><?php echo '生成 SHA-256 哈希值'; ?></button>
        </form>

        <?php if ($hash): ?>
            <div class="alert alert-success" style="margin-top: 20px;">
                <strong>✓ <?php echo '哈希值生成成功!'; ?></strong>
            </div>

            <div class="form-group">
                <label for="hash">SHA-256 哈希值</label>
                <textarea id="hash" readonly onclick="this.select()"><?php echo $hash; ?></textarea>
                <button class="copy-btn" onclick="copyHash()">📋 <?php echo '复制哈希值'; ?></button>
            </div>

            <div class="steps">
                <strong>📝 <?php echo '下一步操作:'; ?></strong>
                <ol>
                    <li><?php echo '复制上面的哈希值'; ?></li>
                    <li><?php echo '打开'; ?> <code>config.php</code> <?php echo '文件'; ?></li>
                    <li><?php echo '找到'; ?> <code>$adminConfig['key_hash']</code> <?php echo '配置项'; ?></li>
                    <li><?php echo '将哈希值粘贴进去,例如:'; ?><br>
                        <code>'key_hash' => '<?php echo substr($hash, 0, 20); ?>...'</code>
                    </li>
                    <li><?php echo '保存 config.php 文件'; ?></li>
                    <li><?php echo '运行'; ?> <code>setup_admin.php</code> <?php echo '创建管理员表'; ?></li>
                    <li><?php echo '访问'; ?> <code>/admin/login.php</code> <?php echo '使用原始密钥登录'; ?></li>
                    <li><strong style="color: #dc3545;"><?php echo '删除此文件 generate_admin_key.php'; ?></strong></li>
                </ol>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function copyHash() {
            const hashField = document.getElementById('hash');
            hashField.select();
            document.execCommand('copy');

            const btn = event.target;
            const originalText = btn.textContent;
            btn.textContent = '✓ 已复制!';
            btn.style.background = '#218838';

            setTimeout(() => {
                btn.textContent = originalText;
                btn.style.background = '#28a745';
            }, 2000);
        }
    </script>
</body>
</html>
