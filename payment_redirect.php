<?php
require_once __DIR__ . '/auth.php';

$auth = getAuth();
$auth->requireLogin(true);
$auth->startSession();

$paymentUrl = $_SESSION['payment_redirect_url'] ?? '';
unset($_SESSION['payment_redirect_url']);

if ($paymentUrl === '') {
    renderActionPage(
        '支付地址缺失',
        '未获取到支付地址，请返回充值页面重新提交。',
        [
            [
                'label' => '返回充值',
                'href' => url('recharge.php'),
                'primary' => true
            ],
            [
                'label' => '返回首页',
                'href' => url('index.php')
            ]
        ],
        400
    );
}

if (!preg_match('#^https?://#i', $paymentUrl)) {
    renderActionPage(
        '支付地址异常',
        '支付地址格式不正确，请返回充值页面重试。',
        [
            [
                'label' => '返回充值',
                'href' => url('recharge.php'),
                'primary' => true
            ],
            [
                'label' => '返回首页',
                'href' => url('index.php')
            ]
        ],
        400
    );
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>前往支付页面</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="auth-container">
        <div class="auth-box">
            <div class="auth-header">
                <h1>前往支付页面</h1>
                <p>请点击下方按钮继续完成支付</p>
            </div>
            <div style="text-align: center; margin-top: 20px;">
                <a class="btn-primary" href="<?php echo htmlspecialchars($paymentUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">继续前往支付</a>
            </div>
        </div>
    </div>
    <noscript>
        <p style="text-align:center; margin-top: 15px;">
            浏览器未启用脚本，请点击上方链接继续。
        </p>
    </noscript>
</body>
</html>
