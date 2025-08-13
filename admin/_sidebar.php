<?php
$active_page = basename($_SERVER['PHP_SELF'], '.php');
?>

<nav class="admin-nav text-white">
    <div class="d-flex flex-column h-100">
        <!-- Мобильный заголовок -->
        <div class="d-flex justify-content-between align-items-center p-3 d-lg-none">
            <h3 class="text-white mb-0">Меню</h3>
            <button class="btn-close btn-close-white close-menu"></button>
        </div>

        <!-- Логотип -->
        <div class="text-center p-4 d-none d-lg-block">
            <img src="../assets/logo.png" alt="Логотип" width="160" class="mb-3">
            <h4 class="text-white">ADMIN</h4>
        </div>

        <!-- Навигация -->
        <ul class="nav flex-column flex-grow-1 px-3">
            <li class="nav-item">
                <a class="nav-link text-white <?= $active_page === 'index' ? 'active' : '' ?>" 
                   href="index.php">
                    <i class="bi bi-box-seam me-2"></i>
                    <span>Товары</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link text-white <?= $active_page === 'orders' ? 'active' : '' ?>" 
                   href="orders.php">
                    <i class="bi bi-receipt me-2"></i>
                    <span>Заказы</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link text-white <?= $active_page === 'users' ? 'active' : '' ?>" 
                   href="users.php">
                    <i class="bi bi-person me-2"></i>
                    <span>Пользователи</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link text-white <?= $active_page === 'settings' ? 'active' : '' ?>" 
                   href="settings.php">
                    <i class="bi bi-gear me-2"></i>
                    <span>Настройки</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link text-white <?= $active_page === 'server_stats' ? 'active' : '' ?>" 
                   href="store_stats.php">
                    <i class="bi bi-graph-up me-2"></i>
                    <span>Статистика</span>
                </a>
            </li>
        </ul>

        <!-- Выход -->
        <div class="mt-auto p-3 border-top">
            <a href="logout.php" class="nav-link text-white">
                <i class="bi bi-box-arrow-right me-2"></i>
                <span>Выйти</span>
            </a>
        </div>
    </div>
</nav>