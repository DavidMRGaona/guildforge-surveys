<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { OTHER, type AnswerDraft, type SurveyQuestion } from '../types/Respond';

const props = defineProps<{
    question: SurveyQuestion;
    error?: string;
}>();

const draft = defineModel<AnswerDraft>({ required: true });

const { t } = useI18n();

const inputId = computed(() => `question-${props.question.id}`);
const helpId = computed(() => `${inputId.value}-help`);
const errorId = computed(() => `${inputId.value}-error`);
const describedBy = computed(
    () =>
        [props.question.help ? helpId.value : null, props.error ? errorId.value : null]
            .filter((id) => id !== null)
            .join(' ') || undefined
);

const scaleValues = computed<number[]>(() => {
    const scale = props.question.scale;
    if (scale === null) {
        return [];
    }

    return Array.from({ length: scale.max - scale.min + 1 }, (_, index) => scale.min + index);
});

const starValues = computed<number[]>(() =>
    Array.from({ length: props.question.stars ?? 0 }, (_, index) => index + 1)
);

const otherChosen = computed(
    () => draft.value.choice === OTHER || draft.value.choices.includes(OTHER)
);

function update(patch: Partial<AnswerDraft>): void {
    draft.value = { ...draft.value, ...patch };
}

function toggleChoice(optionId: string, checked: boolean): void {
    const choices = draft.value.choices.filter((choice) => choice !== optionId);
    update({ choices: checked ? [...choices, optionId] : choices });
}

function chooseCell(rowId: string, columnId: string): void {
    update({ cells: { ...draft.value.cells, [rowId]: columnId } });
}

const inputClass =
    'mt-2 block w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-base-primary shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm';
</script>

