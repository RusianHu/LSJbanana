<?php
/**
 * 支付异步通知处理
 * 
 * 接收支付平台的异步通知，更新订单状态和用户余额
 */

require_once __DIR__ . '/payment.php';
require_once __DIR__ . '/db.php';

// 获取通知参数
$params = $_GET;

// 记录日志
$logData = date('Y-m-d H:i:s') . ' - Notify received: ' . json_encode($params, JSON_UNESCAPED_UNICODE) . "\n";
file_put_contents(__DIR__ . '/logs/notify.log', $logData, FILE_APPEND | LOCK_EX);

$payment = getPayment();
$db = Database::getInstance();

// 处理通知
$result = $payment->handleNotify($params);

if (!$result['success']) {
    // 记录失败日志
    $logData = date('Y-m-d H:i:s') . ' - Notify failed: ' . ($result['message'] ?? 'Unknown error') . "\n";
    file_put_contents(__DIR__ . '/logs/notify.log', $logData, FILE_APPEND | LOCK_EX);
    echo 'fail';
    exit;
}

$outTradeNo = $result['out_trade_no'];
$tradeNo = $result['trade_no'];
$payType = $result['type'];
$money = $result['money'];

// 查找订单
$order = $db->getRechargeOrderByOutTradeNo($outTradeNo);

if ($order === null) {
    // 订单不存在
    $logData = date('Y-m-d H:i:s') . " - Order not found: {$outTradeNo}\n";
    file_put_contents(__DIR__ . '/logs/notify.log', $logData, FILE_APPEND | LOCK_EX);
    echo 'fail';
    exit;
}

// 检查订单是否已处理
if ((int)$order['status'] === 1) {
    // 订单已处理，直接返回成功
    echo 'success';
    exit;
}

// 检查订单是否已取消
if ((int)$order['status'] === 2) {
    $logData = date('Y-m-d H:i:s') . " - Order cancelled: {$outTradeNo}\n";
    file_put_contents(__DIR__ . '/logs/notify.log', $logData, FILE_APPEND | LOCK_EX);
    echo 'fail';
    exit;
}

// 检查订单是否过期（但仍接受支付，因为用户可能在过期前已付款）
// 注意：这里不拒绝已过期但已付款的订单，因为支付回调可能延迟
if ($db->isOrderExpired($order)) {
    $logData = date('Y-m-d H:i:s') . " - Order expired but paid, processing: {$outTradeNo}\n";
    file_put_contents(__DIR__ . '/logs/notify.log', $logData, FILE_APPEND | LOCK_EX);
    // 继续处理，因为支付已完成
}

// 验证金额
if (abs((float)$order['amount'] - $money) > 0.01) {
    $logData = date('Y-m-d H:i:s') . " - Amount mismatch: order={$order['amount']}, paid={$money}\n";
    file_put_contents(__DIR__ . '/logs/notify.log', $logData, FILE_APPEND | LOCK_EX);
    echo 'fail';
    exit;
}

// 使用事务处理订单
try {
    // 确保 balance_logs 表已迁移（幂等操作，部署后首个请求可能是异步回调）
    $db->migrateBalanceLogsVisibility();

    $db->transaction(function ($db) use ($order, $tradeNo, $payType, $params) {
        // 更新订单状态
        $db->markOrderPaid(
            $order['out_trade_no'],
            $tradeNo,
            $payType,
            json_encode($params, JSON_UNESCAPED_UNICODE)
        );

        // 获取充值前余额
        $user = $db->getUserById($order['user_id']);
        $balanceBefore = $user ? (float)$user['balance'] : 0.00;
        $amount = (float)$order['amount'];
        $balanceAfter = $balanceBefore + $amount;

        // 增加用户余额
        $db->updateUserBalance($order['user_id'], $amount);

        // 写入账户流水（在线充值）
        // 在线充值流水仅后台"账户流水"可见，不在前台充值页展示（避免与订单记录重复）
        $db->execute(
            "INSERT INTO balance_logs (user_id, type, amount, balance_before, balance_after, remark, visible_to_user, source_type, source_id, created_at)
             VALUES (:user_id, 'recharge', :amount, :before, :after, :remark, 0, 'online_recharge', :source_id, :created_at)",
            [
                'user_id' => $order['user_id'],
                'amount' => $amount,
                'before' => $balanceBefore,
                'after' => $balanceAfter,
                'remark' => 'Online Recharge - Order: ' . $order['out_trade_no'],
                'source_id' => $order['id'],
                'created_at' => date('Y-m-d H:i:s')
            ]
        );
    });

    // 记录成功日志
    $logData = date('Y-m-d H:i:s') . " - Order paid: {$outTradeNo}, user_id={$order['user_id']}, amount={$order['amount']}\n";
    file_put_contents(__DIR__ . '/logs/notify.log', $logData, FILE_APPEND | LOCK_EX);

    // 返回成功
    echo 'success';

} catch (Exception $e) {
    // 记录异常日志
    $logData = date('Y-m-d H:i:s') . " - Transaction failed: " . $e->getMessage() . "\n";
    file_put_contents(__DIR__ . '/logs/notify.log', $logData, FILE_APPEND | LOCK_EX);
    echo 'fail';
}