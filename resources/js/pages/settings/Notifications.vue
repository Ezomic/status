<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { edit, update } from '@/routes/notifications';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Notification settings',
                href: edit(),
            },
        ],
    },
});

const page = usePage();

const form = useForm({
    wants_incident_mail: page.props.auth.user.wants_incident_mail ?? false,
});

function submit() {
    form.patch(update().url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Notification settings" />

    <h1 class="sr-only">Notification settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Notification settings"
            description="Choose whether Status emails you when an incident opens, gets worse or resolves"
        />

        <form class="space-y-6" @submit.prevent="submit">
            <div
                class="flex items-start justify-between gap-4 rounded-lg border p-4"
            >
                <div>
                    <Label for="wants_incident_mail" class="font-medium"
                        >Incident emails</Label
                    >
                    <p class="mt-1 text-sm text-muted-foreground">
                        One email per transition, not per failed check: when an
                        incident opens, when it escalates, and when it resolves.
                    </p>
                </div>
                <Switch
                    id="wants_incident_mail"
                    v-model="form.wants_incident_mail"
                />
            </div>

            <p
                v-if="form.errors.wants_incident_mail"
                class="text-sm text-destructive"
            >
                {{ form.errors.wants_incident_mail }}
            </p>

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="form.processing"
                    >Save changes</Button
                >
                <span
                    v-if="form.recentlySuccessful"
                    class="text-sm text-muted-foreground"
                    >Saved.</span
                >
            </div>
        </form>
    </div>
</template>
