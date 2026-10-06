<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { formatConcertDate } from '@/lib/dates';
import { Head, Link } from '@inertiajs/vue3';

interface ArtistRow {
    ticketmasterId: string;
    name: string;
    imageUrl: string | null;
    alertScope: 'everywhere' | 'nearby';
    hasNew: boolean;
    nextConcert: { localDate: string | null; startsAt: string; city: string } | null;
}

interface NearbyConcert {
    id: number;
    artistName: string;
    artistTicketmasterId: string;
    localDate: string | null;
    startsAt: string;
    venueName: string;
    city: string;
    distanceMiles: number | null;
}

defineProps<{
    artists: ArtistRow[];
    hasHomeLocation: boolean;
    nearbyMode: 'country' | 'radius';
    homeCountryCode: string | null;
    radiusMiles: number;
    areaLabel: string | null;
    nearby: NearbyConcert[];
}>();

const shortDate: Intl.DateTimeFormatOptions = { weekday: 'short', day: 'numeric', month: 'short' };
</script>

<template>
    <Head title="My Artists" />
    <AppLayout :breadcrumbs="[{ title: 'My Artists', href: '/dashboard' }]">
        <div class="mx-auto w-full max-w-xl space-y-8 p-4">
            <section v-if="artists.length > 0" aria-labelledby="upcoming-near-you">
                <h2 id="upcoming-near-you" class="text-lg font-semibold">Upcoming near you</h2>

                <p v-if="areaLabel === null" class="mt-2 text-sm text-neutral-500">
                    <Link href="/settings/location" class="font-medium text-violet-600 dark:text-violet-400">{{
                        hasHomeLocation ? 'Re-pick your home location' : 'Set your home location'
                    }}</Link>
                    to see gigs near you.
                </p>
                <p v-else-if="nearby.length === 0" class="mt-2 text-sm text-neutral-500">No upcoming gigs {{ areaLabel }} yet.</p>
                <ul v-else class="mt-2 divide-y divide-neutral-200 dark:divide-neutral-800">
                    <li v-for="concert in nearby" :key="concert.id">
                        <Link :href="`/artists/${concert.artistTicketmasterId}`" class="flex min-h-11 items-center gap-3 rounded-lg py-3 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600">
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium">{{ concert.artistName }}</p>
                                <p class="truncate text-sm text-neutral-500">
                                    {{ formatConcertDate(concert.localDate, concert.startsAt, shortDate) }} · {{ concert.venueName }}, {{ concert.city }}
                                </p>
                            </div>
                            <span v-if="concert.distanceMiles !== null" class="shrink-0 text-xs text-neutral-500">{{ concert.distanceMiles }} mi</span>
                        </Link>
                    </li>
                </ul>
            </section>

            <section aria-labelledby="following">
                <h2 id="following" class="text-lg font-semibold">Following</h2>

                <div v-if="artists.length === 0" class="mt-4 rounded-xl border border-dashed border-neutral-300 p-6 text-center dark:border-neutral-700">
                    <p class="font-medium">You're not following anyone yet.</p>
                    <p class="mt-1 text-sm text-neutral-500">Follow artists to get alerts when they announce new dates.</p>
                    <Link href="/search" class="mt-4 inline-flex min-h-11 items-center rounded-full bg-violet-600 px-5 text-sm font-medium text-white">
                        Find artists
                    </Link>
                </div>

                <ul v-else class="mt-2 divide-y divide-neutral-200 dark:divide-neutral-800">
                    <li v-for="artist in artists" :key="artist.ticketmasterId">
                        <Link :href="`/artists/${artist.ticketmasterId}`" class="flex min-h-11 items-center gap-3 rounded-lg py-3 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600">
                            <img v-if="artist.imageUrl" :src="artist.imageUrl" alt="" class="size-12 shrink-0 rounded-lg object-cover" />
                            <div v-else class="size-12 shrink-0 rounded-lg bg-neutral-200 dark:bg-neutral-800" />
                            <div class="min-w-0 flex-1">
                                <p class="flex items-center gap-2">
                                    <span class="truncate font-medium">{{ artist.name }}</span>
                                    <span
                                        v-if="artist.hasNew"
                                        class="shrink-0 rounded-full bg-violet-600 px-2 py-0.5 text-xs font-semibold text-white"
                                    >
                                        New<span class="sr-only"> dates</span>
                                    </span>
                                </p>
                                <p class="truncate text-sm text-neutral-500">
                                    <template v-if="artist.nextConcert">
                                        Next: {{ formatConcertDate(artist.nextConcert.localDate, artist.nextConcert.startsAt, shortDate) }} · {{ artist.nextConcert.city }}
                                    </template>
                                    <template v-else>No upcoming dates</template>
                                </p>
                            </div>
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
