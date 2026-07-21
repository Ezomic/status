<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { store, update } from '@/routes/services';
import type { ServiceDetail } from '@/types/monitoring';

const props = withDefaults(defineProps<{ service?: ServiceDetail }>(), {
    service: undefined,
});

const open = ref(false);

const form = useForm({
    name: props.service?.name ?? '',
    url: props.service?.url ?? '',
    expected_status_code: props.service?.expected_status_code ?? 200,
    interval_seconds: props.service?.interval_seconds ?? 60,
    timeout_seconds: props.service?.timeout_seconds ?? 5,
    degraded_threshold_ms: props.service?.degraded_threshold_ms ?? 1000,
    is_active: props.service?.is_active ?? true,
    is_public: props.service?.is_public ?? false,
});

watch(open, (isOpen) => {
    if (!isOpen) {
        form.clearErrors();
    }
});

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;

            if (props.service === undefined) {
                form.reset();
            }
        },
    };

    if (props.service) {
        form.put(update(props.service.id).url, options);

        return;
    }

    form.post(store().url, options);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <slot>
                <Button variant="outline" size="sm">Add a service</Button>
            </slot>
        </DialogTrigger>

        <DialogContent class="sm:max-w-lg">
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{
                        service ? 'Edit service' : 'Add a service'
                    }}</DialogTitle>
                    <DialogDescription>
                        Status polls this address on its own schedule and opens
                        an incident when two checks in a row fail.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 py-5">
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            placeholder="Tracker"
                            required
                        />
                        <p
                            v-if="form.errors.name"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="url">Address</Label>
                        <Input
                            id="url"
                            v-model="form.url"
                            type="url"
                            placeholder="https://tracker.thijssensoftware.nl"
                            required
                        />
                        <p
                            v-if="form.errors.url"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.url }}
                        </p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="expected_status_code"
                                >Expected status</Label
                            >
                            <Input
                                id="expected_status_code"
                                v-model.number="form.expected_status_code"
                                type="number"
                                required
                            />
                            <p
                                v-if="form.errors.expected_status_code"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.expected_status_code }}
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label for="interval_seconds"
                                >Check every (seconds)</Label
                            >
                            <Input
                                id="interval_seconds"
                                v-model.number="form.interval_seconds"
                                type="number"
                                required
                            />
                            <p
                                v-if="form.errors.interval_seconds"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.interval_seconds }}
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label for="timeout_seconds"
                                >Timeout (seconds)</Label
                            >
                            <Input
                                id="timeout_seconds"
                                v-model.number="form.timeout_seconds"
                                type="number"
                                required
                            />
                            <p
                                v-if="form.errors.timeout_seconds"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.timeout_seconds }}
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label for="degraded_threshold_ms"
                                >Slow above (ms)</Label
                            >
                            <Input
                                id="degraded_threshold_ms"
                                v-model.number="form.degraded_threshold_ms"
                                type="number"
                                required
                            />
                            <p
                                v-if="form.errors.degraded_threshold_ms"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.degraded_threshold_ms }}
                            </p>
                        </div>
                    </div>

                    <div
                        class="flex items-center justify-between rounded-lg border p-3"
                    >
                        <div>
                            <Label for="is_active" class="font-medium"
                                >Checking</Label
                            >
                            <p class="text-sm text-muted-foreground">
                                Pausing stops checks and closes any open
                                incident.
                            </p>
                        </div>
                        <Switch id="is_active" v-model="form.is_active" />
                    </div>

                    <div
                        class="flex items-center justify-between rounded-lg border p-3"
                    >
                        <div>
                            <Label for="is_public" class="font-medium"
                                >Public status</Label
                            >
                            <p class="text-sm text-muted-foreground">
                                Expose this service's state on the public status
                                endpoint and portal. No URL or timing is shared.
                            </p>
                        </div>
                        <Switch id="is_public" v-model="form.is_public" />
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="open = false"
                        >Cancel</Button
                    >
                    <Button type="submit" :disabled="form.processing">
                        {{ service ? 'Save changes' : 'Add service' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
