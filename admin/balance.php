<?php
/**
 * 管理后台 - 余额管理
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

// 获取最近的余额操作记录
$recentOps = $db->getAdminOperationLogs(50, 0, [
    'operation_types' => ['balance_add', 'balance_deduct']
]);

// 辅助函数
function formatAmount($amount): string {
    return number_format((float)$amount, 2);
}

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
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>余额管理 - 管理后台</title>
    <link rel="stylesheet" href="<?php echo url('/admin/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="admin-body">
    <?php include 'sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>
                <i class="fas fa-wallet"></i>
                余额管理
            </h1>
            <div class="admin-user-info">
                <i class="fas fa-user-shield"></i>
                <span>管理员</span>
            </div>
        </div>

        <div class="admin-content">
            <!-- 操作面板 -->
            <div class="panel">
                <div class="panel-header">
                    <h3><i class="fas fa-plus-circle"></i> 人工充值</h3>
                </div>
                <div class="panel-body">
                    <form id="rechargeForm">
                        <div class="form-group">
                            <label for="rechargeUserId">
                                <i class="fas fa-user"></i> 用户ID 或 用户名
                            </label>
                            <div style="display: flex; gap: 10px;">
                                <input
                                    type="text"
                                    id="rechargeUserInput"
                                    class="form-control"
                                    placeholder="输入用户ID或用户名搜索..."
                                    style="flex: 1;"
                                    required
                                >
                                <button type="button" class="btn btn-secondary" onclick="searchUser('recharge')">
                                    <i class="fas fa-search"></i> 搜索
                                </button>
                            </div>
                            <input type="hidden" id="rechargeUserId" name="user_id" required>
                            <div id="rechargeUserInfo" class="text-muted mt-10"></div>
                        </div>

                        <div class="form-group">
                            <label for="rechargeAmount">
                                <i class="fas fa-money-bill"></i> 充值金额 (RMB)
                            </label>
                            <input
                                type="number"
                                id="rechargeAmount"
                                name="amount"
                                class="form-control"
                                step="0.01"
                                min="0.01"
                                placeholder="0.00"
                                required
                            >
                            <div class="mt-10">
                                <button type="button" class="btn btn-sm" onclick="setAmount('recharge', 10)">+10</button>
                                <button type="button" class="btn btn-sm" onclick="setAmount('recharge', 50)">+50</button>
                                <button type="button" class="btn btn-sm" onclick="setAmount('recharge', 100)">+100</button>
                                <button type="button" class="btn btn-sm" onclick="setAmount('recharge', 500)">+500</button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="rechargeRemark">
                                <i class="fas fa-comment"></i> 备注说明（仅管理员可见）
                            </label>
                            <textarea
                                id="rechargeRemark"
                                name="remark"
                                class="form-control"
                                rows="3"
                                placeholder="请填写充值原因或备注..."
                                required
                            ></textarea>
                        </div>

                        <div class="form-group">
                            <label class="checkbox-label" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: normal;">
                                <input
                                    type="checkbox"
                                    id="rechargeVisibleToUser"
                                    name="visible_to_user"
                                    style="width: auto;"
                                    onchange="toggleUserRemarkField('recharge')"
                                >
                                <span><i class="fas fa-eye"></i> 显示给用户</span>
                            </label>
                            <small class="text-muted">勾选后，此充值记录将在用户的充值记录中显示</small>
                        </div>

                        <div class="form-group" id="rechargeUserRemarkGroup" style="display: none;">
                            <label for="rechargeUserRemark">
                                <i class="fas fa-user"></i> 用户可见说明
                            </label>
                            <input
                                type="text"
                                id="rechargeUserRemark"
                                name="user_remark"
                                class="form-control"
                                placeholder="用户将看到此说明，如：活动奖励、系统赠送"
                            >
                            <small class="text-muted">留空则显示"系统调整"</small>
                        </div>

                        <button type="submit" class="btn btn-success btn-block">
                            <i class="fas fa-check"></i> 确认充值
                        </button>
                    </form>
                </div>
            </div>

            <!-- 扣款面板 -->
            <div class="panel">
                <div class="panel-header">
                    <h3><i class="fas fa-minus-circle"></i> 人工扣款</h3>
                </div>
                <div class="panel-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>警告:</strong> 扣款操作不可撤销,请谨慎操作!
                    </div>

                    <form id="deductForm">
                        <div class="form-group">
                            <label for="deductUserId">
                                <i class="fas fa-user"></i> 用户ID 或 用户名
                            </label>
                            <div style="display: flex; gap: 10px;">
                                <input
                                    type="text"
                                    id="deductUserInput"
                                    class="form-control"
                                    placeholder="输入用户ID或用户名搜索..."
                                    style="flex: 1;"
                                    required
                                >
                                <button type="button" class="btn btn-secondary" onclick="searchUser('deduct')">
                                    <i class="fas fa-search"></i> 搜索
                                </button>
                            </div>
                            <input type="hidden" id="deductUserId" name="user_id" required>
                            <div id="deductUserInfo" class="text-muted mt-10"></div>
                        </div>

                        <div class="form-group">
                            <label for="deductAmount">
                                <i class="fas fa-money-bill"></i> 扣款金额 (RMB)
                            </label>
                            <input
                                type="number"
                                id="deductAmount"
                                name="amount"
                                class="form-control"
                                step="0.01"
                                min="0.01"
                                placeholder="0.00"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="deductRemark">
                                <i class="fas fa-comment"></i> 扣款原因 (必填)
                            </label>
                            <textarea
                                id="deductRemark"
                                name="remark"
                                class="form-control"
                                rows="3"
                                placeholder="请详细说明扣款原因..."
                                required
                            ></textarea>
                        </div>

                        <button type="submit" class="btn btn-danger btn-block">
                            <i class="fas fa-exclamation-triangle"></i> 确认扣款 (不可撤销)
                        </button>
                    </form>
                </div>
            </div>

            <!-- 操作记录 -->
            <div class="panel">
                <div class="panel-header">
                    <h3><i class="fas fa-history"></i> 最近操作记录</h3>
                </div>
                <div class="panel-body">
                    <?php if (!empty($recentOps['logs'])): ?>
                        <div class="admin-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>操作类型</th>
                                        <th>目标用户</th>
                                        <th>金额</th>
                                        <th>备注</th>
                                        <th>操作IP</th>
                                        <th>时间</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentOps['logs'] as $log): ?>
                                        <?php
                                        $details = json_decode($log['details'], true);
                                        $amount = $details['amount'] ?? 0;
                                        $remark = $details['remark'] ?? '-';
                                        ?>
                                        <tr>
                                            <td>
                                                <?php if ($log['operation_type'] === 'balance_add'): ?>
                                                    <span class="badge badge-success">
                                                        <i class="fas fa-plus-circle"></i> 充值
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger">
                                                        <i class="fas fa-minus-circle"></i> 扣款
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>#<?php echo $log['target_user_id']; ?></td>
                                            <td>
                                                <span class="<?php echo $log['operation_type'] === 'balance_add' ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo $log['operation_type'] === 'balance_add' ? '+' : '-'; ?>¥<?php echo formatAmount($amount); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($remark); ?></td>
                                            <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                            <td><?php echo formatTime($log['created_at']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted text-center">暂无操作记录</p>
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
    <script>
        let currentUserData = {
            recharge: null,
            deduct: null
        };

        // 搜索用户
        async function searchUser(type) {
            const input = document.getElementById(`${type}UserInput`).value.trim();
            const infoDiv = document.getElementById(`${type}UserInfo`);
            const userIdInput = document.getElementById(`${type}UserId`);

            if (!input) {
                showToast('请输入用户ID或用户名', 'danger');
                return;
            }

            infoDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 搜索中...';

            const result = await apiRequest('search_users', { keyword: input });

            if (result.success && result.data.users && result.data.users.length > 0) {
                const user = result.data.users[0];
                currentUserData[type] = user;
                userIdInput.value = user.id;

                infoDiv.innerHTML = `
                    <div class="alert alert-info">
                        <strong>已选择用户:</strong> #${user.id} - ${user.username} (${user.email})<br>
                        <strong>当前余额:</strong> ¥${parseFloat(user.balance).toFixed(2)}
                    </div>
                `;
            } else {
                infoDiv.innerHTML = '<div class="alert alert-danger">未找到匹配的用户</div>';
                userIdInput.value = '';
                currentUserData[type] = null;
            }
        }

        // 快速设置金额
        function setAmount(type, amount) {
            const input = document.getElementById(`${type}Amount`);
            const currentValue = parseFloat(input.value) || 0;
            input.value = (currentValue + amount).toFixed(2);
        }

        // 充值表单提交
        document.getElementById('rechargeForm').addEventListener('submit', async (e) => {
            e.preventDefault();

            const userId = document.getElementById('rechargeUserId').value;
            const amount = document.getElementById('rechargeAmount').value;
            const remark = document.getElementById('rechargeRemark').value;
            const visibleToUser = document.getElementById('rechargeVisibleToUser').checked ? 1 : 0;
            const userRemark = document.getElementById('rechargeUserRemark').value;

            if (!userId) {
                showToast('请先搜索并选择用户', 'danger');
                return;
            }

            await addBalance(userId, amount, remark, visibleToUser, userRemark);

            // 重置表单
            e.target.reset();
            document.getElementById('rechargeUserInfo').innerHTML = '';
            document.getElementById('rechargeUserId').value = '';
            document.getElementById('rechargeUserRemarkGroup').style.display = 'none';
            currentUserData.recharge = null;
        });

        // 扣款表单提交
        document.getElementById('deductForm').addEventListener('submit', async (e) => {
            e.preventDefault();

            const userId = document.getElementById('deductUserId').value;
            const amount = document.getElementById('deductAmount').value;
            const remark = document.getElementById('deductRemark').value;

            if (!userId) {
                showToast('请先搜索并选择用户', 'danger');
                return;
            }

            const user = currentUserData.deduct;
            if (user && parseFloat(amount) > parseFloat(user.balance)) {
                if (!window.confirm(`用户余额不足(¥${user.balance}),扣款后将变为负数。确定继续?`)) {
                    return;
                }
            }

            await deductBalance(userId, amount, remark);

            // 重置表单
            e.target.reset();
            document.getElementById('deductUserInfo').innerHTML = '';
            document.getElementById('deductUserId').value = '';
            currentUserData.deduct = null;
        });

        // Enter键搜索
        document.getElementById('rechargeUserInput').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchUser('recharge');
            }
        });

        document.getElementById('deductUserInput').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchUser('deduct');
            }
        });

        // 切换用户可见说明输入框的显示/隐藏
        function toggleUserRemarkField(type) {
            const checkbox = document.getElementById(`${type}VisibleToUser`);
            const remarkGroup = document.getElementById(`${type}UserRemarkGroup`);
            if (checkbox && remarkGroup) {
                remarkGroup.style.display = checkbox.checked ? 'block' : 'none';
            }
        }
    </script>
</body>
</html>
