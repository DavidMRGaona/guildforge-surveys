<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { FormCheckbox, FormNumberInput, FormSelect } from '@/components/form';
import type { EditorLocks } from '../../types/Editor';
import type { QuestionType, SurveyOption, SurveyQuestion } from '../../types/Respond';
import {
    canBranch,
    changeType,
    hasOptions,
    POLL_TYPES,
    pruneJumps,
    QUESTION_TYPES,
} from '../../utils/definition';
import JumpsEditor from './JumpsEditor.vue';
import OptionListEditor from './OptionListEditor.vue';
import { FIELD_CLASS } from '../styles';

const props = defineProps<{
    locks: EditorLocks;
    errors: Record<string, string>;
    /** Path of the question in the definition, for its errors. */
    path: string;
    /** Where its options may jump: the later sections and the end. */
    targets: { value: string; label: string }[];
}>();

const question = defineModel<SurveyQuestion>({ required: true });

const { t } = useI18n();

const fieldId = computed(() => `question-${question.value.id}`);
// A question from the template keeps its type, or its series over time would break
const typeLocked = computed(() => props.locks.structure || question.value.key !== null);
const typeOptions = computed(() =>
    (props.locks.poll ? POLL_TYPES : QUESTION_TYPES).map((type) => ({
        value: type,
        label: t(`surveys.editor.types.${type}`),
    }))
);

function error(field: string): string | undefined {
    return props.errors[`definition.${props.path}.${field}`];
}

function set<K extends keyof SurveyQuestion>(field: K, value: SurveyQuestion[K]): void {
    // Removing an option or "Other" takes its jump with it
    question.value = pruneJumps({ ...question.value, [field]: value });
}

function setType(value: string | number | null): void {
    if (typeof value === 'string' && QUESTION_TYPES.includes(value as QuestionType)) {
        question.value = changeType(question.value, value as QuestionType);
    }
}

function setScale(field: 'min' | 'max', value: number | null): void {
    const scale = question.value.scale ?? { min: 1, max: 5, min_label: null, max_label: null };
    set('scale', { ...scale, [field]: value ?? scale[field] });
}

function setScaleLabel(field: 'min_label' | 'max_label', value: string): void {
    const scale = question.value.scale ?? { min: 1, max: 5, min_label: null, max_label: null };
    set('scale', { ...scale, [field]: value === '' ? null : value });
}

function setGrid(field: 'rows' | 'columns', value: SurveyOption[]): void {
    const grid = question.value.grid ?? { rows: [], columns: [] };
    set('grid', { ...grid, [field]: value });
}
</script>

