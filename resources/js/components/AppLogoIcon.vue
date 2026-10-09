<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <!-- GigRadar app icon (same artwork as resources/icons/gigradar.svg) -->
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" :class="className" v-bind="$attrs" aria-hidden="true">
        <rect width="512" height="512" rx="112" fill="#7c3aed" />
        <g transform="translate(0 -40)">
            <circle cx="256" cy="320" r="28" fill="#fff" />
            <g fill="none" stroke="#fff" stroke-width="28" stroke-linecap="round">
                <path d="M176 320a80 80 0 0 1 160 0" />
                <path d="M116 320a140 140 0 0 1 280 0" />
                <path d="M56 320a200 200 0 0 1 400 0" />
            </g>
        </g>
    </svg>
</template>
