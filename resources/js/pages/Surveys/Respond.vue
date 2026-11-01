<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import BaseButton from '@/components/ui/BaseButton.vue';
import { VENUE_TIMEZONE } from '@/utils/datetime';
import QuestionField from '../../components/QuestionField.vue';
import ResultsSummary from '../../components/ResultsSummary.vue';
import type { AnswerDraft, RespondProps } from '../../types/Respond';
import { allAnswers, draftsFor, emptyDraft, missingAnswer, toPayload } from '../../utils/answers';
import { nextSectionId, reachedSectionIds } from '../../utils/navigation';

const props = defineProps<RespondProps>();

const { t, locale } = useI18n();

const drafts = ref<Record<string, AnswerDraft>>(
    props.definition ? draftsFor(props.definition) : {}
);
const errors = ref<Record<string, string>>({});
const isSubmitting = ref(false);

// A survey with several sections shows one per page: `current` is the section on screen
// and `trail` the ones passed to reach it, so "Back" retraces the jumps taken
const sections = computed(() => props.definition?.sections ?? []);
const paged = computed(() => sections.value.length > 1);
const current = ref(0);
const trail = ref<number[]>([]);
const pageTop = ref<HTMLElement | null>(null);
const shownSections = computed(() =>
    paged.value ? sections.value.slice(current.value, current.value + 1) : sections.value
);
const nextId = computed(() => {
    const section = sections.value[current.value];
    if (props.definition === undefined || section === undefined) {
        return null;
    }

    return nextSectionId(props.definition, section.id, allAnswers(props.definition, drafts.value));
});
const onLastPage = computed(() => !paged.value || nextId.value === null);

