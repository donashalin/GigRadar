<script setup lang="ts">
import SettingsGroup from '@/components/settings/SettingsGroup.vue';
import SettingsRow from '@/components/settings/SettingsRow.vue';
import SettingsSwitch from '@/components/settings/SettingsSwitch.vue';
import { useAppearance } from '@/composables/useAppearance';
import { usePush } from '@/composables/usePush';
import AppLayout from '@/layouts/AppLayout.vue';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

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

function sendTestAlert() {
    if (testing.value) {
        return;
    }
    testing.value = true;
    router.post('/settings/test-alert', {}, { preserveScroll: true, onFinish: () => (testing.value = false) });
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
                <p v-if="emailError" role="alert" class="px-4 py-2 text-sm text-red-600 dark:text-red-400">Couldn't save — try again.</p>

                <template v-if="push.needsInstall">
                    <SettingsRow
                        label="Push on this device"
                        value="Add to Home Screen first"
                        action
                        :aria-expanded="showInstallHelp"
                        aria-controls="push-install-help"
                        @click="showInstallHelp = !showInstallHelp"
                    />
                    <p v-if="showInstallHelp" id="push-install-help" class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-300">
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
                    <p v-if="push.error.value" role="alert" class="px-4 py-2 text-sm text-red-600 dark:text-red-400">{{ push.error.value }}</p>
                </template>

                <SettingsRow label="Send a test alert" action :aria-disabled="testing" @click="sendTestAlert" />
                <p v-if="page.props.flash?.success" role="status" class="px-4 py-2 text-sm text-green-700 dark:text-green-400">
                    {{ page.props.flash.success }}
                </p>
                <p v-if="page.props.flash?.error" role="alert" class="px-4 py-2 text-sm text-red-600 dark:text-red-400">
                    {{ page.props.flash.error }}
                </p>
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
