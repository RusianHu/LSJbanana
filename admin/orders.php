<?php
/**
 * 管理后台 - 订单管理
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

// 确保数据库表有 expires_at 字段（自动迁移）
$db->migrateAddExpiresAtColumn();

// 获取过期订单统计
$expiredCount = $db->getExpiredPendingOrderCount();

// 获取没有过期时间的旧订单数量
$ordersWithoutExpires = $db->getPendingOrdersWithoutExpiresAt();

// 分页参数
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// 搜索和筛选参数
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$payType = isset($_GET['pay_type']) ? trim($_GET['pay_type']) : '';
$expiredFilter = isset($_GET['expired']) ? trim($_GET['expired']) : '';

$where = 'WHERE 1=1';
$params = [];

// 特殊状态：过期订单
if ($status === 'expired') {
    $where .= ' AND r.status = 0 AND r.expires_at IS NOT NULL AND r.expires_at < :now';
    $params['now'] = date('Y-m-d H:i:s');
} elseif ($status !== '' && in_array($status, ['0', '1', '2', '3'], true)) {
    $where .= ' AND r.status = :status';
    $params['status'] = (int)$status;
}

if ($payType !== '') {
    $where .= ' AND r.pay_type = :pay_type';
    $params['pay_type'] = $payType;
}

if ($search !== '') {
    $where .= ' AND (r.out_trade_no LIKE :search OR r.trade_no LIKE :search OR r.user_id = :user_id)';
    $params['search'] = '%' . $search . '%';
    $params['user_id'] = is_numeric($search) ? (int)$search : 0;
}

$sql = "SELECT r.*, u.username, u.email
        FROM recharge_orders r
        LEFT JOIN users u ON r.user_id = u.id
        {$where}
        ORDER BY r.created_at DESC
        LIMIT :limit OFFSET :offset";

$queryParams = $params;
$queryParams['limit'] = $perPage;
$queryParams['offset'] = $offset;
$orders = $db->query($sql, $queryParams);

$countSql = "SELECT COUNT(*) as total FROM recharge_orders r {$where}";
$countResult = $db->query($countSql, $params);
$totalOrders = (int)($countResult[0]['total'] ?? 0);
$totalPages = (int)ceil($totalOrders / $perPage);

function formatAmount($amount): string {
    return number_format((float)$amount, 2);
}

function formatTime(?string $datetime): string {
    if (!$datetime) {
        return '-';
    }
    return date('Y-m-d H:i:s', strtotime($datetime));
}

function statusBadge(int $status, ?string $expiresAt = null): array {
    switch ($status) {
        case 1:
            return ['success', __('admin.order_status.1')];
        case 2:
            return ['danger', __('admin.order_status.2')];
        case 3:
            return ['info', __('admin.order_status.3')];
        case 0:
        default:
            // 检查是否过期
            if ($expiresAt && strtotime($expiresAt) < time()) {
                return ['expired', __('admin.order_status.expired')];
            }
            return ['warning', __('admin.order_status.0')];
    }
}

function payTypeLabel(?string $payType): string {
    $map = [
        'alipay' => __('recharge.pay_alipay'),
        'wxpay' => __('recharge.pay_wechat'),
        'qqpay' => __('recharge.pay_qq'),
    ];
    return $map[$payType] ?? ($payType ?: __('recharge.pay_cashier'));
}
?>
<!DOCTYPE html>
<html lang="<?php echo i18n()->getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php _e('admin.orders.title'); ?> - <?php _e('admin.title'); ?></title>
    <link rel="stylesheet" href="<?php echo url('/admin/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="admin-body">
    <?php include 'sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>
                <i class="fas fa-receipt"></i>
                <?php _e('admin.orders.title'); ?>
            </h1>
            <div class="admin-user-info">
                <i class="fas fa-user-shield"></i>
                <span><?php _e('admin.administrator'); ?></span>
            </div>
        </div>

        <div class="admin-content">
            <div class="panel">
                <div class="panel-body">
                    <form method="GET" action="" class="search-bar">
                        <div class="search-inputs">
                            <input
                                type="text"
                                name="search"
                                placeholder="<?php _e('admin.orders.search_placeholder'); ?>"
                                value="<?php echo htmlspecialchars($search); ?>"
                            >
                            <select name="status">
                                <option value=""><?php _e('admin.users.all_status'); ?></option>
                                <option value="0" <?php echo $status === '0' ? 'selected' : ''; ?>><?php _e('admin.order_status.0'); ?></option>
                                <option value="expired" <?php echo $status === 'expired' ? 'selected' : ''; ?>><?php _e('admin.order_status.expired'); ?> (<?php echo $expiredCount; ?>)</option>
                                <option value="1" <?php echo $status === '1' ? 'selected' : ''; ?>><?php _e('admin.order_status.1'); ?></option>
                                <option value="2" <?php echo $status === '2' ? 'selected' : ''; ?>><?php _e('admin.order_status.2'); ?></option>
                                <option value="3" <?php echo $status === '3' ? 'selected' : ''; ?>><?php _e('admin.order_status.3'); ?></option>
                            </select>
                            <select name="pay_type">
                                <option value=""><?php _e('admin.orders.all_pay_types'); ?></option>
                                <option value="alipay" <?php echo $payType === 'alipay' ? 'selected' : ''; ?>><?php _e('recharge.pay_alipay'); ?></option>
                                <option value="wxpay" <?php echo $payType === 'wxpay' ? 'selected' : ''; ?>><?php _e('recharge.pay_wechat'); ?></option>
                                <option value="qqpay" <?php echo $payType === 'qqpay' ? 'selected' : ''; ?>><?php _e('recharge.pay_qq'); ?></option>
                            </select>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> <?php _e('form.search'); ?>
                            </button>
                            <?php if ($search !== '' || $status !== '' || $payType !== ''): ?>
                                <a href="orders.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> <?php _e('form.clear'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                    <div class="text-muted" style="margin-top: 8px;">
                        <?php _e('admin.orders.status_hint'); ?>
                    </div>
                    
                    <?php if ($ordersWithoutExpires > 0): ?>
                    <div class="backfill-actions" style="margin-top: 15px; padding: 15px; background: #d1ecf1; border-radius: 8px; border: 1px solid #17a2b8;">
                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <i class="fas fa-info-circle" style="color: #0c5460;"></i>
                                <strong style="color: #0c5460;"><?php _e('admin.orders.no_expire_count', ['count' => $ordersWithoutExpires]); ?></strong>
                                <span class="text-muted" style="margin-left: 10px;"><?php _e('admin.orders.backfill_hint'); ?></span>
                            </div>
                            <button type="button" class="btn btn-info btn-sm" id="backfillBtn">
                                <i class="fas fa-clock"></i> <?php _e('admin.orders.backfill_btn'); ?>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($expiredCount > 0): ?>
                    <div class="expired-actions" style="margin-top: 15px; padding: 15px; background: #fff3cd; border-radius: 8px; border: 1px solid #ffc107;">
                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <i class="fas fa-exclamation-triangle" style="color: #856404;"></i>
                                <strong style="color: #856404;"><?php _e('admin.orders.expired_count', ['count' => $expiredCount]); ?></strong>
                                <span class="text-muted" style="margin-left: 10px;"><?php _e('admin.orders.expired_hint'); ?></span>
                            </div>
                            <div style="display: flex; gap: 10px;">
                                <a href="?status=expired" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-eye"></i> <?php _e('admin.orders.view_expired'); ?>
                                </a>
                                <button type="button" class="btn btn-warning btn-sm" id="cancelExpiredBtn">
                                    <i class="fas fa-trash-alt"></i> <?php _e('admin.orders.cancel_expired'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="admin-table">
                <table>
                    <thead>
                        <tr>
                            <th><?php _e('admin.table.order_no'); ?></th>
                            <th><?php _e('admin.table.platform_no'); ?></th>
                            <th><?php _e('admin.table.user'); ?></th>
                            <th><?php _e('admin.table.amount'); ?></th>
                            <th><?php _e('admin.table.pay_type'); ?></th>
                            <th><?php _e('admin.table.status'); ?></th>
                            <th><?php _e('admin.table.created_at'); ?></th>
                            <th><?php _e('admin.table.paid_at'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted"><?php _e('admin.orders.no_orders'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($orders as $order): ?>
                                <?php [$badgeClass, $badgeText] = statusBadge((int)$order['status'], $order['expires_at'] ?? null); ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($order['out_trade_no']); ?></td>
                                    <td><?php echo htmlspecialchars($order['trade_no'] ?? '-'); ?></td>
                                    <td>
                                        <?php if (!empty($order['user_id'])): ?>
                                            #<?php echo (int)$order['user_id']; ?>
                                            <?php if (!empty($order['username'])): ?>
                                                <div class="text-muted" style="font-size: 0.85rem;">
                                                    <?php echo htmlspecialchars($order['username']); ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted"><?php _e('status.unknown'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>¥<?php echo formatAmount($order['amount']); ?></td>
                                    <td><?php echo htmlspecialchars(payTypeLabel($order['pay_type'] ?? null)); ?></td>
                                    <td><span class="badge badge-<?php echo $badgeClass; ?>"><?php echo $badgeText; ?></span></td>
                                    <td><?php echo formatTime($order['created_at'] ?? null); ?></td>
                                    <td><?php echo formatTime($order['paid_at'] ?? null); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php
                    $baseUrl = 'orders.php?';
                    if ($search !== '') $baseUrl .= 'search=' . urlencode($search) . '&';
                    if ($status !== '') $baseUrl .= 'status=' . urlencode($status) . '&';
                    if ($payType !== '') $baseUrl .= 'pay_type=' . urlencode($payType) . '&';

                    if ($page > 1): ?>
                        <a href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>" class="page-link">
                            <i class="fas fa-chevron-left"></i> <?php _e('admin.pagination.prev'); ?>
                        </a>
                    <?php endif;

                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);

                    if ($startPage > 1): ?>
                        <a href="<?php echo $baseUrl; ?>page=1" class="page-link">1</a>
                        <?php if ($startPage > 2): ?>
                            <span class="page-link">...</span>
                        <?php endif;
                    endif;

                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <a
                            href="<?php echo $baseUrl; ?>page=<?php echo $i; ?>"
                            class="page-link <?php echo $i === $page ? 'active' : ''; ?>"
                        >
                            <?php echo $i; ?>
                        </a>
                    <?php endfor;

                    if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?>
                            <span class="page-link">...</span>
                        <?php endif; ?>
                        <a href="<?php echo $baseUrl; ?>page=<?php echo $totalPages; ?>" class="page-link"><?php echo $totalPages; ?></a>
                    <?php endif;

                    if ($page < $totalPages): ?>
                        <a href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>" class="page-link">
                            <?php _e('admin.pagination.next'); ?> <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // 注入 i18n
    window.LSJ_LANG = '<?php echo currentLocale(); ?>';
    </script>
    <script src="<?php echo url('/i18n/i18n.js'); ?>"></script>
    <script>
    // 等待 i18n 初始化
    function getTrans(key, defaultVal) {
        if (!window.i18n || !window.i18n.loaded) {
            return defaultVal;
        }
        const result = window.i18n.t(key);
        // 如果返回的是键名本身，说明翻译不存在，返回默认值
        return (result === key) ? defaultVal : result;
    }

    // 回填旧订单过期时间
    document.getElementById('backfillBtn')?.addEventListener('click', function() {
        if (!confirm(getTrans('admin.orders.backfill_confirm', '确定要为所有旧待支付订单回填过期时间吗？回填后这些订单将被标记为过期。'))) {
            return;
        }
        
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + getTrans('form.processing', '处理中...');
        
        fetch('api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=backfill_expired_at&expire_minutes=5'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert(getTrans('error.unknown', '操作失败') + ': ' + data.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-clock"></i> ' + getTrans('admin.orders.backfill_btn', '回填过期时间（5分钟）');
            }
        })
        .catch(error => {
            alert(getTrans('error.unknown', '请求失败') + ': ' + error.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-clock"></i> ' + getTrans('admin.orders.backfill_btn', '回填过期时间（5分钟）');
        });
    });
    
    // 批量取消过期订单
    document.getElementById('cancelExpiredBtn')?.addEventListener('click', function() {
        if (!confirm(getTrans('admin.orders.cancel_confirm', '确定要批量取消所有过期的待支付订单吗？此操作不可撤销。'))) {
            return;
        }
        
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + getTrans('form.processing', '处理中...');
        
        fetch('api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=cancel_expired_orders'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(getTrans('admin.orders.cancelled_count', {count: data.data.cancelled_count}) || ('成功取消 ' + data.data.cancelled_count + ' 个过期订单'));
                location.reload();
            } else {
                alert(getTrans('error.unknown', '操作失败') + ': ' + data.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-trash-alt"></i> ' + getTrans('admin.orders.cancel_expired', '批量取消过期订单');
            }
        })
        .catch(error => {
            alert(getTrans('error.unknown', '请求失败') + ': ' + error.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash-alt"></i> ' + getTrans('admin.orders.cancel_expired', '批量取消过期订单');
        });
    });
    </script>
    
    <style>
    .badge-expired {
        background: #6c757d;
        color: white;
    }
    .btn-sm {
        padding: 6px 12px;
        font-size: 0.85rem;
    }
    .btn-warning {
        background: #ffc107;
        color: #212529;
        border: none;
    }
    .btn-warning:hover {
        background: #e0a800;
    }
    .btn-info {
        background: #17a2b8;
        color: white;
        border: none;
    }
    .btn-info:hover {
        background: #138496;
    }
    </style>
</body>
</html>
