<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';

interface Place {
    name: string;
    lat: number;
    lng: number;
}

const props = defineProps<{
    settings: {
        homeLocationName: string | null;
        homeLat: number | null;
        homeLng: number | null;
        radiusMiles: number;
        notifyEmail: boolean;
    };
    radiusOptions: number[];
}>();

const form = useForm({
    home_location_name: props.settings.homeLocationName,
    home_lat: props.settings.homeLat,
    home_lng: props.settings.homeLng,
    radius_miles: props.settings.radiusMiles,
    notify_email: props.settings.notifyEmail,
});

const placeQuery = ref('');
const places = ref<Place[]>([]);
const lookupError = ref<string | null>(null);
const locating = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
let searchController: AbortController | undefined;
let reverseController: AbortController | undefined;
const previous = ref<Place | null>(null);

async function getJson<T>(url: string, signal: AbortSignal): Promise<T> {
    const response = await fetch(url, { headers: { Accept: 'application/json' }, signal });
    if (response.status === 429) {
        throw new Error('Too many lookups — please wait a moment.');
    }
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(body.message ?? 'Something went wrong. Please try again.');
    }
    return body as T;
}

watch(placeQuery, (value) => {
    clearTimeout(timer);
    lookupError.value = null;
    const q = value.trim();
    if (q.length < 3) {
        searchController?.abort();
        places.value = [];
        return;
    }
    timer = setTimeout(async () => {
        searchController?.abort();
        searchController = new AbortController();
        try {
            places.value = await getJson<Place[]>(`/settings/alerts/places?q=${encodeURIComponent(q)}`, searchController.signal);
            if (places.value.length === 0) {
                lookupError.value = "Couldn't find that place.";
            }
        } catch (e) {
            if ((e as Error).name !== 'AbortError') {
                lookupError.value = (e as Error).message;
            }
        }
    }, 400);
});

onBeforeUnmount(() => {
    clearTimeout(timer);
    searchController?.abort();
    reverseController?.abort();
});

function choose(place: Place) {
    searchController?.abort();
    clearTimeout(timer);
    lookupError.value = null;
    previous.value = null;
    form.home_location_name = place.name;
    form.home_lat = place.lat;
    form.home_lng = place.lng;
    placeQuery.value = '';
    places.value = [];
}

function clearLocation() {
    if (form.home_location_name && form.home_lat !== null && form.home_lng !== null) {
        previous.value = { name: form.home_location_name, lat: form.home_lat, lng: form.home_lng };
    }
    form.home_location_name = null;
    form.home_lat = null;
    form.home_lng = null;
}

function cancelChange() {
    searchController?.abort();
    clearTimeout(timer);
    if (previous.value) {
        form.home_location_name = previous.value.name;
        form.home_lat = previous.value.lat;
        form.home_lng = previous.value.lng;
    }
    previous.value = null;
    placeQuery.value = '';
    places.value = [];
    lookupError.value = null;
}

function useCurrentLocation() {
    if (!('geolocation' in navigator)) {
        lookupError.value = "Your browser can't share your location.";
        return;
    }
    clearTimeout(timer);
    searchController?.abort();
    locating.value = true;
    lookupError.value = null;
    navigator.geolocation.getCurrentPosition(
        async ({ coords }) => {
            reverseController?.abort();
            reverseController = new AbortController();
            try {
                choose(await getJson<Place>(`/settings/alerts/reverse?lat=${coords.latitude}&lng=${coords.longitude}`, reverseController.signal));
            } catch (e) {
                if ((e as Error).name !== 'AbortError') {
                    lookupError.value = (e as Error).message;
                }
            } finally {
                locating.value = false;
            }
        },
        (error) => {
            locating.value = false;
            lookupError.value =
                error.code === 1
                    ? 'Location permission was denied. You can type a city instead.'
                    : error.code === 3
                      ? 'Finding your location took too long — try again or type a city.'
                      : "Couldn't get your location.";
        },
        { timeout: 10000, maximumAge: 600000 },
    );
}

function save() {
    form.patch('/settings/alerts', { preserveScroll: true });
}
</script>

