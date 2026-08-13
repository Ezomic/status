<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { edit, update } from '@/routes/notifications';

const props = defineProps<{
    services: { id: number; name: string; group: string | null }[];
    subscribedServiceIds: number[];
    allServices: boolean;
}>();

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
    all_services: props.allServices,
    services: [...props.subscribedServiceIds],
});

const sections = computed(() => {
    const groups: { group: string | null; services: typeof props.services }[] =
        [];

    for (const service of props.services) {
        const last = groups.at(-1);

        if (last && last.group === service.group) {
            last.services.push(service);

            continue;
        }

        groups.push({ group: service.group, services: [service] });
    }

    return groups;
});

function toggle(id: number, checked: boolean) {
    form.services = checked
        ? [...form.services, id]
        : form.services.filter((subscribed) => subscribed !== id);
}

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

            <fieldset
                v-if="form.wants_incident_mail && services.length"
                class="space-y-4 rounded-lg border p-4"
            >
                <legend class="sr-only">Which services</legend>

                <div class="flex items-start justify-between gap-4">
                    <div>
                        <Label for="all_services" class="font-medium"
                            >Every service</Label
                        >
                        <p class="mt-1 text-sm text-muted-foreground">
                            Includes services added later. Turn this off to pick
                            the ones you want.
                        </p>
                    </div>
                    <Switch id="all_services" v-model="form.all_services" />
                </div>

                <div v-if="!form.all_services" class="space-y-4 border-t pt-4">
                    <div
                        v-for="section in sections"
                        :key="section.group ?? 'ungrouped'"
                        class="space-y-2"
                    >
                        <p
                            v-if="section.group"
                            class="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                        >
                            {{ section.group }}
                        </p>

                        <div
                            v-for="service in section.services"
                            :key="service.id"
                            class="flex items-center gap-3"
                        >
                            <Checkbox
                                :id="`service-${service.id}`"
                                :model-value="
                                    form.services.includes(service.id)
                                "
                                @update:model-value="
                                    (checked) =>
                                        toggle(service.id, checked === true)
                                "
                            />
                            <Label
                                :for="`service-${service.id}`"
                                class="font-normal"
                                >{{ service.name }}</Label
                            >
                        </div>
                    </div>

                    <p
                        v-if="form.errors.services"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.services }}
                    </p>
                </div>
            </fieldset>

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
