<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

interface Result {
    id: string;
    name: string;
    imageUrl: string | null;
    following: boolean;
}

const props = defineProps<{ q: string; results: Result[]; error: string | null }>();

const flashError = computed(() => usePage().props.flash?.error as string | null | undefined);

const query = ref(props.q);
const input = ref<HTMLInputElement | null>(null);
const pendingId = ref<string | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;
let lastSent = props.q;

watch(query, (value) => {
    clearTimeout(timer);
    const next = value.trim().length >= 2 ? value.trim() : '';
    if (next === lastSent) return;
    timer = setTimeout(() => {
        lastSent = next;
        router.get('/search', next ? { q: next } : {}, {
            preserveState: true,
            replace: true,
            only: ['q', 'results', 'error'],
        });
    }, 300);
});

onMounted(() => input.value?.focus());
onBeforeUnmount(() => clearTimeout(timer));

function toggleFollow(result: Result) {
    if (pendingId.value !== null) return;
    pendingId.value = result.id;
    const url = `/artists/${result.id}/follow`;
    const options = {
        preserveScroll: true,
        preserveState: true,
        only: ['results', 'flash'],
        onFinish: () => {
            pendingId.value = null;
        },
    };
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
                ref="input"
                v-model="query"
                type="search"
                aria-label="Search for an artist"
                placeholder="Search for an artist…"
                class="w-full rounded-xl border border-neutral-300 bg-white px-4 py-3 text-base dark:border-neutral-700 dark:bg-neutral-900"
            />

            <p v-if="flashError" role="alert" class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ flashError }}</p>

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
                        :aria-pressed="result.following"
                        :disabled="pendingId === result.id"
                        class="min-h-11 shrink-0 rounded-full px-4 py-1.5 text-sm font-medium disabled:opacity-60"
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
