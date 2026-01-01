<?php
/**
 * 管理后台侧边栏导航组件
 */

require_once __DIR__ . '/../security_utils.php';

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<div class="admin-sidebar">
    <div class="sidebar-logo">
        <h2>
            <i class="fas fa-crown"></i>
            管理后台
        </h2>
    </div>

    <nav class="sidebar-nav">
        <a href="<?php echo url('/admin/index.php'); ?>" class="nav-item <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
            <i class="fas fa-tachometer-alt"></i>
            仪表盘
        </a>

        <a href="<?php echo url('/admin/users.php'); ?>" class="nav-item <?php echo $currentPage === 'users.php' ? 'active' : ''; ?>">
            <i class="fas fa-users"></i>
            用户管理
        </a>

        <a href="<?php echo url('/admin/balance.php'); ?>" class="nav-item <?php echo $currentPage === 'balance.php' ? 'active' : ''; ?>">
            <i class="fas fa-wallet"></i>
            余额管理
        </a>

        <a href="<?php echo url('/admin/orders.php'); ?>" class="nav-item <?php echo $currentPage === 'orders.php' ? 'active' : ''; ?>">
            <i class="fas fa-receipt"></i>
            订单管理
        </a>

        <a href="<?php echo url('/admin/password.php'); ?>" class="nav-item <?php echo $currentPage === 'password.php' ? 'active' : ''; ?>">
            <i class="fas fa-key"></i>
            密码管理
        </a>

        <a href="<?php echo url('/admin/logs.php'); ?>" class="nav-item <?php echo $currentPage === 'logs.php' ? 'active' : ''; ?>">
            <i class="fas fa-history"></i>
            日志查看
        </a>

        <a href="<?php echo url('/admin/login.php?action=logout'); ?>" class="nav-item logout">
            <i class="fas fa-sign-out-alt"></i>
            退出登录
        </a>
    </nav>
</div>
