<?php
/**
 * 管理后台侧边栏导航组件
 */

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
        <a href="index.php" class="nav-item <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
            <i class="fas fa-tachometer-alt"></i>
            仪表盘
        </a>

        <a href="users.php" class="nav-item <?php echo $currentPage === 'users.php' ? 'active' : ''; ?>">
            <i class="fas fa-users"></i>
            用户管理
        </a>

        <a href="balance.php" class="nav-item <?php echo $currentPage === 'balance.php' ? 'active' : ''; ?>">
            <i class="fas fa-wallet"></i>
            余额管理
        </a>

        <a href="orders.php" class="nav-item <?php echo $currentPage === 'orders.php' ? 'active' : ''; ?>">
            <i class="fas fa-receipt"></i>
            订单管理
        </a>

        <a href="password.php" class="nav-item <?php echo $currentPage === 'password.php' ? 'active' : ''; ?>">
            <i class="fas fa-key"></i>
            密码管理
        </a>

        <a href="logs.php" class="nav-item <?php echo $currentPage === 'logs.php' ? 'active' : ''; ?>">
            <i class="fas fa-history"></i>
            日志查看
        </a>

        <a href="login.php?action=logout" class="nav-item logout">
            <i class="fas fa-sign-out-alt"></i>
            退出登录
        </a>
    </nav>
</div>
