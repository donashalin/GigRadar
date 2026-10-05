<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface Result {
    id: string;
    name: string;
    imageUrl: string | null;
    following: boolean;
}

const props = defineProps<{ q: string; results: Result[]; error: string | null }>();

const query = ref(props.q);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(query, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get('/search', value.trim().length >= 2 ? { q: value.trim() } : {}, {
            preserveState: true,
            replace: true,
            only: ['q', 'results', 'error'],
        });
    }, 300);
});

function toggleFollow(result: Result) {
    const url = `/artists/${result.id}/follow`;
    const options = { preserveScroll: true, preserveState: true, only: ['results'] };
    if (result.following) {
        router.delete(url, options);
    } else {
        router.post(url, {}, options);
    }
}
</script>

<template>
    <Head title="Search" />
    <AppLayout :breadcrumbs="[{ title: 'Search', href: '/search' }]">
        <div class="mx-auto w-full max-w-xl p-4">
            <input
                v-model="query"
                type="search"
                autofocus
                placeholder="Search for an artist…"
                class="w-full rounded-xl border border-neutral-300 bg-white px-4 py-3 text-base dark:border-neutral-700 dark:bg-neutral-900"
            />

            <p v-if="error" class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ error }}</p>

            <p v-else-if="q.length >= 2 && results.length === 0" class="mt-6 text-center text-sm text-neutral-500">
                No artists found for “{{ q }}”.
            </p>

            <ul class="mt-4 divide-y divide-neutral-200 dark:divide-neutral-800">
                <li v-for="result in results" :key="result.id" class="flex items-center gap-3 py-3">
                    <Link :href="`/artists/${result.id}`" class="flex min-w-0 flex-1 items-center gap-3">
                        <img v-if="result.imageUrl" :src="result.imageUrl" alt="" class="size-12 shrink-0 rounded-lg object-cover" />
                        <div v-else class="size-12 shrink-0 rounded-lg bg-neutral-200 dark:bg-neutral-800" />
                        <span class="truncate font-medium">{{ result.name }}</span>
                    </Link>
                    <button
                        type="button"
                        class="shrink-0 rounded-full px-4 py-1.5 text-sm font-medium"
                        :class="result.following ? 'bg-neutral-200 dark:bg-neutral-800' : 'bg-violet-600 text-white'"
                        @click="toggleFollow(result)"
                    >
                        {{ result.following ? 'Following' : 'Follow' }}
                    </button>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
