<script setup lang="ts">
import SettingsGroup from '@/components/settings/SettingsGroup.vue';
import SettingsRow from '@/components/settings/SettingsRow.vue';
import { useAppearance } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    alerts: { homeLocationName: string | null; nearbySummary: string; notifyEmail: boolean };
}>();

const { appearance } = useAppearance();
const appearanceLabel = computed(() => ({ light: 'Light', dark: 'Dark', system: 'System' })[appearance.value]);

// Optimistic value shown while a save is in flight; reverts if the save fails.
const pending = ref(false);
const optimisticEmail = ref<boolean | null>(null);
const emailOn = computed(() => optimisticEmail.value ?? props.alerts.notifyEmail);

function toggleEmail() {
    if (pending.value) {
        return;
    }
    const next = !emailOn.value;
    pending.value = true;
    optimisticEmail.value = next;
    router.patch(
        '/settings/alerts',
        { notify_email: next },
        {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                optimisticEmail.value = null;
            },
            onFinish: () => {
                pending.value = false;
                optimisticEmail.value = null;
            },
        },
    );
}
</script>

<template>
    <Head title="Settings" />
    <AppLayout :breadcrumbs="[{ title: 'Settings', href: '/settings' }]">
        <div class="min-h-[calc(100svh-3.5rem)] space-y-6 bg-neutral-100 p-4 dark:bg-neutral-950">
            <SettingsGroup title="Alerts">
                <SettingsRow label="Home location" :value="alerts.homeLocationName ?? 'Not set'" href="/settings/location" />
                <SettingsRow label="Near me" :value="alerts.nearbySummary" href="/settings/near-me" />
                <SettingsRow label="Email alerts">
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="emailOn"
                        aria-label="Email alerts"
                        :disabled="pending"
                        class="-my-2 -mr-2 inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600 disabled:opacity-60"
                        @click="toggleEmail"
                    >
                        <span
                            class="relative inline-block h-7 w-12 rounded-full transition-colors"
                            :class="emailOn ? 'bg-violet-600 dark:bg-violet-500' : 'bg-neutral-300 dark:bg-neutral-700'"
                        >
                            <span
                                class="absolute top-0.5 left-0.5 size-6 rounded-full bg-white shadow transition-transform"
                                :class="emailOn ? 'translate-x-5' : 'translate-x-0'"
                            />
                        </span>
                    </button>
                </SettingsRow>
            </SettingsGroup>

            <SettingsGroup title="Account">
                <SettingsRow label="Profile" href="/settings/profile" />
                <SettingsRow label="Password" href="/settings/password" />
                <SettingsRow label="Appearance" :value="appearanceLabel" href="/settings/appearance" />
            </SettingsGroup>

            <SettingsGroup>
                <SettingsRow label="Log out" href="/logout" method="post" destructive />
            </SettingsGroup>
        </div>
    </AppLayout>
</template>
