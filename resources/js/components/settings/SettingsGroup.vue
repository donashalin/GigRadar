<script setup lang="ts">
defineProps<{ title?: string }>();
</script>

<template>
    <section>
        <h2 v-if="title" class="px-4 pb-2 text-xs font-medium tracking-wide text-neutral-500 uppercase dark:text-neutral-400">{{ title }}</h2>
        <div class="divide-y divide-neutral-200 overflow-hidden rounded-xl bg-white dark:divide-neutral-800 dark:bg-neutral-900">
            <slot />
        </div>
    </section>
</template>
