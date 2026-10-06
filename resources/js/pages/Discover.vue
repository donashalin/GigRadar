<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { formatConcertDate } from '@/lib/dates';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { EyeOff } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';

interface Item {
    eventId: number;
    attractionId: string;
    attractionName: string;
    imageUrl: string | null;
    localDate: string | null;
    startsAt: string;
    venueName: string;
    city: string;
    distanceMiles: number | null;
    ticketUrl: string;
}

interface Group {
    id: string;
    name: string;
    artists: string[];
    items: Item[];
}

const props = defineProps<{ groups: Group[]; hasFollows: boolean; hasArea: boolean }>();

const page = usePage<SharedData>();
const shortDate: Intl.DateTimeFormatOptions = { weekday: 'short', day: 'numeric', month: 'short' };
const UNDO_MS = 5000;

const hidden = ref<Set<string>>(new Set());
const visibleGroups = computed(() =>
    props.groups.map((g) => ({ ...g, items: g.items.filter((i) => !hidden.value.has(i.attractionId)) })).filter((g) => g.items.length > 0),
);

function because(artists: string[]): string {
    const shown = artists.slice(0, 3).join(', ');
    const more = artists.length - 3;
    return `Because you follow ${shown}${more > 0 ? ` and ${more} more` : ''}`;
}

const rootEl = ref<HTMLElement | null>(null);
const error = ref('');

// Follow (a normal visit; dismissals are async so they never interrupt it)
const followPendingId = ref<string | null>(null);

function follow(item: Item, group: Group, index: number) {
    if (followPendingId.value !== null) return;
    followPendingId.value = item.attractionId;
    error.value = '';
    router.post(
        `/artists/${item.attractionId}/follow`,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['groups', 'hasFollows', 'flash'],
            onSuccess: () => {
                error.value = page.props.flash?.error ?? '';
                refocus(index, group.id, item.attractionId);
            },
            onFinish: () => {
                followPendingId.value = null;
            },
        },
    );
}

// Not interested, with a short Undo window
const undo = ref<{ id: string; name: string } | null>(null);
const undoBusy = ref(false);
const dismissing = ref<Set<string>>(new Set()); // dismissals whose POST is still in flight
let undoTimer: ReturnType<typeof setTimeout> | undefined;
onBeforeUnmount(() => clearTimeout(undoTimer));

const undoDisabled = computed(() => undoBusy.value || (undo.value !== null && dismissing.value.has(undo.value.id)));

function startUndoTimer() {
    clearTimeout(undoTimer);
    undoTimer = setTimeout(() => (undo.value = null), UNDO_MS);
}
const pauseUndoTimer = () => clearTimeout(undoTimer);
const resumeUndoTimer = () => {
    if (undo.value) startUndoTimer();
};

/** Move focus to the row now in the slot of the one that went away, else the page container. */
async function refocus(rowIndex: number, groupId: string, onlyIfGone?: string) {
    await nextTick();
    if (onlyIfGone && rootEl.value?.querySelector(`[data-attraction="${onlyIfGone}"]`)) return;
    const group = rootEl.value?.querySelector<HTMLElement>(`[data-group="${groupId}"]`);
    const links = group?.querySelectorAll<HTMLElement>('[data-row-link]');
    const target = links && links.length > 0 ? links[Math.min(rowIndex, links.length - 1)] : null;
    (target ?? rootEl.value)?.focus();
}

function setDismissing(id: string, on: boolean) {
    const next = new Set(dismissing.value);
    if (on) next.add(id);
    else next.delete(id);
    dismissing.value = next;
}

function dismiss(item: Item, group: Group, index: number) {
    const id = item.attractionId;
    hidden.value = new Set(hidden.value).add(id);
    setDismissing(id, true);
    error.value = '';
    undo.value = { id, name: item.attractionName };
    startUndoTimer();
    refocus(index, group.id);

    let succeeded = false;
    router.post(
        '/dismissed-artists',
        { attraction_ticketmaster_id: id, attraction_name: item.attractionName },
        {
            async: true,
            preserveScroll: true,
            preserveState: true,
            only: ['flash'],
            onSuccess: () => {
                succeeded = true;
            },
            onFinish: () => {
                setDismissing(id, false);
                if (succeeded) return;
                // Validation, 429, network failure: put the row back.
                unhide(id);
                if (undo.value?.id === id) undo.value = null;
                error.value = `Couldn't hide ${item.attractionName}. Please try again.`;
            },
        },
    );
}

function unhide(id: string) {
    const next = new Set(hidden.value);
    next.delete(id);
    hidden.value = next;
}

function undoDismiss() {
    if (!undo.value || undoDisabled.value) return;
    const { id } = undo.value;
    undoBusy.value = true;
    clearTimeout(undoTimer);
    let succeeded = false;
    router.delete(`/dismissed-artists/${id}`, {
        async: true,
        preserveScroll: true,
        preserveState: true,
        only: ['groups', 'flash'],
        onSuccess: () => {
            succeeded = true;
            unhide(id);
            undo.value = null;
            nextTick(() => rootEl.value?.querySelector<HTMLElement>(`[data-attraction="${id}"] [data-row-link]`)?.focus());
        },
        onFinish: () => {
            undoBusy.value = false;
            if (succeeded) return;
            if (undo.value?.id === id) undo.value = null;
            error.value = "Couldn't undo. Find them under Settings > Hidden artists.";
        },
    });
}

