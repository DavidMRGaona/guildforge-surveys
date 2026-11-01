<script setup lang="ts">
import { CalendarClock, CircleCheck, ShieldCheck, UserRound } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import BaseButton from '@/components/ui/BaseButton.vue';
import { VENUE_TIMEZONE } from '@/utils/datetime';
import QuestionField from '../../components/QuestionField.vue';
import ResultsSummary from '../../components/ResultsSummary.vue';
import type { AnswerDraft, RespondProps } from '../../types/Respond';
import { allAnswers, draftsFor, emptyDraft, missingAnswer, toPayload } from '../../utils/answers';
import { readDraft, removeDraft, resumeAt, writeDraft } from '../../utils/draft';
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

// There is no fixed number of steps when answers jump: the total is the way the current
// answers take, so it changes as soon as a jumping answer does
const stepTotal = computed(() =>
    props.definition === undefined
        ? 1
        : reachedSectionIds(props.definition, allAnswers(props.definition, drafts.value)).length
);
const stepNumber = computed(() => trail.value.length + 1);
const stepLabel = computed(() =>
    t('surveys.respond.step_of', { current: stepNumber.value, total: stepTotal.value })
);

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
const hasAnswerErrors = computed(() =>
    Object.keys(errors.value).some((key) => key.startsWith('answers.'))
);

function formatInstant(value: string, options: Intl.DateTimeFormatOptions): string {
    return new Intl.DateTimeFormat(locale.value, { ...options, timeZone: VENUE_TIMEZONE }).format(
        new Date(value)
    );
}

const closesAt = computed(() =>
    props.survey?.closesAt
        ? formatInstant(props.survey.closesAt, { dateStyle: 'long', timeStyle: 'short' })
        : null
);
const subjectStartsAt = computed(() =>
    props.subject?.startsAt
        ? formatInstant(props.subject.startsAt, {
              weekday: 'long',
              day: 'numeric',
              month: 'long',
              hour: '2-digit',
              minute: '2-digit',
          })
        : null
);

// The draft lives in this browser tab only (see utils/draft.ts)
const draftKey = computed(() => (props.survey ? `surveys.draft.${props.survey.id}` : null));

function tabStorage(): Storage | null {
    try {
        return window.sessionStorage;
    } catch {
        return null;
    }
}

if (submitted.value && draftKey.value !== null) {
    removeDraft(tabStorage(), draftKey.value);
}

if (showForm.value && props.definition !== undefined && draftKey.value !== null) {
    const saved = readDraft(tabStorage(), draftKey.value, props.definition);
    if (saved !== null) {
        drafts.value = { ...drafts.value, ...saved.drafts };
        const resumed = resumeAt(props.definition, drafts.value, saved.section);
        current.value = resumed.current;
        trail.value = resumed.trail;
    }
}

