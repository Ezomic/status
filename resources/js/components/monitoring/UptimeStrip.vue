<script setup lang="ts">
import { stateBg } from '@/lib/monitoring';
import type { StripSlot } from '@/types/monitoring';

defineProps<{ slots: StripSlot[] }>();

function title(slot: StripSlot): string {
    if (slot.state === 'none') {
        return `${slot.date} - not checked`;
    }

    // A day spent entirely in maintenance has no measurable uptime, so it reports the
    // window rather than a percentage.
    if (slot.uptime === null) {
        return `${slot.date} - maintenance`;
    }

    // A day with a deploy in it still reads as up, so the maintenance is worth
    // mentioning: it explains a dip that is not a fault.
    const suffix = slot.maintenance ? ' up, some maintenance' : ' up';

    return `${slot.date} - ${slot.uptime}%${suffix}`;
}
</script>

<template>
    <div
        class="flex h-7 items-stretch gap-px"
        role="img"
        aria-label="Daily uptime, oldest first"
    >
        <span
            v-for="slot in slots"
            :key="slot.date"
            :title="title(slot)"
            class="min-w-0.5 flex-1 rounded-xs transition-opacity hover:opacity-70"
            :class="stateBg(slot.state)"
        />
    </div>
</template>
