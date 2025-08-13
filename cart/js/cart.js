$(document).ready(function() {
    const cartBadges = document.querySelectorAll('.cart-badge');

    const updateCartBadge = (count) => {
        cartBadges.forEach(badge => badge.textContent = count);
    };

    const showToast = (message, type = 'info') => {
        const toast = document.createElement('div');
        toast.className = `toast align-items-center text-white bg-${type} border-0`;
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        document.body.appendChild(toast);
        new bootstrap.Toast(toast, { autohide: true, delay: 3000 }).show();
        setTimeout(() => toast.remove(), 3500);
    };

    // Функция для парсинга города из адреса
    const parseCityFromAddress = (address) => {
        if (!address) return 'Москва';
        const parts = address.split(',').map(part => part.trim());
        let city = parts[2] || parts[3] || 'Москва';
        city = city.replace(/[^А-Яа-я\s-]/gu, '');
        return city || 'Москва';
    };

    // Показ/скрытие поля ПВЗ и кнопки "Выбрать ПВЗ"
    $('#delivery_company').on('change', function() {
        const value = $(this).val();
        const $pickupField = $('#pickup_point_field');
        const $selectPvzButton = $('#select_pvz');

        $pickupField.toggle(value !== '');
        $('#pickup_point').prop('required', value !== '');

        const shouldShowButton = (value === 'cdek' || value === 'post');
        $selectPvzButton.toggle(shouldShowButton);

        if (!shouldShowButton) {
            $('#pickup_point').val('').prop('readonly', false);
            $('#pickup_city').val('');
            $('#pickup_point_code').val('');
            $('#pvz_code_display').text('Не выбран');
            console.log('Сброс полей: pickup_city, pickup_point_code очищены');
        }

        console.log('Служба доставки:', value, 'Поле ПВЗ видимо:', $pickupField.is(':visible'), 'Кнопка ПВЗ видимо:', $selectPvzButton.is(':visible'));
    }).trigger('change');

    // Открытие модального окна для выбора ПВЗ
    $('#select_pvz').on('click', function() {
        $('#pvz_modal').modal('show');
        console.log('Модальное окно ПВЗ открыто');
    });

    // Валидация поля поиска ПВЗ
    $('#pvz_search').on('input', function() {
        const value = this.value.trim();
        const valid = value === '' || /^[А-Яа-я\s-]{1,100}$/u.test(value) && !value.match(/ул\.|улица|дом|д\./i);
        this.setCustomValidity(valid ? '' : 'Введите название города, а не адрес.');
    });

    // Поиск ПВЗ
    $('#search_pvz').on('click', function() {
        const query = $('#pvz_search').val().trim();
        const delivery_company = $('#delivery_company').val();
        const csrf_token = $('input[name="csrf_token"]').val();

        if (!query) {
            $('#pvz_list').html('<div class="alert alert-warning">Введите город или индекс.</div>');
            console.log('Пустой запрос для поиска ПВЗ');
            return;
        }

        if (!csrf_token) {
            $('#pvz_list').html('<div class="alert alert-danger">Ошибка: CSRF-токен отсутствует.</div>');
            console.log('CSRF-токен отсутствует');
            return;
        }

        console.log('delivery_company:', delivery_company);
        const api_url = delivery_company === 'cdek' ? '/api/get_cdek_pvz.php' : '/api/get_pvz.php';
        console.log('Запрос ПВЗ для:', query, 'API:', api_url);

        $.ajax({
            url: api_url,
            type: 'POST',
            data: { 
                query: query, 
                delivery_company: delivery_company,
                csrf_token: csrf_token 
            },
            dataType: 'json',
            beforeSend: function() {
                $('#pvz_list').html('<div class="text-center"><i class="bi bi-spinner"></i> Загрузка...</div>');
            },
            success: function(response) {
                console.log('Ответ от API:', response);
                if (response.status === 'success') {
                    if (response.data.length > 0) {
                        const pvzHtml = response.data
                            .filter(pvz => pvz.code) // Фильтр пунктов с кодом
                            .map(pvz => {
                                const city = pvz.city || parseCityFromAddress(pvz.address);
                                return `
                                    <div class="pvz-item" 
                                         data-address="${pvz.address}" 
                                         data-code="${pvz.code}" 
                                         data-city="${city}">
                                        <strong>${pvz.address}</strong>
                                        <br><small>Код ПВЗ: ${pvz.code}</small>
                                    </div>
                                `;
                            }).join('');
                        $('#pvz_list').html(pvzHtml || '<div class="alert alert-info">Пункты выдачи с действительными кодами не найдены</div>');
                    } else {
                        $('#pvz_list').html('<div class="alert alert-info">' + (response.message || 'Пункты выдачи не найдены') + '</div>');
                    }
                } else {
                    $('#pvz_list').html('<div class="alert alert-danger">Ошибка: ' + (response.message || 'Неизвестная ошибка') + '</div>');
                }
            },
            error: function(xhr) {
                console.log('Ошибка API:', xhr.responseText);
                $('#pvz_list').html('<div class="alert alert-danger">Ошибка сервера: ' + (xhr.responseJSON?.message || xhr.statusText) + '</div>');
            }
        });
    });

    // Выбор ПВЗ
    $('#pvz_list').on('click', '.pvz-item', function() {
        const address = $(this).attr('data-address') || '';
        const code = $(this).attr('data-code') || '';
        const city = $(this).attr('data-city') || parseCityFromAddress(address);
        console.log('Данные ПВЗ:', { address, city, code });

        if (!address || !city) {
            showToast('Адрес или город пункта выдачи не указаны', 'danger');
            return;
        }
        if (!code && $('#delivery_company').val() === 'cdek') {
            showToast('Выберите пункт выдачи с действительным кодом ПВЗ', 'danger');
            return;
        }

        // Установка значений с помощью jQuery
        $('#pickup_point').val(address).prop('readonly', true);
        $('#pickup_city').val(city);
        $('#pickup_point_code').val(code);
        $('#pvz_code_display').text(code || 'Не выбран');
        
        console.log('Установка значений:', {
            pickup_point: address,
            pickup_city: city,
            pickup_point_code: code,
            pvz_code_display: code || 'Не выбран'
        });

        $('#pvz_modal').modal('hide');
    });

    // Обработка закрытия модального окна
    $('#pvz_modal').on('hidden.bs.modal', function () {
        $(this).removeAttr('aria-hidden');
        document.activeElement.blur();
        setTimeout(() => $('#select_pvz').focus(), 200);
        console.log('Модальное окно закрыто, фокус перенесён на #select_pvz');
    }).on('shown.bs.modal', function () {
        $(this).attr('aria-hidden', 'false');
        console.log('Модальное окно открыто, aria-hidden установлен в false');
    });

    // Валидация формы
    $('#checkoutForm').on('submit', function(e) {
        e.preventDefault();
        if (!isLoggedIn) {
            showToast('Требуется авторизация для оформления заказа', 'danger');
            return;
        }

        const form = this;
        const pickupPoint = $('#pickup_point').val().trim();
        const deliveryCompany = $('#delivery_company').val();
        const pickupPointCode = $('#pickup_point_code').val().trim();
        const pickupCity = $('#pickup_city').val().trim();

        if ((deliveryCompany === 'cdek' || deliveryCompany === 'post') && !pickupPoint) {
            showToast('Укажите адрес пункта выдачи', 'danger');
            form.classList.add('was-validated');
            return;
        }

        if (deliveryCompany === 'cdek' && !pickupPointCode) {
            showToast('Код ПВЗ обязателен для CDEK. Выберите другой пункт.', 'danger');
            form.classList.add('was-validated');
            return;
        }

        if (deliveryCompany === 'cdek' && !pickupCity) {
            const city = parseCityFromAddress(pickupPoint);
            $('#pickup_city').val(city);
            console.log('Город извлечён из адреса:', city);
        }

        console.log('Перед отправкой формы:', {
            pickup_point: pickupPoint,
            pickup_city: $('#pickup_city').val(),
            pickup_point_code: pickupPointCode,
            pvz_code_display: $('#pvz_code_display').text()
        });

        if (!form.checkValidity()) {
            e.stopPropagation();
            form.classList.add('was-validated');
            return;
        }

        const $notification = $('#notification');
        $notification.hide();
        const formData = new FormData(form);
        console.log('Отправляемые данные формы:', Object.fromEntries(formData));

        $.ajax({
            url: '/api/cart.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    $notification.removeClass('alert-danger').addClass('alert-success')
                        .text(response.message || 'Заказ успешно оформлен!')
                        .show();
                    form.reset();
                    form.classList.remove('was-validated');
                    $('#pvz_code_display').text('Не выбран');
                    setTimeout(() => window.location.href = '/orders.php', 2000);
                } else {
                    $notification.removeClass('alert-success').addClass('alert-danger')
                        .text(response.message || 'Ошибка при оформлении заказа.')
                        .show();
                }
            },
            error: function(xhr) {
                $notification.removeClass('alert-success').addClass('alert-danger')
                    .text('Ошибка сервера: ' + (xhr.responseJSON?.message || xhr.statusText))
                    .show();
            }
        });
    });

    // Кастомная валидация полей
    $('#fullname').on('input', function() {
        const value = this.value;
        const valid = /^[А-Яа-я\s]{3,50}$/u.test(value);
        this.setCustomValidity(valid ? '' : 'ФИО должно содержать 3-50 символов, только буквы и пробелы.');
    });

    $('#phone').on('input', function() {
        const value = this.value;
        const valid = /^\+?[0-9]{10,15}$/.test(value);
        this.setCustomValidity(valid ? '' : 'Номер телефона должен содержать 10-15 цифр.');
    });

    // Обработчик изменения количества
    $('.update-cart').on('change', function() {
        if (!isLoggedIn) {
            showToast('Требуется авторизация для изменения корзины', 'danger');
            return;
        }

        const productId = parseInt(this.dataset.productId);
        const quantity = parseInt(this.value);

        if (isNaN(productId) || productId <= 0) {
            showToast('Некорректный ID товара', 'danger');
            this.value = 1;
            return;
        }

        if (isNaN(quantity) || quantity < 1) {
            showToast('Количество должно быть больше 0', 'danger');
            this.value = 1;
            return;
        }

        $.ajax({
            url: '/cart/update_quantity.php',
            type: 'POST',
            data: {
                product_id: productId,
                quantity: quantity,
                csrf_token: $('input[name="csrf_token"]').val()
            },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    updateCartBadge(response.data?.total_count || 0);
                    showToast('Количество обновлено', 'success');
                    window.location.reload();
                } else {
                    showToast(response.message || 'Ошибка при обновлении корзины', 'danger');
                }
            },
            error: function(xhr) {
                showToast('Ошибка сервера: ' + (xhr.responseJSON?.message || xhr.statusText), 'danger');
            }
        });
    });

    // Обработчик удаления товара
    $('.remove-from-cart').on('click', function() {
        if (!isLoggedIn) {
            showToast('Требуется авторизация для удаления товара', 'danger');
            return;
        }

        const productId = parseInt(this.dataset.productId);

        if (isNaN(productId) || productId <= 0) {
            showToast('Некорректный ID товара', 'danger');
            return;
        }

        $.ajax({
            url: '/cart/remove_item.php',
            type: 'POST',
            data: {
                item_id: productId,
                csrf_token: $('input[name="csrf_token"]').val()
            },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    updateCartBadge(response.data?.total_count || 0);
                    showToast('Товар удален из корзины', 'success');
                    window.location.reload();
                } else {
                    showToast(response.message || 'Ошибка при удалении товара', 'danger');
                }
            },
            error: function(xhr) {
                showToast('Ошибка сервера: ' + (xhr.responseJSON?.message || xhr.statusText), 'danger');
            }
        });
    });
});