<script setup lang="ts">
import SettingsGroup from '@/components/settings/SettingsGroup.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{ artists: { attractionId: string; name: string }[] }>();

const pendingId = ref<string | null>(null);
const error = ref('');

function showAgain(id: string) {
    if (pendingId.value !== null) return;
    pendingId.value = id;
    error.value = '';
    router.delete(`/dismissed-artists/${id}`, {
        preserveScroll: true,
        preserveState: true,
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
        <div class="space-y-3 p-4">
            <p v-if="artists.length === 0" class="rounded-xl bg-white p-6 text-center text-neutral-600 dark:bg-neutral-900 dark:text-neutral-300">
                You haven't hidden any artists.
            </p>
            <SettingsGroup v-else>
                <div v-for="artist in artists" :key="artist.attractionId" class="flex min-h-12 items-center gap-3 px-4">
                    <span class="min-w-0 flex-1 truncate">{{ artist.name }}</span>
                    <button
                        type="button"
                        class="inline-flex min-h-11 shrink-0 items-center rounded-lg px-2 text-sm font-medium text-violet-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600 disabled:opacity-60 dark:text-violet-400"
                        :disabled="pendingId !== null"
                        :aria-label="`Show ${artist.name} again`"
                        @click="showAgain(artist.attractionId)"
                    >
                        Show again
                    </button>
                </div>
            </SettingsGroup>
            <p v-if="error" role="alert" class="px-4 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
            <p class="px-4 text-sm text-neutral-500 dark:text-neutral-400">Hidden artists won't appear on Discover.</p>
        </div>
    </AppLayout>
</template>
