<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { FormCheckbox, FormSelect } from '@/components/form';
import { OTHER, type SurveyQuestion } from '../../types/Respond';
import { setJump } from '../../utils/definition';

const props = defineProps<{
    /** Where an option may lead: the later sections and the end, already labelled. */
    targets: { value: string; label: string }[];
    locked: boolean;
    errors: Record<string, string>;
    /** Path of the question in the definition, for its errors. */
    path: string;
}>();

const question = defineModel<SurveyQuestion>({ required: true });

const { t } = useI18n();

// The switch only shows the selects: what is saved is the jumps themselves
const enabled = ref(question.value.next_by_option.length > 0);
const fieldId = computed(() => `question-${question.value.id}`);
const choices = computed(() => [
    ...question.value.options.map((option) => ({
        id: option.id,
        label: option.label,
        field: `${fieldId.value}-jump-${option.id}`,
    })),
    ...(question.value.allow_other
        ? [{ id: OTHER, label: t('surveys.respond.other'), field: `${fieldId.value}-jump-other` }]
        : []),
]);
const options = computed(() => [
    { value: '', label: t('surveys.editor.follow_section') },
    ...props.targets,
]);

function toggle(on: boolean): void {
    enabled.value = on;
    if (!on) {
        question.value = { ...question.value, next_by_option: [] };
    }
}

function targetOf(optionId: string): string {
    return question.value.next_by_option.find((jump) => jump.option_id === optionId)?.next ?? '';
}

function setTarget(optionId: string, value: string | number | null): void {
    question.value = setJump(
        question.value,
        optionId,
        typeof value === 'string' && value !== '' ? value : null
    );
}

/** The server reports a jump by its place in next_by_option, not by option. */
function errorOf(optionId: string): string | undefined {
    const index = question.value.next_by_option.findIndex((jump) => jump.option_id === optionId);
    if (index === -1) {
        return undefined;
    }
    const path = `definition.${props.path}.next_by_option.${index}`;

    return props.errors[`${path}.next`] ?? props.errors[`${path}.option_id`];
}
</script>

<template>
    <div class="space-y-3">
        <FormCheckbox
            :id="`${fieldId}-branches`"
            :model-value="enabled"
            :label="t('surveys.editor.branches')"
            :disabled="props.locked"
            @update:model-value="toggle"
        />
        <p v-if="enabled" class="text-sm text-base-secondary">
            {{ t('surveys.editor.branches_help') }}
        </p>
        <p
            v-if="props.errors[`definition.${props.path}.next_by_option`]"
            class="text-sm text-error"
        >
            {{ props.errors[`definition.${props.path}.next_by_option`] }}
        </p>
        <div v-if="enabled" class="grid gap-3 sm:grid-cols-2">
            <div v-for="choice in choices" :key="choice.id">
                <label :for="choice.field" class="block text-sm font-medium text-base-primary">
                    {{ t('surveys.editor.option_jump', { option: choice.label || '…' }) }}
                </label>
                <FormSelect
                    :id="choice.field"
                    class="mt-1"
                    :model-value="targetOf(choice.id)"
                    :options="options"
                    :placeholder="t('surveys.editor.follow_section')"
                    :disabled="props.locked"
                    :error="errorOf(choice.id) ?? ''"
                    @update:model-value="setTarget(choice.id, $event)"
                />
                <p v-if="errorOf(choice.id)" class="mt-1 text-sm text-error">
                    {{ errorOf(choice.id) }}
                </p>
            </div>
        </div>
    </div>
</template>
