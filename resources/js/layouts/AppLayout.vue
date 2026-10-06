<script setup lang="ts">
import AppTabsLayout from '@/layouts/app/AppTabsLayout.vue';
import type { BreadcrumbItemType } from '@/types';

withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItemType[]; back?: { href: string; label: string }; grouped?: boolean }>(), {
    breadcrumbs: () => [],
    back: undefined,
    grouped: false,
});
</script>

<template>
    <AppTabsLayout :breadcrumbs="breadcrumbs" :back="back" :grouped="grouped">
        <slot />
    </AppTabsLayout>
</template>
