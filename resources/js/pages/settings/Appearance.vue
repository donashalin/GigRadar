<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import AppearanceTabs from '@/components/AppearanceTabs.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { type BreadcrumbItem } from '@/types';

import AppLayout from '@/layouts/AppLayout.vue';

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Appearance',
        href: '/settings/appearance',
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems" :back="{ href: '/settings', label: 'Settings' }">
        <Head title="Appearance" />

        <div class="mx-auto w-full max-w-xl space-y-6 p-4">
            <div class="space-y-6">
                <HeadingSmall title="Theme" description="Choose how GigRadar looks on this device" />
                <AppearanceTabs />
            </div>
        </div>
    </AppLayout>
</template>
