<script setup lang="ts">
import { CircleCheck, CircleX } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import type { QuizFeedback } from '../../types/Respond';

const props = defineProps<{
    quiz: QuizFeedback;
    /** On an anonymous survey the score is only shown now: nothing ties it to the person. */
    anonymous: boolean;
}>();

const { t } = useI18n();

const list = new Intl.ListFormat('es', { style: 'long', type: 'conjunction' });
</script>

<template>
    <section
        aria-labelledby="quiz-feedback-heading"
        class="rounded-xl border-2 border-line bg-surface p-5 sm:p-8"
    >
        <h2 id="quiz-feedback-heading" class="text-lg font-semibold text-base-primary">
            {{ t('surveys.respond.quiz.title') }}
        </h2>
        <p class="mt-2 text-3xl font-bold text-base-primary">
            {{
                t('surveys.respond.quiz.score', {
                    points: props.quiz.points,
                    max: props.quiz.maxPoints,
                })
            }}
        </p>
        <p v-if="props.anonymous" class="mt-2 text-sm text-base-secondary">
            {{ t('surveys.respond.quiz.only_now') }}
        </p>

        <ul v-if="props.quiz.questions" class="mt-6 space-y-3">
            <li
                v-for="question in props.quiz.questions"
                :key="question.id"
                class="flex gap-3 rounded-lg border border-default p-3"
            >
                <CircleCheck
                    v-if="question.correct"
                    class="mt-0.5 h-5 w-5 shrink-0 text-success"
                    aria-hidden="true"
                />
                <CircleX v-else class="mt-0.5 h-5 w-5 shrink-0 text-error" aria-hidden="true" />
                <div class="min-w-0">
                    <p class="font-medium text-base-primary break-words">{{ question.title }}</p>
                    <p class="text-sm text-base-secondary">
                        {{
                            question.correct
                                ? t(
                                      'surveys.respond.quiz.right',
                                      { points: question.points },
                                      question.points
                                  )
                                : t(
                                      'surveys.respond.quiz.wrong',
                                      { points: question.points },
                                      question.points
                                  )
                        }}
                    </p>
                    <p
                        v-if="!question.correct && question.correctAnswers.length > 0"
                        class="text-sm text-base-primary break-words"
                    >
                        {{
                            t(
                                'surveys.respond.quiz.correct_answer',
                                { answers: list.format(question.correctAnswers) },
                                question.correctAnswers.length
                            )
                        }}
                    </p>
                </div>
            </li>
        </ul>
    </section>
</template>
