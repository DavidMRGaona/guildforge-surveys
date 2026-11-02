<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { FormCheckbox, FormNumberInput, FormSelect } from '@/components/form';
import type { EditorLocks } from '../../types/Editor';
import {
    FILE_KINDS,
    type FileKind,
    type QuestionType,
    type SurveyOption,
    type SurveyQuestion,
} from '../../types/Respond';
import {
    allowsOther,
    canBeScored,
    canBranch,
    changeType,
    FILE_MB_MAX,
    FILE_MB_MIN,
    hasOptions,
    POINTS_MAX,
    POINTS_MIN,
    POLL_TYPES,
    pruneCorrect,
    pruneJumps,
    QUESTION_TYPES,
    RANKING_OPTIONS_MAX,
    setCorrect,
} from '../../utils/definition';
import JumpsEditor from './JumpsEditor.vue';
import OptionListEditor from './OptionListEditor.vue';
import SlotListEditor from './SlotListEditor.vue';
import { FIELD_CLASS } from '../styles';

const props = defineProps<{
    locks: EditorLocks;
    errors: Record<string, string>;
    /** Path of the question in the definition, for its errors. */
    path: string;
    /** Where its options may jump: the later sections and the end. */
    targets: { value: string; label: string }[];
    /** The survey is a quiz: a choice question marks its correct options and gives points. */
    quiz: boolean;
}>();

const question = defineModel<SurveyQuestion>({ required: true });

const { t } = useI18n();

const fieldId = computed(() => `question-${question.value.id}`);
const scored = computed(() => props.quiz && canBeScored(question.value.type));
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
    // Removing an option or "Other" takes its jump (and its correct mark) with it
    question.value = pruneCorrect(pruneJumps({ ...question.value, [field]: value }));
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

function setFileKind(kind: FileKind, accepted: boolean): void {
    const file = question.value.file ?? { accept: [], max_mb: 5 };
    const accept = FILE_KINDS.filter((other) =>
        other === kind ? accepted : file.accept.includes(other)
    );
    set('file', { ...file, accept });
}

function setFileSize(maxMb: number | null): void {
    const file = question.value.file ?? { accept: ['image'], max_mb: 5 };
    set('file', { ...file, max_mb: maxMb ?? file.max_mb });
}

function setGrid(field: 'rows' | 'columns', value: SurveyOption[]): void {
    const grid = question.value.grid ?? { rows: [], columns: [] };
    set('grid', { ...grid, [field]: value });
}
</script>

