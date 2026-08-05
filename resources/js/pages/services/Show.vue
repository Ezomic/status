<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed } from 'vue';
import ServiceDialog from '@/components/monitoring/ServiceDialog.vue';
import StatusChip from '@/components/monitoring/StatusChip.vue';
import UptimeStrip from '@/components/monitoring/UptimeStrip.vue';
import { Button } from '@/components/ui/button';
import {
    formatDate,
    formatDuration,
    formatMs,
    formatTime,
    sparklinePath,
    stateText,
} from '@/lib/monitoring';
import { destroy, index as servicesIndex } from '@/routes/services';
import type { CheckRow, IncidentRow, ServiceDetail } from '@/types/monitoring';

const props = defineProps<{
    service: ServiceDetail;
    uptime: { day: number | null; month: number | null };
    responseTimes: { at: string; ms: number }[];
    recentChecks: CheckRow[];
    incidents: IncidentRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Services', href: servicesIndex() }],
    },
});

const chartWidth = 640;
const chartHeight = 148;

const chartValues = computed(() =>
    props.responseTimes.map((point) => point.ms),
);
const chartPath = computed(() =>
    sparklinePath(chartValues.value, chartWidth, chartHeight),
);
const chartArea = computed(() =>
    chartPath.value === ''
        ? ''
        : `${chartPath.value}L${chartWidth} ${chartHeight}L0 ${chartHeight}Z`,
);

const thresholdY = computed(() => {
    const max = Math.max(...chartValues.value, 1);

    if (props.service.degraded_threshold_ms > max) {
        return null;
    }

    return (
        chartHeight -
        (props.service.degraded_threshold_ms / max) * (chartHeight - 2) -
        1
    );
});

const percent = (value: number | null) => (value === null ? '--' : `${value}`);

function remove() {
    if (
        confirm(`Stop watching ${props.service.name} and delete its history?`)
    ) {
        router.delete(destroy(props.service.id).url);
    }
}
</script>

