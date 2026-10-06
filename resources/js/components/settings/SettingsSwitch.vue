<script setup lang="ts">
defineProps<{ checked: boolean; labelledby: string; disabled?: boolean }>();
defineEmits<{ toggle: [] }>();
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="checked"
        :aria-labelledby="labelledby"
        :aria-disabled="disabled"
        class="-my-2 -mr-2 inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600 aria-disabled:opacity-60"
        @click="$emit('toggle')"
    >
        <span
            class="relative inline-block h-7 w-12 rounded-full transition-colors"
            :class="checked ? 'bg-violet-600 dark:bg-violet-500' : 'bg-neutral-300 dark:bg-neutral-700'"
        >
            <span
                class="absolute left-0.5 top-0.5 size-6 rounded-full bg-white shadow transition-transform"
                :class="checked ? 'translate-x-5' : 'translate-x-0'"
            />
        </span>
    </button>
</template>
