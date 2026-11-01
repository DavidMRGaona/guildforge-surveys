<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import {
    AVAILABILITY_ANSWERS,
    type AvailabilityAnswer,
    type SurveySlot,
} from '../../types/Respond';

/**
 * Each proposed date answered "yes", "if needed" or "no", as a group of radios of its own. On a
 * phone a slot is its date over the three answers; from `sm` up they share a row. A slot left
 * unmarked counts as "no" when the survey is sent, which the hint says.
 */
const props = defineProps<{
    name: string;
    slots: SurveySlot[];
    /** The answer by slot id. */
    cells: Record<string, string>;
}>();

const emit = defineEmits<{
    choose: [slotId: string, answer: AvailabilityAnswer];
}>();

const { t } = useI18n();

/** Each answer keeps its colour when chosen, so the column of "yes" reads at a glance. */
const CHOSEN_CLASS: Record<AvailabilityAnswer, string> = {
    yes: 'border-success bg-success-bg',
    if_needed: 'border-warning bg-warning-bg',
    no: 'border-line-strong bg-muted',
};

function slotLabelId(slotId: string): string {
    return `${props.name}-${slotId}-label`;
}
</script>

<template>
    <div class="mt-4">
        <p class="text-sm text-base-secondary">{{ t('surveys.respond.availability.hint') }}</p>
        <div
            v-for="slot in slots"
            :key="slot.id"
            role="radiogroup"
            :aria-labelledby="slotLabelId(slot.id)"
            class="mt-3 border-t border-line pt-3 sm:flex sm:items-center sm:gap-4"
        >
            <p
                :id="slotLabelId(slot.id)"
                class="font-medium text-base-primary first-letter:uppercase max-sm:mb-2 sm:flex-1"
            >
                {{ slot.label }}
            </p>
            <div class="grid grid-cols-3 gap-2 sm:w-[22rem] sm:shrink-0">
                <label
                    v-for="answer in AVAILABILITY_ANSWERS"
                    :key="answer"
                    :data-answer="answer"
                    class="flex min-h-11 cursor-pointer items-center justify-center rounded-lg border-2 px-2 text-center text-sm text-base-primary transition-colors duration-150 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary-500"
                    :class="
                        cells[slot.id] === answer
                            ? [CHOSEN_CLASS[answer], 'font-semibold']
                            : 'border-line bg-surface hover:bg-muted'
                    "
                >
                    <input
                        type="radio"
                        class="sr-only"
                        :name="`${name}-${slot.id}`"
                        :value="answer"
                        :checked="cells[slot.id] === answer"
                        @change="emit('choose', slot.id, answer)"
                    />
                    {{ t(`surveys.respond.availability.answers.${answer}`) }}
                </label>
            </div>
        </div>
    </div>
</template>
