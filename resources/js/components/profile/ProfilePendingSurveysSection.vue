<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/ui/EmptyState.vue';
import { VENUE_TIMEZONE } from '@/utils/datetime';
import type { OrganizedSurvey } from '../../types/Manage';
import type { PendingSurvey } from '../../types/Respond';
import OrganizedSurveysList from '../OrganizedSurveysList.vue';

const props = defineProps<{
    profilePendingSurveys: PendingSurvey[] | null;
    profileOrganizedSurveys?: OrganizedSurvey[] | null;
    /** Only when something the person organized has surveys made from a template. */
    profileSurveyTrendsUrl?: string | null;
}>();

const { t, locale } = useI18n();

const surveys = computed<PendingSurvey[]>(() => props.profilePendingSurveys ?? []);
const organized = computed<OrganizedSurvey[]>(() => props.profileOrganizedSurveys ?? []);

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
                    class="text-sm font-medium text-primary underline-offset-2 hover:underline"
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

            <ul
                v-else
                class="divide-y divide-neutral-200 rounded-lg border border-neutral-200 bg-surface"
            >
                <li
                    v-for="survey in surveys"
                    :key="survey.id"
                    class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div>
                        <p class="font-medium text-base-primary">{{ survey.title }}</p>
                        <p v-if="survey.closesAt" class="text-sm text-base-secondary">
                            {{
                                t('surveys.respond.closes_at', { date: closesAt(survey.closesAt) })
                            }}
                        </p>
                    </div>
                    <Link
                        :href="survey.url"
                        class="inline-flex items-center justify-center rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700"
                    >
                        {{ t('surveys.profile.answer') }}
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>
