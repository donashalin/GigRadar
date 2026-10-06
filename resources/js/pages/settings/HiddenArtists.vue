<script setup lang="ts">
import SettingsGroup from '@/components/settings/SettingsGroup.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

defineProps<{ artists: { attractionId: string; name: string }[] }>();

const listEl = ref<HTMLElement | null>(null);
const headingEl = ref<HTMLElement | null>(null);
const status = ref('');

const pendingId = ref<string | null>(null);
const error = ref('');

function showAgain(id: string, name: string, index: number) {
    if (pendingId.value !== null) return;
    pendingId.value = id;
    error.value = '';
    router.delete(`/dismissed-artists/${id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: async () => {
            status.value = '';
            await nextTick();
            status.value = `${name} will show on Discover again`;
            const buttons = listEl.value?.querySelectorAll<HTMLElement>('button');
            (buttons && buttons.length > 0 ? buttons[Math.min(index, buttons.length - 1)] : headingEl.value)?.focus();
        },
        onError: () => {
            error.value = "Couldn't update that. Please try again.";
        },
        onFinish: () => {
            pendingId.value = null;
        },
    });
}
</script>

<template>
    <Head title="Hidden artists" />
    <AppLayout :breadcrumbs="[{ title: 'Hidden artists', href: '/settings/hidden-artists' }]" :back="{ href: '/settings', label: 'Settings' }" grouped>
        <div ref="headingEl" tabindex="-1" class="space-y-3 p-4 outline-none">
            <p v-if="artists.length === 0" class="rounded-xl bg-white p-6 text-center text-neutral-600 dark:bg-neutral-900 dark:text-neutral-300">
                You haven't hidden any artists.
            </p>
            <SettingsGroup v-else>
                <div ref="listEl" class="divide-y divide-neutral-200 dark:divide-neutral-800">
                <div v-for="(artist, index) in artists" :key="artist.attractionId" class="flex min-h-12 items-center gap-3 px-4">
                    <span class="min-w-0 flex-1 truncate">{{ artist.name }}</span>
                    <button
                        type="button"
                        class="inline-flex min-h-11 shrink-0 items-center rounded-lg px-2 text-sm font-medium text-violet-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600 disabled:opacity-60 dark:text-violet-400"
                        :disabled="pendingId !== null"
                        :aria-label="`Show ${artist.name} again`"
                        @click="showAgain(artist.attractionId, artist.name, index)"
                    >
                        Show again
                    </button>
                </div>
                </div>
            </SettingsGroup>
            <p role="status" class="sr-only">{{ status }}</p>
            <p v-if="error" role="alert" class="px-4 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
            <p class="px-4 text-sm text-neutral-500 dark:text-neutral-400">Hidden artists won't appear on Discover.</p>
        </div>
    </AppLayout>
</template>
