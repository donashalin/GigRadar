<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Alerts',
        href: '/settings/alerts',
    },
    {
        title: 'Profile',
        href: '/settings/profile',
    },
    {
        title: 'Password',
        href: '/settings/password',
    },
    {
        title: 'Appearance',
        href: '/settings/appearance',
    },
];

const page = usePage();
const currentPath = computed(() => page.url.split('?')[0]);
</script>

<template>
    <div class="px-4 py-6">
        <div class="flex flex-col space-y-8">
            <aside class="w-full">
                <nav class="flex flex-col space-x-0 space-y-1">
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="item.href"
                        variant="ghost"
                        :class="['min-h-11 w-full justify-start', { 'bg-muted': currentPath === item.href }]"
                        as-child
                    >
                        <Link :href="item.href">
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    class="mt-4 flex min-h-11 w-full items-center rounded-md px-4 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950"
                >
                    Log out
                </Link>
            </aside>

            <Separator class="my-6" />

            <div class="flex-1">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