<template>
    <div class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label
                    :for="`${fieldId}-title`"
                    class="block text-sm font-medium text-base-primary"
                >
                    {{ t('surveys.editor.question_title') }}
                </label>
                <input
                    :id="`${fieldId}-title`"
                    :value="question.title"
                    type="text"
                    :class="['mt-1', FIELD_CLASS]"
                    :disabled="props.locks.structure"
                    :aria-invalid="error('title') ? 'true' : undefined"
                    @input="set('title', ($event.target as HTMLInputElement).value)"
                />
                <p v-if="error('title')" class="mt-1 text-sm text-error">{{ error('title') }}</p>
            </div>
            <div>
                <label :for="`${fieldId}-type`" class="block text-sm font-medium text-base-primary">
                    {{ t('surveys.editor.question_type') }}
                </label>
                <FormSelect
                    :id="`${fieldId}-type`"
                    class="mt-1"
                    :model-value="question.type"
                    :options="typeOptions"
                    :disabled="typeLocked"
                    :error="error('type')"
                    @update:model-value="setType"
                />
            </div>
        </div>
        <p v-if="question.key !== null" class="text-sm text-base-secondary">
            {{ t('surveys.editor.keyed_hint') }}
        </p>
        <p v-if="error('key')" class="text-sm text-error">{{ error('key') }}</p>

        <div>
            <label :for="`${fieldId}-help`" class="block text-sm font-medium text-base-primary">
                {{ t('surveys.editor.help') }}
            </label>
            <textarea
                :id="`${fieldId}-help`"
                :value="question.help ?? ''"
                rows="2"
                :class="['mt-1', FIELD_CLASS]"
                :disabled="props.locks.help"
                @input="set('help', ($event.target as HTMLTextAreaElement).value || null)"
            ></textarea>
        </div>

        <FormCheckbox
            :id="`${fieldId}-required`"
            :model-value="question.required"
            :label="t('surveys.editor.required')"
            :disabled="props.locks.structure"
            @update:model-value="set('required', $event)"
        />

        <template v-if="hasOptions(question.type)">
            <OptionListEditor
                :model-value="question.options"
                :label="t('surveys.editor.options')"
                :item-label="t('surveys.editor.option_label')"
                :add-label="t('surveys.editor.add_option')"
                :locked="props.locks.structure"
                :errors="props.errors"
                :path="`${props.path}.options`"
                @update:model-value="set('options', $event)"
            />
            <FormCheckbox
                v-if="!props.locks.poll"
                :id="`${fieldId}-other`"
                :model-value="question.allow_other"
                :label="t('surveys.editor.allow_other')"
                :disabled="props.locks.structure"
                @update:model-value="set('allow_other', $event)"
            />
            <JumpsEditor
                v-if="canBranch(question.type) && !props.locks.poll"
                :model-value="question"
                :targets="props.targets"
                :locked="props.locks.structure"
                :errors="props.errors"
                :path="props.path"
                @update:model-value="question = $event"
            />
        </template>

        <div
            v-else-if="question.type === 'linear_scale' && question.scale"
            class="grid gap-4 sm:grid-cols-2"
        >
            <div>
                <label :for="`${fieldId}-min`" class="block text-sm font-medium text-base-primary">
                    {{ t('surveys.editor.scale_min') }}
                </label>
                <FormSelect
                    :id="`${fieldId}-min`"
                    class="mt-1"
                    :model-value="question.scale.min"
                    :options="[
                        { value: 0, label: '0' },
                        { value: 1, label: '1' },
                    ]"
                    :disabled="props.locks.structure"
                    :error="error('scale.min')"
                    @update:model-value="
                        setScale('min', typeof $event === 'number' ? $event : Number($event))
                    "
                />
            </div>
            <div>
                <label :for="`${fieldId}-max`" class="block text-sm font-medium text-base-primary">
                    {{ t('surveys.editor.scale_max') }}
                </label>
                <FormNumberInput
                    :id="`${fieldId}-max`"
                    :model-value="question.scale.max"
                    :min="2"
                    :max="10"
                    :disabled="props.locks.structure"
                    :error="error('scale.max')"
                    @update:model-value="setScale('max', $event)"
                />
            </div>
            <div>
                <label
                    :for="`${fieldId}-min-label`"
                    class="block text-sm font-medium text-base-primary"
                >
                    {{ t('surveys.editor.scale_min_label') }}
                </label>
                <input
                    :id="`${fieldId}-min-label`"
                    :value="question.scale.min_label ?? ''"
                    type="text"
                    :class="['mt-1', FIELD_CLASS]"
                    :disabled="props.locks.structure"
                    @input="setScaleLabel('min_label', ($event.target as HTMLInputElement).value)"
                />
            </div>
            <div>
                <label
                    :for="`${fieldId}-max-label`"
                    class="block text-sm font-medium text-base-primary"
                >
                    {{ t('surveys.editor.scale_max_label') }}
                </label>
                <input
                    :id="`${fieldId}-max-label`"
                    :value="question.scale.max_label ?? ''"
                    type="text"
                    :class="['mt-1', FIELD_CLASS]"
                    :disabled="props.locks.structure"
                    @input="setScaleLabel('max_label', ($event.target as HTMLInputElement).value)"
                />
            </div>
        </div>

        <div v-else-if="question.type === 'star_rating'">
            <label :for="`${fieldId}-stars`" class="block text-sm font-medium text-base-primary">
                {{ t('surveys.editor.stars') }}
            </label>
            <FormNumberInput
                :id="`${fieldId}-stars`"
                :model-value="question.stars"
                :min="3"
                :max="10"
                :disabled="props.locks.structure"
                :error="error('stars')"
                @update:model-value="set('stars', $event)"
            />
        </div>

        <div
            v-else-if="question.type === 'grid' && question.grid"
            class="grid gap-4 sm:grid-cols-2"
        >
            <OptionListEditor
                :model-value="question.grid.rows"
                :label="t('surveys.editor.grid_rows')"
                :item-label="t('surveys.editor.row_label')"
                :add-label="t('surveys.editor.add_row')"
                :locked="props.locks.structure"
                :errors="props.errors"
                :path="`${props.path}.grid.rows`"
                @update:model-value="setGrid('rows', $event)"
            />
            <OptionListEditor
                :model-value="question.grid.columns"
                :label="t('surveys.editor.grid_columns')"
                :item-label="t('surveys.editor.column_label')"
                :add-label="t('surveys.editor.add_column')"
                :locked="props.locks.structure"
                :errors="props.errors"
                :path="`${props.path}.grid.columns`"
                @update:model-value="setGrid('columns', $event)"
            />
        </div>
    </div>
</template>
