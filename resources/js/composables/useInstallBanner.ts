import { usePush } from '@/composables/usePush';
import { computed, ref } from 'vue';

const KEY = 'gigradar:install-dismissed';

function readDismissed(): boolean {
    try {
        return localStorage.getItem(KEY) !== null;
    } catch {
        return false;
    }
}

// Module-level so the banner and the layout share one dismissed state.
const dismissed = ref(readDismissed());

export function useInstallBanner() {
    const { needsInstall } = usePush();
    const visible = computed(() => needsInstall && !dismissed.value);

    function dismiss() {
        dismissed.value = true;
        try {
            localStorage.setItem(KEY, '1');
        } catch {
            // Storage unavailable; the banner stays dismissed for this page view only.
        }
    }

    return { visible, dismiss };
}
