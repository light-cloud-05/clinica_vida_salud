'use strict';
document.querySelectorAll('[data-toggle-password]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.togglePassword);
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.textContent = show ? 'Ocultar' : 'Mostrar';
        button.setAttribute('aria-pressed', String(show));
    });
});
document.querySelectorAll('[data-patient-form], [data-catalog-form]').forEach((form) => {
    form.querySelectorAll('input[required]').forEach((input) => {
        const validate = () => input.setCustomValidity(input.value.trim() ? '' : 'Complete este campo.');
        input.addEventListener('input', validate);
        input.addEventListener('change', validate);
        validate();
    });
});