const submitted = computed(() => props.result === 'submitted');
const verifyingEmail = computed(() => props.state === 'verify_email');
const linkSent = computed(() => verifyingEmail.value && props.result === 'link_sent');
const email = ref('');
const showForm = computed(
    () => props.state === 'open' && !submitted.value && props.definition !== undefined
);
const stateMessage = computed(() =>
    props.state === 'open' || verifyingEmail.value || submitted.value
        ? null
        : t(`surveys.respond.states.${props.state}`)
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

function requestLink(): void {
    if (props.requestUrl === undefined) {
        return;
    }

    router.post(
        props.requestUrl,
        { email: email.value },
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

/** Shows the missing required answers of the section on screen; true when there are none. */
function sectionComplete(): boolean {
    const missing: Record<string, string> = {};
    for (const question of sections.value[current.value]?.questions ?? []) {
        const problem = missingAnswer(question, drafts.value[question.id]);
        if (problem !== null) {
            missing[`answers.${question.id}`] = t(`surveys.respond.missing.${problem}`);
        }
    }
    errors.value = missing;

    return Object.keys(missing).length === 0;
}

function showPage(index: number, passed: number[]): void {
    current.value = index;
    trail.value = passed;
    void nextTick(() => {
        pageTop.value?.focus();
        pageTop.value?.scrollIntoView?.({ block: 'start' });
    });
}

function goNext(): void {
    const index = sections.value.findIndex((section) => section.id === nextId.value);
    if (!sectionComplete() || index === -1) {
        return;
    }

    showPage(index, [...trail.value, current.value]);
}

function goBack(): void {
    const previous = trail.value.at(-1);
    if (previous === undefined) {
        return;
    }

    errors.value = {};
    showPage(previous, trail.value.slice(0, -1));
}

/** After the server refuses an answer, goes back to the first section on the way with an error. */
function showFirstError(received: Record<string, string>): void {
    if (!paged.value || props.definition === undefined) {
        return;
    }

    const path = reachedSectionIds(
        props.definition,
        allAnswers(props.definition, drafts.value)
    ).map((id) => sections.value.findIndex((section) => section.id === id));
    const step = path.findIndex((index) =>
        (sections.value[index]?.questions ?? []).some(
            (question) => received[`answers.${question.id}`] !== undefined
        )
    );
    const index = path[step];
    if (index !== undefined) {
        showPage(index, path.slice(0, step));
    }
}

function onSubmit(): void {
    if (!onLastPage.value) {
        goNext();

        return;
    }
    if (paged.value && !sectionComplete()) {
        return;
    }

    submit();
}

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
                showFirstError(received);
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
            <p
                v-if="closesAt && (showForm || verifyingEmail)"
                class="mt-3 text-sm text-base-secondary"
            >
                {{ t('surveys.respond.closes_at', { date: closesAt }) }}
            </p>
        </header>

        <div v-if="submitted" role="status" class="rounded-lg bg-success-light p-6 text-success">
            <p class="text-lg font-semibold">{{ t('surveys.respond.thanks_title') }}</p>
            <p class="mt-1">{{ t('surveys.respond.thanks_description') }}</p>
        </div>

        <div
            v-else-if="linkSent"
            role="status"
            class="rounded-lg bg-success-light p-6 text-success"
        >
            <p class="text-lg font-semibold">{{ t('surveys.respond.verify_email.sent_title') }}</p>
            <p class="mt-1">{{ t('surveys.respond.verify_email.sent_description') }}</p>
        </div>

        <form
            v-else-if="verifyingEmail && requestUrl"
            novalidate
            class="space-y-4"
            @submit.prevent="requestLink"
        >
            <p class="text-base-secondary">{{ t('surveys.respond.verify_email.intro') }}</p>
            <div>
                <label for="survey-email" class="block text-sm font-medium text-base-primary">
                    {{ t('surveys.respond.verify_email.label') }}
                </label>
                <input
                    id="survey-email"
                    v-model="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    maxlength="254"
                    required
                    class="mt-2 block w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-base-primary shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                    :aria-invalid="errors.email ? 'true' : undefined"
                    :aria-describedby="errors.email ? 'survey-email-error' : undefined"
                />
                <p
                    v-if="errors.email"
                    id="survey-email-error"
                    role="alert"
                    class="mt-2 text-sm text-error"
                >
                    {{ errors.email }}
                </p>
            </div>
            <BaseButton type="submit" variant="primary" class="w-full" :loading="isSubmitting">
                {{ t('surveys.respond.verify_email.submit') }}
            </BaseButton>
        </form>

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

        <form v-if="showForm && definition" novalidate class="space-y-8" @submit.prevent="onSubmit">
            <p v-if="survey" class="rounded-md bg-muted p-3 text-sm text-base-secondary">
                {{
                    survey.anonymous
                        ? t('surveys.respond.anonymous_notice')
                        : t('surveys.respond.named_notice')
                }}
            </p>
            <p
                v-if="errors.survey"
                role="alert"
                class="rounded-md bg-error-light p-3 text-sm text-error"
            >
                {{ errors.survey }}
            </p>

            <div ref="pageTop" tabindex="-1" class="space-y-8 focus:outline-none">
                <section v-for="section in shownSections" :key="section.id" class="space-y-4">
                    <p v-if="paged" class="text-sm font-medium text-base-secondary">
                        {{ t('surveys.respond.section_number', { number: trail.length + 1 }) }}
                    </p>
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
            </div>

            <div class="flex flex-col gap-4 sm:flex-row">
                <BaseButton
                    v-if="trail.length > 0"
                    type="button"
                    variant="secondary"
                    class="w-full sm:flex-1"
                    @click="goBack"
                >
                    {{ t('surveys.respond.back') }}
                </BaseButton>
                <BaseButton
                    type="submit"
                    variant="primary"
                    class="w-full sm:flex-1"
                    :loading="isSubmitting"
                >
                    {{
                        !onLastPage
                            ? t('surveys.respond.next')
                            : survey?.kind === 'poll'
                              ? t('surveys.respond.submit_poll')
                              : t('surveys.respond.submit')
                    }}
                </BaseButton>
            </div>
        </form>
    </div>
</template>
