<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { LINK_CLASS } from './styles';

/**
 * Top of an organizer page: the way back first, then what the page belongs to and its
 * title. A description, when there is one, goes in the default slot.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        eyebrow?: string | null;
        backUrl?: string | null;
        backLabel?: string | null;
        /** The way back leaves the module's pages: a full page load, not an Inertia visit. */
        backReloads?: boolean;
    }>(),
    { eyebrow: null, backUrl: null, backLabel: null, backReloads: false }
);
</script>

<template>
    <header class="space-y-2">
        <component
            :is="props.backReloads ? 'a' : Link"
            v-if="props.backUrl && props.backLabel"
            :href="props.backUrl"
            :class="['inline-flex items-center gap-1 py-1', LINK_CLASS]"
        >
            <svg
                class="h-4 w-4 shrink-0"
                viewBox="0 0 20 20"
                fill="currentColor"
                aria-hidden="true"
            >
                <path
                    fill-rule="evenodd"
                    d="M12.79 5.23a.75.75 0 0 1-.02 1.06L8.832 10l3.938 3.71a.75.75 0 1 1-1.04 1.08l-4.5-4.25a.75.75 0 0 1 0-1.08l4.5-4.25a.75.75 0 0 1 1.06.02Z"
                    clip-rule="evenodd"
                />
            </svg>
            {{ props.backLabel }}
        </component>
        <p v-if="props.eyebrow" class="text-sm font-medium break-words text-base-secondary">
            {{ props.eyebrow }}
        </p>
        <h1 class="text-2xl font-semibold break-words text-base-primary sm:text-3xl">
            {{ props.title }}
        </h1>
        <slot />
    </header>
</template>
