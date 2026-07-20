<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import ServiceDialog from '@/components/monitoring/ServiceDialog.vue';
import Sparkline from '@/components/monitoring/Sparkline.vue';
import StatusChip from '@/components/monitoring/StatusChip.vue';
import UptimeStrip from '@/components/monitoring/UptimeStrip.vue';
import { Button } from '@/components/ui/button';
import { formatMs } from '@/lib/monitoring';
import {
    index as servicesIndex,
    show as servicesShow,
} from '@/routes/services';
import type { IncidentRow, ServiceRow } from '@/types/monitoring';

const props = defineProps<{
    services: ServiceRow[];
    openIncidents: IncidentRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Services', href: servicesIndex() }],
    },
});

const watched = computed(
    () => props.services.filter((service) => service.is_active).length,
);

const verdict = computed(() => {
    if (props.openIncidents.length === 0) {
        return null;
    }

    const worst =
        props.openIncidents.find((incident) => incident.severity === 'down') ??
        props.openIncidents[0];
    const others = props.openIncidents.length - 1;

    return {
        incident: worst,
        headline:
            props.openIncidents.length === 1
                ? `${worst.service} is ${worst.severity}`
                : `${props.openIncidents.length} services need attention`,
        detail: others > 0 ? `${worst.service}: ${worst.reason}` : worst.reason,
    };
});
</script>

<template>
    <Head title="Services" />

    <div class="flex h-full flex-1 flex-col gap-5 p-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p
                    class="text-xs font-semibold tracking-widest text-muted-foreground uppercase"
                >
                    Operator view
                </p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight">Services</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ watched }} of {{ services.length }} checked on their own
                    schedule.
                </p>
            </div>

            <ServiceDialog />
        </div>

        <div
            v-if="verdict"
            class="flex flex-wrap items-center gap-4 rounded-lg border border-l-3 border-l-status-degraded bg-card p-4"
        >
            <span
                class="grid size-9 shrink-0 place-items-center rounded-full bg-status-degraded/10 text-status-degraded"
            >
                <TriangleAlert class="size-4.5" />
            </span>
            <div class="min-w-40 flex-1">
                <strong class="block text-sm font-semibold">{{
                    verdict.headline
                }}</strong>
                <span class="text-sm text-muted-foreground">{{
                    verdict.detail
                }}</span>
            </div>
            <Button variant="outline" size="sm" as-child>
                <Link :href="servicesShow(verdict.incident.service_id ?? 0)"
                    >Open</Link
                >
            </Button>
        </div>

        <div
            v-if="services.length === 0"
            class="rounded-lg border border-dashed p-10 text-center"
        >
            <p class="font-medium">Nothing is being watched yet.</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Add a service and Status will start checking it within a minute.
            </p>
        </div>

        <div v-else class="overflow-hidden rounded-lg border bg-card">
            <div
                class="hidden grid-cols-[minmax(180px,1.5fr)_104px_minmax(160px,2.2fr)_78px_92px] items-center gap-4 px-4 pb-2 md:grid"
            >
                <span
                    class="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                    >Service</span
                >
                <span
                    class="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                    >State</span
                >
                <span
                    class="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                    >Last 60 days</span
                >
                <span
                    class="text-right text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                    >24h</span
                >
                <span
                    class="text-right text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                    >Response</span
                >
            </div>

            <Link
                v-for="service in services"
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
                        :paused="!service.is_active"
                    />
                </span>

                <span class="col-span-2 md:col-span-1">
                    <UptimeStrip :slots="service.strip" />
                </span>

                <span class="hidden md:block">
                    <Sparkline
                        :values="service.sparkline"
                        :state="service.state"
                    />
                </span>

                <span
                    class="col-span-2 font-mono text-sm tabular-nums md:col-span-1 md:text-right"
                >
                    {{ formatMs(service.last_response_time_ms) }}
                </span>
            </Link>
        </div>

        <div
            v-if="services.length"
            class="flex justify-between px-1 text-xs text-muted-foreground"
        >
            <span>60 days ago</span>
            <span class="hidden sm:inline">Each bar is one day</span>
            <span>Today</span>
        </div>
    </div>
</template>
