<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import BaseButton from '@/components/ui/BaseButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import OrganizedSurveysList from '../../../components/OrganizedSurveysList.vue';
import type { ManageSubjectProps } from '../../../types/Manage';

const props = defineProps<ManageSubjectProps>();

const { t } = useI18n();

const preparing = ref(false);

function prepare(): void {
    if (props.prepareUrl === null) {
        return;
    }

    router.post(
        props.prepareUrl,
        {},
        {
            onStart: () => {
                preparing.value = true;
            },
            onFinish: () => {
                preparing.value = false;
            },
        }
    );
}
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

        <p
            v-if="props.subject.status === 'cancelled'"
            class="rounded-lg bg-muted p-4 text-sm text-base-secondary"
        >
            {{ t('surveys.manage.cancelled_notice') }}
        </p>

        <section
            v-if="props.prepareUrl"
            class="flex flex-col gap-4 rounded-lg border border-neutral-200 bg-surface p-4 sm:flex-row sm:items-center sm:justify-between"
            aria-labelledby="prepare-survey-title"
        >
            <div class="space-y-1">
                <h2 id="prepare-survey-title" class="font-medium text-base-primary">
                    {{ t('surveys.manage.prepare_title') }}
                </h2>
                <p class="text-sm text-base-secondary">
                    {{
                        props.subject.status === 'ended'
                            ? t('surveys.manage.prepare_ended_description')
                            : t('surveys.manage.prepare_description')
                    }}
                </p>
            </div>
            <BaseButton class="shrink-0" :loading="preparing" @click="prepare">
                {{ t('surveys.manage.prepare') }}
            </BaseButton>
        </section>

        <EmptyState
            v-if="props.surveys.length === 0 && !props.prepareUrl"
            icon="document"
            :title="t('surveys.manage.empty_title')"
            :description="t('surveys.manage.empty_description')"
        />
        <OrganizedSurveysList
            v-else-if="props.surveys.length > 0"
            :surveys="props.surveys"
            :show-subject="false"
        />
    </div>
</template>
