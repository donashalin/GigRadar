<script setup lang="ts">
import BottomTabBar from '@/components/BottomTabBar.vue';
import type { BreadcrumbItemType } from '@/types';
import { Link } from '@inertiajs/vue3';
import { ChevronLeft } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItemType[]; back?: { href: string; label: string } }>(), {
    breadcrumbs: () => [],
    back: undefined,
});

const title = computed(() => props.breadcrumbs.at(-1)?.title ?? 'GigRadar');
</script>

<template>
    <div class="flex min-h-svh flex-col bg-white dark:bg-neutral-950">
        <header
            class="sticky top-0 z-20 border-b border-neutral-200 bg-white/95 pt-[env(safe-area-inset-top)] backdrop-blur dark:border-neutral-800 dark:bg-neutral-950/95"
        >
            <div v-if="back" class="mx-auto grid h-14 w-full max-w-xl grid-cols-[1fr_auto_1fr] items-center px-2">
                <Link
                    :href="back.href"
                    class="-ml-1 inline-flex min-h-11 items-center gap-0.5 justify-self-start rounded-lg pr-3 text-base text-violet-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600 dark:text-violet-400"
                >
                    <ChevronLeft class="size-6" aria-hidden="true" />
                    {{ back.label }}
                </Link>
                <h1 class="max-w-[50vw] truncate text-lg font-semibold">{{ title }}</h1>
                <span aria-hidden="true" />
            </div>
            <div v-else class="mx-auto flex h-14 w-full max-w-xl items-center px-4">
                <h1 class="truncate text-lg font-semibold">{{ title }}</h1>
            </div>
        </header>

        <main class="mx-auto w-full min-w-0 max-w-xl flex-1 pb-[calc(3.5rem+env(safe-area-inset-bottom))]">
            <slot />
        </main>

        <BottomTabBar />
    </div>
</template>
