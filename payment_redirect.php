<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/i18n/I18n.php';

$auth = getAuth();
$auth->requireLogin(true);
$auth->startSession();

$paymentUrl = $_SESSION['payment_redirect_url'] ?? '';
unset($_SESSION['payment_redirect_url']);

if ($paymentUrl === '') {
    renderActionPage(
        __('recharge.error_url_missing'),
        __('recharge.error_url_missing_desc'),
        [
            [
                'label' => __('recharge.return_recharge'),
                'href' => url('recharge.php'),
                'primary' => true
            ],
            [
                'label' => __('nav.back_home'),
                'href' => url('index.php')
            ]
        ],
        400
    );
}

if (!preg_match('#^https?://#i', $paymentUrl)) {
    renderActionPage(
        __('recharge.error_url_invalid'),
        __('recharge.error_url_invalid_desc'),
        [
            [
                'label' => __('recharge.return_recharge'),
                'href' => url('recharge.php'),
                'primary' => true
            ],
            [
                'label' => __('nav.back_home'),
                'href' => url('index.php')
            ]
        ],
        400
    );
}
?>
<!DOCTYPE html>
<html lang="<?php echo i18n()->getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php _e('recharge.payment_redirect_title'); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="auth-container">
        <div class="auth-box">
            <div class="auth-header">
                <h1><?php _e('recharge.payment_redirect_title'); ?></h1>
                <p><?php _e('recharge.payment_redirect_desc'); ?></p>
            </div>
            <div style="text-align: center; margin-top: 20px;">
                <a class="btn-primary" href="<?php echo htmlspecialchars($paymentUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php _e('recharge.btn_continue_pay'); ?></a>
            </div>
        </div>
    </div>
    <noscript>
        <p style="text-align:center; margin-top: 15px;">
            <?php _e('recharge.browser_no_script'); ?>
        </p>
    </noscript>
</body>
</html>
