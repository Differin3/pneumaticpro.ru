$(document).ready(function() {
    // Обработчик клика по кнопке "Подробнее"
    $('.order-details-btn').on('click', function() {
        const orderId = $(this).data('order-id');
        const csrfToken = '<?= generate_csrf_token() ?>';

        $('#loading').show();
        $('#orderDetailsContent').hide();

        $.ajax({
            url: 'get_order_details.php',
            type: 'GET',
            data: {
                order_id: orderId,
                csrf_token: csrfToken
            },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    const customer = response.data.customer;
                    $('#customerName').text(customer.full_name || '—');
                    $('#customerPhone').text(customer.phone || '—');
                    $('#customerEmail').text(customer.email || '—');
                    $('#customerAddress').text(customer.address || '—');
                    $('#loading').hide();
                    $('#orderDetailsContent').show();
                } else {
                    alert('Ошибка: ' + response.message);
                    $('#loading').hide();
                }
            },
            error: function(xhr) {
                alert('Ошибка загрузки данных: ' + xhr.statusText);
                $('#loading').hide();
            }
        });
    });
});