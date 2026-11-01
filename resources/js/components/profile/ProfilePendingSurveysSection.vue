<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/ui/EmptyState.vue';
import { VENUE_TIMEZONE } from '@/utils/datetime';
import type { OrganizedSurvey } from '../../types/Manage';
import type { PendingSurvey } from '../../types/Respond';
import OrganizedSurveysList from '../OrganizedSurveysList.vue';
import { BADGE_CLASS, CARD_CLASS, LINK_CLASS, PRIMARY_ACTION_CLASS } from '../styles';

const props = defineProps<{
    profilePendingSurveys: PendingSurvey[] | null;
    profileOrganizedSurveys?: OrganizedSurvey[] | null;
    /** Only when something the person organized has surveys made from a template. */
    profileSurveyTrendsUrl?: string | null;
}>();

const { t, locale } = useI18n();

const surveys = computed<PendingSurvey[]>(() => props.profilePendingSurveys ?? []);
const organized = computed<OrganizedSurvey[]>(() => props.profileOrganizedSurveys ?? []);

/** Surveys closing within this many hours are marked so they are not missed. */
const CLOSING_SOON_HOURS = 48;

function closesSoon(value: string | null): boolean {
    if (value === null) {
        return false;
    }

    const left = new Date(value).getTime() - Date.now();

    return left > 0 && left <= CLOSING_SOON_HOURS * 3_600_000;
}

function subject(survey: PendingSurvey): string | null {
    const parts = [survey.subjectType, survey.subjectTitle].filter(
        (part): part is string => part !== null && part !== ''
    );

    return parts.length > 0 ? parts.join(' · ') : null;
}

function closesAt(value: string): string {
    return new Intl.DateTimeFormat(locale.value, {
        dateStyle: 'long',
        timeStyle: 'short',
        timeZone: VENUE_TIMEZONE,
    }).format(new Date(value));
}
</script>

<template>
    <div class="space-y-8">
        <section v-if="organized.length > 0 || props.profileSurveyTrendsUrl" class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-xl font-semibold text-base-primary">
                    {{ t('surveys.manage.profile_title') }}
                </h2>
                <Link
                    v-if="props.profileSurveyTrendsUrl"
                    :href="props.profileSurveyTrendsUrl"
                    :class="LINK_CLASS"
                    data-testid="trends-link"
                >
                    {{ t('surveys.trends.profile_link') }}
                </Link>
            </div>
            <OrganizedSurveysList v-if="organized.length > 0" :surveys="organized" />
        </section>

        <section class="space-y-4">
            <h2 class="text-xl font-semibold text-base-primary">
                {{ t('surveys.profile.title') }}
            </h2>

            <EmptyState
                v-if="surveys.length === 0"
                icon="document"
                :title="t('surveys.profile.empty_title')"
                :description="t('surveys.profile.empty_description')"
            />

            <ul v-else class="space-y-3">
                <li
                    v-for="survey in surveys"
                    :key="survey.id"
                    :class="[
                        CARD_CLASS,
                        'flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between',
                    ]"
                >
                    <div class="min-w-0 space-y-1">
                        <p
                            v-if="subject(survey)"
                            class="text-sm break-words text-base-secondary"
                            data-testid="subject"
                        >
                            {{ subject(survey) }}
                        </p>
                        <p class="font-semibold break-words text-base-primary">
                            {{ survey.title }}
                        </p>
                        <p
                            v-if="survey.closesAt"
                            class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-base-secondary"
                        >
                            <span
                                v-if="closesSoon(survey.closesAt)"
                                :class="[BADGE_CLASS, 'bg-warning-light text-base-primary']"
                            >
                                {{ t('surveys.profile.closing_soon') }}
                            </span>
                            <span>
                                {{
                                    t('surveys.respond.closes_at', {
                                        date: closesAt(survey.closesAt),
                                    })
                                }}
                            </span>
                        </p>
                    </div>
                    <Link :href="survey.url" :class="['shrink-0', PRIMARY_ACTION_CLASS]">
                        {{ t('surveys.profile.answer') }}
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>