<template>
    <div class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-3">
            <div :class="props.locks.availability ? 'sm:col-span-3' : 'sm:col-span-2'">
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
            <div v-if="!props.locks.availability">
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
            v-if="!props.locks.availability"
            :id="`${fieldId}-required`"
            :model-value="question.required"
            :label="t('surveys.editor.required')"
            :disabled="props.locks.structure"
            @update:model-value="set('required', $event)"
        />

        <SlotListEditor
            v-if="question.type === 'availability'"
            :model-value="question.slots"
            :locked="props.locks.structure"
            :errors="props.errors"
            :path="`${props.path}.slots`"
            @update:model-value="set('slots', $event)"
        />

        <template v-else-if="hasOptions(question.type)">
            <OptionListEditor
                :model-value="question.options"
                :label="t('surveys.editor.options')"
                :item-label="(number: number) => t('surveys.editor.option_label', { number })"
                :add-label="t('surveys.editor.add_option')"
                :locked="props.locks.structure"
                :errors="props.errors"
                :path="`${props.path}.options`"
                :max="question.type === 'ranking' ? RANKING_OPTIONS_MAX : undefined"
                :correct="scored ? question.correct_option_ids : undefined"
                @update:model-value="set('options', $event)"
                @update:correct="
                    (id: string, value: boolean) => (question = setCorrect(question, id, value))
                "
            />
            <template v-if="scored">
                <p class="text-sm text-base-secondary">
                    {{
                        t(
                            question.type === 'multiple_choice'
                                ? 'surveys.editor.correct_hint_multiple'
                                : 'surveys.editor.correct_hint_single'
                        )
                    }}
                </p>
                <p v-if="error('correct_option_ids')" class="text-sm text-error">
                    {{ error('correct_option_ids') }}
                </p>
                <div v-if="question.correct_option_ids.length > 0" class="max-w-xs">
                    <label
                        :for="`${fieldId}-points`"
                        class="block text-sm font-medium text-base-primary"
                    >
                        {{ t('surveys.editor.points') }}
                    </label>
                    <FormNumberInput
                        :id="`${fieldId}-points`"
                        :model-value="question.points"
                        :min="POINTS_MIN"
                        :max="POINTS_MAX"
                        :disabled="props.locks.structure"
                        :error="error('points')"
                        @update:model-value="set('points', $event)"
                    />
                    <p v-if="error('points')" class="mt-1 text-sm text-error">
                        {{ error('points') }}
                    </p>
                </div>
            </template>
            <p v-if="question.type === 'ranking'" class="text-sm text-base-secondary">
                {{ t('surveys.editor.ranking_hint', { max: RANKING_OPTIONS_MAX }) }}
            </p>
            <FormCheckbox
                v-if="!props.locks.poll && allowsOther(question.type)"
                :id="`${fieldId}-other`"
                :model-value="question.allow_other"
                :label="t('surveys.editor.allow_other')"
                :disabled="props.locks.structure"
                @update:model-value="set('allow_other', $event)"
            />
            <p v-if="error('allow_other')" class="text-sm text-error">
                {{ error('allow_other') }}
            </p>
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
                <p v-if="error('scale.max')" class="mt-1 text-sm text-error">
                    {{ error('scale.max') }}
                </p>
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
            <p v-if="error('stars')" class="mt-1 text-sm text-error">
                {{ error('stars') }}
            </p>
        </div>

        <div v-else-if="question.type === 'file' && question.file" class="space-y-3">
            <fieldset>
                <legend class="text-sm font-medium text-base-primary">
                    {{ t('surveys.editor.file_accept') }}
                </legend>
                <div class="mt-2 flex flex-col gap-2">
                    <FormCheckbox
                        v-for="kind in FILE_KINDS"
                        :id="`${fieldId}-file-${kind}`"
                        :key="kind"
                        :model-value="question.file.accept.includes(kind)"
                        :label="t(`surveys.editor.file_kinds.${kind}`)"
                        :disabled="props.locks.structure"
                        @update:model-value="setFileKind(kind, $event)"
                    />
                </div>
                <p v-if="error('file.accept') ?? error('file')" class="mt-1 text-sm text-error">
                    {{ error('file.accept') ?? error('file') }}
                </p>
            </fieldset>
            <p class="text-sm text-base-secondary">{{ t('surveys.editor.file_hint') }}</p>
            <div class="max-w-xs">
                <label
                    :for="`${fieldId}-file-size`"
                    class="block text-sm font-medium text-base-primary"
                >
                    {{ t('surveys.editor.file_max_mb') }}
                </label>
                <FormNumberInput
                    :id="`${fieldId}-file-size`"
                    :model-value="question.file.max_mb"
                    :min="FILE_MB_MIN"
                    :max="FILE_MB_MAX"
                    :disabled="props.locks.structure"
                    :error="error('file.max_mb')"
                    @update:model-value="setFileSize"
                />
                <p v-if="error('file.max_mb')" class="mt-1 text-sm text-error">
                    {{ error('file.max_mb') }}
                </p>
            </div>
        </div>

        <div
            v-else-if="question.type === 'grid' && question.grid"
            class="grid gap-4 sm:grid-cols-2"
        >
            <OptionListEditor
                :model-value="question.grid.rows"
                :label="t('surveys.editor.grid_rows')"
                :item-label="(number: number) => t('surveys.editor.row_label', { number })"
                :add-label="t('surveys.editor.add_row')"
                :locked="props.locks.structure"
                :errors="props.errors"
                :path="`${props.path}.grid.rows`"
                @update:model-value="setGrid('rows', $event)"
            />
            <OptionListEditor
                :model-value="question.grid.columns"
                :label="t('surveys.editor.grid_columns')"
                :item-label="(number: number) => t('surveys.editor.column_label', { number })"
                :add-label="t('surveys.editor.add_column')"
                :locked="props.locks.structure"
                :errors="props.errors"
                :path="`${props.path}.grid.columns`"
                @update:model-value="setGrid('columns', $event)"
            />
        </div>
    </div>
</template>
