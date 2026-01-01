<?php
/**
 * 管理后台 - 用户管理
 */

require_once __DIR__ . '/../admin_auth.php';

$adminAuth = getAdminAuth();

// 验证管理员权限
if (!$adminAuth->requireAuth()) {
    exit;
}

// 获取数据库实例
require_once __DIR__ . '/../db.php';
$db = Database::getInstance();

// 分页参数
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// 搜索和筛选参数
$search = isset($_GET['search']) ? trim($_GET['search']) : null;
$status = isset($_GET['status']) && $_GET['status'] !== '' ? (int)$_GET['status'] : null;

// 获取用户列表
$users = $db->getAllUsers($perPage, $offset, $search, $status);
$totalUsers = $db->getUserCount($search, $status);
$totalPages = ceil($totalUsers / $perPage);

// 辅助函数
function formatAmount($amount): string {
    return number_format((float)$amount, 2);
}

function formatTime($datetime): string {
    return date('Y-m-d H:i:s', strtotime($datetime));
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>用户管理 - 管理后台</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="admin-body">
    <?php include 'sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>
                <i class="fas fa-users"></i>
                用户管理
            </h1>
            <div class="admin-user-info">
                <i class="fas fa-user-shield"></i>
                <span>管理员</span>
            </div>
        </div>

        <div class="admin-content">
            <!-- 搜索和筛选 -->
            <div class="panel">
                <div class="panel-body">
                    <form method="GET" action="" class="search-bar">
                        <div class="search-inputs">
                            <input
                                type="text"
                                name="search"
                                placeholder="搜索用户名/邮箱/ID..."
                                value="<?php echo htmlspecialchars($search ?? ''); ?>"
                            >
                            <select name="status">
                                <option value="">全部状态</option>
                                <option value="1" <?php echo $status === 1 ? 'selected' : ''; ?>>正常</option>
                                <option value="0" <?php echo $status === 0 ? 'selected' : ''; ?>>禁用</option>
                            </select>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> 搜索
                            </button>
                            <?php if ($search || $status !== null): ?>
                                <a href="users.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> 清除
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- 用户列表 -->
            <div class="admin-table">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>用户名</th>
                            <th>邮箱</th>
                            <th>余额</th>
                            <th>状态</th>
                            <th>注册时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted">暂无用户数据</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>#<?php echo $user['id']; ?></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>¥<?php echo formatAmount($user['balance']); ?></td>
                                    <td>
                                        <?php if ($user['status'] == 1): ?>
                                            <span class="badge badge-success">正常</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">禁用</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo formatTime($user['created_at']); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button
                                                class="btn-action view"
                                                onclick="viewUser(<?php echo $user['id']; ?>)"
                                                title="查看详情"
                                            >
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button
                                                class="btn-action <?php echo $user['status'] == 1 ? 'disable' : 'enable'; ?>"
                                                onclick="toggleUserStatus(<?php echo $user['id']; ?>)"
                                                title="<?php echo $user['status'] == 1 ? '禁用' : '启用'; ?>"
                                            >
                                                <i class="fas fa-<?php echo $user['status'] == 1 ? 'ban' : 'check'; ?>"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- 分页 -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php
                    $baseUrl = 'users.php?';
                    if ($search) $baseUrl .= 'search=' . urlencode($search) . '&';
                    if ($status !== null) $baseUrl .= 'status=' . $status . '&';

                    // 上一页
                    if ($page > 1): ?>
                        <a href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>" class="page-link">
                            <i class="fas fa-chevron-left"></i> 上一页
                        </a>
                    <?php endif;

                    // 页码
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

                    // 下一页
                    if ($page < $totalPages): ?>
                        <a href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>" class="page-link">
                            下一页 <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 用户详情模态框 -->
    <div id="userDetailModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user"></i> 用户详情</h3>
                <span class="close" onclick="hideModal('userDetailModal')">&times;</span>
            </div>
            <div class="modal-body" id="userDetailContent">
                <div class="text-center">
                    <i class="fas fa-spinner fa-spin"></i> 加载中...
                </div>
            </div>
        </div>
    </div>

    <!-- 编辑邮箱模态框 -->
    <div id="editEmailModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-envelope"></i> 修改邮箱</h3>
                <span class="close" onclick="hideModal('editEmailModal')">&times;</span>
            </div>
            <div class="modal-body">
                <form id="editEmailForm">
                    <input type="hidden" id="editUserId" name="user_id">
                    <div class="form-group">
                        <label for="newEmail">新邮箱地址</label>
                        <input type="email" id="newEmail" name="email" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-save"></i> 保存
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- 充值/扣款模态框 -->
    <div id="balanceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="balanceModalTitle"><i class="fas fa-wallet"></i> 余额操作</h3>
                <span class="close" onclick="hideModal('balanceModal')">&times;</span>
            </div>
            <div class="modal-body">
                <form id="balanceForm">
                    <input type="hidden" id="balanceUserId" name="user_id">
                    <input type="hidden" id="balanceAction" name="action">
                    <div class="form-group">
                        <label for="balanceAmount">金额 (RMB)</label>
                        <input type="number" id="balanceAmount" name="amount" class="form-control" step="0.01" min="0.01" required>
                    </div>
                    <div class="form-group">
                        <label for="balanceRemark">备注</label>
                        <textarea id="balanceRemark" name="remark" class="form-control" rows="3" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-check"></i> 确认
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- 重置密码模态框 -->
    <div id="resetPasswordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-key"></i> 重置密码</h3>
                <span class="close" onclick="hideModal('resetPasswordModal')">&times;</span>
            </div>
            <div class="modal-body">
                <form id="resetPasswordForm">
                    <input type="hidden" id="resetUserId" name="user_id">
                    <div class="form-group">
                        <label for="newPassword">新密码</label>
                        <input type="text" id="newPassword" name="password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <button type="button" class="btn btn-secondary btn-block" onclick="generatePassword()">
                            <i class="fas fa-random"></i> 生成随机密码
                        </button>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-check"></i> 重置密码
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        // 查看用户详情
        async function viewUser(userId) {
            showModal('userDetailModal');
            document.getElementById('userDetailContent').innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> 加载中...</div>';

            const result = await apiRequest('get_user_detail', { user_id: userId });

            if (result.success) {
                const user = result.data.user;
                const stats = result.data.stats;

                document.getElementById('userDetailContent').innerHTML = `
                    <div class="form-group">
                        <label>用户ID</label>
                        <input type="text" class="form-control" value="#${user.id}" disabled>
                    </div>
                    <div class="form-group">
                        <label>用户名</label>
                        <input type="text" class="form-control" value="${user.username}" disabled>
                    </div>
                    <div class="form-group">
                        <label>邮箱</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="text" class="form-control" value="${user.email}" disabled style="flex: 1;">
                            <button class="btn btn-warning" onclick="editEmail(${user.id}, '${user.email}')">
                                <i class="fas fa-edit"></i> 修改
                            </button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>余额</label>
                        <input type="text" class="form-control" value="¥${parseFloat(user.balance).toFixed(2)}" disabled>
                    </div>
                    <div class="form-group">
                        <label>状态</label>
                        <input type="text" class="form-control" value="${user.status == 1 ? '正常' : '禁用'}" disabled>
                    </div>
                    <div class="form-group">
                        <label>注册时间</label>
                        <input type="text" class="form-control" value="${user.created_at}" disabled>
                    </div>
                    <hr>
                    <h4>统计数据</h4>
                    <div class="form-group">
                        <label>累计充值</label>
                        <input type="text" class="form-control" value="¥${parseFloat(stats.total_recharge || 0).toFixed(2)}" disabled>
                    </div>
                    <div class="form-group">
                        <label>累计消费</label>
                        <input type="text" class="form-control" value="¥${parseFloat(stats.total_consumption || 0).toFixed(2)}" disabled>
                    </div>
                    <div class="form-group">
                        <label>生成图片数</label>
                        <input type="text" class="form-control" value="${stats.total_images || 0} 张" disabled>
                    </div>
                    <hr>
                    <h4>快捷操作</h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <button class="btn btn-success" onclick="hideModal('userDetailModal'); showBalanceModal(${user.id}, 'add')">
                            <i class="fas fa-plus-circle"></i> 充值
                        </button>
                        <button class="btn btn-danger" onclick="hideModal('userDetailModal'); showBalanceModal(${user.id}, 'deduct')">
                            <i class="fas fa-minus-circle"></i> 扣款
                        </button>
                        <button class="btn btn-warning" onclick="hideModal('userDetailModal'); showResetPasswordModal(${user.id})">
                            <i class="fas fa-key"></i> 重置密码
                        </button>
                        <button class="btn btn-${user.status == 1 ? 'danger' : 'success'}" onclick="toggleUserStatus(${user.id})">
                            <i class="fas fa-${user.status == 1 ? 'ban' : 'check'}"></i> ${user.status == 1 ? '禁用' : '启用'}
                        </button>
                    </div>
                `;
            } else {
                document.getElementById('userDetailContent').innerHTML = `<div class="alert alert-danger">${result.message}</div>`;
            }
        }

        // 编辑邮箱
        function editEmail(userId, currentEmail) {
            hideModal('userDetailModal');
            showModal('editEmailModal');
            document.getElementById('editUserId').value = userId;
            document.getElementById('newEmail').value = currentEmail;
        }

        document.getElementById('editEmailForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const result = await apiRequest('update_user_email', {
                user_id: formData.get('user_id'),
                email: formData.get('email')
            });

            if (result.success) {
                showToast('邮箱修改成功');
                hideModal('editEmailModal');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(result.message, 'danger');
            }
        });

        // 显示余额操作模态框
        function showBalanceModal(userId, action) {
            showModal('balanceModal');
            document.getElementById('balanceUserId').value = userId;
            document.getElementById('balanceAction').value = action;
            document.getElementById('balanceModalTitle').innerHTML = `<i class="fas fa-wallet"></i> ${action === 'add' ? '人工充值' : '人工扣款'}`;
            document.getElementById('balanceAmount').value = '';
            document.getElementById('balanceRemark').value = '';
        }

        document.getElementById('balanceForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const action = formData.get('action');
            const userId = formData.get('user_id');
            const amount = formData.get('amount');
            const remark = formData.get('remark');

            if (action === 'add') {
                await addBalance(userId, amount, remark);
            } else {
                await deductBalance(userId, amount, remark);
            }

            hideModal('balanceModal');
        });

        // 显示重置密码模态框
        function showResetPasswordModal(userId) {
            showModal('resetPasswordModal');
            document.getElementById('resetUserId').value = userId;
            document.getElementById('newPassword').value = '';
        }

        // 生成随机密码
        function generatePassword() {
            const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
            let password = '';
            for (let i = 0; i < 12; i++) {
                password += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            document.getElementById('newPassword').value = password;
        }

        document.getElementById('resetPasswordForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            await resetPassword(formData.get('user_id'), formData.get('password'));
            hideModal('resetPasswordModal');
        });
    </script>
</body>
</html>
