<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { stateLabel } from '@/lib/monitoring';
import type { PublicStatusRow, PublicVerdict } from '@/types/monitoring';

const props = defineProps<{
    services: PublicStatusRow[];
    verdict: PublicVerdict;
    last_checked_at: string | null;
}>();

const TONE_ACCENT: Record<PublicVerdict['tone'], string> = {
    up: 'bg-status-up',
    degraded: 'bg-status-degraded',
    down: 'bg-status-down',
    maintenance: 'bg-status-maintenance',
    unknown: 'bg-status-idle',
};

const TONE_TEXT: Record<PublicVerdict['tone'], string> = {
    up: 'text-status-up',
    degraded: 'text-status-degraded',
    down: 'text-status-down',
    maintenance: 'text-status-maintenance',
    unknown: 'text-muted-foreground',
};

const DOT: Record<string, string> = {
    up: 'bg-status-up',
    degraded: 'bg-status-degraded',
    down: 'bg-status-down',
    maintenance: 'bg-status-maintenance',
    unknown: 'bg-status-idle',
};

/** Coarse on purpose: a public page should not imply second-level precision. */
const lastChecked = computed(() => {
    if (props.last_checked_at === null) {
        return null;
    }

    const minutes = Math.round(
        (Date.now() - new Date(props.last_checked_at).getTime()) / 60000,
    );

    if (minutes <= 1) {
        return 'just now';
    }

    if (minutes < 60) {
        return `${minutes} minutes ago`;
    }

    const hours = Math.round(minutes / 60);

    return hours === 1 ? 'an hour ago' : `${hours} hours ago`;
});
</script>

<template>
    <!-- No title: the resolver falls back to the app name, so the tab reads "Status"
         rather than "Status - Status". -->
    <Head />

    <div class="min-h-screen bg-background text-foreground">
        <div class="mx-auto w-full max-w-2xl px-6 py-14 sm:py-20">
            <header class="flex items-center gap-2.5">
                <AppLogoIcon class="size-5 fill-current" />
                <span class="text-sm font-semibold tracking-tight"
                    >Thijssensoftware Status</span
                >
            </header>

            <div class="mt-10 flex items-start gap-4">
                <span
                    class="mt-2 size-3 shrink-0 rounded-full"
                    :class="TONE_ACCENT[verdict.tone]"
                    aria-hidden="true"
                />
                <div>
                    <h1
                        class="text-2xl font-bold tracking-tight sm:text-3xl"
                        :class="TONE_TEXT[verdict.tone]"
                    >
                        {{ verdict.headline }}
                    </h1>
                    <p
                        v-if="lastChecked"
                        class="mt-1.5 text-sm text-muted-foreground"
                    >
                        Last checked {{ lastChecked }}.
                    </p>
                </div>
            </div>

            <ul
                v-if="services.length > 0"
                class="mt-10 divide-y rounded-lg border bg-card"
            >
                <li
                    v-for="service in services"
                    :key="service.slug ?? service.name"
                    class="flex items-center justify-between gap-4 px-4 py-3.5"
                >
                    <span class="font-medium tracking-tight">{{
                        service.name
                    }}</span>
                    <span class="flex items-center gap-2 text-sm">
                        <span
                            class="size-2 rounded-full"
                            :class="DOT[service.state] ?? DOT.unknown"
                            aria-hidden="true"
                        />
                        <span class="text-muted-foreground">{{
                            service.stale
                                ? 'Unconfirmed'
                                : stateLabel(service.state)
                        }}</span>
                    </span>
                </li>
            </ul>

            <p
                v-else
                class="mt-10 rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                Nothing is being reported publicly right now.
            </p>

            <footer class="mt-10 text-xs text-muted-foreground">
                Each service is checked on its own schedule. Reload for the
                latest state.
            </footer>
        </div>
    </div>
</template>
