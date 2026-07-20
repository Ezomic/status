<script setup lang="ts">
import { stateBg } from '@/lib/monitoring';
import type { StripSlot } from '@/types/monitoring';

defineProps<{ slots: StripSlot[] }>();

function title(slot: StripSlot): string {
    if (slot.uptime === null) {
        return `${slot.date} - not checked`;
    }

    return `${slot.date} - ${slot.uptime}% up`;
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
