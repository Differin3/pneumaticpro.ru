<?php
session_start();
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

// Проверка авторизации администратора
if (!isset($_SESSION['admin'])) {
    header('Location: login.php');
    exit();
}

// Подключение к базе данных
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    $_SESSION['error'] = "Ошибка подключения к базе данных. Пожалуйста, попробуйте позже.";
    header('Location: products.php');
    exit();
}

// Обработка удаления
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id']) && isset($_GET['table'])) {
    $id = (int)$_GET['id'];
    $table = in_array($_GET['table'], ['products', 'services']) ? $_GET['table'] : null;
    
    if ($table) {
        try {
            // Получаем информацию для удаления изображения
            $stmt = $pdo->prepare("SELECT image FROM $table WHERE id = ?");
            $stmt->execute([$id]);
            $item = $stmt->fetch();
            
            if ($item) {
                // Удаляем изображение, если оно существует
                $uploadDir = "../Uploads/$table/";
                if (!empty($item['image']) && file_exists($uploadDir . $item['image'])) {
                    unlink($uploadDir . $item['image']);
                }
                
                // Удаляем запись из базы данных
                $stmt = $pdo->prepare("DELETE FROM $table WHERE id = ?");
                $stmt->execute([$id]);
                
                $_SESSION['success'] = ucfirst($table === 'products' ? 'Товар' : 'Услуга') . ' успешно удален!';
                header('Location: products.php');
                exit();
            }
        } catch (PDOException $e) {
            error_log("Error deleting item: " . $e->getMessage());
            $_SESSION['error'] = 'Ошибка при удалении: ' . htmlspecialchars($e->getMessage());
            header('Location: products.php');
            exit();
        }
    } else {
        $_SESSION['error'] = 'Недопустимая таблица';
        header('Location: products.php');
        exit();
    }
}

// Получение списка товаров и услуг
try {
    $sql = "
        SELECT 
            p.id,
            p.image,
            p.name,
            p.vendor_code,
            c.name AS category_name,
            'bullet' AS type,
            p.price,
            p.availability AS availability,
            'products' AS table_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        UNION ALL
        SELECT 
            s.id,
            s.image,
            s.name,
            s.vendor_code,
            c.name AS category_name,
            'service' AS type,
            s.price,
            'in_stock' AS availability,
            'services' AS table_name
        FROM services s
        LEFT JOIN categories c ON s.category_id = c.id
        ORDER BY id DESC";
    
    $stmt = $pdo->query($sql);
    $items = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching items: " . $e->getMessage());
    $_SESSION['error'] = "Ошибка при получении списка товаров и услуг.";
    $items = [];
}

