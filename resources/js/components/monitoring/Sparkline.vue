<script setup lang="ts">
import { computed } from 'vue';
import { sparklinePath, stateText } from '@/lib/monitoring';
import type { StatusState } from '@/types/monitoring';

const props = withDefaults(
    defineProps<{
        values: number[];
        state?: StatusState;
        width?: number;
        height?: number;
    }>(),
    { state: 'up', width: 78, height: 26 },
);

const path = computed(() =>
    sparklinePath(props.values, props.width, props.height),
);
const area = computed(() =>
    path.value === ''
        ? ''
        : `${path.value}L${props.width} ${props.height}L0 ${props.height}Z`,
);
</script>

<template>
    <svg
        v-if="path"
        :viewBox="`0 0 ${width} ${height}`"
        preserveAspectRatio="none"
        class="block h-6.5 w-full"
        :class="stateText(state)"
        aria-hidden="true"
    >
        <path :d="area" fill="currentColor" opacity="0.12" />
        <path
            :d="path"
            fill="none"
            stroke="currentColor"
            stroke-width="1.4"
            stroke-linejoin="round"
            vector-effect="non-scaling-stroke"
        />
    </svg>
    <span v-else class="text-xs text-muted-foreground">no data</span>
</template>
