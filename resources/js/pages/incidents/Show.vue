<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Eye, EyeOff, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import StatusChip from '@/components/monitoring/StatusChip.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { formatDate, formatDuration, formatTime } from '@/lib/monitoring';
import {
    destroy as destroyUpdate,
    store as storeUpdate,
    update as saveUpdate,
} from '@/routes/incident-updates';
import { index as incidentsIndex } from '@/routes/incidents';
import { acknowledge } from '@/routes/incidents';
import { show as servicesShow } from '@/routes/services';
import type { IncidentDetail, IncidentUpdateRow } from '@/types/monitoring';

const props = defineProps<{
    incident: IncidentDetail;
    updates: IncidentUpdateRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Incidents', href: incidentsIndex() }],
    },
});

const form = useForm({ body: '', is_published: false });
const editingId = ref<number | null>(null);
const editForm = useForm({ body: '', is_published: false });

function post() {
    form.post(storeUpdate(props.incident.id).url, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function startEditing(update: IncidentUpdateRow) {
    editingId.value = update.id;
    editForm.body = update.body;
    editForm.is_published = update.is_published;
}

function saveEdit(update: IncidentUpdateRow) {
    editForm.put(saveUpdate(update.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null;
        },
    });
}

function togglePublished(update: IncidentUpdateRow) {
    router.put(
        saveUpdate(update.id).url,
        { body: update.body, is_published: !update.is_published },
        { preserveScroll: true },
    );
}

function remove(update: IncidentUpdateRow) {
    if (confirm('Delete this update?')) {
        router.delete(destroyUpdate(update.id).url, { preserveScroll: true });
    }
}

function toggleAcknowledged() {
    router.post(
        acknowledge(props.incident.id).url,
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="`${incident.service} incident`" />

    <div class="flex h-full flex-1 flex-col gap-5 p-4">
        <Link
            :href="incidentsIndex()"
            class="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" />
            All incidents
        </Link>

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold tracking-tight">
                    {{ incident.service }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ incident.reason }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Started {{ formatDate(incident.started_at) }} at
                    {{ formatTime(incident.started_at) }} &middot; lasted
                    {{
                        formatDuration(
                            incident.started_at,
                            incident.resolved_at,
                        )
                    }}
                    <template v-if="incident.resolved_at">
                        &middot; resolved</template
                    >
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <StatusChip :state="incident.severity" />
                <Button variant="outline" size="sm" as-child>
                    <Link :href="servicesShow(incident.service_id)"
                        >Open service</Link
                    >
                </Button>
                <Button
                    :variant="
                        incident.acknowledged_at ? 'secondary' : 'default'
                    "
                    size="sm"
                    @click="toggleAcknowledged"
                >
                    {{
                        incident.acknowledged_at
                            ? 'Unacknowledge'
                            : 'Acknowledge'
                    }}
                </Button>
            </div>
        </div>

        <p
            v-if="incident.acknowledged_at"
            class="rounded-lg border border-l-3 border-l-status-maintenance bg-card p-4 text-sm text-muted-foreground"
        >
            Acknowledged
            <template v-if="incident.acknowledged_by"
                >by {{ incident.acknowledged_by }}</template
            >
            at {{ formatTime(incident.acknowledged_at) }}.
        </p>

        <section class="rounded-lg border bg-card">
            <div class="border-b p-4">
                <h2 class="text-sm font-semibold">Post an update</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Published updates appear on the public status page while
                    this incident is open.
                </p>
            </div>

            <form class="space-y-4 p-4" @submit.prevent="post">
                <textarea
                    v-model="form.body"
                    rows="3"
                    placeholder="Investigating. The database is not accepting connections."
                    class="w-full rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                <p v-if="form.errors.body" class="text-sm text-destructive">
                    {{ form.errors.body }}
                </p>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <Switch id="is_published" v-model="form.is_published" />
                        <Label for="is_published" class="text-sm"
                            >Publish to the status page</Label
                        >
                    </div>
                    <Button type="submit" :disabled="form.processing"
                        >Post update</Button
                    >
                </div>
            </form>
        </section>

        <section class="rounded-lg border bg-card">
            <div class="border-b p-4">
                <h2 class="text-sm font-semibold">Timeline</h2>
            </div>

            <p
                v-if="updates.length === 0"
                class="p-6 text-sm text-muted-foreground"
            >
                No updates yet.
            </p>

            <div
                v-for="update in updates"
                :key="update.id"
                class="border-b p-4 last:border-b-0"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs text-muted-foreground">
                            <template v-if="update.created_at"
                                >{{ formatDate(update.created_at) }}
                                {{ formatTime(update.created_at) }}</template
                            >
                            <template v-if="update.author">
                                &middot; {{ update.author }}</template
                            >
                            &middot;
                            <span
                                :class="
                                    update.is_published
                                        ? 'text-status-up'
                                        : 'text-muted-foreground'
                                "
                                >{{
                                    update.is_published
                                        ? 'published'
                                        : 'internal'
                                }}</span
                            >
                        </p>

                        <template v-if="editingId === update.id">
                            <textarea
                                v-model="editForm.body"
                                rows="3"
                                class="mt-2 w-full rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            />
                            <div class="mt-2 flex items-center gap-2">
                                <Button size="sm" @click="saveEdit(update)"
                                    >Save</Button
                                >
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    @click="editingId = null"
                                    >Cancel</Button
                                >
                            </div>
                        </template>
                        <p v-else class="mt-1 text-sm whitespace-pre-line">
                            {{ update.body }}
                        </p>
                    </div>

                    <div
                        v-if="editingId !== update.id"
                        class="flex shrink-0 items-center gap-1"
                    >
                        <Button
                            variant="ghost"
                            size="sm"
                            :title="
                                update.is_published ? 'Unpublish' : 'Publish'
                            "
                            @click="togglePublished(update)"
                        >
                            <EyeOff v-if="update.is_published" class="size-4" />
                            <Eye v-else class="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="startEditing(update)"
                            >Edit</Button
                        >
                        <Button
                            variant="ghost"
                            size="sm"
                            class="text-destructive"
                            @click="remove(update)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
