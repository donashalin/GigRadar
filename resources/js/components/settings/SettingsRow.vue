<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';

defineProps<{ label: string; value?: string | null; href?: string; destructive?: boolean; method?: 'post' }>();

const rowClass =
    'flex min-h-12 w-full items-center gap-3 px-4 text-left focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-violet-600';
</script>

<template>
    <Link v-if="href" :href="href" :method="method" :as="method ? 'button' : 'a'" :class="[rowClass, destructive && 'justify-center']">
        <span :class="['min-w-0 truncate', destructive ? 'text-red-600 dark:text-red-400' : 'flex-1']">{{ label }}</span>
        <template v-if="!destructive">
            <span v-if="value" class="max-w-[50%] truncate text-neutral-500 dark:text-neutral-400">{{ value }}</span>
            <ChevronRight class="size-5 shrink-0 text-neutral-400 dark:text-neutral-500" aria-hidden="true" />
        </template>
    </Link>
    <div v-else :class="[rowClass, destructive && 'justify-center']">
        <span :class="['min-w-0 truncate', destructive ? 'text-red-600 dark:text-red-400' : 'flex-1']">{{ label }}</span>
        <span v-if="value" class="max-w-[50%] truncate text-neutral-500 dark:text-neutral-400">{{ value }}</span>
        <slot />
    </div>
</template>
