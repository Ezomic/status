<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { index as reportsIndex } from '@/routes/reports';
import { show as servicesShow } from '@/routes/services';
import type { MonthlyReport, ReportMonth, ReportRow } from '@/types/monitoring';

const props = defineProps<{
    months: ReportMonth[];
    report: MonthlyReport;
    retentionDays: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Reports', href: reportsIndex() }],
    },
});

const sections = computed(() => {
    const groups: { group: string | null; services: ReportRow[] }[] = [];

    for (const service of props.report.services) {
        const last = groups.at(-1);

        if (last && last.group === service.group) {
            last.services.push(service);

            continue;
        }

        groups.push({ group: service.group, services: [service] });
    }

    return groups;
});

const measured = computed(() =>
    props.report.services.filter((service) => service.uptime !== null),
);

function formatUptime(uptime: number | null): string {
    return uptime === null ? 'No data' : `${uptime.toFixed(3)}%`;
}

function formatLatency(ms: number | null): string {
    return ms === null ? '--' : `${ms.toLocaleString('en-US')}ms`;
}

function formatDowntime(seconds: number): string {
    if (seconds === 0) {
        return '--';
    }

    if (seconds < 60) {
        return `${seconds}s`;
    }

    const minutes = Math.round(seconds / 60);

    if (minutes < 60) {
        return `${minutes}m`;
    }

    const remainder = minutes % 60;

    return remainder === 0
        ? `${minutes / 60}h`
        : `${Math.floor(minutes / 60)}h ${remainder}m`;
}

function verdict(service: ReportRow): string | null {
    if (service.met === null) {
        return null;
    }

    return service.met ? 'Met' : 'Missed';
}
</script>

<template>
    <Head :title="`Report ${report.label}`" />

    <div class="flex h-full flex-1 flex-col gap-5 p-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p
                    class="text-xs font-semibold tracking-widest text-muted-foreground uppercase"
                >
                    Monthly report
                </p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight">
                    {{ report.label }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ measured.length }} of
                    {{ report.services.length }} services were checked this
                    month.
                </p>
            </div>

            <nav class="flex flex-wrap gap-1.5">
                <Link
                    v-for="month in months"
                    :key="month.month"
                    :href="reportsIndex({ query: { month: month.month } })"
                    class="rounded-md border px-2.5 py-1.5 text-sm transition-colors"
                    :class="
                        month.month === report.month
                            ? 'border-foreground bg-muted font-medium'
                            : 'text-muted-foreground hover:bg-muted'
                    "
                >
                    {{ month.label }}
                </Link>
            </nav>
        </div>

        <p
            v-if="report.partial"
            class="rounded-lg border border-l-3 border-l-status-stale bg-card p-4 text-sm text-muted-foreground"
        >
            This month is incomplete, so the numbers cover part of it only.
            Checks are kept for {{ retentionDays }} days, which is why the
            oldest month here is cut short as well.
        </p>

        <div
            v-if="report.services.length === 0"
            class="rounded-lg border border-dashed p-10 text-center"
        >
            <p class="font-medium">Nothing is being watched yet.</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Add a service and it will appear in next month's report.
            </p>
        </div>

        <div v-else class="overflow-x-auto rounded-lg border bg-card">
            <table class="w-full min-w-[46rem] text-sm">
                <thead>
                    <tr class="text-left">
                        <th
                            class="px-4 pt-4 pb-2 text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                        >
                            Service
                        </th>
                        <th
                            class="px-4 pt-4 pb-2 text-right text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                        >
                            Uptime
                        </th>
                        <th
                            class="px-4 pt-4 pb-2 text-right text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                        >
                            Target
                        </th>
                        <th
                            class="px-4 pt-4 pb-2 text-right text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                        >
                            p50
                        </th>
                        <th
                            class="px-4 pt-4 pb-2 text-right text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                        >
                            p95
                        </th>
                        <th
                            class="px-4 pt-4 pb-2 text-right text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                        >
                            Incidents
                        </th>
                        <th
                            class="px-4 pt-4 pb-2 text-right text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                        >
                            Downtime
                        </th>
                    </tr>
                </thead>

                <tbody v-for="section in sections" :key="section.group ?? '-'">
                    <tr v-if="section.group">
                        <td
                            colspan="7"
                            class="border-t bg-muted/40 px-4 py-1.5 text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                        >
                            {{ section.group }}
                        </td>
                    </tr>

                    <tr
                        v-for="service in section.services"
                        :key="service.id"
                        class="border-t"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="servicesShow(service.id)"
                                class="font-semibold tracking-tight hover:underline"
                                >{{ service.name }}</Link
                            >
                            <span
                                v-if="service.uptime !== null"
                                class="block text-xs text-muted-foreground"
                                >{{
                                    service.checks.toLocaleString('en-US')
                                }}
                                checks</span
                            >
                        </td>

                        <td
                            class="px-4 py-3 text-right font-mono tabular-nums"
                            :class="
                                service.uptime === null
                                    ? 'text-muted-foreground'
                                    : service.met === false
                                      ? 'text-status-down'
                                      : ''
                            "
                        >
                            {{ formatUptime(service.uptime) }}
                        </td>

                        <td
                            class="px-4 py-3 text-right font-mono text-muted-foreground tabular-nums"
                        >
                            <template v-if="service.target === null"
                                >--</template
                            >
                            <template v-else>
                                {{ service.target.toFixed(2) }}%
                                <span
                                    v-if="verdict(service)"
                                    class="block font-sans text-xs"
                                    :class="
                                        service.met === false
                                            ? 'text-status-down'
                                            : 'text-status-up'
                                    "
                                    >{{ verdict(service) }}</span
                                >
                            </template>
                        </td>

                        <td class="px-4 py-3 text-right font-mono tabular-nums">
                            {{ formatLatency(service.p50) }}
                        </td>

                        <td class="px-4 py-3 text-right font-mono tabular-nums">
                            {{ formatLatency(service.p95) }}
                        </td>

                        <td class="px-4 py-3 text-right font-mono tabular-nums">
                            {{
                                service.incidents === 0
                                    ? '--'
                                    : service.incidents
                            }}
                        </td>

                        <td class="px-4 py-3 text-right font-mono tabular-nums">
                            {{ formatDowntime(service.downtime_seconds)
                            }}<span
                                v-if="service.unresolved"
                                class="ml-1 font-sans text-xs text-status-down"
                                >ongoing</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p
            v-if="report.services.length"
            class="px-1 text-xs text-muted-foreground md:hidden"
        >
            Scroll the table sideways for response times and incidents.
        </p>

        <p class="px-1 text-xs text-muted-foreground">
            Uptime excludes maintenance, so a deploy neither helps nor hurts the
            number. Response times cover checks that got an answer: a failed
            connection has no latency to report. Uptime is measured from the
            checks in the month and downtime from when incidents opened and
            closed, so the two are counted separately and need not agree
            exactly.
        </p>
    </div>
</template>
