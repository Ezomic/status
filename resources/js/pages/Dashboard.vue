<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ClockAlert, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import Sparkline from '@/components/monitoring/Sparkline.vue';
import StatusChip from '@/components/monitoring/StatusChip.vue';
import UptimeStrip from '@/components/monitoring/UptimeStrip.vue';
import { Button } from '@/components/ui/button';
import { formatDuration, formatMs } from '@/lib/monitoring';
import { dashboard } from '@/routes';
import { index as incidentsIndex } from '@/routes/incidents';
import {
    index as servicesIndex,
    show as servicesShow,
} from '@/routes/services';
import type {
    AttentionRow,
    DashboardCounts,
    DashboardVerdict,
    Freshness,
    IncidentRow,
} from '@/types/monitoring';

const props = defineProps<{
    verdict: DashboardVerdict;
    counts: DashboardCounts;
    freshness: Freshness;
    attention: AttentionRow[];
    openIncidents: IncidentRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const TONE_CLASSES: Record<DashboardVerdict['tone'], string> = {
    up: 'border-l-status-up bg-status-up/5',
    degraded: 'border-l-status-degraded bg-status-degraded/5',
    down: 'border-l-status-down bg-status-down/5',
    maintenance: 'border-l-status-maintenance bg-status-maintenance/5',
    stale: 'border-l-status-stale bg-status-stale/5',
    unknown: 'border-l-status-idle bg-muted/40',
};

const TONE_TEXT: Record<DashboardVerdict['tone'], string> = {
    up: 'text-status-up',
    degraded: 'text-status-degraded',
    down: 'text-status-down',
    maintenance: 'text-status-maintenance',
    stale: 'text-status-stale',
    unknown: 'text-muted-foreground',
};

/** Only the counts worth a tile: a zero for a state nothing is in is noise. */
const tiles = computed(() =>
    [
        { label: 'Up', value: props.counts.up, tone: 'up' as const },
        { label: 'Down', value: props.counts.down, tone: 'down' as const },
        {
            label: 'Slow',
            value: props.counts.degraded,
            tone: 'degraded' as const,
        },
        {
            label: 'Maintenance',
            value: props.counts.maintenance,
            tone: 'maintenance' as const,
        },
        { label: 'Stale', value: props.counts.stale, tone: 'stale' as const },
        {
            label: 'Not checked',
            value: props.counts.unknown,
            tone: 'unknown' as const,
        },
        {
            label: 'Paused',
            value: props.counts.paused,
            tone: 'unknown' as const,
        },
    ].filter((tile) => tile.value > 0 || tile.tone === 'up'),
);
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-5 p-4">
        <div
            class="rounded-lg border border-l-3 p-5"
            :class="TONE_CLASSES[verdict.tone]"
        >
            <p
                class="text-xs font-semibold tracking-widest uppercase"
                :class="TONE_TEXT[verdict.tone]"
            >
                {{ verdict.tone === 'up' ? 'Operational' : 'Attention' }}
            </p>
            <h1 class="mt-1.5 text-2xl font-bold tracking-tight">
                {{ verdict.headline }}
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ verdict.detail }}
            </p>
        </div>

        <div
            v-if="freshness.stalled && counts.watched > 0"
            class="flex flex-wrap items-center gap-4 rounded-lg border border-l-3 border-l-status-stale bg-card p-4"
        >
            <span
                class="grid size-9 shrink-0 place-items-center rounded-full bg-status-stale/10 text-status-stale"
            >
                <ClockAlert class="size-4.5" />
            </span>
            <p class="min-w-40 flex-1 text-sm text-muted-foreground">
                The scheduler has stopped, so nothing below is current. Check
                the
                <code class="font-mono text-xs">schedule:run</code> cron entry
                on the droplet.
            </p>
        </div>

        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            <div
                v-for="tile in tiles"
                :key="tile.label"
                class="rounded-lg border bg-card p-4"
            >
                <dt
                    class="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                >
                    {{ tile.label }}
                </dt>
                <dd
                    class="mt-1.5 font-mono text-2xl font-semibold tracking-tight tabular-nums"
                    :class="TONE_TEXT[tile.tone]"
                >
                    {{ tile.value
                    }}<span
                        class="ml-1 text-sm font-medium text-muted-foreground"
                        >/ {{ counts.watched }}</span
                    >
                </dd>
            </div>
        </dl>

        <section class="rounded-lg border bg-card">
            <div class="flex items-center justify-between border-b p-4">
                <h2 class="text-sm font-semibold">Needs attention</h2>
                <Button variant="ghost" size="sm" as-child>
                    <Link :href="servicesIndex()">All services</Link>
                </Button>
            </div>

            <p
                v-if="attention.length === 0"
                class="p-6 text-sm text-muted-foreground"
            >
                Nothing to look at. Every watched service answered its last
                check.
            </p>

            <Link
                v-for="service in attention"
                :key="service.id"
                :href="servicesShow(service.id)"
                class="grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-3 border-t px-4 py-3.5 transition-colors first:border-t-0 hover:bg-muted/50 focus-visible:bg-muted/50 md:grid-cols-[minmax(180px,1.5fr)_104px_minmax(160px,2.2fr)_78px_92px] md:gap-y-0"
            >
                <span class="col-start-1 min-w-0">
                    <span class="block truncate font-semibold tracking-tight">{{
                        service.name
                    }}</span>
                    <span
                        class="block truncate font-mono text-xs text-muted-foreground"
                        >{{ service.host }}</span
                    >
                </span>

                <span
                    class="col-start-2 justify-self-end md:justify-self-start"
                >
                    <StatusChip
                        :state="service.state"
                        :stale="service.is_stale"
                    />
                </span>

                <span class="col-span-2 md:col-span-1 md:col-start-3">
                    <UptimeStrip :slots="service.strip" />
                </span>

                <span class="col-start-1 md:col-start-4 md:justify-self-end">
                    <Sparkline
                        :values="service.sparkline"
                        :state="service.state"
                    />
                </span>

                <span
                    class="col-start-2 justify-self-end font-mono text-sm tabular-nums md:col-start-5"
                    >{{ formatMs(service.last_response_time_ms) }}</span
                >
            </Link>
        </section>

        <section class="rounded-lg border bg-card">
            <div class="flex items-center justify-between border-b p-4">
                <h2 class="text-sm font-semibold">Open incidents</h2>
                <Button variant="ghost" size="sm" as-child>
                    <Link :href="incidentsIndex()">All incidents</Link>
                </Button>
            </div>

            <p
                v-if="openIncidents.length === 0"
                class="p-6 text-sm text-muted-foreground"
            >
                No incident is open.
            </p>

            <Link
                v-for="incident in openIncidents"
                :key="incident.id"
                :href="servicesShow(incident.service_id ?? 0)"
                class="flex flex-wrap items-center gap-4 border-t px-4 py-3.5 transition-colors first:border-t-0 hover:bg-muted/50 focus-visible:bg-muted/50"
            >
                <span
                    class="grid size-9 shrink-0 place-items-center rounded-full bg-status-degraded/10 text-status-degraded"
                >
                    <TriangleAlert class="size-4.5" />
                </span>
                <span class="min-w-40 flex-1">
                    <strong class="block text-sm font-semibold"
                        >{{ incident.service }} is
                        {{ incident.severity }}</strong
                    >
                    <span class="block text-sm text-muted-foreground">{{
                        incident.reason
                    }}</span>
                </span>
                <span class="font-mono text-sm tabular-nums">{{
                    formatDuration(incident.started_at, null)
                }}</span>
            </Link>
        </section>
    </div>
</template>
