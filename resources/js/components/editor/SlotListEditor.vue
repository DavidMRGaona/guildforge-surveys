<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { FormDatePicker, FormTimePicker } from '@/components/form';
import type { SurveySlot } from '../../types/Respond';
import { move, newSlot, SLOTS_MAX } from '../../utils/definition';
import { ADD_BUTTON_CLASS, ICON_BUTTON_CLASS } from '../styles';
import PlusIcon from '../icons/PlusIcon.vue';

/**
 * The dates an availability survey proposes, typed in the site's zone: a day and, for a time
 * slot, its start and optional end. The server turns them into instants; an end before the
 * start falls on the next day. Errors come on the stored fields (date, starts_at, ends_at).
 */
const props = defineProps<{
    locked: boolean;
    errors: Record<string, string>;
    /** Path of the list in the definition, for its errors. */
    path: string;
}>();

const slots = defineModel<SurveySlot[]>({ required: true });

const { t } = useI18n();

function update(index: number, patch: Partial<SurveySlot>): void {
    slots.value = slots.value.map((slot, i) => (i === index ? { ...slot, ...patch } : slot));
}

function remove(index: number): void {
    slots.value = slots.value.filter((_, i) => i !== index);
}

/** A new slot starts on the day of the last one, which is often next to it. */
function add(): void {
    slots.value = [...slots.value, newSlot(slots.value.at(-1)?.day ?? '')];
}

function error(index: number, field: 'date' | 'starts_at' | 'ends_at' | 'id'): string | undefined {
    return props.errors[`definition.${props.path}.${index}.${field}`];
}

function errorsOf(index: number): string[] {
    const found = [
        error(index, 'date'),
        error(index, 'starts_at'),
        error(index, 'ends_at'),
        error(index, 'id'),
        props.errors[`definition.${props.path}.${index}`],
    ];

    return found.filter((message): message is string => message !== undefined);
}
</script>

<template>
    <fieldset class="space-y-3">
        <legend class="text-sm font-medium text-base-primary">
            {{ t('surveys.editor.slots') }}
        </legend>
        <p class="text-sm text-base-secondary">{{ t('surveys.editor.slots_hint') }}</p>
        <p v-if="props.errors[`definition.${props.path}`]" class="text-sm text-error">
            {{ props.errors[`definition.${props.path}`] }}
        </p>
        <div
            v-for="(slot, index) in slots"
            :key="slot.id"
            :data-slot="slot.id"
            class="space-y-1 border-t border-line pt-3"
        >
            <div class="flex flex-wrap items-end gap-2">
                <div class="w-full sm:w-44">
                    <span class="block text-xs font-medium text-base-secondary">
                        {{ t('surveys.editor.slot_day') }}
                    </span>
                    <FormDatePicker
                        class="mt-1"
                        :model-value="slot.day"
                        :disabled="props.locked"
                        :error="error(index, 'date')"
                        :aria-label="t('surveys.editor.slot_day_label', { number: index + 1 })"
                        @update:model-value="(day: string) => update(index, { day })"
                    />
                </div>
                <div class="min-w-0 flex-1 sm:w-32 sm:flex-none">
                    <span class="block text-xs font-medium text-base-secondary">
                        {{ t('surveys.editor.slot_start') }}
                    </span>
                    <FormTimePicker
                        class="mt-1"
                        :model-value="slot.start ?? ''"
                        :disabled="props.locked"
                        :error="error(index, 'starts_at')"
                        :step="15"
                        :aria-label="t('surveys.editor.slot_start_label', { number: index + 1 })"
                        @update:model-value="
                            (start: string) => update(index, { start: start === '' ? null : start })
                        "
                    />
                </div>
                <div class="min-w-0 flex-1 sm:w-32 sm:flex-none">
                    <span class="block text-xs font-medium text-base-secondary">
                        {{ t('surveys.editor.slot_end') }}
                    </span>
                    <FormTimePicker
                        class="mt-1"
                        :model-value="slot.end ?? ''"
                        :disabled="props.locked || slot.start === null"
                        :error="error(index, 'ends_at')"
                        :step="15"
                        :aria-label="t('surveys.editor.slot_end_label', { number: index + 1 })"
                        @update:model-value="
                            (end: string) => update(index, { end: end === '' ? null : end })
                        "
                    />
                </div>
                <div v-if="!props.locked" class="flex gap-1">
                    <button
                        type="button"
                        :class="ICON_BUTTON_CLASS"
                        :disabled="index === 0"
                        :aria-label="t('surveys.editor.move_up')"
                        @click="slots = move(slots, index, -1)"
                    >
                        ↑
                    </button>
                    <button
                        type="button"
                        :class="ICON_BUTTON_CLASS"
                        :disabled="index === slots.length - 1"
                        :aria-label="t('surveys.editor.move_down')"
                        @click="slots = move(slots, index, 1)"
                    >
                        ↓
                    </button>
                    <button
                        type="button"
                        :class="ICON_BUTTON_CLASS"
                        :aria-label="t('surveys.editor.remove_slot', { number: index + 1 })"
                        @click="remove(index)"
                    >
                        ✕
                    </button>
                </div>
            </div>
            <p v-for="message in errorsOf(index)" :key="message" class="text-sm text-error">
                {{ message }}
            </p>
        </div>
        <button
            v-if="!props.locked && slots.length < SLOTS_MAX"
            type="button"
            :class="ADD_BUTTON_CLASS"
            @click="add"
        >
            <PlusIcon />
            {{ t('surveys.editor.add_slot') }}
        </button>
    </fieldset>
</template>
