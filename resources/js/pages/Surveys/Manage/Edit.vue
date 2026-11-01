<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import {
    FormCheckbox,
    FormDatePicker,
    FormNumberInput,
    FormTimePicker,
    FormToggle,
} from '@/components/form';
import BaseButton from '@/components/ui/BaseButton.vue';
import DefinitionEditor from '../../../components/editor/DefinitionEditor.vue';
import PageHeader from '../../../components/PageHeader.vue';
import { CARD_CLASS, FIELD_CLASS, NOTICE_CLASS } from '../../../components/styles';
import type { EditorLocks, EditSurveyProps } from '../../../types/Editor';
import type { SurveyDefinition } from '../../../types/Respond';
import { cloneDefinition } from '../../../utils/definition';

const props = defineProps<EditSurveyProps>();

const { t } = useI18n();

const title = ref(props.survey.title);
const description = ref(props.survey.description ?? '');
const anonymous = ref(props.survey.anonymous);
const durationDays = ref<number | null>(props.survey.durationDays);
// The closing moment comes as the site shows it ('YYYY-MM-DDTHH:MM') and is edited as a day
// and a time
const closesDay = ref(props.survey.closesAt?.slice(0, 10) ?? '');
const closesTime = ref(props.survey.closesAt?.slice(11, 16) ?? '');
const reminderHoursBefore = ref<number | null>(props.survey.reminderHoursBefore);
const liveResults = ref(props.survey.liveResults);
const visibleToRespondents = ref(props.survey.visibleToRespondents);
const closingSummary = ref(props.survey.closingSummary);
const sendOnEnd = ref(props.endSending?.sendOnEnd ?? false);
const sendDelayHours = ref<number | null>(props.endSending?.sendDelayHours ?? 0);
const definition = ref<SurveyDefinition>(cloneDefinition(props.definition));

const errors = ref<Record<string, string>>({});
const saving = ref<'save' | 'send' | null>(null);

// A new survey is only saved on its first save, by a POST
const creating = computed(() => props.survey.id === null);
// A new poll or availability survey is its question: its title follows the question instead
// of being asked twice
const titleFollowsQuestion = computed(() => creating.value && props.survey.kind !== 'form');
const heading = computed(() => {
    if (!creating.value) {
        return t('surveys.editor.heading');
    }

    return t(`surveys.editor.new_${props.survey.kind}`);
});

function surveyTitle(): string {
    if (!titleFollowsQuestion.value) {
        return title.value;
    }

    return (definition.value.sections[0]?.questions[0]?.title ?? '').slice(0, 255);
}

const locks = computed<EditorLocks>(() => ({
    structure: props.locked || props.survey.pendingReview,
    help: props.survey.pendingReview,
    poll: props.survey.kind !== 'form',
    availability: props.survey.kind === 'availability',
}));
const detailsLocked = computed(() => locks.value.structure);
// An anonymous survey never becomes named, and a named one only changes before anyone answers
const anonymityLocked = computed(() => props.survey.anonymous || props.locked);

/**
 * Both halves empty keep the date as it is; anything else goes as typed, so a missing or
 * mistyped half comes back as the server's error on the field.
 */
function closesAt(): string | null {
    if (closesDay.value === '' && closesTime.value === '') {
        return null;
    }

    return `${closesDay.value}T${closesTime.value}`;
}

