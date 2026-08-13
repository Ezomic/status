<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate, formatTime } from '@/lib/monitoring';
import { destroy, index, store } from '@/routes/maintenance';
import type { MaintenanceWindowRow } from '@/types/monitoring';

const props = defineProps<{
    windows: MaintenanceWindowRow[];
    services: { id: number; name: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Maintenance', href: index() }],
    },
});

const form = useForm<{
    description: string;
    starts_at: string;
    ends_at: string;
    service_ids: number[];
}>({
    description: '',
    starts_at: '',
    ends_at: '',
    service_ids: [],
});

const PHASE_CLASSES: Record<MaintenanceWindowRow['phase'], string> = {
    open: 'border-l-status-maintenance',
    upcoming: 'border-l-status-idle',
    past: 'border-l-transparent opacity-60',
};

function submit() {
    form.post(store().url, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function toggleService(id: number) {
    form.service_ids = form.service_ids.includes(id)
        ? form.service_ids.filter((current) => current !== id)
        : [...form.service_ids, id];
}

function remove(window: MaintenanceWindowRow) {
    if (confirm(`Remove "${window.description}"?`)) {
        router.delete(destroy(window.id).url, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Maintenance" />

    <div class="flex h-full flex-1 flex-col gap-5 p-4">
        <div>
            <p
                class="text-xs font-semibold tracking-widest text-muted-foreground uppercase"
            >
                Planned work
            </p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight">Maintenance</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Checks keep running and results are still recorded during a
                window. Incidents and alerts stand down, and the public page
                says what is happening.
            </p>
        </div>

        <section class="rounded-lg border bg-card">
            <div class="border-b p-4">
                <h2 class="text-sm font-semibold">Schedule a window</h2>
            </div>

            <form class="space-y-4 p-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="description">What is happening</Label>
                    <Input
                        id="description"
                        v-model="form.description"
                        placeholder="Database upgrade"
                    />
                    <p
                        v-if="form.errors.description"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.description }}
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="starts_at">Starts</Label>
                        <Input
                            id="starts_at"
                            v-model="form.starts_at"
                            type="datetime-local"
                        />
                        <p
                            v-if="form.errors.starts_at"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.starts_at }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="ends_at">Ends</Label>
                        <Input
                            id="ends_at"
                            v-model="form.ends_at"
                            type="datetime-local"
                        />
                        <p
                            v-if="form.errors.ends_at"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.ends_at }}
                        </p>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label>Services covered</Label>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="service in props.services"
                            :key="service.id"
                            type="button"
                            class="rounded-full border px-3 py-1 text-sm transition-colors"
                            :class="
                                form.service_ids.includes(service.id)
                                    ? 'border-status-maintenance bg-status-maintenance/10 text-status-maintenance'
                                    : 'text-muted-foreground hover:bg-muted'
                            "
                            @click="toggleService(service.id)"
                        >
                            {{ service.name }}
                        </button>
                    </div>
                    <p
                        v-if="form.errors.service_ids"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.service_ids }}
                    </p>
                </div>

                <Button type="submit" :disabled="form.processing"
                    >Schedule window</Button
                >
            </form>
        </section>

        <section class="rounded-lg border bg-card">
            <div class="border-b p-4">
                <h2 class="text-sm font-semibold">Windows</h2>
            </div>

            <p
                v-if="windows.length === 0"
                class="p-6 text-sm text-muted-foreground"
            >
                Nothing scheduled.
            </p>

            <div
                v-for="window in windows"
                :key="window.id"
                class="flex flex-wrap items-start justify-between gap-4 border-b border-l-3 p-4 last:border-b-0"
                :class="PHASE_CLASSES[window.phase]"
            >
                <div class="min-w-0">
                    <p class="font-medium">
                        {{ window.description }}
                        <span
                            v-if="window.phase === 'open'"
                            class="ml-1 text-xs font-semibold text-status-maintenance uppercase"
                            >in progress</span
                        >
                        <span
                            v-else-if="window.phase === 'upcoming'"
                            class="ml-1 text-xs font-semibold text-muted-foreground uppercase"
                            >upcoming</span
                        >
                    </p>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        {{ formatDate(window.starts_at) }}
                        {{ formatTime(window.starts_at) }} to
                        {{ formatTime(window.ends_at) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ window.services.join(', ') || 'No services' }}
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="sm"
                    class="text-destructive"
                    @click="remove(window)"
                >
                    <Trash2 class="size-4" />
                </Button>
            </div>
        </section>
    </div>
</template>
