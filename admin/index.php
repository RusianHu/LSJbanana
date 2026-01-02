<?php
/**
 * 管理后台 - 仪表盘
 */

require_once __DIR__ . '/../admin_auth.php';
require_once __DIR__ . '/../security_utils.php';

$adminAuth = getAdminAuth();

// 验证管理员权限
if (!$adminAuth->requireAuth()) {
    exit;
}

// 获取数据库实例
require_once __DIR__ . '/../db.php';
$db = Database::getInstance();

// 获取统计数据（带错误处理）
$stats = [
    'total_users' => 0,
    'today_new_users' => 0,
    'total_recharge' => 0,
    'today_recharge' => 0,
    'total_consumption' => 0,
    'today_consumption' => 0,
    'total_images' => 0,
    'today_images' => 0,
];
$recentUsers = [];
$recentOrders = [];
$recentOps = ['logs' => []];
$dataError = '';

try {
    $stats = $db->getStatistics();
    $recentUsers = $db->getRecentRegistrations(10);
    $recentOrders = $db->getRecentRechargeOrders(10);
    $recentOps = $db->getAdminOperationLogs(10, 0);
} catch (Exception $e) {
    $dataError = '数据加载失败: ' . $e->getMessage();
    error_log('Admin dashboard data load error: ' . $e->getMessage());
}

// 辅助函数：格式化金额
function formatAmount($amount): string {
    return number_format((float)$amount, 2);
}

// 辅助函数：格式化时间
function formatTime($datetime): string {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;

    if ($diff < 60) {
        return '刚刚';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . '分钟前';
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . '小时前';
    } elseif ($diff < 259200) {
        return floor($diff / 86400) . '天前';
    } else {
        return date('Y-m-d H:i', $timestamp);
    }
}

