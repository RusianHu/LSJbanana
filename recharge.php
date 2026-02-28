<?php
/**
 * 充值页面
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/payment.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n/I18n.php';

$auth = getAuth();
$payment = getPayment();
$db = Database::getInstance();

// 要求登录
$auth->requireLogin(true);

$config = require __DIR__ . '/config.php';
$billingConfig = $config['billing'] ?? [];
$paymentConfig = $config['payment'] ?? [];
$pricePerTask = (float) ($billingConfig['price_per_task'] ?? $billingConfig['price_per_image'] ?? 0.20);
$orderExpireMinutes = (int) ($billingConfig['order_expire_minutes'] ?? 5);
$user = $auth->getCurrentUser();

// 获取启用的支付渠道并按 sort 排序
$paymentChannels = [];
$channelsConfig = $paymentConfig['channels'] ?? [];
foreach ($channelsConfig as $key => $channel) {
    if (!empty($channel['enabled'])) {
        $channel['key'] = $key;
        $paymentChannels[] = $channel;
    }
}
usort($paymentChannels, function($a, $b) {
    return ($a['sort'] ?? 99) - ($b['sort'] ?? 99);
});

// 确保数据库表有 expires_at 字段（自动迁移）
$db->migrateAddExpiresAtColumn();

// 确保 balance_logs 表有 visible_to_user、user_remark、source_type、source_id 字段（自动迁移）
$db->migrateBalanceLogsVisibility();

$error = '';
$success = '';

// 处理充值请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = (float) ($_POST['amount'] ?? 0);
    $customAmount = (float) ($_POST['custom_amount'] ?? 0);
    $payType = $_POST['pay_type'] ?? '';

    // 如果选择了自定义金额（使用 <= 0 避免 float/int 类型比较问题）
    if ($amount <= 0 && $customAmount > 0) {
        $amount = $customAmount;
    }

    $minRecharge = $billingConfig['min_recharge'] ?? 1.00;
    $maxRecharge = $billingConfig['max_recharge'] ?? 1000.00;

    if ($amount < $minRecharge) {
        $error = __('recharge.error.min_amount', ['min' => $minRecharge]);
    } elseif ($amount > $maxRecharge) {
        $error = __('recharge.error.max_amount', ['max' => $maxRecharge]);
    } elseif (!$payment->isEnabled()) {
        $error = __('recharge.error.payment_disabled');
    } else {
        // 生成订单号
        $outTradeNo = $payment->generateOutTradeNo();
        $userId = $auth->getCurrentUserId();

        // 创建充值订单记录（带过期时间）
        $db->createRechargeOrder($userId, $outTradeNo, $amount, $payType ?: null, $orderExpireMinutes);

        // 创建支付订单
        $result = $payment->createOrder(
            $outTradeNo,
            $amount,
            __('site.title') . ' - ' . __('recharge.page_title') . " {$amount}",
            $payType ?: null,
            json_encode(['user_id' => $userId])
        );

        if ($result['success']) {
            $paymentUrl = $result['url'] ?? '';
            if ($paymentUrl === '' || !preg_match('#^https?://#i', $paymentUrl)) {
                $error = __('recharge.error.payment_url_invalid');
            } else {
                renderActionPage(
                    __('recharge.order_created'),
                    __('recharge.order_created_desc'),
                    [
                        [
                            'label' => __('recharge.go_pay'),
                            'href' => $paymentUrl,
                            'primary' => true,
                            'new_tab' => true
                        ],
                        [
                            'label' => __('nav.back_home'),
                            'href' => url('index.php')
                        ]
                    ]
                );
            }
        } else {
            $error = $result['message'] ?? __('recharge.error.create_failed');
        }
    }
}

// 获取充值选项
$rechargeOptions = $billingConfig['recharge_options'] ?? [5, 10, 20, 50, 100];

// 获取充值记录（在线支付订单）
$rechargeOrders = $db->getUserRechargeOrders($user['id'], 10);

// 获取用户可见的账户流水记录（管理员手动操作且标记为可见的）
$visibleBalanceLogs = $db->getUserVisibleBalanceLogs($user['id'], 10);

// 合并两种记录并按时间排序
$allRecords = [];

// 处理在线支付订单
foreach ($rechargeOrders as $order) {
$allRecords[] = [
    'type' => 'order',
    'time' => $order['created_at'],
    'amount' => (float)$order['amount'],
    'status' => (int)$order['status'],
    'source' => __('recharge.source_online'),
    'order_no' => $order['out_trade_no'],
    'raw' => $order
];
}

// 处理可见的账户流水记录
foreach ($visibleBalanceLogs['logs'] as $log) {
// 只显示充值类型的记录（recharge），不显示扣款
if ($log['type'] === 'recharge') {
    $allRecords[] = [
        'type' => 'balance_log',
        'time' => $log['created_at'],
        'amount' => (float)$log['amount'],
        'status' => 1, // 已完成
        'source' => $log['user_remark'] ?: __('recharge.source_manual'),
        'order_no' => null,
        'raw' => $log
    ];
}
}

// 按时间降序排序
usort($allRecords, function($a, $b) {
    return strtotime($b['time']) - strtotime($a['time']);
});

// 只取前10条
$allRecords = array_slice($allRecords, 0, 10);
?>
<!DOCTYPE html>
<html lang="<?php echo i18n()->getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php _e('recharge.title'); ?> - <?php _e('site.title'); ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .recharge-container {
            max-width: 600px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #666;
            text-decoration: none;
            font-size: 0.9rem;
            margin-bottom: 20px;
        }
        .back-link:hover {
            color: var(--primary-color);
        }
        .balance-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            padding: 30px;
            color: white;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        .balance-card h3 {
            font-size: 0.95rem;
            font-weight: 400;
            opacity: 0.9;
            margin-bottom: 8px;
        }
        .balance-card .balance {
            font-size: 2.5rem;
            font-weight: 700;
        }
        .balance-card .balance small {
            font-size: 1rem;
            font-weight: 400;
        }
        .balance-card .balance-info {
            margin-top: 15px;
            font-size: 0.9rem;
            opacity: 0.85;
        }
        .recharge-box {
            background: var(--panel-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 30px;
            margin-bottom: 25px;
        }
        .recharge-box h2 {
            font-size: 1.3rem;
            margin-bottom: 20px;
            color: #333;
        }
        .amount-options {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        .amount-option {
            position: relative;
        }
        .amount-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }
        .amount-option label {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 18px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            background: #fff;
        }
        .amount-option label:hover {
            border-color: var(--primary-color);
        }
        .amount-option input:checked + label {
            border-color: var(--primary-color);
            background: #fff7de;
        }
        .amount-option .amount-value {
            font-size: 1.4rem;
            font-weight: 700;
            color: #333;
        }
        .amount-option .amount-images {
            font-size: 0.8rem;
            color: #888;
            margin-top: 4px;
        }
        .custom-amount {
            margin-bottom: 20px;
        }
        .custom-amount label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #444;
        }
        .custom-amount-input {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .custom-amount-input input {
            flex: 1;
            padding: 12px 14px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
        }
        .custom-amount-input span {
            color: #666;
        }
        .pay-methods {
            margin-bottom: 25px;
        }
        .pay-methods label {
            display: block;
            margin-bottom: 12px;
            font-weight: 600;
            color: #444;
        }
        .pay-method-options {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .pay-method-option {
            position: relative;
        }
        .pay-method-option input[type="radio"] {
            position: absolute;
            opacity: 0;
        }
        .pay-method-option label {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-weight: 500;
        }
        .pay-method-option label:hover {
            border-color: var(--primary-color);
        }
        .pay-method-option input:checked + label {
            border-color: var(--primary-color);
            background: #fff7de;
        }
        .pay-method-option i {
            font-size: 1.2rem;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 0.95rem;
        }
        .alert-error {
            background: #fee;
            color: #c62828;
            border: 1px solid #fcc;
        }
        .alert-success {
            background: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }
        .recharge-orders {
            margin-top: 30px;
        }
        .recharge-orders h3 {
            font-size: 1.1rem;
            margin-bottom: 15px;
            color: #333;
        }
        .order-list {
            background: #f9f9f9;
            border-radius: 8px;
            overflow: hidden;
        }
        .order-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid #eee;
        }
        .order-item:last-child {
            border-bottom: none;
        }
        .order-info {
            flex: 1;
        }
        .order-info .order-no {
            font-size: 0.85rem;
            color: #888;
        }
        .order-info .order-time {
            font-size: 0.8rem;
            color: #aaa;
        }
        .order-amount {
            font-weight: 700;
            color: #333;
        }
        .order-status {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .order-status.pending {
            background: #fff3e0;
            color: #ef6c00;
        }
        .order-status.paid {
            background: #e8f5e9;
            color: #2e7d32;
        }
        .order-status.cancelled {
            background: #fafafa;
            color: #999;
        }
        .price-info {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            color: #666;
        }
        .price-info i {
            color: var(--primary-color);
        }
        @media (max-width: 500px) {
            .amount-options {
                grid-template-columns: repeat(2, 1fr);
            }
            .pay-method-options {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="recharge-container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <a href="index.php" class="back-link" style="margin-bottom: 0;">
                <i class="fas fa-arrow-left"></i> <?php _e('nav.back_home'); ?>
            </a>
            <div class="language-switcher">
                <a href="?lang=zh-CN" class="<?php echo isZhCN() ? 'active' : ''; ?>" style="text-decoration: none; margin-right: 8px; color: #666;">CN</a>
                <a href="?lang=en" class="<?php echo isEn() ? 'active' : ''; ?>" style="text-decoration: none; color: #666;">EN</a>
            </div>
        </div>

        <!-- 余额卡片 -->
        <div class="balance-card">
            <h3><?php _e('recharge.current_balance'); ?></h3>
            <div class="balance">
                <?php echo number_format((float)($user['balance'] ?? 0), 2); ?> <small><?php _e('user.balance_unit'); ?></small>
            </div>
            <div class="balance-info">
                <i class="fas fa-image"></i>
                <?php _e('recharge.can_generate', ['count' => $pricePerTask > 0 ? floor((float)($user['balance'] ?? 0) / $pricePerTask) : 0]); ?>
            </div>
        </div>

        <!-- 充值表单 -->
        <div class="recharge-box">
            <h2><i class="fas fa-coins"></i> <?php _e('recharge.page_title'); ?></h2>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="price-info">
                <i class="fas fa-info-circle"></i>
                <?php _e('recharge.price_info'); ?>: <strong><?php _e('recharge.price_per_task', ['price' => $pricePerTask]); ?></strong><?php _e('misc.comma'); ?><?php _e('recharge.recharge_after_use'); ?>
            </div>

            <form method="POST" action="">
                <!-- 金额选项 -->
                <div class="amount-options">
                    <?php foreach ($rechargeOptions as $index => $option): ?>
                        <div class="amount-option">
                            <input type="radio" name="amount" id="amount_<?php echo $index; ?>"
                                   value="<?php echo $option; ?>" <?php echo $index === 0 ? 'checked' : ''; ?>>
                            <label for="amount_<?php echo $index; ?>">
                                <span class="amount-value"><?php echo $option; ?><?php _e('user.balance_unit'); ?></span>
                                <span class="amount-images"><?php _e('misc.unit_times', ['count' => $pricePerTask > 0 ? floor($option / $pricePerTask) : 0]); ?></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- 自定义金额 -->
                <div class="custom-amount">
                    <label><?php _e('recharge.custom_amount'); ?></label>
                    <div class="custom-amount-input">
                        <input type="number" name="custom_amount" placeholder="<?php _e('recharge.custom_amount_placeholder'); ?>"
                               min="<?php echo $billingConfig['min_recharge'] ?? 1; ?>"
                               max="<?php echo $billingConfig['max_recharge'] ?? 1000; ?>"
                               step="0.01">
                        <span><?php _e('user.balance_unit'); ?></span>
                    </div>
                </div>

                <!-- 支付方式 -->
                <?php if (!empty($paymentChannels)): ?>
                <div class="pay-methods">
                    <label><?php _e('recharge.pay_method'); ?></label>
                    <div class="pay-method-options">
                        <?php
                        $isFirst = true;
                        foreach ($paymentChannels as $channel):
                            $channelKey = htmlspecialchars($channel['key']);
                            $channelValue = htmlspecialchars($channel['value'] ?? '');
                            $channelName = htmlspecialchars($channel['name'] ?? $channelKey);
                            $channelIcon = htmlspecialchars($channel['icon'] ?? 'fas fa-credit-card');
                            $iconColor = $channel['icon_color'] ?? '';
                            $iconStyle = $iconColor ? 'style="color: ' . htmlspecialchars($iconColor) . ';"' : '';
                        ?>
                        <div class="pay-method-option">
                            <input type="radio" name="pay_type" id="pay_<?php echo $channelKey; ?>" value="<?php echo $channelValue; ?>" <?php echo $isFirst ? 'checked' : ''; ?>>
                            <label for="pay_<?php echo $channelKey; ?>">
                                <i class="<?php echo $channelIcon; ?>" <?php echo $iconStyle; ?>></i> <?php echo $channelName; ?>
                            </label>
                        </div>
                        <?php
                        $isFirst = false;
                        endforeach;
                        ?>
                    </div>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn-primary">
                    <i class="fas fa-bolt"></i> <?php _e('recharge.btn_recharge'); ?>
                </button>
            </form>
        </div>

        <!-- 充值记录 -->
        <?php if (!empty($allRecords)): ?>
        <div class="recharge-box recharge-orders">
            <h3><i class="fas fa-history"></i> <?php _e('recharge.recharge_history'); ?></h3>
            <div class="order-list">
                <?php foreach ($allRecords as $record): ?>
                    <div class="order-item">
                        <div class="order-info">
                            <?php if ($record['type'] === 'order'): ?>
                                <div class="order-no"><?php echo htmlspecialchars($record['order_no']); ?></div>
                            <?php else: ?>
                                <div class="order-no"><?php echo htmlspecialchars($record['source']); ?></div>
                            <?php endif; ?>
                            <div class="order-time"><?php echo $record['time']; ?></div>
                        </div>
                        <div class="order-amount">+<?php echo number_format($record['amount'], 2); ?><?php _e('user.balance_unit'); ?></div>
                        <?php
                        $statusClass = 'pending';
                        $statusText = __('recharge.order_status.pending');
                        if ($record['status'] == 1) {
                            $statusClass = 'paid';
                            $statusText = __('recharge.order_status.paid');
                        } elseif ($record['status'] == 2) {
                            $statusClass = 'cancelled';
                            $statusText = __('recharge.order_status.cancelled');
                        }
                        ?>
                        <div class="order-status <?php echo $statusClass; ?>"><?php echo $statusText; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // 当选择自定义金额时，取消预设金额选择
        document.querySelector('input[name="custom_amount"]').addEventListener('input', function() {
            if (this.value) {
                document.querySelectorAll('input[name="amount"]').forEach(function(radio) {
                    radio.checked = false;
                });
            }
        });

        // 当选择预设金额时，清空自定义金额
        document.querySelectorAll('input[name="amount"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                document.querySelector('input[name="custom_amount"]').value = '';
            });
        });
    </script>
</body>
</html>
