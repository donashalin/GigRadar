<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Link } from '@inertiajs/vue3';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
        <div class="w-full max-w-sm">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link
                        :href="route('home')"
                        class="flex flex-col items-center gap-3 rounded-2xl focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-violet-600"
                    >
                        <AppLogoIcon class="size-20 rounded-[22%] shadow-lg shadow-violet-600/30" />
                        <span class="text-2xl font-bold tracking-tight">GigRadar</span>
                        <span class="text-center text-sm text-muted-foreground">Never miss a tour date from the artists you love.</span>
                    </Link>
                    <div class="mt-4 space-y-2 text-center">
                        <h1 class="text-xl font-medium">{{ title }}</h1>
                        <p class="text-center text-sm text-muted-foreground">{{ description }}</p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
