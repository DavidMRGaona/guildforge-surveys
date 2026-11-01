<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * A linear scale as a row of buttons, wrapping on narrow screens, with what its ends
 * mean underneath. The buttons are native radios underneath, as in OptionCards.
 */
const props = defineProps<{
    name: string;
    label: string;
    min: number;
    max: number;
    minLabel: string | null;
    maxLabel: string | null;
    value: number | null;
}>();

const emit = defineEmits<{
    choose: [value: number];
}>();

const { t } = useI18n();

const values = computed<number[]>(() =>
    Array.from({ length: props.max - props.min + 1 }, (_, index) => props.min + index)
);
</script>

<template>
    <div class="mt-4">
        <div
            role="radiogroup"
            :aria-label="label"
            class="grid grid-cols-[repeat(auto-fit,minmax(2.75rem,1fr))] gap-2"
        >
            <label
                v-for="option in values"
                :key="option"
                class="flex h-12 cursor-pointer items-center justify-center rounded-lg border-2 font-semibold tabular-nums transition-colors duration-150 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary-500"
                :class="
                    value === option
                        ? 'border-brand bg-brand text-white'
                        : 'border-line bg-surface text-base-primary hover:bg-muted'
                "
            >
                <input
                    type="radio"
                    class="sr-only"
                    :name="name"
                    :value="option"
                    :checked="value === option"
                    @change="emit('choose', option)"
                />
                {{ option }}
            </label>
        </div>
        <div
            v-if="minLabel || maxLabel"
            class="mt-2 flex justify-between gap-4 text-sm text-base-secondary"
        >
            <span>{{
                minLabel ? t('surveys.respond.scale_end', { value: min, label: minLabel }) : ''
            }}</span>
            <span class="text-right">{{
                maxLabel ? t('surveys.respond.scale_end', { value: max, label: maxLabel }) : ''
            }}</span>
        </div>
    </div>
</template>