function submit(send: boolean): void {
    router[props.submitMethod](
        send ? `${props.submitUrl}?send=1` : props.submitUrl,
        {
            title: surveyTitle(),
            description: description.value === '' ? null : description.value,
            definition: definition.value,
            anonymous: anonymous.value,
            duration_days: props.survey.opened ? null : durationDays.value,
            closes_at: props.survey.opened ? closesAt() : null,
            reminder_hours_before: reminderHoursBefore.value,
            live_results: liveResults.value,
            visible_to_respondents: visibleToRespondents.value,
            closing_summary: closingSummary.value,
            // Only a survey prepared for its subject's end says how it goes out then
            ...(props.endSending
                ? { send_on_end: sendOnEnd.value, send_delay_hours: sendDelayHours.value ?? 0 }
                : {}),
        },
        {
            preserveScroll: true,
            onStart: () => {
                saving.value = send ? 'send' : 'save';
                errors.value = {};
            },
            onError: (received: Record<string, string>) => {
                errors.value = received;
            },
            onFinish: () => {
                saving.value = null;
            },
        }
    );
}
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-6 px-4 pt-8 pb-4 sm:space-y-8 sm:py-12">
        <PageHeader
            :title="heading"
            :back-url="props.backUrl"
            :back-label="t('surveys.editor.back', { title: props.subject.title })"
        />

        <p
            v-if="props.survey.pendingReview"
            :class="[NOTICE_CLASS, 'bg-warning-light']"
            role="status"
        >
            {{ t('surveys.editor.pending_review_notice') }}
        </p>
        <p
            v-if="props.survey.rejectionReason"
            :class="[NOTICE_CLASS, 'bg-error-light']"
            role="status"
        >
            {{ t('surveys.manage.rejection_reason', { reason: props.survey.rejectionReason }) }}
        </p>
        <p v-if="props.locked" :class="[NOTICE_CLASS, 'bg-muted']" role="status">
            {{ t('surveys.editor.locked_notice') }}
        </p>
        <p v-if="errors.survey" class="text-sm text-error" role="alert">{{ errors.survey }}</p>
        <p v-if="titleFollowsQuestion && errors.title" class="text-sm text-error" role="alert">
            {{ errors.title }}
        </p>

        <form class="space-y-6 sm:space-y-8" @submit.prevent="submit(false)">
            <section v-if="!titleFollowsQuestion" :class="[CARD_CLASS, 'space-y-4 p-4 sm:p-6']">
                <div>
                    <label for="survey-title" class="block text-sm font-medium text-base-primary">
                        {{ t('surveys.editor.title') }}
                    </label>
                    <input
                        id="survey-title"
                        v-model="title"
                        type="text"
                        required
                        :class="['mt-1', FIELD_CLASS]"
                        :disabled="detailsLocked"
                        :aria-invalid="errors.title ? 'true' : undefined"
                    />
                    <p v-if="errors.title" class="mt-1 text-sm text-error">{{ errors.title }}</p>
                </div>
                <div>
                    <label
                        for="survey-description"
                        class="block text-sm font-medium text-base-primary"
                    >
                        {{ t('surveys.editor.description') }}
                    </label>
                    <textarea
                        id="survey-description"
                        v-model="description"
                        rows="3"
                        :class="['mt-1', FIELD_CLASS]"
                        :disabled="detailsLocked"
                    ></textarea>
                    <p v-if="errors.description" class="mt-1 text-sm text-error">
                        {{ errors.description }}
                    </p>
                </div>
            </section>

            <section
                v-if="props.endSending"
                :class="[CARD_CLASS, 'space-y-4 p-4 sm:p-6']"
                aria-labelledby="survey-end-sending"
            >
                <h2 id="survey-end-sending" class="text-lg font-semibold text-base-primary">
                    {{ t('surveys.editor.end_sending') }}
                </h2>
                <p class="text-sm text-base-secondary">
                    {{ t('surveys.editor.awaiting_end_notice') }}
                </p>
                <div>
                    <FormCheckbox
                        id="survey-send-on-end"
                        v-model="sendOnEnd"
                        :label="t('surveys.editor.send_on_end')"
                    />
                    <p class="mt-1 text-sm text-base-secondary">
                        {{ t('surveys.editor.send_on_end_help') }}
                    </p>
                </div>
                <div v-if="sendOnEnd">
                    <label
                        for="survey-send-delay"
                        class="block text-sm font-medium text-base-primary"
                    >
                        {{ t('surveys.editor.send_delay_hours') }}
                    </label>
                    <FormNumberInput
                        id="survey-send-delay"
                        v-model="sendDelayHours"
                        :min="0"
                        :max="8760"
                        :error="errors.send_delay_hours"
                    />
                    <p class="mt-1 text-sm text-base-secondary">
                        {{ t('surveys.editor.send_delay_help') }}
                    </p>
                </div>
            </section>

            <section
                :class="[CARD_CLASS, 'space-y-5 p-4 sm:p-6']"
                :aria-label="t('surveys.editor.settings')"
            >
                <h2 class="text-lg font-semibold text-base-primary">
                    {{ t('surveys.editor.settings') }}
                </h2>
                <div v-if="!props.survey.opened">
                    <label
                        for="survey-duration"
                        class="block text-sm font-medium text-base-primary"
                    >
                        {{ t('surveys.editor.duration_days') }}
                    </label>
                    <FormNumberInput
                        id="survey-duration"
                        v-model="durationDays"
                        :min="1"
                        :max="365"
                        :error="errors.duration_days"
                    />
                    <p class="mt-1 text-sm text-base-secondary">
                        {{ t('surveys.editor.duration_help') }}
                    </p>
                </div>
                <fieldset
                    v-else
                    :aria-describedby="errors.closes_at ? 'survey-closes-at-error' : undefined"
                    data-testid="closes-at"
                >
                    <legend class="block text-sm font-medium text-base-primary">
                        {{ t('surveys.editor.closes_at') }}
                    </legend>
                    <div class="mt-1 grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1">
                            <label
                                for="survey-closes-at"
                                class="block text-xs font-medium text-base-secondary"
                            >
                                {{ t('surveys.editor.closes_at_day') }}
                            </label>
                            <FormDatePicker
                                id="survey-closes-at"
                                v-model="closesDay"
                                :aria-invalid="errors.closes_at ? 'true' : undefined"
                            />
                        </div>
                        <div class="space-y-1">
                            <label
                                for="survey-closes-at-time"
                                class="block text-xs font-medium text-base-secondary"
                            >
                                {{ t('surveys.editor.closes_at_time') }}
                            </label>
                            <FormTimePicker
                                id="survey-closes-at-time"
                                v-model="closesTime"
                                :aria-invalid="errors.closes_at ? 'true' : undefined"
                            />
                        </div>
                    </div>
                    <p
                        v-if="errors.closes_at"
                        id="survey-closes-at-error"
                        class="mt-1 text-sm text-error"
                    >
                        {{ errors.closes_at }}
                    </p>
                </fieldset>
                <div>
                    <label
                        for="survey-reminder"
                        class="block text-sm font-medium text-base-primary"
                    >
                        {{ t('surveys.editor.reminder_hours_before') }}
                    </label>
                    <FormNumberInput
                        id="survey-reminder"
                        v-model="reminderHoursBefore"
                        :min="1"
                        :max="720"
                        :error="errors.reminder_hours_before"
                    />
                    <p class="mt-1 text-sm text-base-secondary">
                        {{ t('surveys.editor.reminder_help') }}
                    </p>
                </div>
                <div>
                    <FormToggle
                        id="survey-anonymous"
                        v-model="anonymous"
                        :label="t('surveys.editor.anonymous')"
                        :disabled="anonymityLocked"
                    />
                    <p class="mt-1 text-sm text-base-secondary">
                        {{ t('surveys.editor.anonymous_help') }}
                    </p>
                    <p v-if="errors.anonymous" class="mt-1 text-sm text-error">
                        {{ errors.anonymous }}
                    </p>
                </div>
                <FormCheckbox
                    id="survey-visible"
                    v-model="visibleToRespondents"
                    :label="t('surveys.editor.visible_to_respondents')"
                />
                <div>
                    <FormCheckbox
                        id="survey-live"
                        v-model="liveResults"
                        :label="t('surveys.editor.live_results')"
                    />
                    <p
                        v-if="liveResults && anonymous"
                        :class="[NOTICE_CLASS, 'mt-2 bg-warning-light p-3']"
                        role="status"
                        data-testid="live-results-warning"
                    >
                        {{ t('surveys.editor.live_results_warning') }}
                    </p>
                </div>
                <div>
                    <FormCheckbox
                        id="survey-closing-summary"
                        v-model="closingSummary"
                        :label="t('surveys.editor.closing_summary')"
                    />
                    <p class="mt-1 text-sm text-base-secondary">
                        {{ t('surveys.editor.closing_summary_help') }}
                    </p>
                </div>
            </section>

            <section class="space-y-4">
                <h2 class="text-lg font-semibold text-base-primary">
                    {{ t('surveys.editor.questions') }}
                </h2>
                <DefinitionEditor v-model="definition" :locks="locks" :errors="errors" />
            </section>

            <div
                class="flex gap-2 sm:justify-end sm:gap-3 max-sm:sticky max-sm:bottom-0 max-sm:z-10 max-sm:-mx-4 max-sm:border-t max-sm:border-(--color-border) max-sm:bg-surface max-sm:px-4 max-sm:py-3"
                data-testid="editor-actions"
            >
                <BaseButton
                    type="submit"
                    :variant="props.survey.sendable ? 'secondary' : 'primary'"
                    class="max-sm:flex-1"
                    :loading="saving === 'save'"
                    :disabled="saving !== null"
                >
                    {{ creating ? t('surveys.editor.save_draft') : t('surveys.editor.save') }}
                </BaseButton>
                <BaseButton
                    v-if="props.survey.sendable"
                    type="button"
                    class="max-sm:flex-1"
                    :loading="saving === 'send'"
                    :disabled="saving !== null"
                    @click="submit(true)"
                >
                    {{ t('surveys.editor.save_and_send') }}
                </BaseButton>
            </div>
        </form>
    </div>
</template>
