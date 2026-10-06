<script setup lang="ts">
import BottomTabBar from '@/components/BottomTabBar.vue';
import type { BreadcrumbItemType } from '@/types';
import { computed } from 'vue';

const props = withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItemType[] }>(), {
    breadcrumbs: () => [],
});

const title = computed(() => props.breadcrumbs.at(-1)?.title ?? 'GigRadar');
</script>

<template>
    <div class="flex min-h-svh flex-col bg-white dark:bg-neutral-950">
        <header
            class="sticky top-0 z-20 border-b border-neutral-200 bg-white/95 pt-[env(safe-area-inset-top)] backdrop-blur dark:border-neutral-800 dark:bg-neutral-950/95"
        >
            <div class="mx-auto flex h-14 w-full max-w-xl items-center px-4">
                <h1 class="truncate text-lg font-semibold">{{ title }}</h1>
            </div>
        </header>

        <main class="mx-auto w-full min-w-0 max-w-xl flex-1 pb-[calc(3.5rem+env(safe-area-inset-bottom))]">
            <slot />
        </main>

        <BottomTabBar />
    </div>
</template>
