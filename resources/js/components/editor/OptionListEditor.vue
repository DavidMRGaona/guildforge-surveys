<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { SurveyOption } from '../../types/Respond';
import { move, newOption } from '../../utils/definition';
import { INPUT_CLASS, ITEM_BUTTON_CLASS } from './styles';

const props = defineProps<{
    /** Accessible name of the list: options, rows or columns. */
    label: string;
    /** Label of each item's field, with {number}. */
    itemLabel: string;
    addLabel: string;
    locked: boolean;
    errors: Record<string, string>;
    /** Path of the list in the definition, for its errors. */
    path: string;
}>();

const options = defineModel<SurveyOption[]>({ required: true });

const { t } = useI18n();

function update(index: number, label: string): void {
    options.value = options.value.map((option, i) => (i === index ? { ...option, label } : option));
}

function remove(index: number): void {
    options.value = options.value.filter((_, i) => i !== index);
}

function error(index: number): string | undefined {
    return props.errors[`definition.${props.path}.${index}.label`];
}
</script>

<template>
    <fieldset class="space-y-2">
        <legend class="text-sm font-medium text-base-primary">{{ props.label }}</legend>
        <p v-if="props.errors[`definition.${props.path}`]" class="text-sm text-error">
            {{ props.errors[`definition.${props.path}`] }}
        </p>
        <div v-for="(option, index) in options" :key="option.id" class="space-y-1">
            <div class="flex items-center gap-2">
                <input
                    :value="option.label"
                    type="text"
                    :class="INPUT_CLASS"
                    :aria-label="props.itemLabel.replace('{number}', String(index + 1))"
                    :aria-invalid="error(index) ? 'true' : undefined"
                    :disabled="props.locked"
                    @input="update(index, ($event.target as HTMLInputElement).value)"
                />
                <template v-if="!props.locked">
                    <button
                        type="button"
                        :class="ITEM_BUTTON_CLASS"
                        :disabled="index === 0"
                        :aria-label="t('surveys.editor.move_up')"
                        @click="options = move(options, index, -1)"
                    >
                        ↑
                    </button>
                    <button
                        type="button"
                        :class="ITEM_BUTTON_CLASS"
                        :disabled="index === options.length - 1"
                        :aria-label="t('surveys.editor.move_down')"
                        @click="options = move(options, index, 1)"
                    >
                        ↓
                    </button>
                    <button
                        type="button"
                        :class="ITEM_BUTTON_CLASS"
                        :aria-label="t('surveys.editor.remove')"
                        @click="remove(index)"
                    >
                        ✕
                    </button>
                </template>
            </div>
            <p v-if="error(index)" class="text-sm text-error">{{ error(index) }}</p>
        </div>
        <button
            v-if="!props.locked"
            type="button"
            class="text-sm font-medium text-primary hover:underline"
            @click="options = [...options, newOption()]"
        >
            {{ props.addLabel }}
        </button>
    </fieldset>
</template>
