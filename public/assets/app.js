'use strict';
document.querySelectorAll('form[data-required-message]').forEach(form => {
    form.querySelectorAll('input[required],select[required],textarea[required]').forEach(input => {
        input.addEventListener('invalid', () => {
            if (input.validity.valueMissing) {
                input.setCustomValidity(form.dataset.requiredMessage);
            }
        });
        input.addEventListener('input', () => input.setCustomValidity(''));
    });
});
const passwordButton = document.querySelector('[data-toggle-password]');
if (passwordButton) {
    passwordButton.hidden = false;
    passwordButton.addEventListener('click', () => {
        const input = document.getElementById('password');
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        passwordButton.textContent = visible ? passwordButton.dataset.hide : passwordButton.dataset.show;
        passwordButton.setAttribute('aria-pressed', String(visible));
    });
}
const menuButton = document.querySelector('[data-menu]');
if (menuButton) {
    menuButton.hidden = false;
    menuButton.addEventListener('click', () => {
        const open = document.body.classList.toggle('menu-open');
        menuButton.setAttribute('aria-expanded', String(open));
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            document.body.classList.remove('menu-open');
            menuButton.setAttribute('aria-expanded', 'false');
        }
    });
}
