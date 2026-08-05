import type { StatusState, StripState } from '@/types/monitoring';

const STATE_TEXT: Record<StripState, string> = {
    up: 'text-status-up',
    maintenance: 'text-status-maintenance',
    degraded: 'text-status-degraded',
    down: 'text-status-down',
    unknown: 'text-status-idle',
    none: 'text-status-idle',
};

const STATE_BG: Record<StripState, string> = {
    up: 'bg-status-up',
    maintenance: 'bg-status-maintenance',
    degraded: 'bg-status-degraded',
    down: 'bg-status-down',
    unknown: 'bg-status-idle',
    none: 'bg-status-idle/20',
};

export function stateText(state: StripState): string {
    return STATE_TEXT[state] ?? STATE_TEXT.unknown;
}

export function stateBg(state: StripState): string {
    return STATE_BG[state] ?? STATE_BG.unknown;
}

export function stateLabel(state: StatusState): string {
    return {
        up: 'Up',
        maintenance: 'Maintenance',
        degraded: 'Degraded',
        down: 'Down',
        unknown: 'Not checked',
    }[state];
}

export function formatMs(ms: number | null): string {
    return ms === null ? '--' : `${ms.toLocaleString('en-US')}ms`;
}

export function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}

export function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
    });
}

/** Rounded, human duration between two instants, e.g. "2h 3m" or "14m". */
export function formatDuration(from: string, to: string | null): string {
    const start = new Date(from).getTime();
    const end = to === null ? Date.now() : new Date(to).getTime();
    const totalMinutes = Math.max(1, Math.round((end - start) / 60000));

    const days = Math.floor(totalMinutes / 1440);
    const hours = Math.floor((totalMinutes % 1440) / 60);
    const minutes = totalMinutes % 60;

    if (days > 0) {
        return `${days}d ${hours}h`;
    }

    return hours > 0 ? `${hours}h ${minutes}m` : `${minutes}m`;
}

/** An SVG path through evenly spaced values, normalised to the given box. */
export function sparklinePath(
    values: number[],
    width: number,
    height: number,
): string {
    if (values.length < 2) {
        return '';
    }

    const max = Math.max(...values, 1);

    return values
        .map((value, index) => {
            const x = (index / (values.length - 1)) * width;
            const y = height - (value / max) * (height - 2) - 1;

            return `${index === 0 ? 'M' : 'L'}${x.toFixed(1)} ${y.toFixed(1)}`;
        })
        .join('');
}
