<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { FormSelect } from '@/components/form';
import type { EditorLocks } from '../../types/Editor';
import {
    SUBMIT,
    type QuestionType,
    type SurveyQuestion,
    type SurveySection,
} from '../../types/Respond';
import { jumpTargets, move, newQuestion, QUESTION_TYPES } from '../../utils/definition';
import QuestionEditor from './QuestionEditor.vue';
import { ADD_BUTTON_CLASS, FIELD_CLASS, ICON_BUTTON_CLASS } from '../styles';
import PlusIcon from '../icons/PlusIcon.vue';

const props = defineProps<{
    locks: EditorLocks;
    errors: Record<string, string>;
    path: string;
    /** Every section of the survey, to offer the later ones as jump targets. */
    sections: SurveySection[];
}>();

const section = defineModel<SurveySection>({ required: true });

const { t } = useI18n();

const newType = ref<QuestionType>('single_choice');

const targets = computed(() => [
    ...jumpTargets(props.sections, section.value.id).map((target) => ({
        value: target.id,
        label: target.title || t('surveys.editor.section', { number: target.number }),
    })),
    { value: SUBMIT, label: t('surveys.editor.submit_form') },
]);
const nextError = computed(() => props.errors[`definition.${props.path}.next`]);

function set<K extends keyof SurveySection>(field: K, value: SurveySection[K]): void {
    section.value = { ...section.value, [field]: value };
}

function setQuestion(index: number, question: SurveyQuestion): void {
    set(
        'questions',
        section.value.questions.map((current, i) => (i === index ? question : current))
    );
}

function addQuestion(): void {
    set('questions', [...section.value.questions, newQuestion(newType.value)]);
}

function setNext(value: string | number | null): void {
    set('next', typeof value === 'string' && value !== '' ? value : null);
}

function setNewType(value: string | number | null): void {
    if (typeof value === 'string' && QUESTION_TYPES.includes(value as QuestionType)) {
        newType.value = value as QuestionType;
    }
}
</script>

<template>
    <div class="space-y-4">
        <div v-if="!props.locks.poll" class="space-y-3">
            <div>
                <label
                    :for="`section-${section.id}-title`"
                    class="block text-sm font-medium text-base-primary"
                >
                    {{ t('surveys.editor.section_title') }}
                </label>
                <input
                    :id="`section-${section.id}-title`"
                    :value="section.title ?? ''"
                    type="text"
                    :class="['mt-1', FIELD_CLASS]"
                    :disabled="props.locks.structure"
                    @input="set('title', ($event.target as HTMLInputElement).value || null)"
                />
            </div>
            <div>
                <label
                    :for="`section-${section.id}-description`"
                    class="block text-sm font-medium text-base-primary"
                >
                    {{ t('surveys.editor.section_description') }}
                </label>
                <textarea
                    :id="`section-${section.id}-description`"
                    :value="section.description ?? ''"
                    rows="2"
                    :class="['mt-1', FIELD_CLASS]"
                    :disabled="props.locks.structure"
                    @input="
                        set('description', ($event.target as HTMLTextAreaElement).value || null)
                    "
                ></textarea>
            </div>
        </div>

        <ol class="space-y-4">
            <li
                v-for="(question, index) in section.questions"
                :key="question.id"
                class="rounded-lg border border-default bg-surface p-3 sm:p-4"
            >
                <div class="mb-3 flex items-center justify-between gap-2">
                    <span class="text-sm font-medium text-base-secondary">
                        {{ t('surveys.editor.question', { number: index + 1 }) }}
                    </span>
                    <div v-if="!props.locks.structure && !props.locks.poll" class="flex gap-1">
                        <button
                            type="button"
                            :class="ICON_BUTTON_CLASS"
                            :disabled="index === 0"
                            :aria-label="t('surveys.editor.move_up')"
                            @click="set('questions', move(section.questions, index, -1))"
                        >
                            ↑
                        </button>
                        <button
                            type="button"
                            :class="ICON_BUTTON_CLASS"
                            :disabled="index === section.questions.length - 1"
                            :aria-label="t('surveys.editor.move_down')"
                            @click="set('questions', move(section.questions, index, 1))"
                        >
                            ↓
                        </button>
                        <button
                            type="button"
                            :class="ICON_BUTTON_CLASS"
                            :aria-label="t('surveys.editor.remove_question')"
                            @click="
                                set(
                                    'questions',
                                    section.questions.filter((_, i) => i !== index)
                                )
                            "
                        >
                            ✕
                        </button>
                    </div>
                </div>
                <QuestionEditor
                    :model-value="question"
                    :locks="props.locks"
                    :errors="props.errors"
                    :path="`${props.path}.questions.${index}`"
                    :targets="targets"
                    @update:model-value="setQuestion(index, $event)"
                />
            </li>
        </ol>

        <div
            v-if="!props.locks.structure && !props.locks.poll"
            class="flex flex-col gap-2 sm:flex-row sm:items-end"
        >
            <div class="sm:min-w-56">
                <label
                    :for="`section-${section.id}-new-type`"
                    class="block text-sm font-medium text-base-primary"
                >
                    {{ t('surveys.editor.new_question_type') }}
                </label>
                <FormSelect
                    :id="`section-${section.id}-new-type`"
                    class="mt-1"
                    :model-value="newType"
                    :options="
                        QUESTION_TYPES.map((type) => ({
                            value: type,
                            label: t(`surveys.editor.types.${type}`),
                        }))
                    "
                    @update:model-value="setNewType"
                />
            </div>
            <button type="button" :class="ADD_BUTTON_CLASS" @click="addQuestion">
                <PlusIcon />
                {{ t('surveys.editor.add_question') }}
            </button>
        </div>

        <div v-if="!props.locks.poll" class="max-w-md">
            <label
                :for="`section-${section.id}-next`"
                class="block text-sm font-medium text-base-primary"
            >
                {{ t('surveys.editor.section_next') }}
            </label>
            <FormSelect
                :id="`section-${section.id}-next`"
                class="mt-1"
                :model-value="section.next ?? ''"
                :options="[{ value: '', label: t('surveys.editor.continue') }, ...targets]"
                :placeholder="t('surveys.editor.continue')"
                :disabled="props.locks.structure"
                :error="nextError ?? ''"
                @update:model-value="setNext"
            />
            <p v-if="nextError" class="mt-1 text-sm text-error">{{ nextError }}</p>
        </div>
    </div>
</template>
