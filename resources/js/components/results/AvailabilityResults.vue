<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { AvailabilityResult, AvailabilitySlotResult } from '../../types/Respond';
import { percent } from '../../utils/buckets';
import { BADGE_CLASS } from '../styles';

/**
 * Each proposed date with how many can make it, could if needed and cannot, as one bar split in
 * the three answers' colours; the best and the chosen date are marked, and named surveys say who.
 */
defineProps<{
    availability: AvailabilityResult;
    /** Responses that answered: the length of every bar. */
    answered: number;
}>();

const { t } = useI18n();

const SEGMENTS: { key: 'yes' | 'ifNeeded' | 'no'; answer: string; class: string }[] = [
    { key: 'yes', answer: 'yes', class: 'bg-success' },
    { key: 'ifNeeded', answer: 'if_needed', class: 'bg-warning' },
    { key: 'no', answer: 'no', class: 'bg-line-strong' },
];

function names(slot: AvailabilitySlotResult): { answer: string; names: string[] }[] {
    return [
        { answer: 'yes', names: slot.yesNames },
        { answer: 'if_needed', names: slot.ifNeededNames },
        { answer: 'no', names: slot.noNames },
    ].filter((group) => group.names.length > 0);
}
</script>

<template>
    <div>
        <p class="text-sm text-base-secondary">{{ t('surveys.results.availability_help') }}</p>
        <ul class="mt-3 space-y-4">
            <li
                v-for="slot in availability.slots"
                :key="slot.id"
                :data-slot="slot.id"
                class="border-t border-line pt-3"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium text-base-primary first-letter:uppercase">
                        {{ slot.label }}
                    </span>
                    <span
                        v-if="availability.chosenSlotId === slot.id"
                        :class="[BADGE_CLASS, 'bg-success-bg text-base-primary']"
                        data-testid="chosen"
                    >
                        {{ t('surveys.results.availability_chosen') }}
                    </span>
                    <span
                        v-else-if="slot.best"
                        :class="[BADGE_CLASS, 'bg-info-bg text-base-primary']"
                        data-testid="best"
                    >
                        {{ t('surveys.results.availability_best') }}
                    </span>
                </div>
                <p class="mt-1 text-sm tabular-nums text-base-secondary">
                    {{
                        t('surveys.results.availability_counts', {
                            yes: slot.yes,
                            ifNeeded: slot.ifNeeded,
                            no: slot.no,
                        })
                    }}
                </p>
                <div
                    class="mt-2 flex h-2.5 overflow-hidden rounded-full bg-muted"
                    role="img"
                    :aria-label="
                        t('surveys.results.availability_counts', {
                            yes: slot.yes,
                            ifNeeded: slot.ifNeeded,
                            no: slot.no,
                        })
                    "
                >
                    <div
                        v-for="segment in SEGMENTS"
                        :key="segment.key"
                        :class="['h-full', segment.class]"
                        :style="{ width: `${percent(slot[segment.key], answered)}%` }"
                    ></div>
                </div>
                <p
                    v-for="group in names(slot)"
                    :key="group.answer"
                    class="mt-1 text-xs text-base-secondary"
                >
                    {{ t(`surveys.respond.availability.answers.${group.answer}`) }}:
                    {{ group.names.join(', ') }}
                </p>
            </li>
        </ul>
    </div>
</template>
