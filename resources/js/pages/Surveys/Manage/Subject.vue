<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/ui/EmptyState.vue';
import OrganizedSurveysList from '../../../components/OrganizedSurveysList.vue';
import type { ManageSubjectProps } from '../../../types/Manage';

const props = defineProps<ManageSubjectProps>();

const { t } = useI18n();
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-6 px-4 py-12">
        <header class="space-y-1">
            <p class="text-sm text-base-secondary">{{ props.subject.typeLabel }}</p>
            <h1 class="text-2xl font-semibold break-words text-base-primary">
                {{ t('surveys.manage.subject_title', { title: props.subject.title }) }}
            </h1>
            <a
                v-if="props.subject.url"
                :href="props.subject.url"
                class="text-sm text-primary underline-offset-2 hover:underline"
            >
                {{ t('surveys.manage.back_to_subject') }}
            </a>
        </header>

        <EmptyState
            v-if="props.surveys.length === 0"
            icon="document"
            :title="t('surveys.manage.empty_title')"
            :description="t('surveys.manage.empty_description')"
        />
        <OrganizedSurveysList v-else :surveys="props.surveys" :show-subject="false" />
    </div>
</template>
