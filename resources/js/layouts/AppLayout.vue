<script setup lang="ts">
import AppTabsLayout from '@/layouts/app/AppTabsLayout.vue';
import type { BreadcrumbItemType } from '@/types';

withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItemType[] }>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppTabsLayout :breadcrumbs="breadcrumbs">
        <slot />
    </AppTabsLayout>
</template>
