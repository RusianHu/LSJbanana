<?php
/**
 * 管理后台 - 用户管理
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
<html lang="<?php echo i18n()->getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php _e('admin.users.title'); ?> - <?php _e('admin.title'); ?></title>
    <link rel="stylesheet" href="<?php echo url('/admin/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="admin-body">
    <?php include 'sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>
                <i class="fas fa-users"></i>
                <?php _e('admin.users.title'); ?>
            </h1>
            <div class="admin-user-info">
                <i class="fas fa-user-shield"></i>
                <span><?php _e('admin.administrator'); ?></span>
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
                                placeholder="<?php _e('admin.users.search_placeholder'); ?>"
                                value="<?php echo htmlspecialchars($search ?? ''); ?>"
                            >
                            <select name="status">
                                <option value=""><?php _e('admin.users.all_status'); ?></option>
                                <option value="1" <?php echo $status === 1 ? 'selected' : ''; ?>><?php _e('user.status_active'); ?></option>
                                <option value="0" <?php echo $status === 0 ? 'selected' : ''; ?>><?php _e('user.status_disabled'); ?></option>
                            </select>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> <?php _e('form.search'); ?>
                            </button>
                            <?php if ($search || $status !== null): ?>
                                <a href="users.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> <?php _e('form.clear'); ?>
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
                            <th><?php _e('admin.table.id'); ?></th>
                            <th><?php _e('admin.table.username'); ?></th>
                            <th><?php _e('admin.table.email'); ?></th>
                            <th><?php _e('admin.table.balance'); ?></th>
                            <th><?php _e('admin.table.status'); ?></th>
                            <th><?php _e('admin.table.created_at'); ?></th>
                            <th><?php _e('admin.table.actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted"><?php _e('admin.users.no_users'); ?></td>
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
                                            <span class="badge badge-success"><?php _e('user.status_active'); ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-danger"><?php _e('user.status_disabled'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo formatTime($user['created_at']); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button
                                                class="btn-action view"
                                                onclick="viewUser(<?php echo $user['id']; ?>)"
                                                title="<?php _e('admin.users.view_detail'); ?>"
                                            >
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button
                                                class="btn-action <?php echo $user['status'] == 1 ? 'disable' : 'enable'; ?>"
                                                onclick="toggleUserStatus(<?php echo $user['id']; ?>)"
                                                title="<?php echo $user['status'] == 1 ? __('action.disable') : __('action.enable'); ?>"
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
                            <i class="fas fa-chevron-left"></i> <?php _e('admin.pagination.prev'); ?>
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
                            <?php _e('admin.pagination.next'); ?> <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 用户详情模态框（标签页形式） -->
    <div id="userDetailModal" class="modal">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h3><i class="fas fa-user"></i> <?php _e('admin.users.user_detail'); ?> - <span id="userDetailTitle"></span></h3>
                <span class="close" onclick="hideModal('userDetailModal')">&times;</span>
            </div>
            <div class="modal-body">
                <!-- 标签页导航 -->
                <div class="tabs-nav" id="userDetailTabs">
                    <button class="tab-btn active" data-tab="basic" onclick="switchTab('basic')">
                        <i class="fas fa-info-circle"></i> <?php _e('admin.users.basic_info'); ?>
                    </button>
                    <button class="tab-btn" data-tab="login" onclick="switchTab('login')">
                        <i class="fas fa-sign-in-alt"></i> <?php _e('admin.users.login_history'); ?>
                    </button>
                    <button class="tab-btn" data-tab="consumption" onclick="switchTab('consumption')">
                        <i class="fas fa-shopping-cart"></i> <?php _e('admin.users.consumption_detail'); ?>
                    </button>
                    <button class="tab-btn" data-tab="balance" onclick="switchTab('balance')">
                        <i class="fas fa-book"></i> <?php _e('admin.users.balance_history'); ?>
                    </button>
                    <button class="tab-btn" data-tab="orders" onclick="switchTab('orders')">
                        <i class="fas fa-receipt"></i> <?php _e('admin.users.recharge_orders'); ?>
                    </button>
                </div>
                
                <!-- 标签页内容 -->
                <div class="tabs-content">
                    <!-- 基本信息标签页 -->
                    <div class="tab-pane active" id="tab-basic">
                        <div id="userBasicContent">
                            <div class="text-center"><i class="fas fa-spinner fa-spin"></i> <?php _e('form.loading'); ?></div>
                        </div>
                    </div>
                    
                    <!-- 登录历史标签页 -->
                    <div class="tab-pane" id="tab-login">
                        <div id="userLoginContent">
                            <div class="text-center"><i class="fas fa-spinner fa-spin"></i> <?php _e('form.loading'); ?></div>
                        </div>
                    </div>
                    
                    <!-- 消费明细标签页 -->
                    <div class="tab-pane" id="tab-consumption">
                        <div id="userConsumptionContent">
                            <div class="text-center"><i class="fas fa-spinner fa-spin"></i> <?php _e('form.loading'); ?></div>
                        </div>
                    </div>
                    
                    <!-- 账户流水标签页 -->
                    <div class="tab-pane" id="tab-balance">
                        <div id="userBalanceContent">
                            <div class="text-center"><i class="fas fa-spinner fa-spin"></i> <?php _e('form.loading'); ?></div>
                        </div>
                    </div>
                    
                    <!-- 充值订单标签页 -->
                    <div class="tab-pane" id="tab-orders">
                        <div id="userOrdersContent">
                            <div class="text-center"><i class="fas fa-spinner fa-spin"></i> <?php _e('form.loading'); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 编辑邮箱模态框 -->
    <div id="editEmailModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-envelope"></i> <?php _e('admin.modal.edit_email'); ?></h3>
                <span class="close" onclick="hideModal('editEmailModal')">&times;</span>
            </div>
            <div class="modal-body">
                <form id="editEmailForm">
                    <input type="hidden" id="editUserId" name="user_id">
                    <div class="form-group">
                        <label for="newEmail"><?php _e('admin.modal.new_email'); ?></label>
                        <input type="email" id="newEmail" name="email" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-save"></i> <?php _e('form.save'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- 充值/扣款模态框 -->
    <div id="balanceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="balanceModalTitle"><i class="fas fa-wallet"></i> <?php _e('admin.balance.title'); ?></h3>
                <span class="close" onclick="hideModal('balanceModal')">&times;</span>
            </div>
            <div class="modal-body">
                <form id="balanceForm">
                    <input type="hidden" id="balanceUserId" name="user_id">
                    <input type="hidden" id="balanceAction" name="action">
                    <div class="form-group">
                        <label for="balanceAmount"><?php _e('admin.balance.amount'); ?></label>
                        <input type="number" id="balanceAmount" name="amount" class="form-control" step="0.01" min="0.01" required>
                    </div>
                    <div class="form-group">
                        <label for="balanceRemark"><?php _e('admin.balance.remark'); ?></label>
                        <textarea id="balanceRemark" name="remark" class="form-control" rows="3" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-check"></i> <?php _e('form.confirm'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- 重置密码模态框 -->
    <div id="resetPasswordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-key"></i> <?php _e('admin.modal.reset_password'); ?></h3>
                <span class="close" onclick="hideModal('resetPasswordModal')">&times;</span>
            </div>
            <div class="modal-body">
                <form id="resetPasswordForm">
                    <input type="hidden" id="resetUserId" name="user_id">
                    <div class="form-group">
                        <label for="newPassword"><?php _e('admin.modal.new_password'); ?></label>
                        <input type="text" id="newPassword" name="password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <button type="button" class="btn btn-secondary btn-block" onclick="generatePassword()">
                            <i class="fas fa-random"></i> <?php _e('admin.modal.gen_random'); ?>
                        </button>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-check"></i> <?php _e('admin.modal.reset_password'); ?>
                    </button>
                </form>
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
        // 当前查看的用户ID
        let currentUserId = null;
        
        // 标签页分页状态
        let tabPagination = {
            login: { page: 1, perPage: 10 },
            consumption: { page: 1, perPage: 10 },
            balance: { page: 1, perPage: 10 },
            orders: { page: 1, perPage: 10 }
        };

        // 切换标签页
        function switchTab(tabName) {
            // 更新导航按钮状态
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.tab === tabName) {
                    btn.classList.add('active');
                }
            });
            
            // 更新内容面板显示
            document.querySelectorAll('.tab-pane').forEach(pane => {
                pane.classList.remove('active');
            });
            document.getElementById('tab-' + tabName).classList.add('active');
            
            // 加载标签页数据
            loadTabData(tabName);
        }
        
        // 加载标签页数据
        async function loadTabData(tabName) {
            if (!currentUserId) return;
            
            // 基本信息已在viewUser中加载
            if (tabName === 'basic') return;
            
            const contentId = {
                login: 'userLoginContent',
                consumption: 'userConsumptionContent',
                balance: 'userBalanceContent',
                orders: 'userOrdersContent'
            }[tabName];
            
            const actionMap = {
                login: 'get_user_login_logs',
                consumption: 'get_user_consumption_logs',
                balance: 'get_user_balance_logs',
                orders: 'get_user_recharge_orders'
            };
            
            const container = document.getElementById(contentId);
            container.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> ' + window.i18n.t('form.loading') + '</div>';
            
            const pagination = tabPagination[tabName];
            const result = await apiRequest(actionMap[tabName], {
                user_id: currentUserId,
                page: pagination.page,
                per_page: pagination.perPage
            });
            
            if (result.success) {
                renderTabContent(tabName, result.data, container);
            } else {
                container.innerHTML = `<div class="alert alert-danger">${escapeHtml(result.message || window.i18n.t('admin.load_failed'))}</div>`;
            }
        }
        
        // 渲染标签页内容
        function renderTabContent(tabName, data, container) {
            switch(tabName) {
                case 'login':
                    renderLoginLogs(data, container);
                    break;
                case 'consumption':
                    renderConsumptionLogs(data, container);
                    break;
                case 'balance':
                    renderBalanceLogs(data, container);
                    break;
                case 'orders':
                    renderRechargeOrders(data, container);
                    break;
            }
        }
        
        // 渲染登录历史
        function renderLoginLogs(data, container) {
            if (!data.logs || data.logs.length === 0) {
                container.innerHTML = '<div class="text-center text-muted">' + window.i18n.t('admin.users.no_login_records') + '</div>';
                return;
            }
            
            let html = `
                <div class="admin-table compact-table">
                    <table>
                        <thead>
                            <tr>
                                <th>${window.i18n.t('admin.table.login_time')}</th>
                                <th>${window.i18n.t('admin.table.ip_address')}</th>
                                <th>${window.i18n.t('admin.table.login_method')}</th>
                                <th>${window.i18n.t('admin.table.result')}</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            data.logs.forEach(log => {
                const statusBadge = log.status == 1
                    ? '<span class="badge badge-success">' + window.i18n.t('status.success') + '</span>'
                    : '<span class="badge badge-danger">' + window.i18n.t('status.failed') + '</span>';
                
                let loginType = log.login_type;
                if (log.login_type === 'password') loginType = window.i18n.t('admin.login_type.password');
                else if (log.login_type === 'token') loginType = window.i18n.t('admin.login_type.token');
                else if (log.login_type === 'quick_login') loginType = window.i18n.t('admin.login_type.quick_login');
                
                html += `
                    <tr>
                        <td>${log.created_at}</td>
                        <td><code>${log.ip_address}</code></td>
                        <td>${loginType}</td>
                        <td>${statusBadge}</td>
                    </tr>
                `;
            });
            
            html += '</tbody></table></div>';
            html += renderPagination('login', data);
            container.innerHTML = html;
        }
        
        // 解析消费记录的remark字段（支持新旧格式）
        function parseConsumptionRemark(remark) {
            if (!remark) return { prompt: '', images: [] };
            
            // 尝试解析JSON格式（新格式）
            try {
                const data = JSON.parse(remark);
                if (data && typeof data === 'object') {
                    return {
                        prompt: data.prompt || '',
                        images: Array.isArray(data.images) ? data.images : []
                    };
                }
            } catch (e) {
                // 不是JSON，使用旧格式（直接是提示词字符串）
            }
            
            return { prompt: remark, images: [] };
        }
        
        // 渲染消费明细
        function renderConsumptionLogs(data, container) {
            if (!data.logs || data.logs.length === 0) {
                container.innerHTML = '<div class="text-center text-muted">' + window.i18n.t('admin.users.no_consumption_records') + '</div>';
                return;
            }
            
            let html = `
                <div class="admin-table compact-table">
                    <table>
                        <thead>
                            <tr>
                                <th>${window.i18n.t('admin.table.time')}</th>
                                <th>${window.i18n.t('admin.table.type')}</th>
                                <th>${window.i18n.t('admin.table.amount')}</th>
                                <th>${window.i18n.t('admin.table.image_count')}</th>
                                <th>${window.i18n.t('admin.table.model')}</th>
                                <th>${window.i18n.t('admin.table.prompt_summary')}</th>
                                <th>${window.i18n.t('admin.table.gen_files')}</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            data.logs.forEach(log => {
                let actionLabel = log.action;
                if (log.action === 'generate') actionLabel = window.i18n.t('admin.action_type.generate');
                else if (log.action === 'edit') actionLabel = window.i18n.t('admin.action_type.edit');

                const remarkData = parseConsumptionRemark(log.remark);
                const prompt = remarkData.prompt;
                const promptSummary = prompt.length > 50 ? prompt.substring(0, 50) + '...' : prompt;
                const imageFiles = remarkData.images;
                
                // 生成文件列表展示
                let imageDisplay = '-';
                if (imageFiles.length > 0) {
                    if (imageFiles.length === 1) {
                        imageDisplay = `<code style="font-size: 0.7rem;">${escapeHtml(imageFiles[0])}</code>`;
                    } else {
                        imageDisplay = `<span class="badge badge-info" title="${escapeHtml(imageFiles.join(', '))}">${window.i18n.t('admin.files', {count: imageFiles.length})}</span>`;
                    }
                }
                
                html += `
                    <tr>
                        <td>${log.created_at}</td>
                        <td><span class="badge badge-info">${actionLabel}</span></td>
                        <td class="text-danger">-¥${parseFloat(log.amount).toFixed(4)}</td>
                        <td>${log.image_count || 1}</td>
                        <td><small>${log.model_name || '-'}</small></td>
                        <td title="${escapeHtml(prompt)}"><small>${escapeHtml(promptSummary)}</small></td>
                        <td>${imageDisplay}</td>
                    </tr>
                `;
            });
            
            html += '</tbody></table></div>';
            html += renderPagination('consumption', data);
            container.innerHTML = html;
        }
        
        // 渲染账户流水
        function renderBalanceLogs(data, container) {
            if (!data.logs || data.logs.length === 0) {
                container.innerHTML = '<div class="text-center text-muted">' + window.i18n.t('admin.users.no_balance_records') + '</div>';
                return;
            }

            // 来源类型映射（source_type -> {label, badgeClass}）
            const sourceTypeMap = {
                'online_recharge': { label: window.i18n.t('admin.balance_type.online_recharge'), badgeClass: 'badge-success' },
                'manual_recharge': { label: window.i18n.t('admin.balance_type.manual_recharge'), badgeClass: 'badge-info' },
                'consumption':     { label: window.i18n.t('admin.balance_type.consumption'),     badgeClass: 'badge-warning' },
                'manual_deduct':   { label: window.i18n.t('admin.balance_type.manual_deduct'),   badgeClass: 'badge-danger' }
            };
            
            let html = `
                <div class="admin-table compact-table">
                    <table>
                        <thead>
                            <tr>
                                <th>${window.i18n.t('admin.table.time')}</th>
                                <th>${window.i18n.t('admin.table.type')}</th>
                                <th>${window.i18n.t('admin.table.amount')}</th>
                                <th>${window.i18n.t('admin.table.before')}</th>
                                <th>${window.i18n.t('admin.table.after')}</th>
                                <th>${window.i18n.t('admin.table.details')}</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            data.logs.forEach(log => {
                const isRecharge = log.type === 'recharge';
                // 优先使用 source_type 标签，否则回退到旧的 type 标签
                const sourceInfo = log.source_type && sourceTypeMap[log.source_type]
                    ? sourceTypeMap[log.source_type]
                    : (isRecharge
                        ? { label: window.i18n.t('admin.balance_type.recharge'), badgeClass: 'badge-success' }
                        : { label: window.i18n.t('admin.balance_type.deduct'), badgeClass: 'badge-danger' });
                const typeBadge = `<span class="badge ${sourceInfo.badgeClass}">${sourceInfo.label}</span>`;
                const amountClass = isRecharge ? 'text-success' : 'text-danger';
                const amountSign = isRecharge ? '+' : '';
                
                html += `
                    <tr>
                        <td>${log.created_at}</td>
                        <td>${typeBadge}</td>
                        <td class="${amountClass}">${amountSign}¥${parseFloat(Math.abs(log.amount)).toFixed(2)}</td>
                        <td>¥${parseFloat(log.balance_before).toFixed(2)}</td>
                        <td>¥${parseFloat(log.balance_after).toFixed(2)}</td>
                        <td><small>${escapeHtml(log.remark || '-')}</small></td>
                    </tr>
                `;
            });
            
            html += '</tbody></table></div>';
            html += renderPagination('balance', data);
            container.innerHTML = html;
        }
        
        // 渲染充值订单
        function renderRechargeOrders(data, container) {
            if (!data.orders || data.orders.length === 0) {
                container.innerHTML = '<div class="text-center text-muted">' + window.i18n.t('admin.users.no_orders') + '</div>';
                return;
            }
            
            const statusMap = {
                0: { label: window.i18n.t('admin.order_status.0'), class: 'warning' },
                1: { label: window.i18n.t('admin.order_status.1'), class: 'success' },
                2: { label: window.i18n.t('admin.order_status.2'), class: 'danger' },
                3: { label: window.i18n.t('admin.order_status.3'), class: 'info' }
            };
            
            const payTypeMap = {
                'alipay': window.i18n.t('admin.pay_type.alipay'),
                'wxpay': window.i18n.t('admin.pay_type.wxpay'),
                'qqpay': window.i18n.t('admin.pay_type.qqpay')
            };
            
            let html = `
                <div class="admin-table compact-table">
                    <table>
                        <thead>
                            <tr>
                                <th>${window.i18n.t('admin.table.order_no')}</th>
                                <th>${window.i18n.t('admin.table.amount')}</th>
                                <th>${window.i18n.t('admin.table.pay_type')}</th>
                                <th>${window.i18n.t('admin.table.result')}</th>
                                <th>${window.i18n.t('admin.table.create_time')}</th>
                                <th>${window.i18n.t('admin.table.pay_time')}</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            data.orders.forEach(order => {
                const status = statusMap[order.status] || { label: window.i18n.t('status.unknown'), class: 'secondary' };
                const payType = payTypeMap[order.pay_type] || order.pay_type || '-';
                
                html += `
                    <tr>
                        <td><code style="font-size: 0.75rem;">${order.out_trade_no}</code></td>
                        <td>¥${parseFloat(order.amount).toFixed(2)}</td>
                        <td>${payType}</td>
                        <td><span class="badge badge-${status.class}">${status.label}</span></td>
                        <td><small>${order.created_at}</small></td>
                        <td><small>${order.paid_at || '-'}</small></td>
                    </tr>
                `;
            });
            
            html += '</tbody></table></div>';
            html += renderPagination('orders', data);
            container.innerHTML = html;
        }
        
        // 渲染分页
        function renderPagination(tabName, data) {
            if (data.total_pages <= 1) return '';
            
            let html = '<div class="tab-pagination">';
            
            // 上一页
            if (data.page > 1) {
                html += `<button class="btn btn-sm" onclick="goToPage('${tabName}', ${data.page - 1})"><i class="fas fa-chevron-left"></i></button>`;
            }
            
            const pageInfo = window.i18n.t('admin.page_info', {
                current: data.page,
                total: data.total_pages,
                count: data.total
            });
            html += `<span class="page-info">${pageInfo}</span>`;
            
            // 下一页
            if (data.page < data.total_pages) {
                html += `<button class="btn btn-sm" onclick="goToPage('${tabName}', ${data.page + 1})"><i class="fas fa-chevron-right"></i></button>`;
            }
            
            html += '</div>';
            return html;
        }
        
        // 分页跳转
        function goToPage(tabName, page) {
            tabPagination[tabName].page = page;
            loadTabData(tabName);
        }
        
        // HTML转义（用于防止XSS）
        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            const div = document.createElement('div');
            div.textContent = String(text);
            return div.innerHTML;
        }
        
        // JavaScript字符串转义（用于嵌入JS属性）
        function escapeJs(text) {
            if (text === null || text === undefined) return '';
            return String(text)
                .replace(/\\/g, '\\\\')
                .replace(/'/g, "\\'")
                .replace(/"/g, '\\"')
                .replace(/\n/g, '\\n')
                .replace(/\r/g, '\\r');
        }

        // 查看用户详情
        async function viewUser(userId) {
            currentUserId = userId;
            
            // 重置分页状态
            Object.keys(tabPagination).forEach(key => {
                tabPagination[key].page = 1;
            });
            
            // 重置标签页到基本信息
            switchTab('basic');
            
            showModal('userDetailModal');
            document.getElementById('userBasicContent').innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> ' + window.i18n.t('form.loading') + '</div>';
            document.getElementById('userDetailTitle').textContent = '';

            const result = await apiRequest('get_user_detail', { user_id: userId });

            if (result.success) {
                const user = result.data.user;
                const stats = result.data.stats;
                
                document.getElementById('userDetailTitle').textContent = user.username;

                // 使用转义函数防止XSS
                const safeUsername = escapeHtml(user.username);
                const safeEmail = escapeHtml(user.email);
                const safeCreatedAt = escapeHtml(user.created_at);
                const jsEscapedEmail = escapeJs(user.email);

                document.getElementById('userBasicContent').innerHTML = `
                    <div class="user-info-grid">
                        <div class="form-group">
                            <label>${window.i18n.t('admin.users.user_id')}</label>
                            <input type="text" class="form-control" value="#${user.id}" disabled>
                        </div>
                        <div class="form-group">
                            <label>${window.i18n.t('admin.table.username')}</label>
                            <input type="text" class="form-control" value="${safeUsername}" disabled>
                        </div>
                        <div class="form-group">
                            <label>${window.i18n.t('admin.table.email')}</label>
                            <div style="display: flex; gap: 10px;">
                                <input type="text" class="form-control" value="${safeEmail}" disabled style="flex: 1;">
                                <button class="btn btn-warning btn-sm" onclick="editEmail(${user.id}, '${jsEscapedEmail}')">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>${window.i18n.t('admin.table.balance')}</label>
                            <input type="text" class="form-control" value="¥${parseFloat(user.balance).toFixed(2)}" disabled>
                        </div>
                        <div class="form-group">
                            <label>${window.i18n.t('admin.table.status')}</label>
                            <input type="text" class="form-control" value="${user.status == 1 ? window.i18n.t('user.status_active') : window.i18n.t('user.status_disabled')}" disabled>
                        </div>
                        <div class="form-group">
                            <label>${window.i18n.t('admin.table.created_at')}</label>
                            <input type="text" class="form-control" value="${safeCreatedAt}" disabled>
                        </div>
                    </div>
                    <hr>
                    <h4><i class="fas fa-chart-bar"></i> ${window.i18n.t('admin.users.statistics')}</h4>
                    <div class="stats-grid">
                        <div class="stat-item">
                            <span class="stat-label">${window.i18n.t('admin.users.total_recharge')}</span>
                            <span class="stat-value text-success">¥${parseFloat(stats.total_recharge || 0).toFixed(2)}</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">${window.i18n.t('admin.users.total_consumption')}</span>
                            <span class="stat-value text-danger">¥${parseFloat(stats.total_consumption || 0).toFixed(2)}</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">${window.i18n.t('admin.users.total_images')}</span>
                            <span class="stat-value">${parseInt(stats.total_images) || 0} ${window.i18n.t('misc.unit_images')}</span>
                        </div>
                    </div>
                    <hr>
                    <h4><i class="fas fa-tools"></i> ${window.i18n.t('admin.users.quick_actions')}</h4>
                    <div class="action-grid">
                        <button class="btn btn-success" onclick="hideModal('userDetailModal'); showBalanceModal(${user.id}, 'add')">
                            <i class="fas fa-plus-circle"></i> ${window.i18n.t('admin.users.add_balance')}
                        </button>
                        <button class="btn btn-danger" onclick="hideModal('userDetailModal'); showBalanceModal(${user.id}, 'deduct')">
                            <i class="fas fa-minus-circle"></i> ${window.i18n.t('admin.users.deduct_balance')}
                        </button>
                        <button class="btn btn-warning" onclick="hideModal('userDetailModal'); showResetPasswordModal(${user.id})">
                            <i class="fas fa-key"></i> ${window.i18n.t('admin.users.reset_password')}
                        </button>
                        <button class="btn btn-${user.status == 1 ? 'secondary' : 'success'}" onclick="toggleUserStatus(${user.id})">
                            <i class="fas fa-${user.status == 1 ? 'ban' : 'check'}"></i> ${user.status == 1 ? window.i18n.t('action.disable') : window.i18n.t('action.enable')}
                        </button>
                    </div>
                `;
            } else {
                document.getElementById('userBasicContent').innerHTML = `<div class="alert alert-danger">${escapeHtml(result.message || window.i18n.t('error.unknown'))}</div>`;
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
                showToast(window.i18n.t('admin.email_updated'));
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
            const titleText = action === 'add' ? window.i18n.t('admin.balance.add_title') : window.i18n.t('admin.balance.deduct_title');
            document.getElementById('balanceModalTitle').innerHTML = `<i class="fas fa-wallet"></i> ${titleText}`;
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
