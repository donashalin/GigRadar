<script setup lang="ts">
import SettingsGroup from '@/components/settings/SettingsGroup.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Check } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    nearbyMode: 'country' | 'radius';
    radiusMiles: number;
    homeCountryCode: string | null;
    hasHomeLocation: boolean;
    radiusOptions: number[];
}>();

const pending = ref(false);
const error = ref<string | null>(null);

const countryName = computed(() => {
    if (!props.homeCountryCode) {
        return null;
    }
    try {
        return new Intl.DisplayNames(['en'], { type: 'region' }).of(props.homeCountryCode) ?? props.homeCountryCode;
    } catch {
        return props.homeCountryCode;
    }
});
const countryLabel = computed(() => `Anywhere in ${countryName.value ?? 'my country'}`);

const rowClass =
    'flex min-h-12 w-full items-center gap-3 px-4 text-left focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-violet-600 disabled:opacity-60';

function save(data: { nearby_mode: 'country' | 'radius'; radius_miles?: number }) {
    if (pending.value) {
        return;
    }
    pending.value = true;
    error.value = null;
    router.patch('/settings/alerts', data, {
        preserveScroll: true,
        onError: (errors) => {
            error.value = errors.nearby_mode || errors.radius_miles || "Couldn't save that. Please try again.";
        },
        onFinish: () => {
            pending.value = false;
        },
    });
}
</script>

<template>
    <Head title="Near me" />
    <AppLayout :breadcrumbs="[{ title: 'Near me', href: '/settings/near-me' }]" :back="{ href: '/settings', label: 'Settings' }">
        <div class="min-h-[calc(100svh-3.5rem)] space-y-3 bg-neutral-100 p-4 dark:bg-neutral-950">
            <SettingsGroup>
                <div role="radiogroup" aria-label="Which gigs count as near you" class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <button
                        type="button"
                        role="radio"
                        :aria-checked="nearbyMode === 'country'"
                        :class="rowClass"
                        :disabled="pending"
                        @click="save({ nearby_mode: 'country' })"
                    >
                        <span class="min-w-0 flex-1 truncate">{{ countryLabel }}</span>
                        <Check v-if="nearbyMode === 'country'" class="size-5 shrink-0 text-violet-600 dark:text-violet-400" aria-hidden="true" />
                    </button>
                    <button
                        v-for="miles in radiusOptions"
                        :key="miles"
                        type="button"
                        role="radio"
                        :aria-checked="nearbyMode === 'radius' && radiusMiles === miles"
                        :class="rowClass"
                        :disabled="pending"
                        @click="save({ nearby_mode: 'radius', radius_miles: miles })"
                    >
                        <span class="min-w-0 flex-1 truncate">Within {{ miles }} miles</span>
                        <Check v-if="nearbyMode === 'radius' && radiusMiles === miles" class="size-5 shrink-0 text-violet-600 dark:text-violet-400" aria-hidden="true" />
                    </button>
                </div>
            </SettingsGroup>

            <p v-if="error" role="alert" class="px-4 text-sm text-red-600 dark:text-red-400">{{ error }}</p>

            <p v-if="nearbyMode === 'country' && !homeCountryCode" class="px-4 text-sm text-neutral-500 dark:text-neutral-400">
                <template v-if="hasHomeLocation">Re-pick your home location to use this.</template>
                <template v-else>
                    <Link href="/settings/location" class="font-medium text-violet-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600 dark:text-violet-400">
                        Set your home location
                    </Link>
                    so we know which country.
                </template>
            </p>
            <p class="px-4 text-sm text-neutral-500 dark:text-neutral-400">Used for “Near me” alerts and gigs near you.</p>
        </div>
    </AppLayout>
</template>
