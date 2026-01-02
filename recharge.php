<?php
/**
 * 充值页面
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/payment.php';
require_once __DIR__ . '/db.php';

$auth = getAuth();
$payment = getPayment();
$db = Database::getInstance();

// 要求登录
$auth->requireLogin(true);

$config = require __DIR__ . '/config.php';
$billingConfig = $config['billing'] ?? [];
$pricePerTask = (float) ($billingConfig['price_per_task'] ?? $billingConfig['price_per_image'] ?? 0.20);
$user = $auth->getCurrentUser();

$error = '';
$success = '';

// 处理充值请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = (float) ($_POST['amount'] ?? 0);
    $customAmount = (float) ($_POST['custom_amount'] ?? 0);
    $payType = $_POST['pay_type'] ?? '';

    // 如果选择了自定义金额
    if ($amount === 0 && $customAmount > 0) {
        $amount = $customAmount;
    }

    $minRecharge = $billingConfig['min_recharge'] ?? 1.00;
    $maxRecharge = $billingConfig['max_recharge'] ?? 1000.00;

    if ($amount < $minRecharge) {
        $error = "最低充值金额为 {$minRecharge} 元";
    } elseif ($amount > $maxRecharge) {
        $error = "最高充值金额为 {$maxRecharge} 元";
    } elseif (!$payment->isEnabled()) {
        $error = '支付功能暂未开放';
    } else {
        // 生成订单号
        $outTradeNo = $payment->generateOutTradeNo();
        $userId = $auth->getCurrentUserId();

        // 创建充值订单记录
        $db->createRechargeOrder($userId, $outTradeNo, $amount, $payType ?: null);

        // 创建支付订单
        $result = $payment->createOrder(
            $outTradeNo,
            $amount,
            "老司机的香蕉 - 充值 {$amount} 元",
            $payType ?: null,
            json_encode(['user_id' => $userId])
        );

        if ($result['success']) {
            // 跳转到支付页面
            $auth->startSession();
            $_SESSION['payment_redirect_url'] = $result['url'];
            header('Location: payment_redirect.php');
            exit;
        } else {
            $error = $result['message'] ?? '创建支付订单失败';
        }
    }
}

// 获取充值选项
$rechargeOptions = $billingConfig['recharge_options'] ?? [5, 10, 20, 50, 100];

// 获取充值记录
$rechargeOrders = $db->getUserRechargeOrders($user['id'], 10);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>充值 - 老司机的香蕉</title>
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
        .pay-method-option .alipay-icon {
            color: #1677ff;
        }
        .pay-method-option .wxpay-icon {
            color: #07c160;
        }
        .pay-method-option .qqpay-icon {
            color: #12b7f5;
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
        <a href="index.php" class="back-link">
            <i class="fas fa-arrow-left"></i> 返回首页
        </a>

        <!-- 余额卡片 -->
        <div class="balance-card">
            <h3>当前余额</h3>
            <div class="balance">
                <?php echo number_format((float)($user['balance'] ?? 0), 2); ?> <small>元</small>
            </div>
            <div class="balance-info">
                <i class="fas fa-image"></i>
                约可生成 <?php echo $pricePerTask > 0 ? floor((float)($user['balance'] ?? 0) / $pricePerTask) : 0; ?> 次任务
            </div>
        </div>

        <!-- 充值表单 -->
        <div class="recharge-box">
            <h2><i class="fas fa-coins"></i> 账户充值</h2>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="price-info">
                <i class="fas fa-info-circle"></i>
                当前价格: <strong><?php echo $pricePerTask; ?> 元/次</strong>，充值后可立即使用
            </div>

            <form method="POST" action="">
                <!-- 金额选项 -->
                <div class="amount-options">
                    <?php foreach ($rechargeOptions as $index => $option): ?>
                        <div class="amount-option">
                            <input type="radio" name="amount" id="amount_<?php echo $index; ?>" 
                                   value="<?php echo $option; ?>" <?php echo $index === 0 ? 'checked' : ''; ?>>
                            <label for="amount_<?php echo $index; ?>">
                                <span class="amount-value"><?php echo $option; ?>元</span>
                                <span class="amount-images">约<?php echo $pricePerTask > 0 ? floor($option / $pricePerTask) : 0; ?>次</span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- 自定义金额 -->
                <div class="custom-amount">
                    <label>自定义金额</label>
                    <div class="custom-amount-input">
                        <input type="number" name="custom_amount" placeholder="输入充值金额" 
                               min="<?php echo $billingConfig['min_recharge'] ?? 1; ?>" 
                               max="<?php echo $billingConfig['max_recharge'] ?? 1000; ?>" 
                               step="0.01">
                        <span>元</span>
                    </div>
                </div>

                <!-- 支付方式 -->
                <div class="pay-methods">
                    <label>选择支付方式</label>
                    <div class="pay-method-options">
                        <div class="pay-method-option">
                            <input type="radio" name="pay_type" id="pay_auto" value="" checked>
                            <label for="pay_auto">
                                <i class="fas fa-cash-register"></i> 收银台
                            </label>
                        </div>
                        <div class="pay-method-option">
                            <input type="radio" name="pay_type" id="pay_alipay" value="alipay">
                            <label for="pay_alipay">
                                <i class="fab fa-alipay alipay-icon"></i> 支付宝
                            </label>
                        </div>
                        <div class="pay-method-option">
                            <input type="radio" name="pay_type" id="pay_wxpay" value="wxpay">
                            <label for="pay_wxpay">
                                <i class="fab fa-weixin wxpay-icon"></i> 微信
                            </label>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-primary">
                    <i class="fas fa-bolt"></i> 立即充值
                </button>
            </form>
        </div>

        <!-- 充值记录 -->
        <?php if (!empty($rechargeOrders)): ?>
        <div class="recharge-box recharge-orders">
            <h3><i class="fas fa-history"></i> 充值记录</h3>
            <div class="order-list">
                <?php foreach ($rechargeOrders as $order): ?>
                    <div class="order-item">
                        <div class="order-info">
                            <div class="order-no"><?php echo htmlspecialchars($order['out_trade_no']); ?></div>
                            <div class="order-time"><?php echo $order['created_at']; ?></div>
                        </div>
                        <div class="order-amount">+<?php echo number_format((float)$order['amount'], 2); ?>元</div>
                        <?php
                        $statusClass = 'pending';
                        $statusText = '待支付';
                        if ($order['status'] == 1) {
                            $statusClass = 'paid';
                            $statusText = '已完成';
                        } elseif ($order['status'] == 2) {
                            $statusClass = 'cancelled';
                            $statusText = '已取消';
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
