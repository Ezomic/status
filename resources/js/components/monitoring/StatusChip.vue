<script setup lang="ts">
import { computed } from 'vue';
import { stateLabel } from '@/lib/monitoring';
import type { StatusState } from '@/types/monitoring';

const props = withDefaults(
    defineProps<{
        state: StatusState;
        label?: string;
        paused?: boolean;
        stale?: boolean;
    }>(),
    { label: undefined, paused: false, stale: false },
);

// Paused outranks stale: a service nobody is checking on purpose is not a warning.
// Stale outranks the state itself, because the state is what has gone unreliable.
const text = computed(() => {
    if (props.paused) {
        return 'Paused';
    }

    return props.stale ? 'Stale' : (props.label ?? stateLabel(props.state));
});

const classes = computed(() => {
    if (props.paused) {
        return 'text-muted-foreground bg-muted border-border';
    }

    if (props.stale) {
        return 'text-status-stale bg-status-stale/10 border-status-stale/30';
    }

    return {
        up: 'text-status-up bg-status-up/10 border-status-up/25',
        maintenance:
            'text-status-maintenance bg-status-maintenance/10 border-status-maintenance/30',
        degraded:
            'text-status-degraded bg-status-degraded/10 border-status-degraded/30',
        down: 'text-status-down bg-status-down/10 border-status-down/30',
        unknown: 'text-muted-foreground bg-muted border-border',
    }[props.state];
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap"
        :class="classes"
    >
        <span class="size-1.5 rounded-full bg-current" />
        {{ text }}
    </span>
</template>
