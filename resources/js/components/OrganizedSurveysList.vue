<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import BaseButton from '@/components/ui/BaseButton.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import { VENUE_TIMEZONE } from '@/utils/datetime';
import type { OrganizedSurvey, OrganizedSurveyStatus } from '../types/Manage';
import { BADGE_CLASS, CARD_CLASS, LINK_ACTION_CLASS } from './styles';

const props = withDefaults(
    defineProps<{
        surveys: OrganizedSurvey[];
        /** On a subject's own page the subject is already named. */
        showSubject?: boolean;
    }>(),
    { showSubject: true }
);

const { t, locale } = useI18n();

const busy = ref<string | null>(null);
const dismissing = ref<OrganizedSurvey | null>(null);
const dismissOpen = ref(false);
const closing = ref<OrganizedSurvey | null>(null);
const closeOpen = ref(false);

// The organizer's own draft is thrown away; an offered survey is simply not sent
const dismissIsDiscard = computed(() => dismissing.value?.status === 'draft');

const badgeClasses: Record<OrganizedSurveyStatus, string> = {
    draft: 'bg-muted text-base-secondary',
    prepared: 'bg-primary-light text-primary',
    to_send: 'bg-primary-light text-primary',
    scheduled: 'bg-primary-light text-primary',
    pending_review: 'bg-warning-light text-base-primary',
    rejected: 'bg-error-light text-base-primary',
    open: 'bg-success-light text-base-primary',
    closed: 'bg-muted text-base-secondary',
};

function date(value: string): string {
    return new Intl.DateTimeFormat(locale.value, {
        dateStyle: 'long',
        timeStyle: 'short',
        timeZone: VENUE_TIMEZONE,
    }).format(new Date(value));
}

function preparedHint(survey: OrganizedSurvey): string {
    if (!survey.sendOnEnd) {
        return t('surveys.manage.prepared_one_click_hint');
    }

    return survey.sendDelayHours === 0
        ? t('surveys.manage.prepared_auto_now_hint')
        : t('surveys.manage.prepared_auto_hint', { hours: survey.sendDelayHours });
}

function post(survey: OrganizedSurvey, url: string): void {
    router.post(
        url,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                busy.value = survey.id;
            },
            onFinish: () => {
                busy.value = null;
            },
        }
    );
}

function askDismiss(survey: OrganizedSurvey): void {
    dismissing.value = survey;
    dismissOpen.value = true;
}

function confirmDismiss(): void {
    const survey = dismissing.value;
    if (survey?.dismissUrl) {
        post(survey, survey.dismissUrl);
    }
    dismissing.value = null;
}

function askClose(survey: OrganizedSurvey): void {
    closing.value = survey;
    closeOpen.value = true;
}

function confirmClose(): void {
    const survey = closing.value;
    if (survey?.closeUrl) {
        post(survey, survey.closeUrl);
    }
    closing.value = null;
}
</script>

