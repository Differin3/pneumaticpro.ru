<?php
// Получаем код ошибки из параметров сервера
$error_code = $_SERVER['REDIRECT_STATUS'] ?? $_SERVER['ERROR_STATUS'] ?? 500;

// Если код не передан, пытаемся получить из параметра запроса
if (empty($error_code) || $error_code === '200') {
    $error_code = $_GET['code'] ?? 500;
}

// Тексты ошибок
$errors = [
    400 => [
        'title' => 'Неверный запрос',
        'message' => 'Сервер не смог понять ваш запрос из-за неверного синтаксиса.',
        'icon' => 'bi bi-exclamation-triangle'
    ],
    401 => [
        'title' => 'Требуется авторизация',
        'message' => 'Для доступа к этой странице необходимо войти в систему.',
        'icon' => 'bi bi-shield-lock'
    ],
    403 => [
        'title' => 'Доступ запрещен',
        'message' => 'У вас нет прав для просмотра этого содержимого.',
        'icon' => 'bi bi-lock'
    ],
    404 => [
        'title' => 'Страница не найдена',
        'message' => 'Запрашиваемая страница не существует или была перемещена.',
        'icon' => 'bi bi-binoculars'
    ],
    405 => [
        'title' => 'Метод не разрешен',
        'message' => 'Используемый метод HTTP не поддерживается для этого ресурса.',
        'icon' => 'bi bi-slash-circle'
    ],
    429 => [
        'title' => 'Слишком много запросов',
        'message' => 'Вы превысили лимит запросов. Пожалуйста, попробуйте позже.',
        'icon' => 'bi bi-hourglass-split'
    ],
    500 => [
        'title' => 'Внутренняя ошибка сервера',
        'message' => 'На сервере произошла непредвиденная ошибка. Мы уже работаем над решением.',
        'icon' => 'bi bi-server'
    ],
    502 => [
        'title' => 'Плохой шлюз',
        'message' => 'Сервер получил недопустимый ответ от вышестоящего сервера.',
        'icon' => 'bi bi-router'
    ],
    503 => [
        'title' => 'Сервис недоступен',
        'message' => 'Сервер временно не может обрабатывать запросы. Ведутся технические работы.',
        'icon' => 'bi bi-tools'
    ],
    504 => [
        'title' => 'Таймаут шлюза',
        'message' => 'Сервер не получил своевременного ответа от вышестоящего сервера.',
        'icon' => 'bi bi-clock-history'
    ]
];

// Проверка допустимости кода ошибки
$error_code = in_array($error_code, array_keys($errors)) ? $error_code : 500;
$error = $errors[$error_code] ?? $errors[500];

// Установка HTTP статуса
http_response_code($error_code);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ошибка <?= $error_code ?> | pnevmatpro.ru</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/error.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="icon" href="https://pnevmatpro.ru/assets/favicon.png" type="image/png">
    <style>
        .error-hero {
            background: linear-gradient(135deg, #2A3950 0%, #686f7c 100%);
            min-height: 60vh;
            clip-path: polygon(0 0, 100% 0, 100% 85%, 0 100%);
        }
        .error-animation {
            animation: float 6s ease-in-out infinite;
        }
        .error-code {
            font-size: 8rem;
            font-weight: 900;
            text-shadow: 0 5px 15px rgba(0,0,0,0.3);
            line-height: 1;
            color: rgba(255,255,255,0.9);
        }
        .error-icon {
            font-size: 6rem;
            margin-bottom: 1.5rem;
            color: var(--accent);
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        .error-card {
            transition: all 0.3s ease;
            animation: pulse 3s infinite;
            border-top: 4px solid var(--accent);
        }
        .error-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 30px rgba(0,0,0,0.2);
        }
        @media (max-width: 768px) {
            .error-code {
                font-size: 5rem;
            }
            .error-icon {
                font-size: 4rem;
            }
            .error-hero {
                min-height: 50vh;
                clip-path: polygon(0 0, 100% 0, 100% 90%, 0 100%);
            }
        }
    </style>
</head>
<body>
    <!-- Шапка ошибки -->
    <header class="error-hero bg-dark text-white d-flex align-items-center">
        <div class="container text-center py-5">
            <div class="error-animation mb-4">
                <i class="<?= $error['icon'] ?> error-icon"></i>
            </div>
            <h1 class="error-code mb-0"><?= $error_code ?></h1>
            <h2 class="display-4 mb-4"><?= $error['title'] ?></h2>
            <p class="lead fs-3"><?= $error['message'] ?></p>
        </div>
    </header>

    <!-- Основной контент -->
    <main class="py-5 bg-light">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="error-card card border-0 shadow-lg p-4">
                        <div class="card-body text-center">
                            <div class="decorative-line bg-accent mx-auto mb-4"></div>
                            
                            <h3 class="h2 mb-4">Что можно сделать?</h3>
                            
                            <div class="row g-4 mt-4">
                                <div class="col-md-4">
                                    <div class="icon-wrapper bg-accent-light mx-auto mb-3">
                                        <i class="bi bi-house-door fs-1 text-accent"></i>
                                    </div>
                                    <p>Вернитесь на главную страницу</p>
                                    <a href="/" class="btn btn-outline-primary rounded-pill">На главную</a>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="icon-wrapper bg-accent-light mx-auto mb-3">
                                        <i class="bi bi-arrow-left-right fs-1 text-accent"></i>
                                    </div>
                                    <p>Попробуйте перезагрузить страницу</p>
                                    <button onclick="location.reload()" class="btn btn-outline-primary rounded-pill">
                                        <i class="bi bi-arrow-repeat"></i> Обновить
                                    </button>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="icon-wrapper bg-accent-light mx-auto mb-3">
                                        <i class="bi bi-envelope fs-1 text-accent"></i>
                                    </div>
                                    <p>Сообщите нам о проблеме</p>
                                    <a href="mailto:support@pnevmatpro.ru" class="btn btn-outline-primary rounded-pill">
                                        Написать
                                    </a>
                                </div>
                            </div>
                            
                            <div class="mt-5">
                                <h4 class="mb-3">Или воспользуйтесь поиском по сайту:</h4>
                                <form action="/" method="GET" class="d-flex mb-4">
                                    <input type="text" name="search" class="form-control me-2 rounded-pill" 
                                           placeholder="Поиск товаров и услуг...">
                                    <button type="submit" class="btn btn-primary rounded-pill">
                                        <i class="bi bi-search"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Футер -->
    <footer class="bg-dark text-white py-4">
        <div class="container text-center">
            <p>© 2025 pnevmatpro.ru. «пневматПро», ИНН:345801776616</p>
            <p>
                <a href="/privacy-policy.php" class="text-white text-decoration-none">Политика конфиденциальности</a>
            </p>
            <p>Техническая поддержка: support@pnevmatpro.ru</p>
        </div>
    </footer>

    <script>
        // Анимация для иконки ошибки
        document.addEventListener('DOMContentLoaded', () => {
            const errorIcon = document.querySelector('.error-animation');
            if (errorIcon) {
                setInterval(() => {
                    errorIcon.style.transform = 'rotate(' + (Math.random() * 10 - 5) + 'deg)';
                }, 3000);
            }
        });
    </script>
</body>
</html>