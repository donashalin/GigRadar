<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const appName = (page.props.name as string) || 'GigRadar';
</script>

<template>
    <Head title="Welcome" />
    <div class="flex min-h-screen flex-col items-center justify-center bg-white px-6 py-12 text-center text-neutral-900 dark:bg-neutral-950 dark:text-neutral-100">
        <main class="w-full max-w-sm">
            <h1 class="text-4xl font-bold tracking-tight">{{ appName }}</h1>
            <p class="mt-3 text-base text-neutral-600 dark:text-neutral-400">Never miss a tour date from the artists you love.</p>

            <div class="mt-8 flex flex-col gap-3">
                <Link
                    v-if="$page.props.auth.user"
                    href="/search"
                    class="inline-flex min-h-11 items-center justify-center rounded-full bg-violet-600 px-5 py-2 text-sm font-medium text-white"
                >
                    Go to app
                </Link>
                <template v-else>
                    <Link
                        :href="route('login')"
                        class="inline-flex min-h-11 items-center justify-center rounded-full bg-violet-600 px-5 py-2 text-sm font-medium text-white"
                    >
                        Log in
                    </Link>
                    <Link
                        :href="route('register')"
                        class="inline-flex min-h-11 items-center justify-center rounded-full border border-violet-600 px-5 py-2 text-sm font-medium text-violet-600 dark:text-violet-400"
                    >
                        Register
                    </Link>
                </template>
            </div>
        </main>
    </div>
</template>
