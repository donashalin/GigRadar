<script setup lang="ts">
import { usePush } from '@/composables/usePush';
import { X } from 'lucide-vue-next';
import { ref } from 'vue';

const KEY = 'gigradar:install-dismissed';

const { needsInstall } = usePush();

function readDismissed(): boolean {
    try {
        return localStorage.getItem(KEY) !== null;
    } catch {
        return false;
    }
}

const dismissed = ref(readDismissed());

function dismiss() {
    dismissed.value = true;
    try {
        localStorage.setItem(KEY, '1');
    } catch {
        // Storage unavailable; the banner stays dismissed for this page view only.
    }
}
</script>

<template>
    <div
        v-if="needsInstall && !dismissed"
        class="fixed inset-x-0 bottom-[calc(3.5rem+env(safe-area-inset-bottom))] z-10 mx-auto flex max-w-xl items-start gap-2 bg-violet-600 py-3 pl-4 pr-2 text-white"
    >
        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold">Add GigRadar to your Home Screen for alerts</p>
            <p class="text-sm text-violet-100">Tap Share, then Add to Home Screen.</p>
        </div>
        <button
            type="button"
            aria-label="Dismiss"
            class="-my-1 inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
            @click="dismiss"
        >
            <X class="size-5" aria-hidden="true" />
        </button>
    </div>
</template>
