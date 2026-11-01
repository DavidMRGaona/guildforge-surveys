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
// One row from `sm` up; on a phone a long scale splits into two even rows (0-10: 6 + 5)
const columns = computed(() => ({
    '--scale-columns': String(values.value.length),
    '--scale-columns-narrow': String(
        values.value.length > 6 ? Math.ceil(values.value.length / 2) : values.value.length
    ),
}));
</script>

<template>
    <div class="mt-4">
        <div
            role="radiogroup"
            :aria-label="label"
            class="grid grid-cols-[repeat(var(--scale-columns-narrow),minmax(0,1fr))] gap-2 sm:grid-cols-[repeat(var(--scale-columns),minmax(0,1fr))]"
            :style="columns"
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
