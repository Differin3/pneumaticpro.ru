<?php
session_start();
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

// Проверка авторизации администратора
if (!isset($_SESSION['admin'])) {
    header('Location: login.php');
    exit;
}

// Генерация CSRF-токена
$csrf_token = generate_csrf_token();

// Обработка параметров
$search = isset($_GET['search']) ? clean_input($_GET['search']) : '';
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$items_per_page = 10;

// Динамическое построение SQL
$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(u.username LIKE :search_username OR u.email LIKE :search_email OR c.full_name LIKE :search_name OR c.phone LIKE :search_phone)";
    $params[':search_username'] = "%$search%";
    $params[':search_email'] = "%$search%";
    $params[':search_name'] = "%$search%";
    $params[':search_phone'] = "%$search%";
}

// Получение списка пользователей
try {
    $sql_where = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "
        SELECT u.id, u.username, u.email, u.email_verified_at, u.created_at, 
               c.full_name, c.phone,
               (SELECT COUNT(*) FROM notes n WHERE n.entity_id = u.id AND n.entity_type = 'customer') AS note_count,
               (SELECT MAX(CASE 
                           WHEN n.flag_color = 'red' THEN 3 
                           WHEN n.flag_color = 'yellow' THEN 2 
                           ELSE 1 
                         END) 
                FROM notes n 
                WHERE n.entity_id = u.id AND n.entity_type = 'customer') AS flag_priority
        FROM users u
        LEFT JOIN customers c ON u.id = c.user_id
        $sql_where
        ORDER BY u.created_at DESC
        LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($current_page - 1) * $items_per_page, PDO::PARAM_INT);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Преобразование приоритета флага в цвет
    foreach ($users as &$user) {
        $user['flag_color'] = match((int)($user['flag_priority'] ?? 0)) {
            3 => 'danger',    // красный
            2 => 'warning',   // желтый
            1 => 'success',   // зеленый
            default => ''
        };
    }
    unset($user); // сброс ссылки

    // Подсчет общего количества
    $count_sql = "SELECT COUNT(*) FROM users u LEFT JOIN customers c ON u.id = c.user_id $sql_where";
    $count_stmt = $pdo->prepare($count_sql);
    foreach ($params as $key => $value) {
        $count_stmt->bindValue($key, $value);
    }
    $count_stmt->execute();
    $total_items = $count_stmt->fetchColumn();

} catch (PDOException $e) {
    die("Ошибка загрузки пользователей: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <title>Админ-панель | Управление пользователями</title>
    <link rel="icon" href="data:,">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/../css/admin.css">
    <link rel="icon" href="https://pnevmatpro.ru/assets/favicon.png" type="image/png">
    <style>
        /* Мобильные карточки для пользователей */
        .user-card-mobile {
            display: none;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .user-card-mobile .user-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 1rem;
            font-weight: 600;
        }

        .user-card-mobile .user-body {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .user-card-mobile .col-span-2 {
            grid-column: span 2;
            text-align: center;
        }

        .badge-verified { background-color: #198754; }
        .badge-unverified { background-color: #dc3545; }
        #userDetailsModal .modal-body { max-height: 70vh; overflow-y: auto; }
        .is-invalid { border-color: #dc3545 !important; }
        .invalid-feedback { display: block; color: #dc3545; font-size: 0.875em; margin-top: 0.25rem; }
        
        .bi-flag-fill.text-success { color: #198754; }
        .bi-flag-fill.text-warning { color: #ffc107; }
        .bi-flag-fill.text-danger { color: #dc3545; }
        
        .flag-color-btn.active {
            transform: scale(1.1);
            box-shadow: 0 0 0 3px rgba(0,0,0,0.1);
        }

        /* Стили для активных табов */
        .nav-tabs .nav-link.active {
            color: #000 !important;
            font-weight: 600;
            border-bottom: 3px solid #0d6efd;
            background-color: transparent;
        }

        /* Стили для неактивных табов */
        .nav-tabs .nav-link {
            color: #6c757d !important;
            border: none;
            border-bottom: 2px solid transparent;
            padding: 0.5rem 1rem;
        }

        /* Убираем стандартную границу у табов */
        .nav-tabs {
            border-bottom: none;
        }

        /* Отступ для заголовка с табами */
        .modal-header.bg-light.pt-0 {
            padding-top: 0.5rem !important;
            padding-bottom: 0 !important;
            border-bottom: none !important;
        }

        /* Стили для заметок */
        .note-content {
            white-space: pre-wrap;       /* Сохраняет пробелы и переносы */
            word-break: break-word;      /* Переносит длинные слова */
            font-family: monospace;      /* Моноширинный шрифт */
            background-color: #f8f9fa;   /* Светлый фон */
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #dee2e6;
        }
        
        /* Группа кнопок выбора цвета */
        .flag-color-group {
            margin-top: 8px;
            display: flex;
            gap: 6px;
        }

    .admin-wrapper {
        display: flex;
        min-height: 100vh;
        overflow-y: auto;
    }

    .admin-nav {
        background-color: #2c3e50;
        color: white;
        padding: 20px;
        width: 250px;
        position: fixed;
        top: 0;
        left: 0;
        height: 100vh;
        transform: translateX(0);
        transition: transform 0.3s ease-in-out;
        z-index: 1050;
        overflow-x: hidden; /* Предотвращаем горизонтальную прокрутку */
    }

    .admin-nav .nav {
        height: calc(100% - 100px);
        overflow-y: auto;
        overflow-x: hidden; /* Убеждаемся, что горизонтальная прокрутка отключена */
    }

    .admin-nav .nav-link {
        white-space: nowrap; /* Предотвращаем перенос текста */
        overflow: hidden; /* Скрываем избыточный контент */
        text-overflow: ellipsis; /* Добавляем многоточие, если текст обрезается */
        padding: 10px 15px; /* Уменьшаем внутренние отступы для компактности */
        font-size: 0.95rem; /* Немного уменьшаем шрифт для экономии места */
    }

    .admin-nav .nav-link i {
        margin-right: 8px; /* Фиксируем отступ для иконок */
        min-width: 20px; /* Задаем минимальную ширину для иконок */
        text-align: center;
    }

    .admin-nav .nav-link span {
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 150px; /* Ограничиваем ширину текста */
        display: inline-block;
    }

    .close-menu {
        display: block;
        width: 100%;
        text-align: left;
        background: none;
        border: none;
        color: white;
        font-size: 1.5rem;
        margin-bottom: 1rem;
    }

    .mobile-menu-btn {
        z-index: 1100;
        position: fixed;
        top: 10px;
        left: 10px;
        background-color: #0d6efd;
        border: none;
        border-radius: 50%;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        opacity: 0;
        transition: opacity 0.3s;
        pointer-events: none;
    }

    .overlay.active {
        opacity: 1;
        pointer-events: all;
    }

    .admin-main {
        flex: 1;
        padding: 20px;
        margin-left: 250px;
        padding-top: 20px;
        overflow-y: auto;
    }

    /* Медиа-запросы для мобильной версии */
    @media (max-width: 767.98px) {
        .admin-nav {
            transform: translateX(-100%); /* Меню скрыто по умолчанию */
            position: fixed;
            width: 250px; /* Фиксируем ширину на мобильных устройствах */
            overflow-x: hidden; /* Отключаем горизонтальную прокрутку */
        }
        .admin-nav.active {
            transform: translateX(0); /* Меню открывается при добавлении класса active */
        }
        .admin-main {
            padding: 15px;
            width: 100%;
            margin-left: 0;
            padding-top: 60px;
        }
        
        .card {
            border-radius: 8px;
        }
        
        .table-responsive {
            border-radius: 8px;
            overflow: hidden;
        }
        
        .nav-link {
            font-size: 14px;
            padding: 10px 12px !important;
        }
        
        .user-table-desktop {
            display: none;
        }
        
        .user-card-mobile {
            display: block;
        }

        /* Адаптация модального окна для мобильных устройств */
        #userDetailsModal .modal-dialog {
            margin: 0.5rem;
            max-width: none;
        }

        #userDetailsModal .modal-body .row {
            flex-direction: column;
        }

        #userDetailsModal .modal-body .col-md-6 {
            flex: 0 0 100%;
            max-width: 100%;
            padding: 0 0.5rem;
        }

        #userDetailsModal .card {
            margin-bottom: 1rem;
        }

        #userDetailsModal .card-body table {
            width: 100%;
            word-break: break-word;
        }

        #userDetailsModal .card-body th,
        #userDetailsModal .card-body td {
            padding: 0.25rem;
        }
    }

    @media (max-width: 576px) {
        .user-card-mobile .user-body {
            grid-template-columns: 1fr;
        }
        
        .user-card-mobile .col-span-2 {
            grid-column: auto;
        }
    }

    @media (min-width: 768px) {
        .admin-nav {
            transform: translateX(0);
            position: fixed;
        }
        .mobile-menu-btn {
            display: none;
        }
        .overlay {
            display: none;
        }
        .admin-main {
            padding-top: 20px;
        }
    }
</style>
</head>
<body class="admin-wrapper">
<div class="container-fluid">
    <div class="row flex-nowrap">
        <!-- Боковая панель -->
        <?php include '_sidebar.php'; ?>

        <!-- Основной контент -->
        <main class="admin-main">
            <!-- Кнопка мобильного меню -->
            <button class="btn btn-primary mobile-menu-btn d-md-none">
                <i class="bi bi-list"></i>
            </button>

            <!-- Оверлей -->
            <div class="overlay d-md-none"></div>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="display-6 text-dark fw-bold">Управление пользователями</h2>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-secondary" id="refresh-users">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                    <a href="logout.php" class="btn btn-danger d-md-none">
                        <i class="bi bi-box-arrow-right"></i>
                    </a>
                </div>
            </div>

            <!-- Фильтры -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET">
                        <div class="row g-3">
                            <div class="col-md-5 col-12">
                                <input type="text" name="search" class="form-control" 
                                       placeholder="Поиск по имени, email или телефону..." 
                                       value="<?= htmlspecialchars($search) ?>">
                            </div>
                            <div class="col-md-4 col-12">
                            </div>
                            <div class="col-md-3 col-12">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-funnel"></i> <span class="d-none d-md-inline">Применить</span>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <?php if(!empty($users)): ?>
                <!-- Десктопная версия (таблица) -->
                <div class="table-responsive user-table-desktop">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Имя пользователя</th>
                                <th class="d-none d-lg-table-cell">ФИО</th>
                                <th>Email</th>
                                <th class="d-none d-md-table-cell">Телефон</th>
                                <th>Email верифицирован</th>
                                <th>Дата регистрации</th>
                                <th>Заметка</th>
                                <th class="text-end">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($users as $user): ?>
                                <tr>
                                    <td><?= htmlspecialchars($user['id'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($user['username'] ?? '') ?></td>
                                    <td class="d-none d-lg-table-cell"><?= htmlspecialchars($user['full_name'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($user['email'] ?? '') ?></td>
                                    <td class="d-none d-md-table-cell"><?= htmlspecialchars($user['phone'] ?? '—') ?></td>
                                    <td>
                                        <span class="badge <?= $user['email_verified_at'] ? 'badge-verified' : 'badge-unverified' ?>">
                                            <?= $user['email_verified_at'] ? 'Да' : 'Нет' ?>
                                        </span>
                                    </td>
                                    <td><?= date('d.m.Y H:i', strtotime($user['created_at'] ?? '')) ?></td>
                                    <td>
                                        <?php if ($user['note_count'] > 0): ?>
                                            <i class="bi bi-flag-fill text-<?= $user['flag_color'] ?>"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-primary view-user-details" 
                                                data-user-id="<?= $user['id'] ?? '' ?>"
                                                data-username="<?= htmlspecialchars($user['username'] ?? '') ?>">
                                            <i class="bi bi-eye"></i> <span class="d-none d-md-inline">Подробнее</span>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Мобильная версия (карточки) -->
                <div class="user-cards-mobile">
                    <?php foreach($users as $user): ?>
                        <div class="user-card-mobile">
                            <div class="user-header">
                                <strong>ID: <?= htmlspecialchars($user['id'] ?? '') ?></strong>
                                <span><?= date('d.m.Y', strtotime($user['created_at'] ?? '')) ?></span>
                            </div>
                            <div class="user-body">
                                <div>
                                    <small class="text-muted">Имя пользователя</small>
                                    <div><?= htmlspecialchars($user['username'] ?? '') ?></div>
                                </div>
                                <div>
                                    <small class="text-muted">Email</small>
                                    <div><?= htmlspecialchars($user['email'] ?? '') ?></div>
                                </div>
                                <div>
                                    <small class="text-muted">ФИО</small>
                                    <div><?= htmlspecialchars($user['full_name'] ?? '—') ?></div>
                                </div>
                                <div>
                                    <small class="text-muted">Телефон</small>
                                    <div><?= htmlspecialchars($user['phone'] ?? '—') ?></div>
                                </div>
                                <div>
                                    <small class="text-muted">Email верифицирован</small>
                                    <div>
                                        <span class="badge <?= $user['email_verified_at'] ? 'badge-verified' : 'badge-unverified' ?>">
                                            <?= $user['email_verified_at'] ? 'Да' : 'Нет' ?>
                                        </span>
                                    </div>
                                </div>
                                <div>
                                    <small class="text-muted">Заметка</small>
                                    <div>
                                        <?php if ($user['note_count'] > 0): ?>
                                            <i class="bi bi-flag-fill text-<?= $user['flag_color'] ?>"></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-span-2 text-center mt-2">
                                    <button class="btn btn-sm btn-outline-primary view-user-details" 
                                            data-user-id="<?= $user['id'] ?? '' ?>"
                                            data-username="<?= htmlspecialchars($user['username'] ?? '') ?>">
                                        <i class="bi bi-eye"></i> Подробнее
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Пагинация -->
                <?php if ($total_items > $items_per_page): ?>
                    <nav class="mt-4">
                        <ul class="pagination justify-content-center flex-wrap">
                            <?php for ($i = 1; $i <= ceil($total_items / $items_per_page); $i++): ?>
                                <li class="page-item <?= $i === $current_page ? 'active' : '' ?>">
                                    <a class="page-link" 
                                       href="?page=<?= $i ?>&search=<?= urlencode($search) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-person fs-1 text-muted mb-3"></i>
                    <h4 class="text-dark mb-3">Нет пользователей</h4>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Модальное окно для деталей пользователя -->
<div class="modal fade" id="userDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <!-- Заголовок с именем пользователя -->
            <div class="modal-header bg-light">
                <h5 class="modal-title">Данные пользователя <span id="modal-username"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <!-- Табы с черным текстом -->
            <div class="modal-header bg-light pt-0 border-bottom-0">
                <ul class="nav nav-tabs card-header-tabs" id="userTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active text-dark fw-bold" id="user-details-tab" data-bs-toggle="tab" data-bs-target="#user-details" type="button" role="tab">Данные</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link text-dark fw-bold" id="user-notes-tab" data-bs-toggle="tab" data-bs-target="#user-notes" type="button" role="tab">Заметки</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link text-dark fw-bold" id="create-note-tab" data-bs-toggle="tab" data-bs-target="#create-note" type="button" role="tab">Создать заметку</button>
                    </li>
                </ul>
            </div>
            
            <div class="modal-body">
                <div class="tab-content" id="userTabsContent">
                    <div class="tab-pane fade show active" id="user-details" role="tabpanel">
                        <!-- Контент деталей пользователя будет здесь -->
                    </div>
                    <div class="tab-pane fade" id="user-notes" role="tabpanel">
                        <div id="notes-container" class="mb-3">
                            <!-- Заметки будут загружены здесь -->
                        </div>
                    </div>
                    <div class="tab-pane fade" id="create-note" role="tabpanel">
                        <form id="add-note-form">
                            <input type="hidden" name="entity_id" id="note-entity-id">
                            <input type="hidden" name="entity_type" value="customer">
                            <div class="mb-3">
                                <label class="form-label">Текст заметки</label>
                                <textarea class="form-control" name="content" rows="3" required></textarea>
                            </div>
                            <!-- Цвет флажка перенесен ниже -->
                            <div class="mb-3">
                                <label class="form-label">Цвет флажка</label>
                                <div class="flag-color-group">
                                    <button type="button" class="btn btn-success flag-color-btn active" data-color="success">Зеленый</button>
                                    <button type="button" class="btn btn-warning flag-color-btn" data-color="warning">Желтый</button>
                                    <button type="button" class="btn btn-danger flag-color-btn" data-color="danger">Красный</button>
                                </div>
                                <input type="hidden" name="flag_color" value="success">
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                <button type="button" class="btn btn-primary" id="verify-user-email" style="display: none;">
                    Верифицировать Email
                </button>
                <button type="button" class="btn btn-primary" id="add-note-button" style="display: none;">
                    Добавить заметку
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Скрытое поле для CSRF-токена -->
<input type="hidden" id="csrfToken" value="<?= htmlspecialchars($csrf_token) ?>">

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const menuBtn = document.querySelector('.mobile-menu-btn');
    const closeBtn = document.querySelector('.close-menu');
    const sidebar = document.querySelector('.admin-nav');
    const overlay = document.querySelector('.overlay');

    function toggleMenu(event) {
        event.preventDefault();
        const isOpening = !sidebar.classList.contains('active');
        sidebar.classList.toggle('active', isOpening);
        overlay.classList.toggle('active', isOpening);
        document.body.classList.toggle('menu-open', isOpening);
        overlay.style.display = isOpening ? 'block' : 'none';
        setTimeout(() => {
            if (!isOpening) overlay.style.display = 'none';
        }, 300);
    }

    if (menuBtn) menuBtn.addEventListener('click', toggleMenu);
    if (closeBtn) closeBtn.addEventListener('click', toggleMenu);
    if (overlay) overlay.addEventListener('click', toggleMenu);

    // Сброс состояния при изменении размера окна
    window.addEventListener('resize', () => {
        if (window.innerWidth >= 768) {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
            overlay.style.display = 'none';
            document.body.classList.remove('menu-open');
        }
    });

    // Инициализация
    if (window.innerWidth < 768) {
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
        overlay.style.display = 'none';
    }

    // Инициализация модального окна
    const modal = new bootstrap.Modal(document.getElementById('userDetailsModal'));
    let currentUserId = null;
    let currentUserData = null;
    let currentOrderPage = 1;
    const ordersPerPage = 5;
    const verifyButton = document.getElementById('verify-user-email');
    const addNoteButton = document.getElementById('add-note-button');

    // Обработчик кнопки "Обновить"
    document.getElementById('refresh-users').addEventListener('click', function() {
        location.reload();
    });

    // Обработчик кнопки "Подробнее"
    document.querySelectorAll('.view-user-details').forEach(btn => {
        btn.addEventListener('click', function() {
            currentUserId = this.dataset.userId;
            const username = this.dataset.username;
            document.getElementById('modal-username').textContent = username;
            currentOrderPage = 1;
            loadUserDetails(currentUserId);
        });
    });

    // Управление кнопками при переключении вкладок
    document.querySelectorAll('#userTabs button[data-bs-toggle="tab"]').forEach(tab => {
        tab.addEventListener('shown.bs.tab', event => {
            const targetId = event.target.getAttribute('data-bs-target');
            
            // Скрыть все кнопки в футере кроме "Закрыть"
            verifyButton.style.display = 'none';
            addNoteButton.style.display = 'none';
            
            // Показать соответствующие кнопки для активной вкладки
            if (targetId === '#user-details') {
                if (currentUserData && currentUserData.user && !currentUserData.user.email_verified_at) {
                    verifyButton.style.display = 'inline-block';
                }
            } 
            else if (targetId === '#create-note') {
                addNoteButton.style.display = 'inline-block';
            }
        });
    });

    // Сохраняем оригинальный текст кнопки
    addNoteButton._originalText = addNoteButton.innerHTML;
    
    // Обработчик кнопки "Добавить заметку"
    addNoteButton.addEventListener('click', function() {
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status"></span> Добавление...`;
        // Отправляем форму
        document.getElementById('add-note-form').dispatchEvent(new Event('submit'));
    });

    // Функция загрузки деталей пользователя
    async function loadUserDetails(userId) {
        const contentDiv = document.getElementById('user-details');
        const csrfToken = document.getElementById('csrfToken').value;
        
        try {
            contentDiv.innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Загрузка...</span>
                    </div>
                    <p class="mt-2">Загрузка данных пользователя...</p>
                </div>`;
            
            modal.show();
            
            const response = await fetch(`users/get_user_details.php?user_id=${userId}&csrf_token=${encodeURIComponent(csrfToken)}`);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.status !== 'success') {
                throw new Error(data.message || 'Ошибка при загрузке данных');
            }
            
            currentUserData = data.data;
            renderUserDetails(data.data);
            
            // Установка ID для заметок
            document.getElementById('note-entity-id').value = userId;
            
            // Загрузка заметок
            loadNotes(userId, 'customer');
            
        } catch (error) {
            console.error('Ошибка:', error);
            contentDiv.innerHTML = `
                <div class="alert alert-danger">
                    <h5>Ошибка при загрузке данных пользователя</h5>
                    <p>${error.message}</p>
                    <button class="btn btn-sm btn-outline-primary" onclick="loadUserDetails(${userId})">
                        <i class="bi bi-arrow-clockwise"></i> Попробовать снова
                    </button>
                </div>`;
        }
    }

    // Функция отображения деталей пользователя
    function renderUserDetails(userData) {
        const contentDiv = document.getElementById('user-details');
        
        if (!userData || !userData.user || !userData.customer) {
            contentDiv.innerHTML = '<div class="alert alert-danger">Ошибка: Неполные данные пользователя</div>';
            return;
        }

        const formatDate = (dateString) => (!dateString ? '—' : new Date(dateString).toLocaleString('ru-RU'));

        const user = userData.user || {};
        const customer = userData.customer || {};
        const orders = userData.orders || [];

        const totalOrders = orders.length;
        const totalPages = Math.max(1, Math.ceil(totalOrders / ordersPerPage));
        currentOrderPage = Math.min(currentOrderPage, totalPages);
        const startIndex = (currentOrderPage - 1) * ordersPerPage;
        const endIndex = startIndex + ordersPerPage;
        const paginatedOrders = orders.slice(startIndex, endIndex);

        let ordersHtml = '';
        if (paginatedOrders.length > 0) {
            ordersHtml = `
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Заказы пользователя</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-bordered mb-3">
                            <thead>
                                <tr>
                                    <th>Номер заказа</th>
                                    <th>Дата создания</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${paginatedOrders.map(order => `
                                    <tr>
                                        <td>${order.order_number || '—'}</td>
                                        <td>${formatDate(order.created_at)}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                        ${totalOrders > ordersPerPage ? `
                            <nav>
                                <ul class="pagination justify-content-center">
                                    ${Array.from({ length: totalPages }, (_, i) => i + 1).map(page => `
                                        <li class="page-item ${page === currentOrderPage ? 'active' : ''}">
                                            <button class="page-link" onclick="changeOrderPage(${page})">${page}</button>
                                        </li>
                                    `).join('')}
                                </ul>
                            </nav>
                        ` : ''}
                    </div>
                </div>
            `;
        } else if (orders.length === 0) {
            ordersHtml = `
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Заказы пользователя</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">У пользователя нет заказов.</p>
                    </div>
                </div>
            `;
        } else {
            ordersHtml = `
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Заказы пользователя</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Нет заказов на этой странице.</p>
                    </div>
                </div>
            `;
        }

        contentDiv.innerHTML = `
            <div class="container-fluid">
                <div class="row">
                    <div class="col-md-6 col-12">
                        <div class="card mb-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">Данные пользователя</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered mb-0">
                                    <tbody>
                                        <tr>
                                            <th width="40%">ID</th>
                                            <td>${user.id || '—'}</td>
                                        </tr>
                                        <tr>
                                            <th>Имя пользователя</th>
                                            <td>${user.username || '—'}</td>
                                        </tr>
                                        <tr>
                                            <th>Email</th>
                                            <td>${user.email || '—'}</td>
                                        </tr>
                                        <tr>
                                            <th>Email верифицирован</th>
                                            <td>
                                                <span class="badge ${user.email_verified_at ? 'badge-verified' : 'badge-unverified'}">
                                                    ${user.email_verified_at ? 'Да' : 'Нет'}
                                                </span>
                                                ${user.email_verified_at ? '<br>' + formatDate(user.email_verified_at) : ''}
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Дата регистрации</th>
                                            <td>${formatDate(user.created_at)}</td>
                                        </tr>
                                        <tr>
                                            <th>Последнее обновление</th>
                                            <td>${formatDate(user.updated_at)}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-12">
                        <div class="card mb-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">Профиль пользователя</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered mb-0">
                                    <tbody>
                                        <tr>
                                            <th width="40%">ФИО</th>
                                            <td id="customerName">${customer.full_name || '—'}</td>
                                        </tr>
                                        <tr>
                                            <th>Телефон</th>
                                            <td id="customerPhone">${customer.phone || '—'}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        ${ordersHtml}
                    </div>
                </div>
            </div>
        `;

        if (!user.email_verified_at) {
            verifyButton.style.display = 'block';
            verifyButton.addEventListener('click', async () => {
                const btn = verifyButton;
                const originalText = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status"></span> Верификация...`;

                try {
                    const csrfToken = document.getElementById('csrfToken').value;
                    const response = await fetch('users/verify_email.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `user_id=${encodeURIComponent(user.id)}&csrf_token=${encodeURIComponent(csrfToken)}`
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }

                    const data = await response.json();

                    if (data.status === 'success') {
                        contentDiv.innerHTML = `
                            <div class="alert alert-success text-center">
                                <h5>Успех!</h5>
                                <p>Email успешно верифицирован.</p>
                                <p>Страница будет перезагружена через 2 секунды...</p>
                            </div>`;
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        throw new Error(data.message || 'Неизвестная ошибка');
                    }
                } catch (error) {
                    console.error('Ошибка:', error);
                    contentDiv.innerHTML = `
                        <div class="alert alert-danger">
                            <h5>Ошибка при верификации</h5>
                            <p>${error.message}</p>
                            <button class="btn btn-sm btn-outline-primary" onclick="loadUserDetails(${user.id})">
                                <i class="bi bi-arrow-left"></i> Вернуться к данным
                            </button>
                        </div>`;
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            });
        } else {
            verifyButton.style.display = 'none';
        }
        
        // Обработчики для кнопок цвета заметок
        document.querySelectorAll('.flag-color-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.flag-color-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                document.querySelector('input[name="flag_color"]').value = this.dataset.color;
            });
        });
        
        // Обработчик табуляции в textarea
        document.querySelectorAll('textarea[name="content"]').forEach(textarea => {
            textarea.addEventListener('keydown', function(e) {
                if (e.key === 'Tab') {
                    e.preventDefault();
                    const start = this.selectionStart;
                    const end = this.selectionEnd;
                    
                    // Вставляем табуляцию
                    this.value = this.value.substring(0, start) + '\t' + this.value.substring(end);
                    
                    // Перемещаем курсор
                    this.selectionStart = this.selectionEnd = start + 1;
                }
            });
        });
        
        // Обработчик добавления заметки
        document.getElementById('add-note-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('csrf_token', document.getElementById('csrfToken').value);
            
            try {
                const response = await fetch('notes_api/add_note.php', {
                    method: 'POST',
                    body: formData
                });
                
                if (!response.ok) {
                    const errorText = await response.text();
                    throw new Error(`Ошибка сети: ${response.status}, ${errorText}`);
                }

                const result = await response.json();
                if (result.error) {
                    throw new Error(result.error);
                }
                
                this.reset();
                loadNotes(currentUserId, 'customer');
                setTimeout(() => location.reload(), 1000);
                
            } catch (error) {
                console.error('Ошибка:', error);
                alert('Ошибка при добавлении заметки: ' + error.message);
                // Восстанавливаем кнопку при ошибке
                if (addNoteButton) {
                    addNoteButton.disabled = false;
                    addNoteButton.innerHTML = addNoteButton._originalText;
                }
            }
        });
    }
    
    // Функция загрузки заметок
    async function loadNotes(entityId, entityType) {
        const container = document.getElementById('notes-container');
        container.innerHTML = '<div class="text-center"><div class="spinner-border"></div></div>';

        try {
            const response = await fetch(`notes_api/get_notes.php?entity_id=${entityId}&entity_type=${entityType}`);
            const notes = await response.json();
            
            let html = '';
            if (notes.length > 0) {
                notes.forEach(note => {
                    html += `
                    <div class="card mb-2">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <span class="badge bg-${note.flag_color} me-2">${note.created_at}</span>
                                    ${note.admin_name}
                                </div>
                                <button class="btn btn-sm btn-outline-danger delete-note" data-note-id="${note.id}">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                            <div class="mt-2 note-content">${note.content}</div>
                        </div>
                    </div>`;
                });
            } else {
                html = '<p class="text-center text-muted">Нет заметок</p>';
            }
            
            container.innerHTML = html;
            
            // Обработчики удаления
            document.querySelectorAll('.delete-note').forEach(btn => {
                btn.addEventListener('click', async function() {
                    const noteId = this.dataset.noteId;
                    const formData = new FormData();
                    formData.append('note_id', noteId);
                    formData.append('csrf_token', document.getElementById('csrfToken').value);
                    
                    const response = await fetch('notes_api/delete_note.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    if (response.ok) {
                        loadNotes(entityId, entityType);
                        // Обновляем страницу чтобы обновить флажки в списке
                        setTimeout(() => location.reload(), 1000);
                    }
                });
            });
            
        } catch (error) {
            console.error('Ошибка загрузки заметок:', error);
            container.innerHTML = '<div class="alert alert-danger">Ошибка загрузки заметок</div>';
        }
    }

    // Функция смены страницы заказов
    window.changeOrderPage = function(page) {
        currentOrderPage = page;
        renderUserDetails(currentUserData);
    };
});
</script>
</body>
</html>