<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { FormSelect } from '@/components/form';
import BaseButton from '@/components/ui/BaseButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import OrganizedSurveysList from '../../../components/OrganizedSurveysList.vue';
import type { ManageSubjectProps } from '../../../types/Manage';

const props = defineProps<ManageSubjectProps>();

const { t } = useI18n();

const preparing = ref(false);

const endChoice = ref<string | null>(props.endChoice?.selected ?? null);
const endChoiceBase = ref<string | null>(props.endChoice?.baseTemplates[0]?.value ?? null);
const savingEndChoice = ref(false);
const startsOwnSurvey = computed(
    () =>
        props.endChoice !== null &&
        props.endChoice.ownValue !== null &&
        endChoice.value === props.endChoice.ownValue
);

function text(value: string | number | null): string | null {
    return value === null ? null : String(value);
}

function saveEndChoice(): void {
    if (props.endChoice === null || props.endChoice.locked) {
        return;
    }

    router.post(
        props.endChoice.url,
        { choice: endChoice.value, base: startsOwnSurvey.value ? endChoiceBase.value : null },
        {
            preserveScroll: true,
            onStart: () => {
                savingEndChoice.value = true;
            },
            onFinish: () => {
                savingEndChoice.value = false;
            },
        }
    );
}

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
            v-if="props.endChoice"
            class="space-y-4 rounded-lg border border-neutral-200 bg-surface p-4"
            aria-labelledby="end-choice-title"
        >
            <div class="space-y-1">
                <h2 id="end-choice-title" class="font-medium text-base-primary">
                    {{ t('surveys.manage.end_choice_title') }}
                </h2>
                <p class="text-sm text-base-secondary">
                    {{ props.endChoice.lockedReason ?? t('surveys.manage.end_choice_description') }}
                </p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="end-choice" class="block text-sm font-medium text-base-primary">
                        {{ t('surveys.manage.end_choice_label') }}
                    </label>
                    <FormSelect
                        id="end-choice"
                        class="mt-1"
                        :model-value="endChoice"
                        :options="props.endChoice.options"
                        :disabled="props.endChoice.locked"
                        @update:model-value="endChoice = text($event)"
                    />
                </div>
                <div v-if="startsOwnSurvey">
                    <label
                        for="end-choice-base"
                        class="block text-sm font-medium text-base-primary"
                    >
                        {{ t('surveys.manage.end_choice_base') }}
                    </label>
                    <FormSelect
                        id="end-choice-base"
                        class="mt-1"
                        :model-value="endChoiceBase"
                        :options="props.endChoice.baseTemplates"
                        @update:model-value="endChoiceBase = text($event)"
                    />
                </div>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end">
                <a
                    v-if="props.endChoice.editUrl"
                    :href="props.endChoice.editUrl"
                    class="text-sm text-primary underline-offset-2 hover:underline"
                >
                    {{ t('surveys.manage.end_choice_edit') }}
                </a>
                <BaseButton
                    v-if="!props.endChoice.locked"
                    class="shrink-0"
                    :loading="savingEndChoice"
                    @click="saveEndChoice"
                >
                    {{ t('surveys.manage.end_choice_save') }}
                </BaseButton>
            </div>
        </section>

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

        <section
            v-if="props.createUrls"
            class="flex flex-col gap-4 rounded-lg border border-neutral-200 bg-surface p-4 sm:flex-row sm:items-center sm:justify-between"
            aria-labelledby="create-survey-title"
        >
            <div class="space-y-1">
                <h2 id="create-survey-title" class="font-medium text-base-primary">
                    {{ t('surveys.manage.create_title') }}
                </h2>
                <p class="text-sm text-base-secondary">
                    {{ t('surveys.manage.create_description') }}
                </p>
            </div>
            <div class="flex shrink-0 flex-col gap-2 sm:flex-row">
                <Link
                    :href="props.createUrls.poll"
                    class="inline-flex items-center justify-center rounded-md border border-neutral-200 px-4 py-2 text-sm font-medium text-primary hover:bg-muted"
                >
                    {{ t('surveys.manage.new_poll') }}
                </Link>
                <Link
                    :href="props.createUrls.form"
                    class="inline-flex items-center justify-center rounded-md border border-neutral-200 px-4 py-2 text-sm font-medium text-primary hover:bg-muted"
                >
                    {{ t('surveys.manage.new_form') }}
                </Link>
            </div>
        </section>

        <EmptyState
            v-if="
                props.surveys.length === 0 &&
                !props.prepareUrl &&
                props.subject.status !== 'cancelled'
            "
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
