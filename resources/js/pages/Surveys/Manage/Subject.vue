<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { FormSelect } from '@/components/form';
import BaseButton from '@/components/ui/BaseButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import OrganizedSurveysList from '../../../components/OrganizedSurveysList.vue';
import PageHeader from '../../../components/PageHeader.vue';
import { CARD_CLASS, LINK_CLASS, SECONDARY_ACTION_CLASS } from '../../../components/styles';
import type { ManageSubjectProps } from '../../../types/Manage';

const props = defineProps<ManageSubjectProps>();

const { t } = useI18n();

const preparing = ref(false);

const copySource = ref<string | null>(props.copy?.surveys[0]?.value ?? null);
const copying = ref(false);

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

function copyHere(): void {
    if (props.copy === null || copySource.value === null) {
        return;
    }

    router.post(
        props.copy.url,
        { survey: copySource.value },
        {
            onStart: () => {
                copying.value = true;
            },
            onFinish: () => {
                copying.value = false;
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
    <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:py-12">
        <PageHeader
            :title="t('surveys.manage.subject_title', { title: props.subject.title })"
            :eyebrow="props.subject.typeLabel"
            :back-url="props.subject.url"
            :back-label="t('surveys.manage.back_to_subject')"
            back-reloads
        />

        <p
            v-if="props.subject.status === 'cancelled'"
            class="rounded-lg bg-muted p-4 text-sm text-base-primary"
            role="status"
        >
            {{ t('surveys.manage.cancelled_notice') }}
        </p>

        <section
            v-if="props.endChoice"
            :class="[CARD_CLASS, 'space-y-4 p-4 sm:p-6']"
            aria-labelledby="end-choice-title"
        >
            <div class="space-y-1">
                <h2 id="end-choice-title" class="text-lg font-semibold text-base-primary">
                    {{ t('surveys.manage.end_choice_title') }}
                </h2>
                <p class="text-sm text-base-secondary">
                    {{ props.endChoice.lockedReason ?? t('surveys.manage.end_choice_description') }}
                </p>
            </div>
            <!-- min-w-0: a long template name must not stretch the select past the card -->
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="min-w-0">
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
                <div v-if="startsOwnSurvey" class="min-w-0">
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
                    :class="['self-start py-2 sm:self-auto', LINK_CLASS]"
                >
                    {{ t('surveys.manage.end_choice_edit') }}
                </a>
                <BaseButton
                    v-if="!props.endChoice.locked"
                    class="w-full shrink-0 sm:w-auto"
                    :loading="savingEndChoice"
                    @click="saveEndChoice"
                >
                    {{ t('surveys.manage.end_choice_save') }}
                </BaseButton>
            </div>
        </section>

        <section
            v-if="props.prepareUrl"
            :class="[
                CARD_CLASS,
                'flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-6',
            ]"
            aria-labelledby="prepare-survey-title"
        >
            <div class="space-y-1">
                <h2 id="prepare-survey-title" class="text-lg font-semibold text-base-primary">
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
            <BaseButton class="w-full shrink-0 sm:w-auto" :loading="preparing" @click="prepare">
                {{ t('surveys.manage.prepare') }}
            </BaseButton>
        </section>

        <section
            v-if="props.createUrls"
            :class="[
                CARD_CLASS,
                'flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-6',
            ]"
            aria-labelledby="create-survey-title"
        >
            <div class="space-y-1">
                <h2 id="create-survey-title" class="text-lg font-semibold text-base-primary">
                    {{ t('surveys.manage.create_title') }}
                </h2>
                <p class="text-sm text-base-secondary">
                    {{ t('surveys.manage.create_description') }}
                </p>
            </div>
            <div class="flex shrink-0 flex-col gap-2 sm:flex-row">
                <Link :href="props.createUrls.availability" :class="SECONDARY_ACTION_CLASS">
                    {{ t('surveys.manage.new_availability') }}
                </Link>
                <Link :href="props.createUrls.poll" :class="SECONDARY_ACTION_CLASS">
                    {{ t('surveys.manage.new_poll') }}
                </Link>
                <Link :href="props.createUrls.form" :class="SECONDARY_ACTION_CLASS">
                    {{ t('surveys.manage.new_form') }}
                </Link>
            </div>
        </section>

        <section
            v-if="props.copy"
            :class="[CARD_CLASS, 'space-y-4 p-4 sm:p-6']"
            aria-labelledby="copy-survey-title"
        >
            <div class="space-y-1">
                <h2 id="copy-survey-title" class="text-lg font-semibold text-base-primary">
                    {{ t('surveys.manage.copy_title') }}
                </h2>
                <p class="text-sm text-base-secondary">
                    {{ t('surveys.manage.copy_description') }}
                </p>
            </div>
            <!-- min-w-0: a long survey name must not stretch the select past the card -->
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
                <div class="min-w-0 sm:flex-1">
                    <label for="copy-source" class="block text-sm font-medium text-base-primary">
                        {{ t('surveys.manage.copy_label') }}
                    </label>
                    <FormSelect
                        id="copy-source"
                        class="mt-1"
                        :model-value="copySource"
                        :options="props.copy.surveys"
                        @update:model-value="copySource = text($event)"
                    />
                </div>
                <BaseButton
                    variant="secondary"
                    class="w-full shrink-0 sm:w-auto"
                    :loading="copying"
                    @click="copyHere"
                >
                    {{ t('surveys.manage.copy_here') }}
                </BaseButton>
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