watch(
    [drafts, current],
    () => {
        if (!showForm.value || props.definition === undefined || draftKey.value === null) {
            return;
        }
        writeDraft(
            tabStorage(),
            draftKey.value,
            props.definition,
            drafts.value,
            sections.value[current.value]?.id ?? null
        );
    },
    { deep: true }
);

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
            onSuccess: () => {
                if (draftKey.value !== null) {
                    removeDraft(tabStorage(), draftKey.value);
                }
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

const primaryLinkClass =
    'inline-flex min-h-11 items-center justify-center rounded-lg text-center bg-brand px-5 py-2.5 font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500';
const secondaryLinkClass =
    'inline-flex min-h-11 items-center justify-center rounded-lg text-center border-2 border-line bg-surface px-5 py-2.5 font-medium text-base-primary hover:bg-muted focus:outline-none focus:ring-2 focus:ring-primary-500';
</script>

<template>
    <div class="mx-auto max-w-2xl px-4 py-10 sm:py-12">
        <header v-if="survey" class="mb-8">
            <p
                v-if="subject"
                class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-base-secondary"
            >
                <span
                    v-if="subject.typeLabel"
                    class="rounded-full bg-brand-light px-2.5 py-0.5 text-xs font-semibold text-base-primary"
                    >{{ subject.typeLabel }}</span
                >
                <Link
                    v-if="subject.url"
                    :href="subject.url"
                    class="font-medium text-base-primary underline decoration-line-strong underline-offset-4 hover:decoration-current"
                    >{{ subject.title }}</Link
                >
                <span v-else class="font-medium text-base-primary">{{ subject.title }}</span>
                <span v-if="subjectStartsAt" class="hidden sm:inline" aria-hidden="true">·</span>
                <span v-if="subjectStartsAt" class="w-full sm:w-auto">{{ subjectStartsAt }}</span>
            </p>
            <h1
                class="mt-3 text-3xl font-bold tracking-tight text-base-primary text-balance sm:text-4xl"
            >
                {{ survey.title }}
            </h1>
            <p
                v-if="survey.description"
                class="mt-3 whitespace-pre-line text-lg text-base-secondary"
            >
                {{ survey.description }}
            </p>
            <p
                v-if="closesAt && (showForm || verifyingEmail)"
                class="mt-4 flex items-center gap-2 text-sm text-base-secondary"
            >
                <CalendarClock class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ t('surveys.respond.closes_at', { date: closesAt }) }}
            </p>
            <div
                v-if="showForm || verifyingEmail"
                class="mt-5 flex gap-3 rounded-lg border-2 border-line bg-muted p-4"
            >
                <component
                    :is="survey.anonymous ? ShieldCheck : UserRound"
                    class="mt-0.5 h-5 w-5 shrink-0 text-base-primary"
                    aria-hidden="true"
                />
                <div>
                    <p class="font-semibold text-base-primary">
                        {{
                            survey.anonymous
                                ? t('surveys.respond.anonymous_title')
                                : t('surveys.respond.named_title')
                        }}
                    </p>
                    <p class="mt-0.5 text-sm text-base-secondary">
                        {{
                            survey.anonymous
                                ? t('surveys.respond.anonymous_notice')
                                : t('surveys.respond.named_notice')
                        }}
                    </p>
                </div>
            </div>
        </header>

        <section
            v-if="submitted"
            role="status"
            class="rounded-xl border-2 border-line bg-surface p-6 text-center sm:p-8"
        >
            <span
                class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-success-light text-success"
            >
                <CircleCheck class="h-8 w-8" aria-hidden="true" />
            </span>
            <h2 class="mt-4 text-2xl font-bold text-base-primary">
                {{
                    survey?.kind === 'poll'
                        ? t('surveys.respond.thanks_poll_title')
                        : t('surveys.respond.thanks_title')
                }}
            </h2>
            <p class="mt-2 text-base-secondary">
                {{
                    survey?.anonymous
                        ? t('surveys.respond.thanks_anonymous')
                        : t('surveys.respond.thanks_description')
                }}
            </p>
        </section>

        <div
            v-else-if="linkSent"
            role="status"
            class="rounded-xl border-2 border-line bg-surface p-6 sm:p-8"
        >
            <p class="text-lg font-semibold text-base-primary">
                {{ t('surveys.respond.verify_email.sent_title') }}
            </p>
            <p class="mt-1 text-base-secondary">
                {{ t('surveys.respond.verify_email.sent_description') }}
            </p>
        </div>

        <form
            v-else-if="verifyingEmail && requestUrl"
            novalidate
            class="space-y-4 rounded-xl border-2 border-line bg-surface p-5 sm:p-6"
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
                    class="mt-2 block w-full rounded-lg border-2 bg-surface px-4 py-2.5 text-base-primary focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500"
                    :class="errors.email ? 'border-error' : 'border-line'"
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
            class="rounded-xl border-2 border-line bg-warning-light p-6 text-warning-dark"
        >
            <p>{{ stateMessage }}</p>
        </div>

        <ResultsSummary v-if="results && !showForm" :results="results" class="mt-8" />

        <nav
            v-if="submitted || stateMessage"
            class="mt-8 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:justify-center"
        >
            <Link v-if="subject?.url" :href="subject.url" :class="primaryLinkClass">{{
                t('surveys.respond.back_to_subject', { title: subject.title })
            }}</Link>
            <Link
                v-if="profileUrl"
                :href="profileUrl"
                :class="subject?.url ? secondaryLinkClass : primaryLinkClass"
                >{{ t('surveys.respond.pending_surveys') }}</Link
            >
            <Link v-if="!subject?.url && !profileUrl" href="/" :class="secondaryLinkClass">{{
                t('surveys.respond.back_home')
            }}</Link>
        </nav>

        <form v-if="showForm && definition" novalidate @submit.prevent="onSubmit">
            <div v-if="paged" class="mb-6">
                <p class="text-sm font-semibold text-base-primary">{{ stepLabel }}</p>
                <div
                    role="progressbar"
                    :aria-label="stepLabel"
                    aria-valuemin="1"
                    :aria-valuemax="stepTotal"
                    :aria-valuenow="stepNumber"
                    :aria-valuetext="stepLabel"
                    class="mt-2 h-2 overflow-hidden rounded-full bg-muted"
                >
                    <div
                        class="h-full rounded-full bg-brand transition-[width] duration-300"
                        :style="{ width: `${(stepNumber / stepTotal) * 100}%` }"
                    ></div>
                </div>
            </div>

            <p
                v-if="errors.survey"
                role="alert"
                class="mb-6 rounded-lg border-2 border-error bg-error-light p-4 text-sm text-error"
            >
                {{ errors.survey }}
            </p>
            <p
                v-else-if="hasAnswerErrors && !paged"
                role="alert"
                class="mb-6 rounded-lg border-2 border-error bg-error-light p-4 text-sm font-medium text-error"
            >
                {{ t('surveys.respond.review_errors') }}
            </p>

            <div ref="pageTop" tabindex="-1" class="space-y-8 focus:outline-none">
                <section v-for="section in shownSections" :key="section.id" class="space-y-4">
                    <div v-if="section.title || section.description">
                        <h2 v-if="section.title" class="text-xl font-bold text-base-primary">
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

            <div class="mt-8 flex gap-3">
                <BaseButton
                    v-if="trail.length > 0"
                    type="button"
                    variant="secondary"
                    class="flex-1 sm:flex-none sm:px-8"
                    @click="goBack"
                >
                    {{ t('surveys.respond.back') }}
                </BaseButton>
                <BaseButton
                    type="submit"
                    variant="primary"
                    class="flex-1 sm:ml-auto sm:flex-none sm:px-8"
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
