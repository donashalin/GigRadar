<script setup lang="ts">
import AppTabsLayout from '@/layouts/app/AppTabsLayout.vue';
import type { BreadcrumbItemType } from '@/types';

withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItemType[]; back?: { href: string; label: string } }>(), {
    breadcrumbs: () => [],
    back: undefined,
});
</script>

<template>
    <AppTabsLayout :breadcrumbs="breadcrumbs" :back="back">
        <slot />
    </AppTabsLayout>
</template>
