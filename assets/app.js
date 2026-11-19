// Główny moduł panelu. Bez frameworka, bez inline JS (CSP).

const toggle = document.querySelector('.nav-toggle');
const nav = document.getElementById('main-nav');

if (toggle && nav) {
    toggle.addEventListener('click', () => {
        const open = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', String(!open));
        nav.classList.toggle('is-open', !open);
    });
}

// Potwierdzenia akcji destrukcyjnych: <form data-confirm="...">
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form instanceof HTMLFormElement && form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
        event.preventDefault();
    }
});
