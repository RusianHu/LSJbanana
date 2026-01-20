<?php
/**
 * 管理后台侧边栏导航组件
 */

require_once __DIR__ . '/../security_utils.php';
require_once __DIR__ . '/../i18n/I18n.php';

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!-- 移动端菜单切换按钮 -->
<button class="menu-toggle" id="menuToggle" aria-label="<?php _e('admin.sidebar.toggle_menu'); ?>">
    <i class="fas fa-bars" id="menuIcon"></i>
</button>

<!-- 移动端遮罩层 -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="admin-sidebar" id="adminSidebar">
    <div class="sidebar-logo">
        <h2>
            <i class="fas fa-crown"></i>
            <?php _e('admin.title'); ?>
        </h2>
    </div>

    <nav class="sidebar-nav">
        <a href="<?php echo url('/admin/index.php'); ?>" class="nav-item <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
            <i class="fas fa-tachometer-alt"></i>
            <?php _e('admin.sidebar.dashboard'); ?>
        </a>

        <a href="<?php echo url('/admin/users.php'); ?>" class="nav-item <?php echo $currentPage === 'users.php' ? 'active' : ''; ?>">
            <i class="fas fa-users"></i>
            <?php _e('admin.sidebar.users'); ?>
        </a>

        <a href="<?php echo url('/admin/balance.php'); ?>" class="nav-item <?php echo $currentPage === 'balance.php' ? 'active' : ''; ?>">
            <i class="fas fa-wallet"></i>
            <?php _e('admin.sidebar.balance'); ?>
        </a>

        <a href="<?php echo url('/admin/orders.php'); ?>" class="nav-item <?php echo $currentPage === 'orders.php' ? 'active' : ''; ?>">
            <i class="fas fa-receipt"></i>
            <?php _e('admin.sidebar.orders'); ?>
        </a>

        <a href="<?php echo url('/admin/password.php'); ?>" class="nav-item <?php echo $currentPage === 'password.php' ? 'active' : ''; ?>">
            <i class="fas fa-key"></i>
            <?php _e('admin.sidebar.password'); ?>
        </a>

        <a href="<?php echo url('/admin/logs.php'); ?>" class="nav-item <?php echo $currentPage === 'logs.php' ? 'active' : ''; ?>">
            <i class="fas fa-history"></i>
            <?php _e('admin.sidebar.logs'); ?>
        </a>

        <a href="<?php echo url('/admin/announcements.php'); ?>" class="nav-item <?php echo $currentPage === 'announcements.php' ? 'active' : ''; ?>">
            <i class="fas fa-bullhorn"></i>
            <?php _e('admin.sidebar.announcements'); ?>
        </a>

        <a href="<?php echo url('/admin/login.php?action=logout'); ?>" class="nav-item logout">
            <i class="fas fa-sign-out-alt"></i>
            <?php _e('admin.sidebar.logout'); ?>
        </a>
    </nav>
</div>
