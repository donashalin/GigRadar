<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Music, Search, Settings } from 'lucide-vue-next';

const page = usePage();

const tabs = [
    { title: 'My Artists', href: '/dashboard', icon: Music, match: ['/dashboard', '/artists'] },
    { title: 'Search', href: '/search', icon: Search, match: ['/search'] },
    { title: 'Settings', href: '/settings', icon: Settings, match: ['/settings'] },
];

const isActive = (match: string[]) =>
    match.some((prefix) => page.url === prefix || page.url.startsWith(`${prefix}/`) || page.url.startsWith(`${prefix}?`));
</script>

<template>
    <nav
        aria-label="Main"
        class="fixed inset-x-0 bottom-0 z-20 border-t border-neutral-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur dark:border-neutral-800 dark:bg-neutral-950/95"
    >
        <ul class="mx-auto grid max-w-xl grid-cols-3">
            <li v-for="tab in tabs" :key="tab.href">
                <Link
                    :href="tab.href"
                    class="flex min-h-14 flex-col items-center justify-center gap-0.5 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600"
                    :class="isActive(tab.match) ? 'text-violet-600 dark:text-violet-400' : 'text-neutral-500 dark:text-neutral-400'"
                    :aria-current="isActive(tab.match) ? 'page' : undefined"
                >
                    <component :is="tab.icon" class="size-6" aria-hidden="true" />
                    {{ tab.title }}
                </Link>
            </li>
        </ul>
    </nav>
</template>
