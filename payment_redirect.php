<?php
require_once __DIR__ . '/auth.php';

$auth = getAuth();
$auth->requireLogin(true);
$auth->startSession();

$paymentUrl = $_SESSION['payment_redirect_url'] ?? '';
unset($_SESSION['payment_redirect_url']);

if ($paymentUrl === '') {
    header('Location: recharge.php');
    exit;
}

if (!preg_match('#^https?://#i', $paymentUrl)) {
    header('Location: recharge.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>正在跳转到支付页面</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="auth-container">
        <div class="auth-box">
            <div class="auth-header">
                <h1>正在跳转到支付页面</h1>
                <p>如果没有自动跳转，请点击下方按钮继续</p>
            </div>
            <div style="text-align: center; margin-top: 20px;">
                <a class="btn-primary" href="<?php echo htmlspecialchars($paymentUrl, ENT_QUOTES, 'UTF-8'); ?>">继续前往支付</a>
            </div>
        </div>
    </div>
    <script>
        window.location.replace(<?php echo json_encode($paymentUrl, JSON_UNESCAPED_SLASHES); ?>);
    </script>
    <noscript>
        <p style="text-align:center; margin-top: 15px;">
            浏览器未启用脚本，请点击上方链接继续。
        </p>
    </noscript>
</body>
</html>
