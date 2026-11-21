// Podgląd: po jakim czasie ciszy urządzenie zostanie uznane za DOWN.
import { formatDuration } from './format.js';

const preview = document.querySelector('[data-hb-preview]');
const field = (name) => document.querySelector(`[data-hb="${name}"]`);

function update() {
    if (!preview) return;
    const interval = Number(field('interval')?.value || 60);
    const missedRaw = field('missed')?.value;
    const missed = Number(missedRaw || preview.dataset.globalMissed || 3);
    const grace = Number(field('grace')?.value || 0);
    const total = interval * missed + grace;
    preview.textContent = `Alarm DOWN po ${formatDuration(total)} bez kontaktu (${missed} × ${formatDuration(interval)} + ${formatDuration(grace)} marginesu).`;
}

['interval', 'missed', 'grace'].forEach((name) => field(name)?.addEventListener('input', update));
update();
