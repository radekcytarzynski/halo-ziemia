// Auto-odświeżanie dashboardu: pobiera fragment HTML i podmienia karty (bez przeładowania strony).

const container = document.getElementById('dashboard');
const note = document.querySelector('[data-refresh-note]');

if (container) {
    const url = container.dataset.refreshUrl;
    const intervalMs = Number(container.dataset.refreshSeconds || 15) * 1000;
    let failures = 0;

    const refresh = async () => {
        if (document.hidden) {
            return;
        }
        try {
            const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/html' }, cache: 'no-store' });
            // Wygasła sesja → przekierowanie na logowanie: przeładuj całą stronę.
            if (response.redirected || response.status === 401 || response.status === 403) {
                window.location.reload();
                return;
            }
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            container.innerHTML = await response.text();
            failures = 0;
            if (note) {
                note.textContent = `Odświeżono ${new Date().toLocaleTimeString('pl-PL')}`;
                note.classList.remove('error-text');
            }
        } catch {
            failures += 1;
            if (note) {
                note.textContent = `Brak połączenia z serwerem (${failures}×)`;
                note.classList.add('error-text');
            }
        }
    };

    setInterval(refresh, intervalMs);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
}
