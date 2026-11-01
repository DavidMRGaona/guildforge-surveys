<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { FormCombobox, FormDatePicker, FormSelect, FormTimePicker } from '@/components/form';
import { OTHER, type AnswerDraft, type SurveyQuestion } from '../types/Respond';
import GridAnswer from './respond/GridAnswer.vue';
import OptionCards from './respond/OptionCards.vue';
import ScaleButtons from './respond/ScaleButtons.vue';
import StarRating from './respond/StarRating.vue';

const props = defineProps<{
    question: SurveyQuestion;
    error?: string | undefined;
}>();

const draft = defineModel<AnswerDraft>({ required: true });

const { t } = useI18n();

/** Longer dropdowns get a search box. */
const SEARCHABLE_FROM = 11;

const inputId = computed(() => `question-${props.question.id}`);
const titleId = computed(() => `${inputId.value}-title`);
const helpId = computed(() => `${inputId.value}-help`);
const errorId = computed(() => `${inputId.value}-error`);
const describedBy = computed(
    () =>
        [props.question.help ? helpId.value : null, props.error ? errorId.value : null]
            .filter((id) => id !== null)
            .join(' ') || undefined
);

const otherChosen = computed(
    () => draft.value.choice === OTHER || draft.value.choices.includes(OTHER)
);

const dropdownOptions = computed(() => [
    ...props.question.options.map((option) => ({ value: option.id, label: option.label })),
    ...(props.question.allow_other ? [{ value: OTHER, label: t('surveys.respond.other') }] : []),
]);

function update(patch: Partial<AnswerDraft>): void {
    draft.value = { ...draft.value, ...patch };
}

function choose(optionId: string, chosen: boolean): void {
    if (props.question.type === 'single_choice') {
        update({ choice: optionId });

        return;
    }
    const choices = draft.value.choices.filter((choice) => choice !== optionId);
    update({ choices: chosen ? [...choices, optionId] : choices });
}

function chooseCell(rowId: string, columnId: string): void {
    update({ cells: { ...draft.value.cells, [rowId]: columnId } });
}

function chooseFromList(value: string | number | null): void {
    update({ choice: value === null ? '' : String(value) });
}

const textClass =
    'mt-4 block w-full rounded-lg border-2 border-line bg-surface px-4 py-2.5 text-base-primary placeholder:text-base-muted focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500';
</script>

<template>
    <fieldset
        class="rounded-xl border-2 bg-surface p-5 sm:p-6"
        :class="error ? 'border-error' : 'border-line'"
        :aria-describedby="describedBy"
        :aria-invalid="error ? 'true' : undefined"
    >
        <legend class="sr-only">{{ question.title }}</legend>
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <p :id="titleId" class="text-lg font-semibold text-base-primary" aria-hidden="true">
                {{ question.title }}
            </p>
            <span
                v-if="question.required"
                class="rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium text-base-secondary"
                >{{ t('surveys.respond.required') }}</span
            >
        </div>
        <p
            v-if="question.help"
            :id="helpId"
            class="mt-1 whitespace-pre-line text-sm text-base-secondary"
        >
            {{ question.help }}
        </p>

        <input
            v-if="question.type === 'short_text'"
            :id="inputId"
            type="text"
            maxlength="500"
            :class="textClass"
            :value="draft.text"
            :required="question.required"
            :aria-label="question.title"
            @input="update({ text: ($event.target as HTMLInputElement).value })"
        />

        <textarea
            v-else-if="question.type === 'paragraph'"
            :id="inputId"
            rows="4"
            maxlength="5000"
            :class="textClass"
            :value="draft.text"
            :required="question.required"
            :aria-label="question.title"
            @input="update({ text: ($event.target as HTMLTextAreaElement).value })"
        />

        <OptionCards
            v-else-if="question.type === 'single_choice' || question.type === 'multiple_choice'"
            :name="inputId"
            :options="question.options"
            :allow-other="question.allow_other"
            :multiple="question.type === 'multiple_choice'"
            :selected="question.type === 'single_choice' ? [draft.choice] : draft.choices"
            @choose="choose"
        />

        <div v-else-if="question.type === 'dropdown'" class="mt-4">
            <FormCombobox
                v-if="dropdownOptions.length >= SEARCHABLE_FROM"
                :id="inputId"
                :model-value="draft.choice"
                :options="dropdownOptions"
                option-label="label"
                option-value="value"
                :placeholder="t('surveys.respond.choose')"
                :error="error"
                @update:model-value="chooseFromList"
            />
            <FormSelect
                v-else
                :id="inputId"
                :model-value="draft.choice"
                :options="dropdownOptions"
                :placeholder="t('surveys.respond.choose')"
                :error="error ?? ''"
                @update:model-value="chooseFromList"
            />
        </div>

        <ScaleButtons
            v-else-if="question.type === 'linear_scale' && question.scale"
            :name="inputId"
            :label="question.title"
            :min="question.scale.min"
            :max="question.scale.max"
            :min-label="question.scale.min_label"
            :max-label="question.scale.max_label"
            :value="draft.number"
            @choose="(value: number) => update({ number: value })"
        />

        <StarRating
            v-else-if="question.type === 'star_rating'"
            :name="inputId"
            :label="question.title"
            :total="question.stars ?? 0"
            :value="draft.number"
            @choose="(value: number) => update({ number: value })"
        />

        <div v-else-if="question.type === 'date'" class="mt-4 max-w-xs">
            <FormDatePicker
                :id="inputId"
                :model-value="draft.text"
                :error="error"
                :aria-label="question.title"
                @update:model-value="(value: string) => update({ text: value })"
            />
        </div>

        <div v-else-if="question.type === 'time'" class="mt-4 max-w-[12rem]">
            <FormTimePicker
                :id="inputId"
                :model-value="draft.text"
                :error="error"
                :step="15"
                :aria-label="question.title"
                @update:model-value="(value: string) => update({ text: value })"
            />
        </div>

        <GridAnswer
            v-else-if="question.type === 'grid' && question.grid"
            :name="inputId"
            :rows="question.grid.rows"
            :columns="question.grid.columns"
            :cells="draft.cells"
            @choose="chooseCell"
        />

        <input
            v-if="question.allow_other && otherChosen"
            type="text"
            maxlength="500"
            :class="textClass"
            :value="draft.other"
            :placeholder="t('surveys.respond.other_placeholder')"
            :aria-label="t('surveys.respond.other')"
            @input="update({ other: ($event.target as HTMLInputElement).value })"
        />

        <p v-if="error" :id="errorId" class="mt-3 text-sm font-medium text-error" role="alert">
            {{ error }}
        </p>
    </fieldset>
</template>
