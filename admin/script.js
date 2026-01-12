/**
 * 管理后台前端脚本
 */

// 移动端菜单切换
document.addEventListener('DOMContentLoaded', function() {
    initMobileMenu();
});

function initMobileMenu() {
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const menuIcon = document.getElementById('menuIcon');
    
    if (!menuToggle || !sidebar) return;
    
    function openMenu() {
        sidebar.classList.add('open');
        if (overlay) overlay.classList.add('active');
        if (menuIcon) {
            menuIcon.classList.remove('fa-bars');
            menuIcon.classList.add('fa-times');
        }
        document.body.style.overflow = 'hidden';
    }
    
    function closeMenu() {
        sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('active');
        if (menuIcon) {
            menuIcon.classList.remove('fa-times');
            menuIcon.classList.add('fa-bars');
        }
        document.body.style.overflow = '';
    }
    
    function toggleMenu() {
        if (sidebar.classList.contains('open')) {
            closeMenu();
        } else {
            openMenu();
        }
    }
    
    menuToggle.addEventListener('click', toggleMenu);
    
    if (overlay) {
        overlay.addEventListener('click', closeMenu);
    }
    
    // ESC 键关闭菜单
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar.classList.contains('open')) {
            closeMenu();
        }
    });
    
    // 窗口大小变化时自动关闭移动端菜单
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768 && sidebar.classList.contains('open')) {
            closeMenu();
        }
    });
}

// Toast 提示
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `alert alert-${type}`;
    toast.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; animation: slideIn 0.3s;';
    toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}`;

    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// 确认对话框
function confirm(message, callback) {
    // 优先使用 callback，如果 message 是字符串则作为提示，否则使用默认提示
    const msg = typeof message === 'string' ? message : (window.i18n ? window.i18n.t('misc.confirm_action') : '确定要执行此操作吗？');
    if (window.confirm(msg)) {
        callback();
    }
}

// AJAX 请求封装
// 注意: ADMIN_API_ENDPOINT 应该在 HTML 中通过 PHP 注入
// 如果未定义,则回退到相对路径 (在二级目录中也能正常工作)
const ADMIN_API_ENDPOINT = window.ADMIN_API_ENDPOINT || 'api.php';

async function apiRequest(action, data = {}) {
    try {
        const formData = new FormData();
        formData.append('action', action);

        for (const key in data) {
            formData.append(key, data[key]);
        }

        const response = await fetch(ADMIN_API_ENDPOINT, {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        return result;
    } catch (error) {
        console.error('API请求失败:', error);
        return { success: false, message: '网络错误,请重试' };
    }
}

// 切换用户状态
async function toggleUserStatus(userId) {
    const msg = window.i18n ? window.i18n.t('misc.confirm_action') : '确定要切换用户状态吗?';
    if (!window.confirm(msg)) return;

    const result = await apiRequest('toggle_user_status', { user_id: userId });

    if (result.success) {
        showToast(result.message);
        setTimeout(() => location.reload(), 1000);
    } else {
        showToast(result.message, 'danger');
    }
}

// 人工充值
async function addBalance(userId, amount, remark, visibleToUser = 0, userRemark = '') {
    const result = await apiRequest('add_balance', {
        user_id: userId,
        amount: amount,
        remark: remark,
        visible_to_user: visibleToUser,
        user_remark: userRemark
    });

    if (result.success) {
        showToast(window.i18n ? window.i18n.t('admin.balance_added') : '充值成功');
        setTimeout(() => location.reload(), 1000);
    } else {
        showToast(result.message, 'danger');
    }
}

// 人工扣款
async function deductBalance(userId, amount, remark) {
    const msg = window.i18n ? window.i18n.t('misc.confirm_action') : '确定要扣除用户余额吗?此操作不可撤销!';
    if (!window.confirm(msg)) return;

    const result = await apiRequest('deduct_balance', {
        user_id: userId,
        amount: amount,
        remark: remark
    });

    if (result.success) {
        showToast(window.i18n ? window.i18n.t('admin.balance_deducted') : '扣款成功');
        setTimeout(() => location.reload(), 1000);
    } else {
        showToast(result.message, 'danger');
    }
}

// 重置密码
async function resetPassword(userId, newPassword) {
    const msg = window.i18n ? window.i18n.t('misc.confirm_action') : '确定要重置用户密码吗?';
    if (!window.confirm(msg)) return;

    const result = await apiRequest('reset_password', {
        user_id: userId,
        new_password: newPassword
    });

    if (result.success) {
        showToast(window.i18n ? window.i18n.t('admin.password_reset') : '密码重置成功');
        return result;
    } else {
        showToast(result.message, 'danger');
        return null;
    }
}

// 模态框控制
function showModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('show');
    }
}

function hideModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('show');
    }
}

// 点击模态框外部关闭
document.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal')) {
        e.target.classList.remove('show');
    }
});

// CSS动画
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);