<template>
    <AppLayout :breadcrumbs="[{ title: 'Alert settings', href: '/settings/alerts' }]">
        <Head title="Alert settings" />

        <SettingsLayout>
            <form class="space-y-8" @submit.prevent="save">
                <section class="space-y-3">
                    <HeadingSmall title="Home location" description="Used for “Near me” alerts and gigs near you." />

                    <div
                        v-if="form.home_location_name"
                        class="flex items-center justify-between gap-3 rounded-xl border border-neutral-200 p-3 dark:border-neutral-800"
                    >
                        <span class="min-w-0 truncate font-medium">{{ form.home_location_name }}</span>
                        <button type="button" class="min-h-11 shrink-0 px-2 text-sm font-medium text-violet-600 dark:text-violet-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600" @click="clearLocation">
                            Change
                        </button>
                    </div>

                    <template v-else>
                        <input
                            v-model="placeQuery"
                            type="search"
                            maxlength="100"
                            :disabled="locating"
                            @keydown.enter.prevent="places.length && choose(places[0])"
                            aria-label="Search for a town or city"
                            placeholder="Type a town or city…"
                            class="w-full rounded-xl border border-neutral-300 bg-white px-4 py-3 text-base dark:border-neutral-700 dark:bg-neutral-900"
                        />
                        <ul v-if="places.length" class="divide-y divide-neutral-200 rounded-xl border border-neutral-200 dark:divide-neutral-800 dark:border-neutral-800">
                            <li v-for="place in places" :key="`${place.lat},${place.lng}`">
                                <button type="button" class="flex min-h-11 w-full items-center px-4 text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600" @click="choose(place)">
                                    {{ place.name }}
                                </button>
                            </li>
                        </ul>
                        <button
                            type="button"
                            class="inline-flex min-h-11 items-center rounded-full border border-violet-600 px-4 text-sm font-medium text-violet-600 disabled:opacity-60 dark:border-violet-400 dark:text-violet-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600"
                            :disabled="locating"
                            @click="useCurrentLocation"
                        >
                            {{ locating ? 'Finding you…' : 'Use my current location' }}
                        </button>
                        <button
                            v-if="previous"
                            type="button"
                            class="ml-2 inline-flex min-h-11 items-center px-2 text-sm font-medium text-violet-600 dark:text-violet-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600"
                            @click="cancelChange"
                        >
                            Cancel
                        </button>
                    </template>

                    <p v-if="lookupError" role="alert" class="text-sm text-red-600 dark:text-red-400">{{ lookupError }}</p>
                    <InputError :message="form.errors.home_location_name || form.errors.home_lat || form.errors.home_lng" />
                </section>

                <section class="space-y-3">
                    <HeadingSmall title="Distance" description="How far you'd travel for a gig." />
                    <div class="grid grid-cols-4 gap-1 rounded-xl bg-neutral-100 p-1 dark:bg-neutral-900" role="group" aria-label="Distance in miles">
                        <button
                            v-for="miles in radiusOptions"
                            :key="miles"
                            type="button"
                            class="min-h-11 rounded-lg text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600"
                            :class="form.radius_miles === miles ? 'bg-white shadow dark:bg-neutral-700' : 'text-neutral-600 dark:text-neutral-400'"
                            :aria-pressed="form.radius_miles === miles"
                            @click="form.radius_miles = miles"
                        >
                            {{ miles }} mi
                        </button>
                    </div>
                    <InputError :message="form.errors.radius_miles" />
                </section>

                <section class="space-y-3">
                    <HeadingSmall title="Alerts" description="How we tell you about new dates." />
                    <label class="flex min-h-11 items-center justify-between gap-3">
                        <span>Email me when artists I follow announce new dates</span>
                        <input v-model="form.notify_email" type="checkbox" class="size-5 shrink-0 accent-violet-600" />
                    </label>
                    <p class="text-sm text-neutral-500">Push notifications are coming soon.</p>
                </section>

                <div class="flex items-center gap-4">
                    <button
                        type="submit"
                        class="min-h-11 rounded-full bg-violet-600 px-6 text-sm font-medium text-white disabled:opacity-60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600"
                        :disabled="form.processing"
                    >
                        Save
                    </button>
                    <p v-if="form.recentlySuccessful" role="status" class="text-sm text-neutral-500">Saved.</p>
                </div>
            </form>
        </SettingsLayout>
    </AppLayout>
</template>
