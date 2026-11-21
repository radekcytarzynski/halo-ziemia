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

// Kopiowanie do schowka: <button data-copy-target="id-elementu">
document.addEventListener('click', async (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-copy-target]') : null;
    if (!button) {
        return;
    }
    const target = document.getElementById(button.dataset.copyTarget ?? '');
    if (!target) {
        return;
    }
    const original = button.textContent;
    try {
        await navigator.clipboard.writeText(target.textContent.trim());
        button.textContent = 'Skopiowano ✓';
    } catch {
        // Fallback (np. HTTP bez secure context): zaznacz tekst do ręcznego skopiowania.
        const range = document.createRange();
        range.selectNodeContents(target);
        const selection = window.getSelection();
        selection?.removeAllRanges();
        selection?.addRange(range);
        button.textContent = 'Zaznaczono – Ctrl+C';
    }
    setTimeout(() => { button.textContent = original; }, 2000);
});
