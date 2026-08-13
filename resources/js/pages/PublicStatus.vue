<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { formatDate, formatTime, stateLabel } from '@/lib/monitoring';
import type {
    PublicMaintenance,
    PublicStatusRow,
    PublicVerdict,
} from '@/types/monitoring';

const props = defineProps<{
    services: PublicStatusRow[];
    verdict: PublicVerdict;
    maintenance: PublicMaintenance;
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

/**
 * Sections in payload order, so the server decides the ordering and this only groups
 * consecutive runs. Ungrouped services collect under a heading-less section rather than
 * vanishing (STAT-43).
 */
const sections = computed(() => {
    const out: { group: string | null; services: PublicStatusRow[] }[] = [];

    for (const service of props.services) {
        const last = out[out.length - 1];

        if (last && last.group === service.group) {
            last.services.push(service);
            continue;
        }

        out.push({ group: service.group, services: [service] });
    }

    return out;
});

/**
 * Hours and minutes only. formatTime() includes seconds, which is right for an incident
 * log and absurd on a public notice announcing planned work.
 */
function windowTime(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

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

            <!-- Declared windows, which is the part a detected deploy cannot announce
                 ahead of time (STAT-37). -->
            <div
                v-if="
                    maintenance.open.length > 0 ||
                    maintenance.upcoming.length > 0
                "
                class="mt-8 space-y-2"
            >
                <p
                    v-for="(window, index) in maintenance.open"
                    :key="`open-${index}`"
                    class="rounded-lg border border-l-3 border-l-status-maintenance bg-card p-4 text-sm"
                >
                    <strong class="font-semibold text-status-maintenance"
                        >Maintenance in progress</strong
                    >
                    &middot; {{ window.description }}. Expected to finish
                    {{ windowTime(window.ends_at) }}.
                </p>
                <p
                    v-for="(window, index) in maintenance.upcoming"
                    :key="`upcoming-${index}`"
                    class="rounded-lg border bg-card p-4 text-sm text-muted-foreground"
                >
                    <strong class="font-semibold text-foreground"
                        >Scheduled maintenance</strong
                    >
                    &middot; {{ window.description }} on
                    {{ formatDate(window.starts_at) }},
                    {{ windowTime(window.starts_at) }} to
                    {{ windowTime(window.ends_at) }}.
                </p>
            </div>

            <template v-if="services.length > 0">
                <div
                    v-for="(section, sectionIndex) in sections"
                    :key="section.group ?? `ungrouped-${sectionIndex}`"
                    class="mt-10"
                >
                    <h2
                        v-if="section.group"
                        class="mb-2 text-xs font-semibold tracking-widest text-muted-foreground uppercase"
                    >
                        {{ section.group }}
                    </h2>

                    <ul class="divide-y rounded-lg border bg-card">
                        <li
                            v-for="service in section.services"
                            :key="service.slug ?? service.name"
                            class="px-4 py-3.5"
                        >
                            <div
                                class="flex items-center justify-between gap-4"
                            >
                                <span class="font-medium tracking-tight">{{
                                    service.name
                                }}</span>
                                <span class="flex items-center gap-2 text-sm">
                                    <span
                                        class="size-2 rounded-full"
                                        :class="
                                            DOT[service.state] ?? DOT.unknown
                                        "
                                        aria-hidden="true"
                                    />
                                    <span class="text-muted-foreground">{{
                                        service.stale
                                            ? 'Unconfirmed'
                                            : stateLabel(service.state)
                                    }}</span>
                                </span>
                            </div>

                            <!-- Human-written updates only. The machine-written incident
                                 reason is never published: it carries internal
                                 hostnames (STAT-5). -->
                            <ol
                                v-if="service.updates.length > 0"
                                class="mt-3 space-y-2 border-l pl-3"
                            >
                                <li
                                    v-for="(update, index) in service.updates"
                                    :key="index"
                                    class="text-sm"
                                >
                                    <span
                                        v-if="update.at"
                                        class="text-xs text-muted-foreground"
                                        >{{ formatDate(update.at) }}
                                        {{ formatTime(update.at) }}</span
                                    >
                                    <p
                                        class="whitespace-pre-line text-muted-foreground"
                                    >
                                        {{ update.body }}
                                    </p>
                                </li>
                            </ol>
                        </li>
                    </ul>
                </div>
            </template>

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