<template>
    <fieldset
        class="rounded-lg border border-neutral-200 bg-surface p-4 sm:p-6"
        :aria-describedby="describedBy"
        :aria-invalid="error ? 'true' : undefined"
    >
        <legend class="sr-only">{{ question.title }}</legend>
        <p class="text-base font-medium text-base-primary" aria-hidden="true">
            {{ question.title }}
            <span v-if="question.required" class="text-error" :title="t('surveys.respond.required')"
                >*</span
            >
        </p>
        <p v-if="question.help" :id="helpId" class="mt-1 text-sm text-base-secondary">
            {{ question.help }}
        </p>

        <input
            v-if="question.type === 'short_text'"
            :id="inputId"
            type="text"
            maxlength="500"
            :class="inputClass"
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
            :class="inputClass"
            :value="draft.text"
            :required="question.required"
            :aria-label="question.title"
            @input="update({ text: ($event.target as HTMLTextAreaElement).value })"
        />

        <div v-else-if="question.type === 'single_choice'" class="mt-3 space-y-2">
            <label
                v-for="option in question.options"
                :key="option.id"
                class="flex items-center gap-3 text-base-primary"
            >
                <input
                    type="radio"
                    :name="inputId"
                    :value="option.id"
                    :checked="draft.choice === option.id"
                    class="h-4 w-4 border-neutral-300 text-primary-600 focus:ring-primary-500"
                    @change="update({ choice: option.id })"
                />
                {{ option.label }}
            </label>
            <label v-if="question.allow_other" class="flex items-center gap-3 text-base-primary">
                <input
                    type="radio"
                    :name="inputId"
                    :value="OTHER"
                    :checked="draft.choice === OTHER"
                    class="h-4 w-4 border-neutral-300 text-primary-600 focus:ring-primary-500"
                    @change="update({ choice: OTHER })"
                />
                {{ t('surveys.respond.other') }}
            </label>
        </div>

        <div v-else-if="question.type === 'multiple_choice'" class="mt-3 space-y-2">
            <label
                v-for="option in question.options"
                :key="option.id"
                class="flex items-center gap-3 text-base-primary"
            >
                <input
                    type="checkbox"
                    :value="option.id"
                    :checked="draft.choices.includes(option.id)"
                    class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                    @change="toggleChoice(option.id, ($event.target as HTMLInputElement).checked)"
                />
                {{ option.label }}
            </label>
            <label v-if="question.allow_other" class="flex items-center gap-3 text-base-primary">
                <input
                    type="checkbox"
                    :value="OTHER"
                    :checked="draft.choices.includes(OTHER)"
                    class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                    @change="toggleChoice(OTHER, ($event.target as HTMLInputElement).checked)"
                />
                {{ t('surveys.respond.other') }}
            </label>
        </div>

        <select
            v-else-if="question.type === 'dropdown'"
            :id="inputId"
            :class="inputClass"
            :value="draft.choice"
            :required="question.required"
            :aria-label="question.title"
            @change="update({ choice: ($event.target as HTMLSelectElement).value })"
        >
            <option value="">{{ t('surveys.respond.choose') }}</option>
            <option v-for="option in question.options" :key="option.id" :value="option.id">
                {{ option.label }}
            </option>
            <option v-if="question.allow_other" :value="OTHER">
                {{ t('surveys.respond.other') }}
            </option>
        </select>

        <div v-else-if="question.type === 'linear_scale'" class="mt-3">
            <div class="flex flex-wrap items-center gap-3">
                <span v-if="question.scale?.min_label" class="text-sm text-base-secondary">{{
                    question.scale.min_label
                }}</span>
                <label
                    v-for="value in scaleValues"
                    :key="value"
                    class="flex flex-col items-center gap-1 text-sm text-base-primary"
                >
                    {{ value }}
                    <input
                        type="radio"
                        :name="inputId"
                        :value="value"
                        :checked="draft.number === value"
                        class="h-4 w-4 border-neutral-300 text-primary-600 focus:ring-primary-500"
                        @change="update({ number: value })"
                    />
                </label>
                <span v-if="question.scale?.max_label" class="text-sm text-base-secondary">{{
                    question.scale.max_label
                }}</span>
            </div>
        </div>

        <div
            v-else-if="question.type === 'star_rating'"
            class="mt-3 flex gap-1"
            role="radiogroup"
            :aria-label="question.title"
        >
            <label v-for="value in starValues" :key="value" class="cursor-pointer">
                <input
                    type="radio"
                    class="sr-only"
                    :name="inputId"
                    :value="value"
                    :checked="draft.number === value"
                    @change="update({ number: value })"
                />
                <span
                    aria-hidden="true"
                    class="text-3xl leading-none"
                    :class="
                        draft.number !== null && value <= draft.number
                            ? 'text-warning'
                            : 'text-neutral-300'
                    "
                    >★</span
                >
                <span class="sr-only">{{
                    t('surveys.respond.stars', { count: value, total: question.stars ?? 0 })
                }}</span>
            </label>
        </div>

        <input
            v-else-if="question.type === 'date' || question.type === 'time'"
            :id="inputId"
            :type="question.type"
            :class="inputClass"
            :value="draft.text"
            :required="question.required"
            :aria-label="question.title"
            @input="update({ text: ($event.target as HTMLInputElement).value })"
        />

        <div v-else-if="question.type === 'grid' && question.grid" class="mt-3 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr>
                        <th scope="col"></th>
                        <th
                            v-for="column in question.grid.columns"
                            :key="column.id"
                            scope="col"
                            class="px-2 py-1 font-medium text-base-secondary"
                        >
                            {{ column.label }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in question.grid.rows" :key="row.id">
                        <th scope="row" class="py-2 pr-3 text-left font-normal text-base-primary">
                            {{ row.label }}
                        </th>
                        <td
                            v-for="column in question.grid.columns"
                            :key="column.id"
                            class="px-2 py-2 text-center"
                        >
                            <input
                                type="radio"
                                :name="`${inputId}-${row.id}`"
                                :value="column.id"
                                :checked="draft.cells[row.id] === column.id"
                                :aria-label="`${row.label}: ${column.label}`"
                                class="h-4 w-4 border-neutral-300 text-primary-600 focus:ring-primary-500"
                                @change="chooseCell(row.id, column.id)"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <input
            v-if="question.allow_other && otherChosen"
            type="text"
            maxlength="500"
            :class="inputClass"
            :value="draft.other"
            :placeholder="t('surveys.respond.other_placeholder')"
            :aria-label="t('surveys.respond.other')"
            @input="update({ other: ($event.target as HTMLInputElement).value })"
        />

        <p v-if="error" :id="errorId" class="mt-2 text-sm text-error" role="alert">{{ error }}</p>
    </fieldset>
</template>
