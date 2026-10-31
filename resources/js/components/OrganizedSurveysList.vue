<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import BaseButton from '@/components/ui/BaseButton.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import { VENUE_TIMEZONE } from '@/utils/datetime';
import type { OrganizedSurvey, OrganizedSurveyStatus } from '../types/Manage';

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

const badgeClasses: Record<OrganizedSurveyStatus, string> = {
    prepared: 'bg-primary-light text-primary',
    to_send: 'bg-primary-light text-primary',
    scheduled: 'bg-primary-light text-primary',
    pending_review: 'bg-warning-light text-warning',
    rejected: 'bg-error-light text-error',
    open: 'bg-success-light text-success',
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
</script>

<template>
    <ul class="divide-y divide-neutral-200 rounded-lg border border-neutral-200 bg-surface">
        <li
            v-for="survey in props.surveys"
            :key="survey.id"
            class="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between"
            :data-status="survey.status"
        >
            <div class="min-w-0 space-y-1">
                <p class="font-medium break-words text-base-primary">{{ survey.title }}</p>
                <p
                    v-if="props.showSubject && survey.subjectTitle"
                    class="text-sm break-words text-base-secondary"
                >
                    {{ survey.subjectTitle }}
                </p>
                <p>
                    <span
                        class="inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="badgeClasses[survey.status]"
                    >
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
                v-if="survey.sendUrl || survey.dismissUrl || survey.editUrl || survey.resultsUrl"
                class="flex shrink-0 flex-col gap-2 sm:flex-row"
            >
                <Link
                    v-if="survey.resultsUrl"
                    :href="survey.resultsUrl"
                    class="inline-flex items-center justify-center rounded-md px-3 py-1.5 text-sm font-medium text-primary hover:underline"
                >
                    {{ t('surveys.manage.results') }}
                </Link>
                <Link
                    v-if="survey.editUrl"
                    :href="survey.editUrl"
                    class="inline-flex items-center justify-center rounded-md px-3 py-1.5 text-sm font-medium text-primary hover:underline"
                >
                    {{ t('surveys.manage.edit') }}
                </Link>
                <BaseButton
                    v-if="survey.dismissUrl"
                    variant="secondary"
                    size="sm"
                    :disabled="busy !== null"
                    @click="askDismiss(survey)"
                >
                    {{ t('surveys.manage.dismiss') }}
                </BaseButton>
                <BaseButton
                    v-if="survey.sendUrl"
                    size="sm"
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
        :title="t('surveys.manage.dismiss_title')"
        :message="t('surveys.manage.dismiss_message')"
        :confirm-label="t('surveys.manage.dismiss')"
        :cancel-label="t('surveys.manage.cancel')"
        confirm-variant="danger"
        @confirm="confirmDismiss"
    />
</template>