const focusClass = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600';
const linkClass = `font-medium text-violet-600 dark:text-violet-400 ${focusClass}`;
</script>

<template>
    <Head title="Discover" />
    <AppLayout :breadcrumbs="[{ title: 'Discover', href: '/discover' }]" grouped>
        <div ref="rootEl" tabindex="-1" class="space-y-6 p-4 outline-none">
            <p v-if="error" role="alert" class="px-1 text-sm text-red-600 dark:text-red-400">{{ error }}</p>

            <div v-if="!hasFollows" class="rounded-xl bg-white p-6 text-center dark:bg-neutral-900">
                <p class="font-medium">Follow a few artists to get recommendations.</p>
                <Link href="/search" class="mt-4 inline-flex min-h-11 items-center rounded-full bg-violet-600 px-5 text-sm font-medium text-white" :class="focusClass">
                    Find artists
                </Link>
            </div>
            <div v-else-if="!hasArea" class="rounded-xl bg-white p-6 text-center dark:bg-neutral-900">
                <p class="font-medium">Set your home location to see gigs near you.</p>
                <Link href="/settings/location" class="mt-4 inline-flex min-h-11 items-center rounded-full bg-violet-600 px-5 text-sm font-medium text-white" :class="focusClass">
                    Set home location
                </Link>
            </div>
            <p v-else-if="visibleGroups.length === 0" class="rounded-xl bg-white p-6 text-center text-neutral-600 dark:bg-neutral-900 dark:text-neutral-300">
                No matching gigs near you yet — check back soon.
            </p>

            <section v-for="group in visibleGroups" :key="group.id" :data-group="group.id" :aria-labelledby="`group-${group.id}`">
                <h2 :id="`group-${group.id}`" class="px-1 text-lg font-semibold">{{ group.name }}</h2>
                <p class="px-1 pb-2 text-sm text-neutral-500 dark:text-neutral-400">{{ because(group.artists) }}</p>
                <ul class="divide-y divide-neutral-200 overflow-hidden rounded-xl bg-white dark:divide-neutral-800 dark:bg-neutral-900">
                    <li v-for="(item, index) in group.items" :key="item.attractionId" :data-attraction="item.attractionId" class="flex items-center gap-3 px-4 py-3">
                        <img v-if="item.imageUrl" :src="item.imageUrl" alt="" class="size-12 shrink-0 rounded-lg object-cover" />
                        <div v-else class="size-12 shrink-0 rounded-lg bg-neutral-200 dark:bg-neutral-800" aria-hidden="true" />
                        <div class="min-w-0 flex-1">
                            <Link
                                :href="`/artists/${item.attractionId}`"
                                data-row-link
                                class="block truncate rounded font-medium"
                                :class="focusClass"
                            >
                                {{ item.attractionName }}
                            </Link>
                            <p class="line-clamp-2 text-sm text-neutral-500 dark:text-neutral-400">
                                {{ formatConcertDate(item.localDate, item.startsAt, shortDate) }} · {{ item.city
                                }}<template v-if="item.distanceMiles !== null"> · {{ item.distanceMiles }} mi</template>
                            </p>
                        </div>
                        <button
                            type="button"
                            class="inline-flex min-h-11 shrink-0 items-center rounded-full bg-violet-600 px-3 text-sm font-medium text-white disabled:opacity-60"
                            :class="focusClass"
                            :disabled="followPendingId !== null"
                            :aria-label="`Follow ${item.attractionName}`"
                            @click="follow(item, group, index)"
                        >
                            Follow
                        </button>
                        <button
                            type="button"
                            class="inline-flex size-11 shrink-0 items-center justify-center rounded-full text-neutral-500 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800"
                            :class="focusClass"
                            :aria-label="`Not interested in ${item.attractionName}`"
                            @click="dismiss(item, group, index)"
                        >
                            <EyeOff class="size-5" aria-hidden="true" />
                        </button>
                    </li>
                </ul>
            </section>
        </div>

        <div
            role="status"
            class="pointer-events-none fixed inset-x-0 bottom-[calc(4.5rem+env(safe-area-inset-bottom))] z-30 mx-auto flex max-w-xl justify-center px-4"
        >
            <div
                v-if="undo"
                class="pointer-events-auto flex min-h-12 items-center gap-3 rounded-xl bg-neutral-900 py-1 pr-1 pl-4 text-sm text-white shadow-lg dark:bg-neutral-100 dark:text-neutral-900"
                @pointerenter="pauseUndoTimer"
                @pointerleave="resumeUndoTimer"
                @focusin="pauseUndoTimer"
                @focusout="resumeUndoTimer"
            >
                <span class="min-w-0 truncate">Hidden {{ undo.name }}</span>
                <button
                    type="button"
                    class="inline-flex min-h-11 items-center rounded-lg px-3 font-semibold text-violet-300 disabled:opacity-60 dark:text-violet-700"
                    :class="focusClass"
                    :disabled="undoDisabled"
                    @click="undoDismiss"
                >
                    Undo
                </button>
            </div>
        </div>
    </AppLayout>
</template>
