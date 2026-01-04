<?php
/**
 * 管理后台 - 订单管理
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
            return ['success', '已支付'];
        case 2:
            return ['danger', '已取消'];
        case 3:
            return ['info', '已退款'];
        case 0:
        default:
            // 检查是否过期
            if ($expiresAt && strtotime($expiresAt) < time()) {
                return ['expired', '已过期'];
            }
            return ['warning', '待支付'];
    }
}

function payTypeLabel(?string $payType): string {
    $map = [
        'alipay' => '支付宝',
        'wxpay' => '微信支付',
        'qqpay' => 'QQ钱包',
    ];
    return $map[$payType] ?? ($payType ?: '收银台');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>订单管理 - 管理后台</title>
    <link rel="stylesheet" href="<?php echo url('/admin/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="admin-body">
    <?php include 'sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>
                <i class="fas fa-receipt"></i>
                订单管理
            </h1>
            <div class="admin-user-info">
                <i class="fas fa-user-shield"></i>
                <span>管理员</span>
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
                                placeholder="搜索商户单号/平台单号/用户ID..."
                                value="<?php echo htmlspecialchars($search); ?>"
                            >
                            <select name="status">
                                <option value="">全部状态</option>
                                <option value="0" <?php echo $status === '0' ? 'selected' : ''; ?>>待支付</option>
                                <option value="expired" <?php echo $status === 'expired' ? 'selected' : ''; ?>>已过期 (<?php echo $expiredCount; ?>)</option>
                                <option value="1" <?php echo $status === '1' ? 'selected' : ''; ?>>已支付</option>
                                <option value="2" <?php echo $status === '2' ? 'selected' : ''; ?>>已取消</option>
                                <option value="3" <?php echo $status === '3' ? 'selected' : ''; ?>>已退款</option>
                            </select>
                            <select name="pay_type">
                                <option value="">全部支付方式</option>
                                <option value="alipay" <?php echo $payType === 'alipay' ? 'selected' : ''; ?>>支付宝</option>
                                <option value="wxpay" <?php echo $payType === 'wxpay' ? 'selected' : ''; ?>>微信支付</option>
                                <option value="qqpay" <?php echo $payType === 'qqpay' ? 'selected' : ''; ?>>QQ钱包</option>
                            </select>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> 搜索
                            </button>
                            <?php if ($search !== '' || $status !== '' || $payType !== ''): ?>
                                <a href="orders.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> 清除
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                    <div class="text-muted" style="margin-top: 8px;">
                        订单状态以支付回调为准，回调成功后才会标记为已支付并入账。
                    </div>
                    
                    <?php if ($ordersWithoutExpires > 0): ?>
                    <div class="backfill-actions" style="margin-top: 15px; padding: 15px; background: #d1ecf1; border-radius: 8px; border: 1px solid #17a2b8;">
                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <i class="fas fa-info-circle" style="color: #0c5460;"></i>
                                <strong style="color: #0c5460;">有 <?php echo $ordersWithoutExpires; ?> 个旧待支付订单没有过期时间</strong>
                                <span class="text-muted" style="margin-left: 10px;">这些是迁移前创建的订单，需要回填过期时间</span>
                            </div>
                            <button type="button" class="btn btn-info btn-sm" id="backfillBtn">
                                <i class="fas fa-clock"></i> 回填过期时间（5分钟）
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($expiredCount > 0): ?>
                    <div class="expired-actions" style="margin-top: 15px; padding: 15px; background: #fff3cd; border-radius: 8px; border: 1px solid #ffc107;">
                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <i class="fas fa-exclamation-triangle" style="color: #856404;"></i>
                                <strong style="color: #856404;">有 <?php echo $expiredCount; ?> 个过期的待支付订单</strong>
                                <span class="text-muted" style="margin-left: 10px;">过期订单不会被支付，建议定期清理</span>
                            </div>
                            <div style="display: flex; gap: 10px;">
                                <a href="?status=expired" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-eye"></i> 查看过期订单
                                </a>
                                <button type="button" class="btn btn-warning btn-sm" id="cancelExpiredBtn">
                                    <i class="fas fa-trash-alt"></i> 批量取消过期订单
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
                            <th>商户订单号</th>
                            <th>平台订单号</th>
                            <th>用户</th>
                            <th>金额</th>
                            <th>支付方式</th>
                            <th>状态</th>
                            <th>创建时间</th>
                            <th>支付时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted">暂无订单数据</td>
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
                                            <span class="text-muted">未知</span>
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
                            <i class="fas fa-chevron-left"></i> 上一页
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
                            下一页 <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // 回填旧订单过期时间
    document.getElementById('backfillBtn')?.addEventListener('click', function() {
        if (!confirm('确定要为所有旧待支付订单回填过期时间吗？回填后这些订单将被标记为过期。')) {
            return;
        }
        
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 处理中...';
        
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
                alert('操作失败: ' + data.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-clock"></i> 回填过期时间（5分钟）';
            }
        })
        .catch(error => {
            alert('请求失败: ' + error.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-clock"></i> 回填过期时间（5分钟）';
        });
    });
    
    // 批量取消过期订单
    document.getElementById('cancelExpiredBtn')?.addEventListener('click', function() {
        if (!confirm('确定要批量取消所有过期的待支付订单吗？此操作不可撤销。')) {
            return;
        }
        
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 处理中...';
        
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
                alert('成功取消 ' + data.data.cancelled_count + ' 个过期订单');
                location.reload();
            } else {
                alert('操作失败: ' + data.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-trash-alt"></i> 批量取消过期订单';
            }
        })
        .catch(error => {
            alert('请求失败: ' + error.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash-alt"></i> 批量取消过期订单';
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
