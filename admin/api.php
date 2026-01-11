<?php
/**
 * 管理后台统一 API 接口
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../admin_auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../i18n/I18n.php';

$adminAuth = getAdminAuth();

// API 权限验证
if (!$adminAuth->requireAuthApi()) {
    exit;
}

$db = Database::getInstance();
$auth = getAuth();

// 确保数据库迁移已执行
$db->migrateBalanceLogsVisibility();

// 获取客户端IP
function getClientIp(): string {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// 响应函数
function jsonResponse(bool $success, string $message, $data = null): void {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取 POST 参数
$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        // ============================================================
        // 用户管理相关
        // ============================================================

        case 'get_user_detail':
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) {
                jsonResponse(false, __('admin.users.invalid_id'));
            }

            $user = $db->getUserById($userId);
            if (!$user) {
                jsonResponse(false, __('error.user_not_found'));
            }

            $stats = $db->getUserRechargeStats($userId);

            jsonResponse(true, __('status.success'), [
                'user' => $user,
                'stats' => $stats
            ]);
            break;

        // ============================================================
        // 用户操作记录查询
        // ============================================================

        case 'get_user_login_logs':
            $userId = (int)($_POST['user_id'] ?? 0);
            $page = max(1, (int)($_POST['page'] ?? 1));
            $perPage = min(50, max(1, (int)($_POST['per_page'] ?? 10)));
            $offset = ($page - 1) * $perPage;

            if ($userId <= 0) {
                jsonResponse(false, '无效的用户ID');
            }

            $result = $db->getUserLoginLogs($userId, $perPage, $offset);
            
            jsonResponse(true, __('status.success'), [
                'logs' => $result['logs'],
                'total' => $result['total'],
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($result['total'] / $perPage)
            ]);
            break;

        case 'get_user_consumption_logs':
            $userId = (int)($_POST['user_id'] ?? 0);
            $page = max(1, (int)($_POST['page'] ?? 1));
            $perPage = min(50, max(1, (int)($_POST['per_page'] ?? 10)));
            $offset = ($page - 1) * $perPage;

            if ($userId <= 0) {
                jsonResponse(false, '无效的用户ID');
            }

            $result = $db->getUserConsumptionLogsPaginated($userId, $perPage, $offset);
            
            jsonResponse(true, __('status.success'), [
                'logs' => $result['logs'],
                'total' => $result['total'],
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($result['total'] / $perPage)
            ]);
            break;

        case 'get_user_balance_logs':
            $userId = (int)($_POST['user_id'] ?? 0);
            $page = max(1, (int)($_POST['page'] ?? 1));
            $perPage = min(50, max(1, (int)($_POST['per_page'] ?? 10)));
            $offset = ($page - 1) * $perPage;

            if ($userId <= 0) {
                jsonResponse(false, '无效的用户ID');
            }

            $result = $db->getUserBalanceLogs($userId, $perPage, $offset);
            
            jsonResponse(true, __('status.success'), [
                'logs' => $result['logs'],
                'total' => $result['total'],
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($result['total'] / $perPage)
            ]);
            break;

        case 'get_user_recharge_orders':
            $userId = (int)($_POST['user_id'] ?? 0);
            $page = max(1, (int)($_POST['page'] ?? 1));
            $perPage = min(50, max(1, (int)($_POST['per_page'] ?? 10)));
            $offset = ($page - 1) * $perPage;
            $includeAll = ($_POST['include_all'] ?? 'true') === 'true';

            if ($userId <= 0) {
                jsonResponse(false, '无效的用户ID');
            }

            $result = $db->getUserRechargeOrdersPaginated($userId, $perPage, $offset, $includeAll);
            
            jsonResponse(true, __('status.success'), [
                'orders' => $result['orders'],
                'total' => $result['total'],
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($result['total'] / $perPage)
            ]);
            break;

        case 'update_user_email':
            $userId = (int)($_POST['user_id'] ?? 0);
            $email = trim($_POST['email'] ?? '');

            if ($userId <= 0) {
                jsonResponse(false, __('admin.users.invalid_id'));
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                jsonResponse(false, __('validation.email_invalid'));
            }

            // 检查邮箱是否已被使用
            $existingUser = $db->getUserByEmail($email);
            if ($existingUser && $existingUser['id'] != $userId) {
                jsonResponse(false, __('auth.error.email_exists'));
            }

            $result = $db->updateUserEmail($userId, $email);

            if ($result) {
                // 记录操作日志
                $db->logAdminOperation('user_edit', $userId, [
                    'field' => 'email',
                    'new_value' => $email
                ], getClientIp());

                jsonResponse(true, __('admin.email_updated'));
            } else {
                jsonResponse(false, __('error.unknown'));
            }
            break;

        case 'toggle_user_status':
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) {
                jsonResponse(false, __('admin.users.invalid_id'));
            }

            $user = $db->getUserById($userId);
            if (!$user) {
                jsonResponse(false, __('error.user_not_found'));
            }

            $result = $db->toggleUserStatus($userId);

            if ($result) {
                $newStatus = $user['status'] == 1 ? 0 : 1;
                $opType = $newStatus == 1 ? 'user_enable' : 'user_disable';

                // 记录操作日志
                $db->logAdminOperation($opType, $userId, [
                    'old_status' => $user['status'],
                    'new_status' => $newStatus
                ], getClientIp());

                $message = $newStatus == 1 ? __('admin.user_enabled') : __('admin.user_disabled');
                jsonResponse(true, $message);
            } else {
                jsonResponse(false, __('error.unknown'));
            }
            break;

        case 'search_users':
            $keyword = trim($_POST['keyword'] ?? '');
            if (empty($keyword)) {
                jsonResponse(false, __('form.search') . '...');
            }

            // 尝试搜索用户 (ID、用户名、邮箱)
            $users = $db->getAllUsers(10, 0, $keyword, null);

            jsonResponse(true, __('status.success'), ['users' => $users]);
            break;

        // ============================================================
        // 余额管理相关
        // ============================================================

        case 'add_balance':
            $userId = (int)($_POST['user_id'] ?? 0);
            $amount = floatval($_POST['amount'] ?? 0);
            $remark = trim($_POST['remark'] ?? '');
            $visibleToUser = (int)($_POST['visible_to_user'] ?? 0);
            $userRemark = trim($_POST['user_remark'] ?? '');

            if ($userId <= 0) {
                jsonResponse(false, __('admin.users.invalid_id'));
            }

            if ($amount <= 0) {
                jsonResponse(false, __('validation.amount_min', ['min' => 0.01]));
            }

            if (empty($remark)) {
                jsonResponse(false, __('admin.balance.remark') . ' ' . __('validation.required'));
            }

            // 如果选择显示给用户但没有填写用户可见说明，使用默认值
            if ($visibleToUser && empty($userRemark)) {
                $userRemark = __('recharge.source_manual');
            }

            $user = $db->getUserById($userId);
            if (!$user) {
                jsonResponse(false, __('error.user_not_found'));
            }

            // 更新用户余额
            $newBalance = $user['balance'] + $amount;
            $result = $db->execute(
                "UPDATE users SET balance = :balance WHERE id = :id",
                ['balance' => $newBalance, 'id' => $userId]
            );

            if ($result) {
                // 记录充值日志（包含可见性设置）
                $db->execute(
                    "INSERT INTO balance_logs (user_id, type, amount, balance_before, balance_after, remark, visible_to_user, user_remark, created_at)
                     VALUES (:user_id, 'recharge', :amount, :before, :after, :remark, :visible_to_user, :user_remark, :created_at)",
                    [
                        'user_id' => $userId,
                        'amount' => $amount,
                        'before' => $user['balance'],
                        'after' => $newBalance,
                        'remark' => $remark,
                        'visible_to_user' => $visibleToUser,
                        'user_remark' => $userRemark ?: null,
                        'created_at' => date('Y-m-d H:i:s')
                    ]
                );

                // 记录管理操作日志
                $db->logAdminOperation('balance_add', $userId, [
                    'amount' => $amount,
                    'remark' => $remark,
                    'visible_to_user' => $visibleToUser,
                    'user_remark' => $userRemark,
                    'balance_before' => $user['balance'],
                    'balance_after' => $newBalance
                ], getClientIp());

                jsonResponse(true, __('admin.balance_added'), [
                    'new_balance' => $newBalance
                ]);
            } else {
                jsonResponse(false, __('error.unknown'));
            }
            break;

        case 'deduct_balance':
            $userId = (int)($_POST['user_id'] ?? 0);
            $amount = floatval($_POST['amount'] ?? 0);
            $remark = trim($_POST['remark'] ?? '');

            if ($userId <= 0) {
                jsonResponse(false, __('admin.users.invalid_id'));
            }

            if ($amount <= 0) {
                jsonResponse(false, __('validation.amount_min', ['min' => 0.01]));
            }

            if (empty($remark)) {
                jsonResponse(false, __('admin.balance.remark') . ' ' . __('validation.required'));
            }

            $user = $db->getUserById($userId);
            if (!$user) {
                jsonResponse(false, __('error.user_not_found'));
            }

            // 更新用户余额(允许为负)
            $newBalance = $user['balance'] - $amount;
            $result = $db->execute(
                "UPDATE users SET balance = :balance WHERE id = :id",
                ['balance' => $newBalance, 'id' => $userId]
            );

            if ($result) {
                // 记录扣款日志
                $db->execute(
                    "INSERT INTO balance_logs (user_id, type, amount, balance_before, balance_after, remark, created_at)
                     VALUES (:user_id, 'deduct', :amount, :before, :after, :remark, :created_at)",
                    [
                        'user_id' => $userId,
                        'amount' => -$amount,
                        'before' => $user['balance'],
                        'after' => $newBalance,
                        'remark' => __('admin.balance_deducted') . ': ' . $remark,
                        'created_at' => date('Y-m-d H:i:s')
                    ]
                );

                // 记录管理操作日志
                $db->logAdminOperation('balance_deduct', $userId, [
                    'amount' => $amount,
                    'remark' => $remark,
                    'balance_before' => $user['balance'],
                    'balance_after' => $newBalance
                ], getClientIp());

                jsonResponse(true, __('admin.balance_deducted'), [
                    'new_balance' => $newBalance
                ]);
            } else {
                jsonResponse(false, __('error.unknown'));
            }
            break;

        // ============================================================
        // 密码管理相关
        // ============================================================

        case 'reset_password':
            $userId = (int)($_POST['user_id'] ?? 0);
            $newPassword = trim($_POST['new_password'] ?? '');

            if ($userId <= 0) {
                jsonResponse(false, __('admin.users.invalid_id'));
            }

            if (strlen($newPassword) < 6) {
                jsonResponse(false, __('validation.password_min_length', ['min' => 6]));
            }

            $result = $auth->resetPasswordByAdmin($userId, $newPassword);

            if ($result['success']) {
                // 记录管理操作日志
                $db->logAdminOperation('password_reset', $userId, [
                    'method' => 'admin_reset'
                ], getClientIp());

                jsonResponse(true, __('admin.password_reset'));
            } else {
                jsonResponse(false, __('password.error.reset_failed'));
            }
            break;

        case 'generate_temp_password':
            $length = (int)($_POST['length'] ?? 12);
            $length = max(8, min(32, $length));

            $tempPassword = $auth->generateTempPassword($length);

            jsonResponse(true, __('status.success'), [
                'password' => $tempPassword
            ]);
            break;

        // ============================================================
        // 数据查询相关
        // ============================================================

        case 'get_statistics':
            $stats = $db->getStatistics();
            jsonResponse(true, __('status.success'), $stats);
            break;

        case 'get_orders':
            $page = max(1, (int)($_POST['page'] ?? 1));
            $perPage = 20;
            $offset = ($page - 1) * $perPage;

            $status = $_POST['status'] ?? null;
            $search = trim($_POST['search'] ?? '');

            $query = "SELECT * FROM recharge_orders WHERE 1=1";
            $params = [];

            if ($status && $status !== 'all') {
                $query .= " AND status = :status";
                $params['status'] = $status;
            }

            if ($search) {
                $query .= " AND (trade_no LIKE :search OR user_id = :user_id)";
                $params['search'] = "%{$search}%";
                $params['user_id'] = is_numeric($search) ? (int)$search : 0;
            }

            $query .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
            $params['limit'] = $perPage;
            $params['offset'] = $offset;

            $orders = $db->query($query, $params);

            // 获取总数
            $countQuery = str_replace('SELECT *', 'SELECT COUNT(*) as total', explode('ORDER BY', $query)[0]);
            $countParams = $params;
            unset($countParams['limit'], $countParams['offset']);
            $totalResult = $db->query($countQuery, $countParams);
            $total = $totalResult[0]['total'] ?? 0;

            jsonResponse(true, __('status.success'), [
                'orders' => $orders,
                'total' => $total,
                'total_pages' => ceil($total / $perPage)
            ]);
            break;

        case 'get_logs':
            $page = max(1, (int)($_POST['page'] ?? 1));
            $perPage = 50;
            $offset = ($page - 1) * $perPage;

            $type = $_POST['type'] ?? 'admin';
            $filters = [];

            if ($type === 'admin') {
                // 管理操作日志
                $logs = $db->getAdminOperationLogs($perPage, $offset, $filters);
                jsonResponse(true, __('status.success'), $logs);
            } elseif ($type === 'login') {
                // 登录日志
                $logs = $db->query(
                    "SELECT * FROM admin_login_attempts ORDER BY attempt_time DESC LIMIT :limit OFFSET :offset",
                    ['limit' => $perPage, 'offset' => $offset]
                );
                jsonResponse(true, __('status.success'), ['logs' => $logs]);
            } else {
                jsonResponse(false, __('error.unknown'));
            }
            break;

        // ============================================================
        // 订单管理相关
        // ============================================================

        case 'cancel_expired_orders':
            // 批量取消过期订单
            $cancelledCount = $db->cancelExpiredOrders(1000);  // 每次最多处理1000个
            
            // 记录操作日志
            $db->logAdminOperation('cancel_expired_orders', null, [
                'cancelled_count' => $cancelledCount
            ], getClientIp());
            
            jsonResponse(true, "已取消 {$cancelledCount} 个过期订单", [
                'cancelled_count' => $cancelledCount
            ]);
            break;

        case 'cancel_order':
            // 取消单个订单
            $outTradeNo = trim($_POST['out_trade_no'] ?? '');
            if (empty($outTradeNo)) {
                jsonResponse(false, '订单号不能为空');
            }
            
            $order = $db->getRechargeOrderByOutTradeNo($outTradeNo);
            if (!$order) {
                jsonResponse(false, '订单不存在');
            }
            
            if ((int)$order['status'] !== 0) {
                jsonResponse(false, '只能取消待支付订单');
            }
            
            $result = $db->cancelOrder($outTradeNo);
            if ($result) {
                // 记录操作日志
                $db->logAdminOperation('cancel_order', $order['user_id'], [
                    'out_trade_no' => $outTradeNo,
                    'amount' => $order['amount']
                ], getClientIp());
                
                jsonResponse(true, '订单已取消');
            } else {
                jsonResponse(false, '取消失败');
            }
            break;

        case 'get_expired_order_count':
            // 获取过期订单数量
            $count = $db->getExpiredPendingOrderCount();
            jsonResponse(true, '获取成功', ['count' => $count]);
            break;

        case 'backfill_expired_at':
            // 手动为旧订单回填过期时间
            $expireMinutes = (int)($_POST['expire_minutes'] ?? 5);
            if ($expireMinutes <= 0) {
                $expireMinutes = 5;
            }
            
            $result = $db->manualBackfillExpiredAt($expireMinutes);
            
            if ($result['updated_count'] > 0) {
                // 记录操作日志
                $db->logAdminOperation('backfill_expired_at', null, [
                    'expire_minutes' => $expireMinutes,
                    'updated_count' => $result['updated_count']
                ], getClientIp());
            }
            
            jsonResponse($result['success'], $result['message'], [
                'updated_count' => $result['updated_count']
            ]);
            break;

        case 'get_orders_without_expires':
            // 获取没有过期时间的待支付订单数量
            $count = $db->getPendingOrdersWithoutExpiresAt();
            jsonResponse(true, '获取成功', ['count' => $count]);
            break;

        default:
            jsonResponse(false, '未知的操作: ' . $action);
    }
} catch (Exception $e) {
    error_log('Admin API Error: ' . $e->getMessage());
    jsonResponse(false, '服务器错误: ' . $e->getMessage());
}
