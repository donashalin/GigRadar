<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="My Artists" />
    <AppLayout :breadcrumbs="[{ title: 'My Artists', href: '/dashboard' }]" />
</template>
