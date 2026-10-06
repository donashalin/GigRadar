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
    alerts: { homeLocationName: string | null; nearbySummary: string; notifyEmail: boolean };
}>();

const { appearance } = useAppearance();
const appearanceLabel = computed(() => ({ light: 'Light', dark: 'Dark', system: 'System' })[appearance.value]);

// Optimistic value shown while a save is in flight; reverts if the save fails.
const pending = ref(false);
const emailError = ref(false);
const optimisticEmail = ref<boolean | null>(null);
const emailOn = computed(() => optimisticEmail.value ?? props.alerts.notifyEmail);

function toggleEmail() {
    if (pending.value) {
        return;
    }
    const next = !emailOn.value;
    pending.value = true;
    emailError.value = false;
    optimisticEmail.value = next;
    router.patch(
        '/settings/alerts',
        { notify_email: next },
        {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                optimisticEmail.value = null;
                emailError.value = true;
            },
            onFinish: () => {
                pending.value = false;
                optimisticEmail.value = null;
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
                    <SettingsSwitch :checked="emailOn" labelledby="email-alerts-label" :disabled="pending" @toggle="toggleEmail" />
                </SettingsRow>
                <p role="alert" :class="emailError && 'px-4 py-2'" class="text-sm text-red-600 dark:text-red-400">
                    {{ emailError ? "Couldn't save — try again." : '' }}
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
