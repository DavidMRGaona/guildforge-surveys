<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/ui/EmptyState.vue';
import { VENUE_TIMEZONE } from '@/utils/datetime';
import type { PendingSurvey } from '../../types/Respond';

const props = defineProps<{
    profilePendingSurveys: PendingSurvey[] | null;
}>();

const { t, locale } = useI18n();

const surveys = computed<PendingSurvey[]>(() => props.profilePendingSurveys ?? []);

function closesAt(value: string): string {
    return new Intl.DateTimeFormat(locale.value, {
        dateStyle: 'long',
        timeStyle: 'short',
        timeZone: VENUE_TIMEZONE,
    }).format(new Date(value));
}
</script>

<template>
    <section class="space-y-4">
        <h2 class="text-xl font-semibold text-base-primary">{{ t('surveys.profile.title') }}</h2>

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
                        {{ t('surveys.respond.closes_at', { date: closesAt(survey.closesAt) }) }}
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
</template>
