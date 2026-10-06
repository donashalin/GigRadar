<script setup lang="ts">
import { useInstallBanner } from '@/composables/useInstallBanner';
import { X } from 'lucide-vue-next';

const { visible, dismiss } = useInstallBanner();
</script>

<template>
    <div
        v-if="visible"
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
