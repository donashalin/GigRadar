<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { formatConcertDate } from '@/lib/dates';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type AlertScope = 'everywhere' | 'nearby';

interface Concert {
    id: number;
    name: string;
    startsAt: string;
    localDate: string | null;
    venueName: string;
    city: string;
    country: string;
    ticketUrl: string;
    status: 'onsale' | 'offsale' | 'cancelled' | 'postponed' | 'rescheduled';
}

const props = defineProps<{
    artist: { ticketmasterId: string; name: string; imageUrl: string | null };
    concerts: Concert[];
    following: boolean;
    alertScope: AlertScope | null;
    refreshFailed: boolean;
}>();

const flashError = computed(() => usePage().props.flash?.error as string | null | undefined);

const followUrl = `/artists/${props.artist.ticketmasterId}/follow`;
const pending = ref(false);
const options = {
    preserveScroll: true,
    onFinish: () => {
        pending.value = false;
    },
};

function toggleFollow() {
    if (pending.value) return;
    pending.value = true;
    if (props.following) {
        router.delete(followUrl, options);
    } else {
        router.post(followUrl, {}, options);
    }
}

function retry() {
    if (pending.value) return;
    pending.value = true;
    router.reload({
        onFinish: () => {
            pending.value = false;
        },
    });
}

function setScope(alert_scope: AlertScope) {
    if (pending.value) return;
    pending.value = true;
    router.patch(followUrl, { alert_scope }, options);
}

const statusLabels: Partial<Record<Concert['status'], string>> = {
    cancelled: 'Cancelled',
    postponed: 'Postponed',
    rescheduled: 'Rescheduled',
};
</script>

<template>
    <Head :title="artist.name" />
    <AppLayout :breadcrumbs="[{ title: artist.name, href: `/artists/${artist.ticketmasterId}` }]">
        <div class="mx-auto w-full max-w-xl p-4">
            <img v-if="artist.imageUrl" :src="artist.imageUrl" alt="" class="aspect-video w-full rounded-2xl object-cover" />

            <div class="mt-4 flex items-center justify-between gap-3">
                <h1 class="text-2xl font-bold">{{ artist.name }}</h1>
                <button
                    type="button"
                    :aria-pressed="following"
                    :disabled="pending"
                    class="min-h-11 shrink-0 rounded-full px-5 py-2 text-sm font-medium disabled:opacity-60"
                    :class="following ? 'bg-neutral-200 dark:bg-neutral-800' : 'bg-violet-600 text-white'"
                    @click="toggleFollow"
                >
                    {{ following ? 'Following' : 'Follow' }}
                </button>
            </div>

            <div v-if="following" class="mt-4">
                <p class="mb-2 text-sm text-neutral-500">Alert me about new dates</p>
                <div class="grid grid-cols-2 gap-1 rounded-xl bg-neutral-100 p-1 dark:bg-neutral-900">
                    <button
                        v-for="scope in (['everywhere', 'nearby'] as AlertScope[])"
                        :key="scope"
                        type="button"
                        :aria-pressed="alertScope === scope"
                        :disabled="pending"
                        class="min-h-11 rounded-lg py-2 text-sm font-medium disabled:opacity-60"
                        :class="alertScope === scope ? 'bg-white shadow dark:bg-neutral-700' : 'text-neutral-500'"
                        @click="setScope(scope)"
                    >
                        {{ scope === 'everywhere' ? 'Everywhere' : 'Near me' }}
                    </button>
                </div>
            </div>

            <p v-if="flashError" role="alert" class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ flashError }}</p>

            <div
                v-if="refreshFailed && concerts.length === 0"
                role="alert"
                class="mt-4 flex items-center justify-between gap-3 rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
            >
                <span>Couldn't load dates right now.</span>
                <button
                    type="button"
                    :disabled="pending"
                    class="min-h-11 shrink-0 rounded-full border border-red-700 px-4 text-sm font-medium disabled:opacity-60 dark:border-red-300"
                    @click="retry"
                >
                    Retry
                </button>
            </div>

            <p v-else-if="refreshFailed" class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200">
                Couldn't refresh — showing saved dates.
            </p>

            <h2 class="mt-6 text-lg font-semibold">Upcoming concerts</h2>

            <p v-if="concerts.length === 0 && !refreshFailed" class="mt-3 text-sm text-neutral-500">
                No upcoming dates — we'll alert you when they're announced.
            </p>

            <ul class="mt-2 divide-y divide-neutral-200 dark:divide-neutral-800">
                <li v-for="concert in concerts" :key="concert.id" class="flex items-center gap-3 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ formatConcertDate(concert.localDate, concert.startsAt) }}</p>
                        <p class="truncate text-sm text-neutral-500">{{ concert.venueName }} · {{ concert.city }}, {{ concert.country }}</p>
                        <span
                            v-if="statusLabels[concert.status]"
                            class="mt-1 inline-block rounded bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950 dark:text-red-300"
                        >
                            {{ statusLabels[concert.status] }}
                        </span>
                    </div>
                    <a
                        v-if="concert.ticketUrl && concert.status !== 'cancelled'"
                        :href="concert.ticketUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex min-h-11 shrink-0 items-center rounded-full border border-violet-600 px-4 py-1.5 text-sm font-medium text-violet-600"
                    >
                        Get tickets
                    </a>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
