<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import StatusChip from '@/components/monitoring/StatusChip.vue';
import {
    formatDate,
    formatDuration,
    formatTime,
    stateBg,
} from '@/lib/monitoring';
import { index as incidentsIndex } from '@/routes/incidents';
import { show as servicesShow } from '@/routes/services';
import type { IncidentRow } from '@/types/monitoring';

defineProps<{ incidents: IncidentRow[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Incidents', href: incidentsIndex() }],
    },
});
</script>

<template>
    <Head title="Incidents" />

    <div class="flex h-full flex-1 flex-col gap-5 p-4">
        <div>
            <p
                class="text-xs font-semibold tracking-widest text-muted-foreground uppercase"
            >
                History
            </p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight">Incidents</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Opened when a check fails twice in a row, closed when it passes
                twice in a row.
            </p>
        </div>

        <div
            v-if="incidents.length === 0"
            class="rounded-lg border border-dashed p-10 text-center"
        >
            <p class="font-medium">No incidents recorded.</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Nothing has failed two checks in a row yet.
            </p>
        </div>

        <ul v-else class="overflow-hidden rounded-lg border bg-card">
            <li
                v-for="incident in incidents"
                :key="incident.id"
                class="grid grid-cols-[12px_1fr] gap-4 border-t p-4 first:border-t-0"
            >
                <span class="relative flex justify-center pt-1.5">
                    <span
                        class="size-2 rounded-full ring-3 ring-card"
                        :class="stateBg(incident.severity)"
                    />
                </span>

                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <Link
                            v-if="incident.service_id"
                            :href="servicesShow(incident.service_id)"
                            class="font-semibold tracking-tight hover:underline"
                        >
                            {{ incident.service }}
                        </Link>
                        <span v-else class="font-semibold tracking-tight">{{
                            incident.service
                        }}</span>
                        <StatusChip
                            :state="incident.severity"
                            :label="incident.resolved_at ? 'Resolved' : 'Open'"
                        />
                    </div>

                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ incident.reason }}
                    </p>

                    <p
                        class="mt-1.5 font-mono text-xs text-muted-foreground tabular-nums"
                    >
                        {{ formatDate(incident.started_at) }}
                        {{ formatTime(incident.started_at) }}
                        <template v-if="incident.resolved_at">
                            to {{ formatTime(incident.resolved_at) }} &middot;
                            down for
                            {{
                                formatDuration(
                                    incident.started_at,
                                    incident.resolved_at,
                                )
                            }}
                        </template>
                        <template v-else>
                            &middot; running for
                            {{ formatDuration(incident.started_at, null) }}
                        </template>
                    </p>
                </div>
            </li>
        </ul>
    </div>
</template>