// Получение списка заказов
try {
    $sql = "
        SELECT o.order_number, o.total, o.status, o.created_at, o.delivery_service, o.tracking_number,
               c.full_name AS customer_name
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        ORDER BY o.created_at DESC";
    $stmt = $pdo->query($sql);
    $orders = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching orders: " . $e->getMessage());
    $_SESSION['error'] = "Ошибка при получении списка заказов.";
    $orders = [];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление товарами и услугами | Админ-панель</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .admin-wrapper { min-height: 100vh; display: flex; }
        .admin-nav { background: #2c3e50; width: 250px; padding: 20px; }
        .admin-main { flex: 1; padding: 20px; }
        .product-image-thumb { width: 60px; height: 60px; object-fit: cover; border-radius: 4px; }
        .table-responsive { overflow-x: auto; }
    </style>
</head>
<body class="admin-wrapper">
    <!-- Боковая панель -->
    <?php include '_sidebar.php'; ?>

    <!-- Основной контент -->
    <main class="admin-main">
        <div class="container-fluid mt-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="bi bi-box-seam"></i> Управление товарами и услугами</h2>
                <a href="add_product.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Добавить товар/услугу
                </a>
            </div>
            
            <!-- Вывод сообщений об ошибках/успехе -->
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error']) ?></div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success']) ?></div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>
            
            <!-- Список товаров и услуг -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>ID</th>
                                    <th>Изображение</th>
                                    <th>Название</th>
                                    <th>Артикул</th>
                                    <th>Категория</th>
                                    <th>Тип</th>
                                    <th>Цена</th>
                                    <th>Статус</th>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><?= $item['id'] ?></td>
                                    <td>
                                        <?php if (!empty($item['image'])): ?>
                                            <img src="../Uploads/<?= $item['table_name'] ?>/<?= htmlspecialchars($item['image']) ?>" 
                                                 class="product-image-thumb"
                                                 alt="<?= htmlspecialchars($item['name']) ?>">
                                        <?php else: ?>
                                            <span class="text-muted">Нет</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($item['name']) ?></td>
                                    <td><?= htmlspecialchars($item['vendor_code']) ?></td>
                                    <td><?= htmlspecialchars($item['category_name'] ?? 'Без категории') ?></td>
                                    <td>
                                        <?php if ($item['type'] === 'bullet'): ?>
                                            <span class="badge bg-primary">Пульки</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Услуга</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= number_format($item['price'], 2) ?> ₽</td>
                                    <td>
                                        <?php if ($item['availability'] === 'in_stock'): ?>
                                            <span class="badge bg-success">В наличии</span>
                                        <?php elseif ($item['availability'] === 'pre_order'): ?>
                                            <span class="badge bg-warning text-dark">Под заказ</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Нет в наличии</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="edit_<?= $item['table_name'] ?>.php?id=<?= $item['id'] ?>" 
                                               class="btn btn-outline-primary"
                                               title="Редактировать">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="products.php?action=delete&id=<?= $item['id'] ?>&table=<?= $item['table_name'] ?>" 
                                               class="btn btn-outline-danger"
                                               title="Удалить"
                                               onclick="return confirm('Вы уверены, что хотите удалить этот элемент?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Список заказов -->
            <div class="card">
                <div class="card-body">
                    <h3>Заказы</h3>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Nº заказа</th>
                                    <th>Клиент</th>
                                    <th>Дата</th>
                                    <th>Сумма</th>
                                    <th>Статус</th>
                                    <th>Транспортная компания</th>
                                    <th>Трек-номер</th>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td><?= htmlspecialchars($order['order_number']) ?></td>
                                    <td><?= htmlspecialchars($order['customer_name']) ?></td>
                                    <td><?= date('d.m.Y H:i', strtotime($order['created_at'])) ?></td>
                                    <td><?= number_format($order['total'], 2) ?> ₽</td>
                                    <td>
                                        <?php if ($order['status'] === 'new'): ?>
                                            <span class="badge bg-info">Новый</span>
                                        <?php elseif ($order['status'] === 'processing'): ?>
                                            <span class="badge bg-warning">В обработке</span>
                                        <?php elseif ($order['status'] === 'shipped'): ?>
                                            <span class="badge bg-primary">Отправлен</span>
                                        <?php elseif ($order['status'] === 'completed'): ?>
                                            <span class="badge bg-success">Завершен</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Отменен</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($order['delivery_service']) ?></td>
                                    <td><?= htmlspecialchars($order['tracking_number'] ?? '-') ?></td>
                                    <td>
                                        <button class="btn btn-outline-primary btn-sm" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#orderDetailsModal"
                                                data-order-number="<?= htmlspecialchars($order['order_number']) ?>">
                                            <i class="bi bi-eye"></i> Подробнее
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Модальное окно для деталей заказа -->
    <div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-labelledby="orderDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="orderDetailsModalLabel">Детали заказа</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p><strong>Номер заказа:</strong> <span id="modal-order-number"></span></p>
                    <p><strong>Клиент:</strong> <span id="modal-customer-name"></span></p>
                    <p><strong>Сумма:</strong> <span id="modal-total"></span> ₽</p>
                    <h6>Товары и услуги:</h6>
                    <ul id="modal-order-items"></ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // JavaScript для загрузки деталей заказа в модальное окно
        const orderDetailsModal = document.getElementById('orderDetailsModal');
        orderDetailsModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const orderNumber = button.getAttribute('data-order-number');

            // Загружаем данные через AJAX
            fetch(`get_order_details.php?order_number=${orderNumber}`)
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(err => {
                            throw new Error(err.message || `HTTP error! status: ${response.status}`);
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.status === 'error') {
                        throw new Error(data.message);
                    }

                    // Успешный ответ
                    document.getElementById('modal-order-number').textContent = data.data.order.order_number;
                    document.getElementById('modal-customer-name').textContent = data.data.customer.full_name;
                    document.getElementById('modal-total').textContent = parseFloat(data.data.order.total).toFixed(2);

                    const itemsList = document.getElementById('modal-order-items');
                    itemsList.innerHTML = '';
                    data.data.items.forEach(item => {
                        const li = document.createElement('li');
                        li.textContent = `${item.name} (x${item.quantity}) - ${parseFloat(item.price).toFixed(2)} ₽`;
                        itemsList.appendChild(li);
                    });
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert(`Ошибка при загрузке данных заказа: ${error.message}`);
                });
        });
    </script>
</body>
</html>