// 辅助函数：操作类型翻译
function translateOpType($opType): string {
    $types = [
        'user_edit' => '编辑用户',
        'balance_add' => '人工充值',
        'balance_deduct' => '人工扣款',
        'user_disable' => '禁用用户',
        'user_enable' => '启用用户',
        'password_reset' => '重置密码',
    ];
    return $types[$opType] ?? $opType;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>仪表盘 - 管理后台</title>
    <link rel="stylesheet" href="<?php echo url('/admin/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="admin-body">
    <?php include 'sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>
                <i class="fas fa-tachometer-alt"></i>
                仪表盘
            </h1>
            <div class="admin-user-info">
                <i class="fas fa-user-shield"></i>
                <span>管理员</span>
            </div>
        </div>

        <div class="admin-content">
            <?php if ($dataError): ?>
                <div class="alert alert-danger" style="margin-bottom: 20px;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>警告:</strong> <?php echo htmlspecialchars($dataError); ?>
                    <p style="margin-top: 8px; font-size: 0.9rem;">
                        部分数据可能无法正常显示。请检查数据库连接和表结构是否完整。
                    </p>
                </div>
            <?php endif; ?>

            <!-- 统计卡片 -->
            <div class="stat-cards">
                <div class="stat-card primary">
                    <div class="stat-card-header">
                        <div>
                            <div class="stat-card-value"><?php echo number_format($stats['total_users']); ?></div>
                            <div class="stat-card-label">总用户数</div>
                        </div>
                        <div class="stat-card-icon">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <?php if ($stats['today_new_users'] > 0): ?>
                        <div class="stat-card-change up">
                            <i class="fas fa-arrow-up"></i> 今日新增 <?php echo $stats['today_new_users']; ?> 人
                        </div>
                    <?php endif; ?>
                </div>

                <div class="stat-card success">
                    <div class="stat-card-header">
                        <div>
                            <div class="stat-card-value">¥<?php echo formatAmount($stats['total_recharge']); ?></div>
                            <div class="stat-card-label">总充值金额</div>
                        </div>
                        <div class="stat-card-icon">
                            <i class="fas fa-wallet"></i>
                        </div>
                    </div>
                    <?php if ($stats['today_recharge'] > 0): ?>
                        <div class="stat-card-change up">
                            <i class="fas fa-arrow-up"></i> 今日充值 ¥<?php echo formatAmount($stats['today_recharge']); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="stat-card warning">
                    <div class="stat-card-header">
                        <div>
                            <div class="stat-card-value">¥<?php echo formatAmount($stats['total_consumption']); ?></div>
                            <div class="stat-card-label">总消费金额</div>
                        </div>
                        <div class="stat-card-icon">
                            <i class="fas fa-credit-card"></i>
                        </div>
                    </div>
                    <?php if ($stats['today_consumption'] > 0): ?>
                        <div class="stat-card-change up">
                            <i class="fas fa-arrow-up"></i> 今日消费 ¥<?php echo formatAmount($stats['today_consumption']); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="stat-card danger">
                    <div class="stat-card-header">
                        <div>
                            <div class="stat-card-value"><?php echo number_format($stats['total_images']); ?></div>
                            <div class="stat-card-label">生成图片数</div>
                        </div>
                        <div class="stat-card-icon">
                            <i class="fas fa-image"></i>
                        </div>
                    </div>
                    <?php if ($stats['today_images'] > 0): ?>
                        <div class="stat-card-change up">
                            <i class="fas fa-arrow-up"></i> 今日生成 <?php echo $stats['today_images']; ?> 张
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 最近活动 -->
            <div class="panel">
                <div class="panel-header">
                    <h3><i class="fas fa-clock"></i> 最近活动</h3>
                </div>
                <div class="panel-body">
                    <!-- 最近注册用户 -->
                    <h4 class="mb-10"><i class="fas fa-user-plus"></i> 最近注册用户</h4>
                    <?php if (!empty($recentUsers)): ?>
                        <div class="admin-table mb-20">
                            <table>
                                <thead>
                                    <tr>
                                        <th>用户ID</th>
                                        <th>用户名</th>
                                        <th>邮箱</th>
                                        <th>余额</th>
                                        <th>注册时间</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentUsers as $user): ?>
                                        <tr>
                                            <td>#<?php echo $user['id']; ?></td>
                                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td>¥<?php echo formatAmount($user['balance']); ?></td>
                                            <td><?php echo formatTime($user['created_at']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-20">暂无数据</p>
                    <?php endif; ?>

                    <!-- 最近充值订单 -->
                    <h4 class="mb-10"><i class="fas fa-money-bill-wave"></i> 最近充值订单</h4>
                    <?php if (!empty($recentOrders)): ?>
                        <div class="admin-table mb-20">
                            <table>
                                <thead>
                                    <tr>
                                        <th>订单号</th>
                                        <th>用户</th>
                                        <th>金额</th>
                                        <th>状态</th>
                                        <th>时间</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentOrders as $order): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($order['trade_no']); ?></td>
                                            <td>
                                                <?php if ($order['user_id']): ?>
                                                    #<?php echo $order['user_id']; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">未知</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>¥<?php echo formatAmount($order['amount']); ?></td>
                                            <td>
                                                <?php
                                                $statusClass = '';
                                                $statusText = '';
                                                switch ($order['status']) {
                                                    case 'pending':
                                                        $statusClass = 'warning';
                                                        $statusText = '待支付';
                                                        break;
                                                    case 'paid':
                                                        $statusClass = 'success';
                                                        $statusText = '已支付';
                                                        break;
                                                    case 'cancelled':
                                                        $statusClass = 'danger';
                                                        $statusText = '已取消';
                                                        break;
                                                    case 'refunded':
                                                        $statusClass = 'info';
                                                        $statusText = '已退款';
                                                        break;
                                                    default:
                                                        $statusClass = 'info';
                                                        $statusText = $order['status'];
                                                }
                                                ?>
                                                <span class="badge badge-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                            </td>
                                            <td><?php echo formatTime($order['created_at']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-20">暂无数据</p>
                    <?php endif; ?>

                    <!-- 最近管理操作 -->
                    <h4 class="mb-10"><i class="fas fa-tasks"></i> 最近管理操作</h4>
                    <?php if (!empty($recentOps['logs'])): ?>
                        <div class="admin-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>操作类型</th>
                                        <th>目标用户</th>
                                        <th>详细信息</th>
                                        <th>操作IP</th>
                                        <th>时间</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentOps['logs'] as $log): ?>
                                        <tr>
                                            <td><?php echo translateOpType($log['operation_type']); ?></td>
                                            <td>
                                                <?php if ($log['target_user_id']): ?>
                                                    #<?php echo $log['target_user_id']; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $details = json_decode($log['details'], true);
                                                if ($details) {
                                                    if (isset($details['amount'])) {
                                                        echo '金额: ¥' . formatAmount($details['amount']);
                                                    }
                                                    if (isset($details['remark'])) {
                                                        echo ' - ' . htmlspecialchars($details['remark']);
                                                    }
                                                } else {
                                                    echo '<span class="text-muted">-</span>';
                                                }
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                            <td><?php echo formatTime($log['created_at']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted">暂无操作记录</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    // 注入 API 端点配置 (可选,script.js 会回退到相对路径)
    window.ADMIN_API_ENDPOINT = '<?php echo url('/admin/api.php'); ?>';
    </script>
    <script src="<?php echo url('/admin/script.js'); ?>"></script>
</body>
</html>
