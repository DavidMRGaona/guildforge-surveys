<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { OTHER, type SurveyOption } from '../../types/Respond';

/**
 * Single or multiple choice as cards. Each card wraps a native radio or checkbox, hidden
 * but focusable, so the keyboard and screen readers get the usual behaviour (arrows move
 * between radios, space ticks a box) while the card shows the state.
 */
const props = defineProps<{
    name: string;
    options: SurveyOption[];
    allowOther: boolean;
    multiple: boolean;
    /** Ids of the chosen options (one at most when not multiple). */
    selected: string[];
}>();

const emit = defineEmits<{
    choose: [optionId: string, chosen: boolean];
}>();

const { t } = useI18n();

const cards = computed<SurveyOption[]>(() =>
    props.allowOther
        ? [...props.options, { id: OTHER, label: t('surveys.respond.other') }]
        : props.options
);

function isChosen(optionId: string): boolean {
    return props.selected.includes(optionId);
}
</script>

<template>
    <div class="mt-4 grid gap-2">
        <label
            v-for="option in cards"
            :key="option.id"
            :data-option="option.id"
            :data-chosen="isChosen(option.id) ? 'true' : undefined"
            class="flex min-h-12 cursor-pointer items-center gap-3 rounded-lg border-2 px-4 py-3 transition-colors duration-150 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary-500"
            :class="
                isChosen(option.id)
                    ? 'border-brand bg-brand-light'
                    : 'border-line bg-surface hover:bg-muted'
            "
        >
            <input
                :type="multiple ? 'checkbox' : 'radio'"
                class="sr-only"
                :name="name"
                :value="option.id"
                :checked="isChosen(option.id)"
                @change="emit('choose', option.id, ($event.target as HTMLInputElement).checked)"
            />
            <span
                aria-hidden="true"
                class="flex h-5 w-5 shrink-0 items-center justify-center border-2"
                :class="[
                    multiple ? 'rounded' : 'rounded-full',
                    isChosen(option.id) ? 'border-brand bg-brand' : 'border-line-strong bg-surface',
                ]"
            >
                <Check
                    v-if="multiple && isChosen(option.id)"
                    class="h-3.5 w-3.5 text-white"
                    :stroke-width="3"
                />
                <span v-else-if="isChosen(option.id)" class="h-2 w-2 rounded-full bg-white"></span>
            </span>
            <span class="text-base-primary">{{ option.label }}</span>
        </label>
    </div>
</template>