<template>
    <Head :title="service.name" />

    <div class="flex h-full flex-1 flex-col gap-5 p-4">
        <Link
            :href="servicesIndex()"
            class="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" />
            All services
        </Link>

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <p
                    class="truncate font-mono text-xs tracking-wide text-muted-foreground"
                >
                    {{ service.url }}
                </p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight">
                    {{ service.name }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Expects {{ service.expected_status_code }} &middot; every
                    {{ service.interval_seconds }}s &middot;
                    {{ service.timeout_seconds }}s timeout &middot; slow above
                    {{ formatMs(service.degraded_threshold_ms) }}
                </p>
                <p
                    v-if="service.expected_body"
                    class="mt-1 text-sm text-muted-foreground"
                >
                    Must contain
                    <code
                        class="rounded bg-muted px-1.5 py-0.5 font-mono text-xs"
                        >{{ service.expected_body }}</code
                    >
                </p>
            </div>

            <div class="flex gap-2">
                <ServiceDialog :service="service">
                    <Button variant="outline" size="sm">Edit</Button>
                </ServiceDialog>
                <Button
                    variant="ghost"
                    size="sm"
                    class="text-destructive"
                    @click="remove"
                    >Delete</Button
                >
            </div>
        </div>

        <div>
            <StatusChip
                :state="service.state"
                :paused="!service.is_active"
                :stale="service.is_stale"
            />
        </div>

        <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-lg border bg-card p-4">
                <dt
                    class="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                >
                    Uptime 24h
                </dt>
                <dd
                    class="mt-1.5 font-mono text-2xl font-semibold tracking-tight tabular-nums"
                >
                    {{ percent(uptime.day)
                    }}<span class="text-sm font-medium text-muted-foreground"
                        >%</span
                    >
                </dd>
            </div>
            <div class="rounded-lg border bg-card p-4">
                <dt
                    class="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                >
                    Uptime 30d
                </dt>
                <dd
                    class="mt-1.5 font-mono text-2xl font-semibold tracking-tight tabular-nums"
                >
                    {{ percent(uptime.month)
                    }}<span class="text-sm font-medium text-muted-foreground"
                        >%</span
                    >
                </dd>
            </div>
            <div class="rounded-lg border bg-card p-4">
                <dt
                    class="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                >
                    Last response
                </dt>
                <dd
                    class="mt-1.5 font-mono text-2xl font-semibold tracking-tight tabular-nums"
                >
                    {{ formatMs(service.last_response_time_ms) }}
                </dd>
            </div>
            <div class="rounded-lg border bg-card p-4">
                <dt
                    class="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                >
                    Last check
                </dt>
                <dd
                    class="mt-1.5 font-mono text-2xl font-semibold tracking-tight tabular-nums"
                >
                    {{
                        service.last_checked_at
                            ? formatTime(service.last_checked_at)
                            : '--'
                    }}
                </dd>
            </div>
        </dl>

        <div class="rounded-lg border bg-card p-4">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold">Last 60 days</h2>
                <span
                    class="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                    >Daily uptime</span
                >
            </div>
            <UptimeStrip :slots="service.strip" />
        </div>

        <div class="grid items-start gap-4 lg:grid-cols-[1.6fr_1fr]">
            <section class="rounded-lg border bg-card">
                <div class="flex items-center justify-between border-b p-4">
                    <h2 class="text-sm font-semibold">Response time</h2>
                    <span
                        class="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                        >Last 24 hours</span
                    >
                </div>
                <div class="p-4">
                    <svg
                        v-if="chartPath"
                        :viewBox="`0 0 ${chartWidth} ${chartHeight}`"
                        preserveAspectRatio="none"
                        class="block h-37 w-full"
                        :class="stateText(service.state)"
                        role="img"
                        aria-label="Response time over the last 24 hours"
                    >
                        <line
                            v-if="thresholdY !== null"
                            x1="0"
                            :x2="chartWidth"
                            :y1="thresholdY"
                            :y2="thresholdY"
                            stroke="currentColor"
                            stroke-width="1"
                            stroke-dasharray="3 4"
                            opacity="0.5"
                        />
                        <path
                            :d="chartArea"
                            fill="currentColor"
                            opacity="0.1"
                        />
                        <path
                            :d="chartPath"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.6"
                            stroke-linejoin="round"
                            vector-effect="non-scaling-stroke"
                        />
                    </svg>
                    <p
                        v-else
                        class="py-10 text-center text-sm text-muted-foreground"
                    >
                        No checks in the last 24 hours yet.
                    </p>
                </div>
            </section>

            <section class="rounded-lg border bg-card">
                <div class="flex items-center justify-between border-b p-4">
                    <h2 class="text-sm font-semibold">Recent checks</h2>
                    <span
                        class="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                        >Newest first</span
                    >
                </div>
                <ul v-if="recentChecks.length" class="divide-y">
                    <li
                        v-for="check in recentChecks"
                        :key="check.id"
                        class="flex items-center gap-3 px-4 py-2 font-mono text-xs tabular-nums"
                    >
                        <span
                            class="size-1.5 shrink-0 rounded-full bg-current"
                            :class="stateText(check.state)"
                        />
                        <span class="text-muted-foreground">{{
                            formatTime(check.checked_at)
                        }}</span>
                        <span>{{ check.status_code ?? 'no response' }}</span>
                        <span
                            class="ml-auto"
                            :class="
                                check.state === 'up'
                                    ? 'text-muted-foreground'
                                    : stateText(check.state)
                            "
                        >
                            {{ formatMs(check.response_time_ms) }}
                        </span>
                    </li>
                </ul>
                <p v-else class="p-6 text-center text-sm text-muted-foreground">
                    Not checked yet.
                </p>
            </section>
        </div>

        <section v-if="incidents.length" class="rounded-lg border bg-card">
            <div class="border-b p-4">
                <h2 class="text-sm font-semibold">Incident history</h2>
            </div>
            <ul class="divide-y">
                <li
                    v-for="incident in incidents"
                    :key="incident.id"
                    class="flex flex-wrap items-center gap-3 p-4"
                >
                    <StatusChip
                        :state="incident.severity"
                        :label="incident.resolved_at ? 'Resolved' : 'Open'"
                    />
                    <span class="text-sm">{{ incident.reason }}</span>
                    <span
                        class="ml-auto font-mono text-xs text-muted-foreground tabular-nums"
                    >
                        {{ formatDate(incident.started_at) }} &middot;
                        {{
                            formatDuration(
                                incident.started_at,
                                incident.resolved_at,
                            )
                        }}
                    </span>
                </li>
            </ul>
        </section>
    </div>
</template>
