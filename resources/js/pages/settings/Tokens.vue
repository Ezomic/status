<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Check, Copy, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/monitoring';
import { destroy, index, store } from '@/routes/tokens';
import type { ApiToken } from '@/types/monitoring';

const props = defineProps<{
    tokens: ApiToken[];
    /**
     * Present for exactly one render, straight after creation. Only a hash is stored,
     * so once this page is left or reloaded the plaintext is gone for good.
     */
    createdToken: { name: string; plain: string } | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'API tokens', href: index() }],
    },
});

const form = useForm({ name: '' });
const copied = ref(false);

function submit() {
    form.post(store().url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            copied.value = false;
        },
    });
}

async function copy(value: string) {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = true;
    } catch {
        // Clipboard access can be refused (insecure context, denied permission). The
        // value stays selectable on screen, so the user is not stuck.
        copied.value = false;
    }
}

function revoke(token: ApiToken) {
    if (
        confirm(`Revoke "${token.name}"? Anything using it will stop working.`)
    ) {
        form.delete(destroy(token.id).url, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="API tokens" />

    <h1 class="sr-only">API tokens</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="API tokens"
            description="Tokens authenticate machine access to the status endpoint"
        />

        <div
            v-if="props.createdToken"
            class="rounded-lg border border-l-3 border-l-status-up bg-card p-4"
        >
            <p class="text-sm font-semibold">
                Token &ldquo;{{ props.createdToken.name }}&rdquo; created
            </p>
            <p class="mt-1 text-sm text-muted-foreground">
                Copy it now. This is the only time it is shown, because only a
                hash is stored.
            </p>
            <div class="mt-3 flex items-center gap-2">
                <code
                    class="min-w-0 flex-1 truncate rounded bg-muted px-3 py-2 font-mono text-xs select-all"
                    >{{ props.createdToken.plain }}</code
                >
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="copy(props.createdToken.plain)"
                >
                    <Check v-if="copied" class="size-4" />
                    <Copy v-else class="size-4" />
                    {{ copied ? 'Copied' : 'Copy' }}
                </Button>
            </div>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="name">Token name</Label>
                <div class="flex items-start gap-2">
                    <Input
                        id="name"
                        v-model="form.name"
                        placeholder="ID portal"
                        class="flex-1"
                        autocomplete="off"
                    />
                    <Button type="submit" :disabled="form.processing"
                        >Create token</Button
                    >
                </div>
                <p v-if="form.errors.name" class="text-sm text-destructive">
                    {{ form.errors.name }}
                </p>
            </div>
        </form>

        <div class="rounded-lg border">
            <p
                v-if="tokens.length === 0"
                class="p-6 text-sm text-muted-foreground"
            >
                You have no tokens yet.
            </p>

            <div
                v-for="token in tokens"
                :key="token.id"
                class="flex items-center justify-between gap-4 border-b p-4 last:border-b-0"
            >
                <div class="min-w-0">
                    <p class="truncate font-medium">{{ token.name }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        <template v-if="token.created_at"
                            >Created
                            {{ formatDate(token.created_at) }}</template
                        >
                        <template v-if="token.last_used_at">
                            &middot; last used
                            {{ formatDate(token.last_used_at) }}</template
                        >
                        <template v-else> &middot; never used</template>
                    </p>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="text-destructive"
                    @click="revoke(token)"
                >
                    <Trash2 class="size-4" />
                    Revoke
                </Button>
            </div>
        </div>
    </div>
</template>
