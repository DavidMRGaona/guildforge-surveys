<script setup lang="ts">
import { Star } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * Stars large enough to tap, lit up to the one under the pointer. Native radios underneath
 * keep the arrows and the names ("3 de 5 estrellas") for keyboards and screen readers.
 */
const props = defineProps<{
    name: string;
    label: string;
    total: number;
    value: number | null;
}>();

const emit = defineEmits<{
    choose: [value: number];
}>();

const { t } = useI18n();

const hovered = ref<number | null>(null);
const values = computed<number[]>(() =>
    Array.from({ length: props.total }, (_, index) => index + 1)
);
const lit = computed(() => hovered.value ?? props.value ?? 0);
</script>

<template>
    <div
        role="radiogroup"
        :aria-label="label"
        class="mt-4 flex flex-wrap gap-1"
        @mouseleave="hovered = null"
    >
        <label
            v-for="star in values"
            :key="star"
            class="flex h-12 w-12 cursor-pointer items-center justify-center rounded-lg has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary-500"
            @mouseenter="hovered = star"
        >
            <input
                type="radio"
                class="sr-only"
                :name="name"
                :value="star"
                :checked="value === star"
                @change="emit('choose', star)"
            />
            <Star
                aria-hidden="true"
                class="h-9 w-9 transition-colors duration-150"
                :class="
                    star <= lit ? 'fill-warning text-warning' : 'fill-transparent text-base-muted'
                "
                :stroke-width="1.5"
            />
            <span class="sr-only">{{ t('surveys.respond.stars', { count: star, total }) }}</span>
        </label>
    </div>
</template>
