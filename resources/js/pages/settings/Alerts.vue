<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Alert settings" />
    <AppLayout :breadcrumbs="[{ title: 'Alert settings', href: '/settings/alerts' }]" />
</template>
