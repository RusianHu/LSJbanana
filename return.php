<?php
/**
 * 支付同步跳转处理
 * 
 * 用户支付完成后跳转回来的页面
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/payment.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n/I18n.php';

$auth = getAuth();
$payment = getPayment();
$db = Database::getInstance();

// 获取参数
$params = $_GET;

$success = false;
$message = '';
$order = null;

// 验证签名
if (!empty($params)) {
    $result = $payment->handleNotify($params);
    
    if ($result['success']) {
        $outTradeNo = $result['out_trade_no'];
        $order = $db->getRechargeOrderByOutTradeNo($outTradeNo);
        
        if ($order !== null) {
            // 检查订单状态
            if ((int)$order['status'] === 1) {
                $success = true;
                $message = __('return.success_msg');
            } else {
                // 订单可能还未被异步通知处理，等待一下再查询
                sleep(1);
                $order = $db->getRechargeOrderByOutTradeNo($outTradeNo);
                if ((int)$order['status'] === 1) {
                    $success = true;
                    $message = __('return.success_msg');
                } else {
                    $message = __('return.pending_msg');
                }
            }
        } else {
            $message = __('return.order_not_found');
        }
    } else {
        $message = __('return.failed_msg');
    }
} else {
    $message = __('return.invalid_callback');
}

// 获取当前用户（如果已登录）
$user = null;
if ($auth->isLoggedIn()) {
    $user = $auth->refreshCurrentUser();
}
$isPending = (!$success && strpos($message, __('return.pending_title')) !== false);
?>
<!DOCTYPE html>
<html lang="<?php echo i18n()->getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php _e('return.title'); ?> - <?php _e('site.title'); ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .result-container {
            max-width: 500px;
            margin: 80px auto;
            padding: 0 20px;
            text-align: center;
        }
        .result-box {
            background: var(--panel-bg);
            border-radius: 16px;
            box-shadow: var(--shadow);
            padding: 50px 40px;
        }
        .result-icon {
            font-size: 4rem;
            margin-bottom: 25px;
        }
        .result-icon.success {
            color: #4caf50;
        }
        .result-icon.pending {
            color: #ff9800;
        }
        .result-icon.error {
            color: #f44336;
        }
        .result-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 15px;
            color: #333;
        }
        .result-message {
            color: #666;
            margin-bottom: 25px;
            line-height: 1.6;
        }
        .order-info {
            background: #f5f5f5;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
            text-align: left;
        }
        .order-info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .order-info-item:last-child {
            margin-bottom: 0;
        }
        .order-info-item .label {
            color: #888;
        }
        .order-info-item .value {
            font-weight: 600;
            color: #333;
        }
        .order-info-item .value.amount {
            color: #4caf50;
            font-size: 1.1rem;
        }
        .balance-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            padding: 20px;
            color: white;
            margin-bottom: 25px;
        }
        .balance-info h4 {
            font-size: 0.9rem;
            font-weight: 400;
            opacity: 0.9;
            margin-bottom: 8px;
        }
        .balance-info .balance {
            font-size: 2rem;
            font-weight: 700;
        }
        .action-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        .action-buttons a {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        .btn-primary-link {
            background: var(--primary-color);
            color: #333;
        }
        .btn-primary-link:hover {
            background: var(--primary-hover);
        }
        .btn-secondary-link {
            background: #f0f0f0;
            color: #666;
        }
        .btn-secondary-link:hover {
            background: #e0e0e0;
        }
    </style>
</head>
<body>
    <div class="result-container">
        <div class="result-box">
            <?php if ($success): ?>
                <div class="result-icon success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h1 class="result-title"><?php _e('return.success_title'); ?></h1>
                <p class="result-message"><?php echo htmlspecialchars($message); ?></p>

                <?php if ($order): ?>
                <div class="order-info">
                    <div class="order-info-item">
                        <span class="label"><?php _e('return.order_no'); ?></span>
                        <span class="value"><?php echo htmlspecialchars($order['out_trade_no']); ?></span>
                    </div>
                    <div class="order-info-item">
                        <span class="label"><?php _e('return.amount'); ?></span>
                        <span class="value amount">+<?php echo number_format((float)$order['amount'], 2); ?> <?php _e('user.balance_unit'); ?></span>
                    </div>
                    <div class="order-info-item">
                        <span class="label"><?php _e('return.pay_time'); ?></span>
                        <span class="value"><?php echo $order['paid_at'] ?? $order['created_at']; ?></span>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($user): ?>
                <div class="balance-info">
                    <h4><?php _e('recharge.current_balance'); ?></h4>
                    <div class="balance"><?php echo number_format((float)$user['balance'], 2); ?> <?php _e('user.balance_unit'); ?></div>
                </div>
                <?php endif; ?>

            <?php elseif (strpos($message, __('return.pending_title')) !== false): ?>
                <div class="result-icon pending">
                    <i class="fas fa-clock"></i>
                </div>
                <h1 class="result-title"><?php _e('return.pending_title'); ?></h1>
                <p class="result-message"><?php echo htmlspecialchars($message); ?></p>

            <?php else: ?>
                <div class="result-icon error">
                    <i class="fas fa-times-circle"></i>
                </div>
                <h1 class="result-title"><?php _e('return.failed_title'); ?></h1>
                <p class="result-message"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <div class="action-buttons">
                <?php if ($isPending): ?>
                    <a href="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? url('return.php')); ?>" class="btn-secondary-link">
                        <i class="fas fa-sync-alt"></i> <?php _e('return.refresh_status'); ?>
                    </a>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars(url('index.php')); ?>" class="btn-primary-link">
                    <i class="fas fa-home"></i> <?php _e('nav.back_home'); ?>
                </a>
                <a href="<?php echo htmlspecialchars(url('recharge.php')); ?>" class="btn-secondary-link">
                    <i class="fas fa-redo"></i> <?php _e('return.continue_recharge'); ?>
                </a>
            </div>
        </div>
    </div>
</body>
</html>
