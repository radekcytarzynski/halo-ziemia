// Formatowanie wartości (odpowiednik App\Twig\Formatter).

const nf = (digits) => new Intl.NumberFormat('pl-PL', { maximumFractionDigits: digits });

export function formatDuration(seconds) {
    let s = Math.max(0, Math.round(seconds));
    if (s < 60) return `${s} s`;
    const parts = [];
    for (const [unit, size] of [['d', 86400], ['h', 3600], ['min', 60], ['s', 1]]) {
        if (s >= size) {
            parts.push(`${Math.floor(s / size)} ${unit}`);
            s %= size;
        }
        if (parts.length === 2 || (parts.length > 0 && s === 0)) break;
    }
    return parts.join(' ');
}

export function formatBytes(bytes) {
    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    let v = bytes;
    let i = 0;
    while (Math.abs(v) >= 1000 && i < units.length - 1) { v /= 1000; i += 1; }
    return `${nf(v >= 100 || i === 0 ? 0 : 1).format(v)} ${units[i]}`;
}

export function formatMetric(value, unit) {
    if (value === null || value === undefined) return '–';
    switch (unit) {
        case 'B': return formatBytes(value);
        case 'kB': return formatBytes(value * 1000);
        case 's': return formatDuration(value);
        case '%': return `${nf(1).format(value)} %`;
        case null: case undefined: case '': return nf(2).format(value);
        default: return `${nf(1).format(value)} ${unit}`;
    }
}

// Krótka forma na osie wykresów (np. "1,5 h", "2 d", "1,2 TB").
export function formatAxis(value, unit) {
    if (unit === 's') {
        const abs = Math.abs(value);
        if (abs >= 86400) return `${nf(1).format(value / 86400)} d`;
        if (abs >= 3600) return `${nf(1).format(value / 3600)} h`;
        if (abs >= 60) return `${nf(0).format(value / 60)} min`;
        return `${nf(0).format(value)} s`;
    }
    return formatMetric(value, unit);
}
