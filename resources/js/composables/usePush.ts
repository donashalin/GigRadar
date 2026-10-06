import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

function urlBase64ToUint8Array(base64: string): Uint8Array<ArrayBuffer> {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(padded);
    const out = new Uint8Array(new ArrayBuffer(raw.length));
    for (let i = 0; i < raw.length; i++) {
        out[i] = raw.charCodeAt(i);
    }
    return out;
}

/** Resolves true only when the Inertia request succeeded (no validation or server error, not cancelled). */
function request(method: 'post' | 'delete', url: string, data: Record<string, unknown>): Promise<boolean> {
    return new Promise((resolve) => {
        let ok = false;
        const options = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                ok = true;
            },
            onFinish: () => resolve(ok),
        };
        if (method === 'post') {
            router.post(url, data as never, options);
        } else {
            router.delete(url, { ...options, data: data as never });
        }
    });
}

export function usePush() {
    const page = usePage<SharedData>();
    const hasBrowserSupport = typeof navigator !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

    const supported = computed(() => hasBrowserSupport && Boolean(page.props.vapidPublicKey));

    const isIOS =
        typeof navigator !== 'undefined' &&
        (/iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1));
    const standalone =
        typeof window !== 'undefined' &&
        (window.matchMedia('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true);
    const needsInstall = isIOS && !standalone;

    const permission = ref<NotificationPermission | 'unsupported'>(hasBrowserSupport ? Notification.permission : 'unsupported');
    const subscribed = ref(false);
    const busy = ref(false);
    const error = ref<string | null>(null);

    async function refresh() {
        if (!hasBrowserSupport) {
            return;
        }
        permission.value = Notification.permission;
        try {
            const registration = await navigator.serviceWorker.ready;
            subscribed.value = (await registration.pushManager.getSubscription()) !== null;
        } catch {
            subscribed.value = false;
        }
    }

    async function enable() {
        if (busy.value || !supported.value) {
            return;
        }
        busy.value = true;
        error.value = null;
        let subscription: PushSubscription | null = null;
        try {
            permission.value = await Notification.requestPermission();
            if (permission.value !== 'granted') {
                error.value = 'Notifications are blocked. Allow them in your device settings.';
                return;
            }

            const registration = await navigator.serviceWorker.ready;
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(page.props.vapidPublicKey as string),
            });
            const json = subscription.toJSON();
            const stored = await request('post', '/push-subscriptions', {
                endpoint: subscription.endpoint,
                keys: { p256dh: json.keys?.p256dh, auth: json.keys?.auth },
                contentEncoding: PushManager.supportedContentEncodings?.[0] ?? 'aes128gcm',
            });
            if (!stored) {
                throw new Error('Subscription was not stored');
            }
            subscribed.value = true;
        } catch {
            // Never keep a browser subscription the server didn't store.
            await subscription?.unsubscribe().catch(() => false);
            subscribed.value = false;
            error.value ??= "Couldn't turn on push — try again.";
        } finally {
            busy.value = false;
        }
    }

    async function disable() {
        if (busy.value || !hasBrowserSupport) {
            return;
        }
        busy.value = true;
        error.value = null;
        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            if (subscription) {
                const removed = await request('delete', '/push-subscriptions', { endpoint: subscription.endpoint });
                if (!removed) {
                    throw new Error('Subscription was not removed');
                }
                await subscription.unsubscribe();
            }
            subscribed.value = false;
        } catch {
            // Server still has the subscription, so keep the switch on.
            subscribed.value = true;
            error.value = "Couldn't turn off push — try again.";
        } finally {
            busy.value = false;
        }
    }

    return { supported, needsInstall, permission, subscribed, busy, error, enable, disable, refresh };
}
