<script setup lang="ts">
import SettingsGroup from '@/components/settings/SettingsGroup.vue';
import SettingsRow from '@/components/settings/SettingsRow.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

interface Place {
    name: string;
    lat: number;
    lng: number;
    countryCode: string | null;
}

defineProps<{
    homeLocationName: string | null;
}>();

const placeQuery = ref('');
const places = ref<Place[]>([]);
const lookupError = ref<string | null>(null);
const saveError = ref<string | null>(null);
const locating = ref(false);
const saving = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
let searchController: AbortController | undefined;
let reverseController: AbortController | undefined;
let lookupId = 0;

const busy = computed(() => locating.value || saving.value);

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
    lookupId++;
    clearTimeout(timer);
    searchController?.abort();
    reverseController?.abort();
});

function saveLocation(fields: {
    home_location_name: string | null;
    home_lat: number | null;
    home_lng: number | null;
    home_country_code: string | null;
}) {
    if (saving.value) {
        return;
    }
    saving.value = true;
    saveError.value = null;
    router.patch(
        '/settings/alerts',
        { ...fields, redirect_to: 'settings' },
        {
            preserveScroll: true,
            preserveState: 'errors',
            onError: (errors) => {
                saveError.value =
                    errors.home_location_name ||
                    errors.home_lat ||
                    errors.home_lng ||
                    errors.home_country_code ||
                    "Couldn't save that. Please try again.";
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}

function choose(place: Place) {
    searchController?.abort();
    clearTimeout(timer);
    lookupError.value = null;
    placeQuery.value = '';
    places.value = [];
    saveLocation({ home_location_name: place.name, home_lat: place.lat, home_lng: place.lng, home_country_code: place.countryCode ?? null });
}

function removeLocation() {
    saveLocation({ home_location_name: null, home_lat: null, home_lng: null, home_country_code: null });
}

function useCurrentLocation() {
    if (!('geolocation' in navigator)) {
        lookupError.value = "Your browser can't share your location.";
        return;
    }
    clearTimeout(timer);
    searchController?.abort();
    const id = ++lookupId;
    locating.value = true;
    lookupError.value = null;
    navigator.geolocation.getCurrentPosition(
        async ({ coords }) => {
            if (id !== lookupId) {
                return;
            }
            reverseController?.abort();
            reverseController = new AbortController();
            try {
                const place = await getJson<Place>(
                    `/settings/alerts/reverse?lat=${coords.latitude}&lng=${coords.longitude}`,
                    reverseController.signal,
                );
                if (id !== lookupId) {
                    return;
                }
                choose(place);
            } catch (e) {
                if (id === lookupId && (e as Error).name !== 'AbortError') {
                    lookupError.value = (e as Error).message;
                }
            } finally {
                if (id === lookupId) {
                    locating.value = false;
                }
            }
        },
        (error) => {
            if (id !== lookupId) {
                return;
            }
            locating.value = false;
            lookupError.value =
                error.code === error.PERMISSION_DENIED
                    ? 'Location permission was denied. You can type a city instead.'
                    : error.code === error.TIMEOUT
                      ? 'Finding your location took too long — try again or type a city.'
                      : "Couldn't get your location.";
        },
        { timeout: 10000, maximumAge: 600000 },
    );
}
</script>

<template>
    <Head title="Home location" />
    <AppLayout :breadcrumbs="[{ title: 'Home location', href: '/settings/location' }]" :back="{ href: '/settings', label: 'Settings' }" grouped>
        <div class="space-y-6 p-4">
            <SettingsGroup v-if="homeLocationName" title="Current">
                <SettingsRow label="Home location" :value="homeLocationName" />
            </SettingsGroup>

            <div class="space-y-3">
                <input
                    v-model="placeQuery"
                    type="search"
                    maxlength="100"
                    :disabled="busy"
                    @keydown.enter.prevent="places.length && choose(places[0])"
                    aria-label="Search for a town or city"
                    placeholder="Type a town or city…"
                    class="w-full rounded-xl border border-neutral-300 bg-white px-4 py-3 text-base focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600 dark:border-neutral-700 dark:bg-neutral-900"
                />
                <ul
                    v-if="places.length"
                    class="divide-y divide-neutral-200 rounded-xl border border-neutral-200 dark:divide-neutral-800 dark:border-neutral-800"
                >
                    <li v-for="place in places" :key="`${place.lat},${place.lng}`">
                        <button
                            type="button"
                            class="flex min-h-11 w-full items-center px-4 text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600 disabled:opacity-60"
                            :disabled="busy"
                            @click="choose(place)"
                        >
                            {{ place.name }}
                        </button>
                    </li>
                </ul>
                <button
                    type="button"
                    class="inline-flex min-h-11 items-center rounded-full border border-violet-600 px-4 text-sm font-medium text-violet-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600 disabled:opacity-60 dark:border-violet-400 dark:text-violet-400"
                    :disabled="busy"
                    @click="useCurrentLocation"
                >
                    {{ locating ? 'Finding you…' : 'Use my current location' }}
                </button>

                <p v-if="lookupError" role="alert" class="text-sm text-red-600 dark:text-red-400">{{ lookupError }}</p>
                <p v-if="saveError" role="alert" class="text-sm text-red-600 dark:text-red-400">{{ saveError }}</p>
            </div>

            <SettingsGroup v-if="homeLocationName">
                <SettingsRow label="Remove home location" action destructive :aria-disabled="busy" @click="removeLocation" />
            </SettingsGroup>
            <p class="px-4 text-sm text-neutral-500 dark:text-neutral-400">Used for “Near me” alerts and gigs near you.</p>
        </div>
    </AppLayout>
</template>
