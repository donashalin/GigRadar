<script setup lang="ts">
import SettingsGroup from '@/components/settings/SettingsGroup.vue';
import SettingsRow from '@/components/settings/SettingsRow.vue';
import SettingsSwitch from '@/components/settings/SettingsSwitch.vue';
import { useAppearance } from '@/composables/useAppearance';
import { usePush } from '@/composables/usePush';
import AppLayout from '@/layouts/AppLayout.vue';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    alerts: { homeLocationName: string | null; nearbySummary: string; notifyEmail: boolean; notifySimilar: boolean };
    hiddenCount: number;
}>();

const { appearance } = useAppearance();
const appearanceLabel = computed(() => ({ light: 'Light', dark: 'Dark', system: 'System' })[appearance.value]);

// Optimistic values shown while a save is in flight; they revert if the save fails.
type AlertKey = 'notify_email' | 'notify_similar';

const alertSaving = ref(false);
const alertErrors = ref<Record<AlertKey, boolean>>({ notify_email: false, notify_similar: false });
const optimistic = ref<Record<AlertKey, boolean | null>>({ notify_email: null, notify_similar: null });
const emailOn = computed(() => optimistic.value.notify_email ?? props.alerts.notifyEmail);
const similarOn = computed(() => optimistic.value.notify_similar ?? props.alerts.notifySimilar);

function toggleAlert(key: AlertKey) {
    if (alertSaving.value) {
        return;
    }
    const next = !(key === 'notify_email' ? emailOn.value : similarOn.value);
    alertSaving.value = true;
    alertErrors.value[key] = false;
    optimistic.value[key] = next;
    router.patch(
        '/settings/alerts',
        { [key]: next },
        {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                optimistic.value[key] = null;
                alertErrors.value[key] = true;
            },
            onFinish: () => {
                alertSaving.value = false;
                optimistic.value[key] = null;
            },
        },
    );
}

const push = usePush();
const showInstallHelp = ref(false);
onMounted(push.refresh);

function togglePush() {
    if (push.busy.value) {
        return;
    }
    return push.subscribed.value ? push.disable() : push.enable();
}

const page = usePage<SharedData>();
const testing = ref(false);
const testBlocked = computed(() => testing.value || push.busy.value);

// Flash text is mirrored into always-rendered live regions. Clearing first (then setting on the
// next tick) makes a repeated identical message announce again.
const successText = ref('');
const errorText = ref('');

async function syncFlash() {
    successText.value = '';
    errorText.value = '';
    await nextTick();
    successText.value = page.props.flash?.success ?? '';
    errorText.value = page.props.flash?.error ?? '';
}
watch(() => [page.props.flash?.success, page.props.flash?.error], syncFlash, { immediate: true });

const pushMessage = computed(
    () => push.error.value ?? (push.permission.value === 'denied' ? 'Notifications are blocked. Allow them in your device settings.' : ''),
);

function sendTestAlert() {
    if (testBlocked.value) {
        return;
    }
    testing.value = true;
    router.post(
        '/settings/test-alert',
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                testing.value = false;
                syncFlash();
            },
        },
    );
}

async function logout() {
    if (push.subscribed.value) {
        try {
            await push.disable();
        } catch {
            // Logging out matters more than cleaning up this device's subscription.
        }
    }
    router.post('/logout');
}
</script>

<template>
    <Head title="Settings" />
    <AppLayout :breadcrumbs="[{ title: 'Settings', href: '/settings' }]" grouped>
        <div class="space-y-6 p-4">
            <SettingsGroup title="Alerts">
                <SettingsRow label="Home location" :value="alerts.homeLocationName ?? 'Not set'" href="/settings/location" />
                <SettingsRow label="Near me" :value="alerts.nearbySummary" href="/settings/near-me" />
                <SettingsRow label="Email alerts" label-id="email-alerts-label">
                    <SettingsSwitch
                        :checked="emailOn"
                        labelledby="email-alerts-label"
                        :disabled="alertSaving"
                        @toggle="toggleAlert('notify_email')"
                    />
                </SettingsRow>
                <p role="alert" :class="alertErrors.notify_email && 'px-4 py-2'" class="text-sm text-red-600 dark:text-red-400">
                    {{ alertErrors.notify_email ? "Couldn't save — try again." : '' }}
                </p>

                <template v-if="push.needsInstall">
                    <SettingsRow
                        label="Push on this device"
                        value="Add to Home Screen first"
                        action
                        :aria-expanded="showInstallHelp"
                        aria-controls="push-install-help"
                        @click="showInstallHelp = !showInstallHelp"
                    />
                    <p v-show="showInstallHelp" id="push-install-help" class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-300">
                        Tap Share, then Add to Home Screen, then open GigRadar from the new icon and turn push on here.
                    </p>
                </template>
                <SettingsRow v-else-if="!push.supported.value" label="Push on this device" value="Not supported on this browser" />
                <template v-else>
                    <SettingsRow label="Push on this device" label-id="push-alerts-label">
                        <SettingsSwitch
                            :checked="push.subscribed.value"
                            labelledby="push-alerts-label"
                            :disabled="push.busy.value"
                            @toggle="togglePush"
                        />
                    </SettingsRow>
                    <p role="alert" :class="pushMessage && 'px-4 py-2'" class="text-sm text-red-600 dark:text-red-400">{{ pushMessage }}</p>
                </template>

                <SettingsRow label="Send a test alert" action :aria-disabled="testBlocked" @click="sendTestAlert" />
                <p role="status" :class="successText && 'px-4 py-2'" class="text-sm text-green-700 dark:text-green-400">{{ successText }}</p>
                <p role="alert" :class="errorText && 'px-4 py-2'" class="text-sm text-red-600 dark:text-red-400">{{ errorText }}</p>
            </SettingsGroup>

            <SettingsGroup title="Discover">
                <SettingsRow label="Similar artists" sublabel="Weekly roundup of gigs that match your taste" label-id="similar-alerts-label">
                    <SettingsSwitch
                        :checked="similarOn"
                        labelledby="similar-alerts-label"
                        :disabled="alertSaving"
                        @toggle="toggleAlert('notify_similar')"
                    />
                </SettingsRow>
                <p role="alert" :class="alertErrors.notify_similar && 'px-4 py-2'" class="text-sm text-red-600 dark:text-red-400">
                    {{ alertErrors.notify_similar ? "Couldn't save — try again." : '' }}
                </p>

                <SettingsRow label="Hidden artists" :value="String(hiddenCount)" href="/settings/hidden-artists" />
            </SettingsGroup>

            <SettingsGroup title="Account">
                <SettingsRow label="Profile" href="/settings/profile" />
                <SettingsRow label="Password" href="/settings/password" />
                <SettingsRow label="Appearance" :value="appearanceLabel" href="/settings/appearance" />
            </SettingsGroup>

            <SettingsGroup>
                <SettingsRow label="Log out" action destructive @click="logout" />
            </SettingsGroup>
        </div>
    </AppLayout>
</template>
