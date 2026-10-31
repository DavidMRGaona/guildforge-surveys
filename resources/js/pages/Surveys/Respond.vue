<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import BaseButton from '@/components/ui/BaseButton.vue';
import { VENUE_TIMEZONE } from '@/utils/datetime';
import QuestionField from '../../components/QuestionField.vue';
import ResultsSummary from '../../components/ResultsSummary.vue';
import type { AnswerDraft, RespondProps } from '../../types/Respond';
import { draftsFor, emptyDraft, toPayload } from '../../utils/answers';

const props = defineProps<RespondProps>();

const { t, locale } = useI18n();

const drafts = ref<Record<string, AnswerDraft>>(
    props.definition ? draftsFor(props.definition) : {}
);
const errors = ref<Record<string, string>>({});
const isSubmitting = ref(false);

const submitted = computed(() => props.result === 'submitted');
const showForm = computed(
    () => props.state === 'open' && !submitted.value && props.definition !== undefined
);
const stateMessage = computed(() =>
    props.state === 'open' || submitted.value ? null : t(`surveys.respond.states.${props.state}`)
);
const hasRequired = computed(
    () =>
        props.definition?.sections.some((section) =>
            section.questions.some((question) => question.required)
        ) ?? false
);
const closesAt = computed(() => {
    if (!props.survey?.closesAt) {
        return null;
    }

    return new Intl.DateTimeFormat(locale.value, {
        dateStyle: 'long',
        timeStyle: 'short',
        timeZone: VENUE_TIMEZONE,
    }).format(new Date(props.survey.closesAt));
});

function submit(): void {
    if (props.definition === undefined || props.submitUrl === undefined) {
        return;
    }

    router.post(
        props.submitUrl,
        { answers: toPayload(props.definition, drafts.value) },
        {
            preserveScroll: true,
            onStart: () => {
                isSubmitting.value = true;
                errors.value = {};
            },
            onError: (received: Record<string, string>) => {
                errors.value = received;
            },
            onFinish: () => {
                isSubmitting.value = false;
            },
        }
    );
}
</script>

<template>
    <div class="mx-auto max-w-2xl px-4 py-12">
        <header v-if="survey" class="mb-8">
            <h1 class="text-3xl font-bold tracking-tight text-base-primary">{{ survey.title }}</h1>
            <p v-if="survey.description" class="mt-3 whitespace-pre-line text-base-secondary">
                {{ survey.description }}
            </p>
            <p v-if="closesAt && showForm" class="mt-3 text-sm text-base-secondary">
                {{ t('surveys.respond.closes_at', { date: closesAt }) }}
            </p>
        </header>

        <div v-if="submitted" role="status" class="rounded-lg bg-success-light p-6 text-success">
            <p class="text-lg font-semibold">{{ t('surveys.respond.thanks_title') }}</p>
            <p class="mt-1">{{ t('surveys.respond.thanks_description') }}</p>
        </div>

        <div
            v-else-if="stateMessage"
            role="alert"
            class="rounded-lg bg-warning-light p-6 text-warning-dark"
        >
            <p>{{ stateMessage }}</p>
            <Link href="/" class="mt-4 inline-flex text-sm font-medium underline">{{
                t('surveys.respond.back_home')
            }}</Link>
        </div>

        <ResultsSummary v-if="results && !showForm" :results="results" />

        <form v-if="showForm && definition" novalidate class="space-y-8" @submit.prevent="submit">
            <p v-if="survey" class="rounded-md bg-muted p-3 text-sm text-base-secondary">
                {{
                    survey.anonymous
                        ? t('surveys.respond.anonymous_notice')
                        : t('surveys.respond.named_notice')
                }}
            </p>
            <p v-if="hasRequired" class="text-sm text-base-secondary">
                {{ t('surveys.respond.required_hint') }}
            </p>
            <p
                v-if="errors.survey"
                role="alert"
                class="rounded-md bg-error-light p-3 text-sm text-error"
            >
                {{ errors.survey }}
            </p>

            <section v-for="section in definition.sections" :key="section.id" class="space-y-4">
                <div v-if="section.title || section.description">
                    <h2 v-if="section.title" class="text-xl font-semibold text-base-primary">
                        {{ section.title }}
                    </h2>
                    <p
                        v-if="section.description"
                        class="mt-1 whitespace-pre-line text-base-secondary"
                    >
                        {{ section.description }}
                    </p>
                </div>
                <QuestionField
                    v-for="question in section.questions"
                    :key="question.id"
                    :model-value="drafts[question.id] ?? emptyDraft()"
                    :question="question"
                    :error="errors[`answers.${question.id}`]"
                    @update:model-value="(value: AnswerDraft) => (drafts[question.id] = value)"
                />
            </section>

            <BaseButton type="submit" variant="primary" class="w-full" :loading="isSubmitting">
                {{
                    survey?.kind === 'poll'
                        ? t('surveys.respond.submit_poll')
                        : t('surveys.respond.submit')
                }}
            </BaseButton>
        </form>
    </div>
</template>
