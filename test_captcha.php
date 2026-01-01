<?php
/**
 * 验证码功能测试脚本
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>验证码测试</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .success { color: green; }
        .error { color: red; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; }
        img { border: 2px solid #333; margin: 10px 0; }
    </style>
</head>
<body>
    <h1>验证码功能测试</h1>

    <div class="section">
        <h2>1. PHP GD库检测</h2>
        <?php
        if (extension_loaded('gd')) {
            echo '<p class="success">✓ GD库已安装</p>';
            $gdInfo = gd_info();
            echo '<ul>';
            foreach ($gdInfo as $key => $value) {
                echo '<li>' . htmlspecialchars($key) . ': ' . htmlspecialchars($value) . '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p class="error">✗ GD库未安装</p>';
            echo '<p>请在 php.ini 中启用 extension=gd</p>';
        }
        ?>
    </div>

    <div class="section">
        <h2>2. Session功能检测</h2>
        <?php
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            echo '<p class="success">✓ Session功能正常</p>';
            echo '<p>Session ID: ' . session_id() . '</p>';
        } else {
            echo '<p class="error">✗ Session功能异常</p>';
        }
        ?>
    </div>

    <div class="section">
        <h2>3. 验证码类加载测试</h2>
        <?php
        try {
            require_once __DIR__ . '/captcha_utils.php';
            echo '<p class="success">✓ CaptchaUtils类加载成功</p>';

            $captcha = getCaptcha();
            echo '<p class="success">✓ CaptchaUtils实例创建成功</p>';

            // 测试验证码生成
            $code = $captcha->generate(4);
            echo '<p class="success">✓ 验证码生成成功: ' . htmlspecialchars($code) . '</p>';
            echo '<p>验证码已保存到Session中</p>';

        } catch (Exception $e) {
            echo '<p class="error">✗ 错误: ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        }
        ?>
    </div>

    <div class="section">
        <h2>4. 验证码图片显示测试</h2>
        <?php if (extension_loaded('gd')): ?>
            <p>验证码图片：</p>
            <img src="captcha.php?t=<?php echo time(); ?>" alt="验证码">
            <br>
            <button onclick="document.querySelector('img').src='captcha.php?t='+new Date().getTime()">刷新验证码</button>
        <?php else: ?>
            <p class="error">无法显示验证码图片（GD库未安装）</p>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>5. 配置检查</h2>
        <?php
        try {
            $config = require __DIR__ . '/config.php';
            $captchaConfig = $config['captcha'] ?? null;

            if ($captchaConfig) {
                echo '<p class="success">✓ 验证码配置已加载</p>';
                echo '<pre>' . print_r($captchaConfig, true) . '</pre>';
            } else {
                echo '<p class="error">✗ 验证码配置未找到</p>';
            }
        } catch (Exception $e) {
            echo '<p class="error">✗ 配置加载错误: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        ?>
    </div>

    <div class="section">
        <h2>6. PHP信息</h2>
        <p>PHP版本: <?php echo PHP_VERSION; ?></p>
        <p>Server API: <?php echo PHP_SAPI; ?></p>
        <p><a href="?phpinfo=1">查看完整PHP信息</a></p>
        <?php
        if (isset($_GET['phpinfo'])) {
            phpinfo();
        }
        ?>
    </div>

</body>
</html>
