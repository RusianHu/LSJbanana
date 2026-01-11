<?php
/**
 * 管理后台 - 余额管理
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
?>
<!DOCTYPE html>
<html lang="<?php echo i18n()->getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php _e('admin.balance.title'); ?> - <?php _e('admin.title'); ?></title>
    <link rel="stylesheet" href="<?php echo url('/admin/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="admin-body">
    <?php include 'sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>
                <i class="fas fa-wallet"></i>
                <?php _e('admin.balance.title'); ?>
            </h1>
            <div class="admin-user-info">
                <i class="fas fa-user-shield"></i>
                <span><?php _e('admin.administrator'); ?></span>
            </div>
        </div>

        <div class="admin-content">
            <!-- 操作面板 -->
            <div class="panel">
                <div class="panel-header">
                    <h3><i class="fas fa-plus-circle"></i> <?php _e('admin.balance.manual_recharge'); ?></h3>
                </div>
                <div class="panel-body">
                    <form id="rechargeForm">
                        <div class="form-group">
                            <label for="rechargeUserId">
                                <i class="fas fa-user"></i> <?php _e('admin.balance.user_placeholder'); ?>
                            </label>
                            <div style="display: flex; gap: 10px;">
                                <input
                                    type="text"
                                    id="rechargeUserInput"
                                    class="form-control"
                                    placeholder="<?php _e('admin.balance.user_placeholder'); ?>"
                                    style="flex: 1;"
                                    required
                                >
                                <button type="button" class="btn btn-secondary" onclick="searchUser('recharge')">
                                    <i class="fas fa-search"></i> <?php _e('form.search'); ?>
                                </button>
                            </div>
                            <input type="hidden" id="rechargeUserId" name="user_id" required>
                            <div id="rechargeUserInfo" class="text-muted mt-10"></div>
                        </div>

                        <div class="form-group">
                            <label for="rechargeAmount">
                                <i class="fas fa-money-bill"></i> <?php _e('admin.balance.amount'); ?>
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
                                <i class="fas fa-comment"></i> <?php _e('admin.balance.remark_admin'); ?>
                            </label>
                            <textarea
                                id="rechargeRemark"
                                name="remark"
                                class="form-control"
                                rows="3"
                                placeholder="<?php _e('admin.balance.remark_placeholder'); ?>"
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
                                <span><i class="fas fa-eye"></i> <?php _e('admin.balance.visible_to_user'); ?></span>
                            </label>
                            <small class="text-muted"><?php _e('admin.balance.visible_hint'); ?></small>
                        </div>

                        <div class="form-group" id="rechargeUserRemarkGroup" style="display: none;">
                            <label for="rechargeUserRemark">
                                <i class="fas fa-user"></i> <?php _e('admin.balance.user_remark'); ?>
                            </label>
                            <input
                                type="text"
                                id="rechargeUserRemark"
                                name="user_remark"
                                class="form-control"
                                placeholder="<?php _e('admin.balance.user_remark_placeholder'); ?>"
                            >
                            <small class="text-muted"><?php _e('admin.balance.user_remark_default'); ?></small>
                        </div>

                        <button type="submit" class="btn btn-success btn-block">
                            <i class="fas fa-check"></i> <?php _e('admin.balance.confirm_recharge'); ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- 扣款面板 -->
            <div class="panel">
                <div class="panel-header">
                    <h3><i class="fas fa-minus-circle"></i> <?php _e('admin.balance.manual_deduct'); ?></h3>
                </div>
                <div class="panel-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong><?php _e('admin.warning'); ?>:</strong> <?php _e('admin.balance.deduct_warning'); ?>
                    </div>

                    <form id="deductForm">
                        <div class="form-group">
                            <label for="deductUserId">
                                <i class="fas fa-user"></i> <?php _e('admin.balance.user_placeholder'); ?>
                            </label>
                            <div style="display: flex; gap: 10px;">
                                <input
                                    type="text"
                                    id="deductUserInput"
                                    class="form-control"
                                    placeholder="<?php _e('admin.balance.user_placeholder'); ?>"
                                    style="flex: 1;"
                                    required
                                >
                                <button type="button" class="btn btn-secondary" onclick="searchUser('deduct')">
                                    <i class="fas fa-search"></i> <?php _e('form.search'); ?>
                                </button>
                            </div>
                            <input type="hidden" id="deductUserId" name="user_id" required>
                            <div id="deductUserInfo" class="text-muted mt-10"></div>
                        </div>

                        <div class="form-group">
                            <label for="deductAmount">
                                <i class="fas fa-money-bill"></i> <?php _e('admin.balance.deduct_amount'); ?>
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
                                <i class="fas fa-comment"></i> <?php _e('admin.balance.deduct_reason'); ?>
                            </label>
                            <textarea
                                id="deductRemark"
                                name="remark"
                                class="form-control"
                                rows="3"
                                placeholder="<?php _e('admin.balance.deduct_reason_placeholder'); ?>"
                                required
                            ></textarea>
                        </div>

                        <button type="submit" class="btn btn-danger btn-block">
                            <i class="fas fa-exclamation-triangle"></i> <?php _e('admin.balance.confirm_deduct'); ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- 操作记录 -->
            <div class="panel">
                <div class="panel-header">
                    <h3><i class="fas fa-history"></i> <?php _e('admin.dashboard.recent_ops'); ?></h3>
                </div>
                <div class="panel-body">
                    <?php if (!empty($recentOps['logs'])): ?>
                        <div class="admin-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th><?php _e('admin.table.op_type'); ?></th>
                                        <th><?php _e('admin.table.target_user'); ?></th>
                                        <th><?php _e('admin.table.amount'); ?></th>
                                        <th><?php _e('admin.table.remark'); ?></th>
                                        <th><?php _e('admin.table.ip'); ?></th>
                                        <th><?php _e('admin.table.time'); ?></th>
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
                                                        <i class="fas fa-plus-circle"></i> <?php _e('admin.balance.recharge'); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger">
                                                        <i class="fas fa-minus-circle"></i> <?php _e('admin.balance.deduct'); ?>
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
                        <p class="text-muted text-center"><?php _e('admin.dashboard.no_records'); ?></p>
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
                showToast(window.i18n.t('admin.balance.user_placeholder'), 'danger');
                return;
            }

            infoDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + window.i18n.t('form.loading');

            const result = await apiRequest('search_users', { keyword: input });

            if (result.success && result.data.users && result.data.users.length > 0) {
                const user = result.data.users[0];
                currentUserData[type] = user;
                userIdInput.value = user.id;

                infoDiv.innerHTML = `
                    <div class="alert alert-info">
                        <strong>${window.i18n.t('admin.balance.selected_user')}:</strong> #${user.id} - ${user.username} (${user.email})<br>
                        <strong>${window.i18n.t('admin.balance.current_balance')}:</strong> ¥${parseFloat(user.balance).toFixed(2)}
                    </div>
                `;
            } else {
                infoDiv.innerHTML = '<div class="alert alert-danger">' + window.i18n.t('error.user_not_found') + '</div>';
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
                showToast(window.i18n.t('admin.balance.select_user_hint'), 'danger');
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
                showToast(window.i18n.t('admin.balance.select_user_hint'), 'danger');
                return;
            }

            const user = currentUserData.deduct;
            if (user && parseFloat(amount) > parseFloat(user.balance)) {
                const confirmMsg = window.i18n.t('admin.balance.deduct_confirm_negative', {balance: user.balance});
                if (!window.confirm(confirmMsg)) {
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