<template>
    <ul class="space-y-3">
        <li
            v-for="survey in props.surveys"
            :key="survey.id"
            :class="[
                CARD_CLASS,
                'flex flex-col gap-4 p-4 sm:flex-row sm:items-start sm:justify-between',
            ]"
            :data-status="survey.status"
        >
            <div class="min-w-0 space-y-1">
                <p class="font-semibold break-words text-base-primary">{{ survey.title }}</p>
                <p
                    v-if="props.showSubject && survey.subjectTitle"
                    class="text-sm break-words text-base-secondary"
                >
                    {{ survey.subjectTitle }}
                </p>
                <p>
                    <span :class="[BADGE_CLASS, badgeClasses[survey.status]]" data-testid="status">
                        {{ t(`surveys.manage.statuses.${survey.status}`) }}
                    </span>
                </p>
                <p
                    v-if="survey.status === 'scheduled' && survey.scheduledSendAt"
                    class="text-sm text-base-secondary"
                >
                    {{ t('surveys.manage.scheduled_at', { date: date(survey.scheduledSendAt) }) }}
                </p>
                <p v-if="survey.status === 'prepared'" class="text-sm text-base-secondary">
                    {{ preparedHint(survey) }}
                </p>
                <p v-if="survey.status === 'draft'" class="text-sm text-base-secondary">
                    {{ t('surveys.manage.draft_hint') }}
                </p>
                <p v-if="survey.status === 'to_send'" class="text-sm text-base-secondary">
                    {{ t('surveys.manage.to_send_hint') }}
                </p>
                <p v-if="survey.status === 'pending_review'" class="text-sm text-base-secondary">
                    {{ t('surveys.manage.pending_review_hint') }}
                </p>
                <p v-if="survey.rejectionReason" class="text-sm break-words text-error">
                    {{ t('surveys.manage.rejection_reason', { reason: survey.rejectionReason }) }}
                </p>
                <p
                    v-if="survey.status === 'open' || survey.status === 'closed'"
                    class="text-sm text-base-secondary"
                >
                    {{
                        t('surveys.manage.response_rate', {
                            responses: survey.responses,
                            audience: survey.audienceSize,
                        })
                    }}
                    <template v-if="survey.status === 'open' && survey.closesAt">
                        · {{ t('surveys.respond.closes_at', { date: date(survey.closesAt) }) }}
                    </template>
                </p>
            </div>

            <div
                v-if="
                    survey.sendUrl ||
                    survey.dismissUrl ||
                    survey.editUrl ||
                    survey.resultsUrl ||
                    survey.closeUrl
                "
                class="flex shrink-0 flex-col gap-2 sm:flex-row sm:flex-wrap sm:justify-end"
            >
                <Link v-if="survey.resultsUrl" :href="survey.resultsUrl" :class="LINK_ACTION_CLASS">
                    {{ t('surveys.manage.results') }}
                </Link>
                <Link v-if="survey.editUrl" :href="survey.editUrl" :class="LINK_ACTION_CLASS">
                    {{ t('surveys.manage.edit') }}
                </Link>
                <BaseButton
                    v-if="survey.dismissUrl"
                    variant="secondary"
                    size="sm"
                    class="w-full sm:w-auto"
                    :disabled="busy !== null"
                    @click="askDismiss(survey)"
                >
                    {{
                        survey.status === 'draft'
                            ? t('surveys.manage.discard')
                            : t('surveys.manage.dismiss')
                    }}
                </BaseButton>
                <BaseButton
                    v-if="survey.closeUrl"
                    variant="secondary"
                    size="sm"
                    class="w-full sm:w-auto"
                    :loading="busy === survey.id"
                    :disabled="busy !== null"
                    @click="askClose(survey)"
                >
                    {{ t('surveys.manage.close') }}
                </BaseButton>
                <BaseButton
                    v-if="survey.sendUrl"
                    size="sm"
                    class="w-full sm:w-auto"
                    :loading="busy === survey.id"
                    :disabled="busy !== null"
                    @click="post(survey, survey.sendUrl)"
                >
                    {{
                        survey.status === 'scheduled'
                            ? t('surveys.manage.send_now')
                            : t('surveys.manage.send')
                    }}
                </BaseButton>
            </div>
        </li>
    </ul>

    <ConfirmDialog
        v-model="dismissOpen"
        :title="
            dismissIsDiscard ? t('surveys.manage.discard_title') : t('surveys.manage.dismiss_title')
        "
        :message="
            dismissIsDiscard
                ? t('surveys.manage.discard_message')
                : t('surveys.manage.dismiss_message')
        "
        :confirm-label="
            dismissIsDiscard ? t('surveys.manage.discard') : t('surveys.manage.dismiss')
        "
        :cancel-label="t('surveys.manage.cancel')"
        confirm-variant="danger"
        @confirm="confirmDismiss"
    />

    <ConfirmDialog
        v-model="closeOpen"
        :title="t('surveys.manage.close_title')"
        :message="t('surveys.manage.close_message')"
        :confirm-label="t('surveys.manage.close')"
        :cancel-label="t('surveys.manage.cancel')"
        @confirm="confirmClose"
    />
</template>
