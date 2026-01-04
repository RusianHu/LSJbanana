<?php
/**
 * 管理后台统一 API 接口
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../admin_auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

$adminAuth = getAdminAuth();

// API 权限验证
if (!$adminAuth->requireAuthApi()) {
    exit;
}

$db = Database::getInstance();
$auth = getAuth();

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
                jsonResponse(false, '无效的用户ID');
            }

            $user = $db->getUserById($userId);
            if (!$user) {
                jsonResponse(false, '用户不存在');
            }

            $stats = $db->getUserRechargeStats($userId);

            jsonResponse(true, '获取成功', [
                'user' => $user,
                'stats' => $stats
            ]);
            break;

        case 'update_user_email':
            $userId = (int)($_POST['user_id'] ?? 0);
            $email = trim($_POST['email'] ?? '');

            if ($userId <= 0) {
                jsonResponse(false, '无效的用户ID');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                jsonResponse(false, '邮箱格式不正确');
            }

            // 检查邮箱是否已被使用
            $existingUser = $db->getUserByEmail($email);
            if ($existingUser && $existingUser['id'] != $userId) {
                jsonResponse(false, '该邮箱已被其他用户使用');
            }

            $result = $db->updateUserEmail($userId, $email);

            if ($result) {
                // 记录操作日志
                $db->logAdminOperation('user_edit', $userId, [
                    'field' => 'email',
                    'new_value' => $email
                ], getClientIp());

                jsonResponse(true, '邮箱修改成功');
            } else {
                jsonResponse(false, '邮箱修改失败');
            }
            break;

        case 'toggle_user_status':
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) {
                jsonResponse(false, '无效的用户ID');
            }

            $user = $db->getUserById($userId);
            if (!$user) {
                jsonResponse(false, '用户不存在');
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

                $message = $newStatus == 1 ? '用户已启用' : '用户已禁用';
                jsonResponse(true, $message);
            } else {
                jsonResponse(false, '状态切换失败');
            }
            break;

        case 'search_users':
            $keyword = trim($_POST['keyword'] ?? '');
            if (empty($keyword)) {
                jsonResponse(false, '请输入搜索关键词');
            }

            // 尝试搜索用户 (ID、用户名、邮箱)
            $users = $db->getAllUsers(10, 0, $keyword, null);

            jsonResponse(true, '搜索成功', ['users' => $users]);
            break;

        // ============================================================
        // 余额管理相关
        // ============================================================

        case 'add_balance':
            $userId = (int)($_POST['user_id'] ?? 0);
            $amount = floatval($_POST['amount'] ?? 0);
            $remark = trim($_POST['remark'] ?? '');

            if ($userId <= 0) {
                jsonResponse(false, '无效的用户ID');
            }

            if ($amount <= 0) {
                jsonResponse(false, '充值金额必须大于0');
            }

            if (empty($remark)) {
                jsonResponse(false, '请填写充值备注');
            }

            $user = $db->getUserById($userId);
            if (!$user) {
                jsonResponse(false, '用户不存在');
            }

            // 更新用户余额
            $newBalance = $user['balance'] + $amount;
            $result = $db->execute(
                "UPDATE users SET balance = :balance WHERE id = :id",
                ['balance' => $newBalance, 'id' => $userId]
            );

            if ($result) {
                // 记录充值日志
                $db->execute(
                    "INSERT INTO balance_logs (user_id, type, amount, balance_before, balance_after, remark, created_at)
                     VALUES (:user_id, 'recharge', :amount, :before, :after, :remark, :created_at)",
                    [
                        'user_id' => $userId,
                        'amount' => $amount,
                        'before' => $user['balance'],
                        'after' => $newBalance,
                        'remark' => '管理员人工充值: ' . $remark,
                        'created_at' => date('Y-m-d H:i:s')
                    ]
                );

                // 记录管理操作日志
                $db->logAdminOperation('balance_add', $userId, [
                    'amount' => $amount,
                    'remark' => $remark,
                    'balance_before' => $user['balance'],
                    'balance_after' => $newBalance
                ], getClientIp());

                jsonResponse(true, '充值成功', [
                    'new_balance' => $newBalance
                ]);
            } else {
                jsonResponse(false, '充值失败');
            }
            break;

        case 'deduct_balance':
            $userId = (int)($_POST['user_id'] ?? 0);
            $amount = floatval($_POST['amount'] ?? 0);
            $remark = trim($_POST['remark'] ?? '');

            if ($userId <= 0) {
                jsonResponse(false, '无效的用户ID');
            }

            if ($amount <= 0) {
                jsonResponse(false, '扣款金额必须大于0');
            }

            if (empty($remark)) {
                jsonResponse(false, '请填写扣款原因');
            }

            $user = $db->getUserById($userId);
            if (!$user) {
                jsonResponse(false, '用户不存在');
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
                        'remark' => '管理员人工扣款: ' . $remark,
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

                jsonResponse(true, '扣款成功', [
                    'new_balance' => $newBalance
                ]);
            } else {
                jsonResponse(false, '扣款失败');
            }
            break;

        // ============================================================
        // 密码管理相关
        // ============================================================

        case 'reset_password':
            $userId = (int)($_POST['user_id'] ?? 0);
            $newPassword = trim($_POST['new_password'] ?? '');

            if ($userId <= 0) {
                jsonResponse(false, '无效的用户ID');
            }

            if (strlen($newPassword) < 6) {
                jsonResponse(false, '密码长度不能少于6位');
            }

            $result = $auth->resetPasswordByAdmin($userId, $newPassword);

            if ($result['success']) {
                // 记录管理操作日志
                $db->logAdminOperation('password_reset', $userId, [
                    'method' => 'admin_reset'
                ], getClientIp());

                jsonResponse(true, $result['message']);
            } else {
                jsonResponse(false, $result['message']);
            }
            break;

        case 'generate_temp_password':
            $length = (int)($_POST['length'] ?? 12);
            $length = max(8, min(32, $length));

            $tempPassword = $auth->generateTempPassword($length);

            jsonResponse(true, '临时密码生成成功', [
                'password' => $tempPassword
            ]);
            break;

        // ============================================================
        // 数据查询相关
        // ============================================================

        case 'get_statistics':
            $stats = $db->getStatistics();
            jsonResponse(true, '获取成功', $stats);
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

            jsonResponse(true, '获取成功', [
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
                jsonResponse(true, '获取成功', $logs);
            } elseif ($type === 'login') {
                // 登录日志
                $logs = $db->query(
                    "SELECT * FROM admin_login_attempts ORDER BY attempt_time DESC LIMIT :limit OFFSET :offset",
                    ['limit' => $perPage, 'offset' => $offset]
                );
                jsonResponse(true, '获取成功', ['logs' => $logs]);
            } else {
                jsonResponse(false, '无效的日志类型');
            }
            break;

        default:
            jsonResponse(false, '未知的操作: ' . $action);
    }
} catch (Exception $e) {
    error_log('Admin API Error: ' . $e->getMessage());
    jsonResponse(false, '服务器错误: ' . $e->getMessage());
}
