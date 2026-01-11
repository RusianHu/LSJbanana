<?php
/**
 * 管理后台 - 仪表盘
 */

require_once __DIR__ . '/../admin_auth.php';
require_once __DIR__ . '/../security_utils.php';
require_once __DIR__ . '/../i18n/I18n.php';

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
        return __('time.just_now');
    } elseif ($diff < 3600) {
        return __('time.minutes_ago', ['n' => floor($diff / 60)]);
    } elseif ($diff < 86400) {
        return __('time.hours_ago', ['n' => floor($diff / 3600)]);
    } elseif ($diff < 259200) {
        return __('time.days_ago', ['n' => floor($diff / 86400)]);
    } else {
        return date('Y-m-d H:i', $timestamp);
    }
}

// 辅助函数：操作类型翻译
function translateOpType($opType): string {
    $key = 'admin.op_type.' . $opType;
    $trans = i18n()->get($key);
    return $trans === $key ? $opType : $trans;
}
?>
<!DOCTYPE html>
<html lang="<?php echo i18n()->getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php _e('admin.dashboard.title'); ?> - <?php _e('admin.title'); ?></title>
    <link rel="stylesheet" href="<?php echo url('/admin/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="admin-body">
    <?php include 'sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>
                <i class="fas fa-tachometer-alt"></i>
                <?php _e('admin.dashboard.title'); ?>
            </h1>
            <div class="admin-user-info">
                <div class="language-switcher" style="margin-right: 15px;">
                    <a href="?lang=zh-CN" class="<?php echo isZhCN() ? 'active' : ''; ?>" style="text-decoration: none; margin-right: 8px; color: #666; font-size: 0.9em;">CN</a>
                    <a href="?lang=en" class="<?php echo isEn() ? 'active' : ''; ?>" style="text-decoration: none; color: #666; font-size: 0.9em;">EN</a>
                </div>
                <i class="fas fa-user-shield"></i>
                <span><?php _e('admin.administrator'); ?></span>
            </div>
        </div>

        <div class="admin-content">
            <?php if ($dataError): ?>
                <div class="alert alert-danger" style="margin-bottom: 20px;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Warning:</strong> <?php echo htmlspecialchars($dataError); ?>
                    <p style="margin-top: 8px; font-size: 0.9rem;">
                        <?php _e('error.partial_function'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- 统计卡片 -->
            <div class="stat-cards">
                <div class="stat-card primary">
                    <div class="stat-card-header">
                        <div>
                            <div class="stat-card-value"><?php echo number_format($stats['total_users']); ?></div>
                            <div class="stat-card-label"><?php _e('admin.dashboard.total_users'); ?></div>
                        </div>
                        <div class="stat-card-icon">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <?php if ($stats['today_new_users'] > 0): ?>
                        <div class="stat-card-change up">
                            <i class="fas fa-arrow-up"></i> <?php _e('admin.dashboard.today_new', ['count' => $stats['today_new_users']]); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="stat-card success">
                    <div class="stat-card-header">
                        <div>
                            <div class="stat-card-value">¥<?php echo formatAmount($stats['total_recharge']); ?></div>
                            <div class="stat-card-label"><?php _e('admin.dashboard.total_recharge'); ?></div>
                        </div>
                        <div class="stat-card-icon">
                            <i class="fas fa-wallet"></i>
                        </div>
                    </div>
                    <?php if ($stats['today_recharge'] > 0): ?>
                        <div class="stat-card-change up">
                            <i class="fas fa-arrow-up"></i> <?php _e('admin.dashboard.today_recharge'); ?> ¥<?php echo formatAmount($stats['today_recharge']); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="stat-card warning">
                    <div class="stat-card-header">
                        <div>
                            <div class="stat-card-value">¥<?php echo formatAmount($stats['total_consumption']); ?></div>
                            <div class="stat-card-label"><?php _e('admin.dashboard.total_consumption'); ?></div>
                        </div>
                        <div class="stat-card-icon">
                            <i class="fas fa-credit-card"></i>
                        </div>
                    </div>
                    <?php if ($stats['today_consumption'] > 0): ?>
                        <div class="stat-card-change up">
                            <i class="fas fa-arrow-up"></i> <?php _e('admin.dashboard.today_consumption'); ?> ¥<?php echo formatAmount($stats['today_consumption']); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="stat-card danger">
                    <div class="stat-card-header">
                        <div>
                            <div class="stat-card-value"><?php echo number_format($stats['total_images']); ?></div>
                            <div class="stat-card-label"><?php _e('admin.dashboard.total_images'); ?></div>
                        </div>
                        <div class="stat-card-icon">
                            <i class="fas fa-image"></i>
                        </div>
                    </div>
                    <?php if ($stats['today_images'] > 0): ?>
                        <div class="stat-card-change up">
                            <i class="fas fa-arrow-up"></i> <?php _e('admin.dashboard.today_images', ['count' => $stats['today_images']]); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 最近活动 -->
            <div class="panel">
                <div class="panel-header">
                    <h3><i class="fas fa-clock"></i> <?php _e('admin.dashboard.recent_activity'); ?></h3>
                </div>
                <div class="panel-body">
                    <!-- 最近注册用户 -->
                    <h4 class="mb-10"><i class="fas fa-user-plus"></i> <?php _e('admin.dashboard.recent_users'); ?></h4>
                    <?php if (!empty($recentUsers)): ?>
                        <div class="admin-table mb-20">
                            <table>
                                <thead>
                                    <tr>
                                        <th><?php _e('admin.table.user_id'); ?></th>
                                        <th><?php _e('admin.table.username'); ?></th>
                                        <th><?php _e('admin.table.email'); ?></th>
                                        <th><?php _e('admin.table.balance'); ?></th>
                                        <th><?php _e('admin.table.created_at'); ?></th>
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
                        <p class="text-muted mb-20"><?php _e('admin.dashboard.no_data'); ?></p>
                    <?php endif; ?>

                    <!-- 最近充值订单 -->
                    <h4 class="mb-10"><i class="fas fa-money-bill-wave"></i> <?php _e('admin.dashboard.recent_orders'); ?></h4>
                    <?php if (!empty($recentOrders)): ?>
                        <div class="admin-table mb-20">
                            <table>
                                <thead>
                                    <tr>
                                        <th><?php _e('admin.table.order_no'); ?></th>
                                        <th><?php _e('admin.table.user'); ?></th>
                                        <th><?php _e('admin.table.amount'); ?></th>
                                        <th><?php _e('admin.table.status'); ?></th>
                                        <th><?php _e('admin.table.time'); ?></th>
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
                                                    <span class="text-muted"><?php _e('status.unknown'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>¥<?php echo formatAmount($order['amount']); ?></td>
                                            <td>
                                                <?php
                                                $statusClass = '';
                                                $statusKey = '';
                                                switch ($order['status']) {
                                                    case 'pending':
                                                        $statusClass = 'warning';
                                                        $statusKey = 'admin.order_status.0';
                                                        break;
                                                    case 'paid':
                                                        $statusClass = 'success';
                                                        $statusKey = 'admin.order_status.1';
                                                        break;
                                                    case 'cancelled':
                                                        $statusClass = 'danger';
                                                        $statusKey = 'admin.order_status.2';
                                                        break;
                                                    case 'refunded':
                                                        $statusClass = 'info';
                                                        $statusKey = 'admin.order_status.3';
                                                        break;
                                                    default:
                                                        $statusClass = 'info';
                                                        $statusKey = 'status.unknown';
                                                }
                                                ?>
                                                <span class="badge badge-<?php echo $statusClass; ?>"><?php _e($statusKey); ?></span>
                                            </td>
                                            <td><?php echo formatTime($order['created_at']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-20"><?php _e('admin.dashboard.no_data'); ?></p>
                    <?php endif; ?>

                    <!-- 最近管理操作 -->
                    <h4 class="mb-10"><i class="fas fa-tasks"></i> <?php _e('admin.dashboard.recent_ops'); ?></h4>
                    <?php if (!empty($recentOps['logs'])): ?>
                        <div class="admin-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th><?php _e('admin.table.op_type'); ?></th>
                                        <th><?php _e('admin.table.target_user'); ?></th>
                                        <th><?php _e('admin.table.details'); ?></th>
                                        <th><?php _e('admin.table.ip'); ?></th>
                                        <th><?php _e('admin.table.time'); ?></th>
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
                                                        echo __('admin.table.amount') . ': ¥' . formatAmount($details['amount']);
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
                        <p class="text-muted"><?php _e('admin.dashboard.no_records'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    // 注入 API 端点配置 (可选,script.js 会回退到相对路径)
    window.ADMIN_API_ENDPOINT = '<?php echo url('/admin/api.php'); ?>';
    window.LSJ_LANG = '<?php echo currentLocale(); ?>';
    </script>
    <script src="<?php echo url('/i18n/i18n.js'); ?>"></script>
    <script src="<?php echo url('/admin/script.js'); ?>"></script>
</body>
</html>
