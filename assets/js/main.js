document.addEventListener('DOMContentLoaded', () => {
    // Работа с корзиной
    const cart = JSON.parse(localStorage.getItem('cart')) || [];

    // Добавление в корзину
    document.querySelectorAll('.add-to-cart').forEach(btn => {
        btn.addEventListener('click', () => {
            const product = {
                id: btn.dataset.productId,
                name: btn.closest('.card').querySelector('.card-title').innerText,
                price: parseFloat(btn.closest('.card').querySelector('.price').innerText),
                diameter: parseFloat(btn.closest('.card').querySelector('.diameter').innerText)
            };

            cart.push(product);
            localStorage.setItem('cart', JSON.stringify(cart));
            updateCartBadge();
        });
    });

    function updateCartBadge() {
        document.querySelectorAll('.cart-badge').forEach(badge => {
            badge.textContent = cart.length;
        });
    }
});