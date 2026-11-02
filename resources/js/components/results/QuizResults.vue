<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { QuizResults } from '../../types/Results';
import { CARD_CLASS } from '../styles';

const props = defineProps<{
    quiz: QuizResults;
}>();

const { t, locale } = useI18n();

const mean = computed(() =>
    props.quiz.mean === null
        ? null
        : new Intl.NumberFormat(locale.value, { maximumFractionDigits: 2 }).format(props.quiz.mean)
);
const mostResponses = computed(() =>
    Math.max(1, ...props.quiz.distribution.map((count) => count.responses))
);
</script>

<template>
    <section
        :class="[CARD_CLASS, 'space-y-5 p-4 sm:p-6']"
        aria-labelledby="quiz-results-title"
        data-testid="quiz-results"
    >
        <div>
            <h2 id="quiz-results-title" class="text-lg font-semibold text-base-primary">
                {{ t('surveys.results.quiz.title') }}
            </h2>
            <p v-if="props.quiz.belowMinimum" class="mt-1 text-sm text-base-secondary">
                {{ t('surveys.results.quiz.below_minimum') }}
            </p>
            <p
                v-else-if="mean !== null"
                class="mt-1 text-2xl font-semibold tabular-nums text-base-primary"
            >
                {{ t('surveys.results.quiz.mean', { mean, max: props.quiz.maxPoints }) }}
            </p>
        </div>

        <div v-if="props.quiz.distribution.length > 0">
            <h3 class="text-sm font-medium text-base-primary">
                {{ t('surveys.results.quiz.distribution') }}
            </h3>
            <ul class="mt-2 space-y-1.5">
                <li
                    v-for="count in props.quiz.distribution"
                    :key="count.points"
                    class="grid grid-cols-[4.5rem_1fr_2.5rem] items-center gap-2 text-sm"
                >
                    <span class="tabular-nums text-base-secondary">
                        {{
                            t('surveys.results.quiz.points', { points: count.points }, count.points)
                        }}
                    </span>
                    <span class="h-3 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                        <span
                            class="block h-full rounded-full bg-primary"
                            :style="{ width: `${(count.responses / mostResponses) * 100}%` }"
                        ></span>
                    </span>
                    <span class="text-end tabular-nums text-base-primary">
                        {{ count.responses }}
                    </span>
                </li>
            </ul>
        </div>

        <div v-if="props.quiz.leaderboard && props.quiz.leaderboard.length > 0">
            <h3 class="text-sm font-medium text-base-primary">
                {{ t('surveys.results.quiz.leaderboard') }}
            </h3>
            <table class="mt-2 w-full text-sm">
                <thead class="sr-only">
                    <tr>
                        <th scope="col">{{ t('surveys.results.quiz.rank') }}</th>
                        <th scope="col">{{ t('surveys.results.quiz.person') }}</th>
                        <th scope="col">{{ t('surveys.results.points') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="standing in props.quiz.leaderboard"
                        :key="`${standing.rank}-${standing.name}`"
                        class="border-b border-default last:border-b-0"
                    >
                        <td class="w-12 whitespace-nowrap py-2 tabular-nums text-base-secondary">
                            {{ t('surveys.results.quiz.rank_place', { place: standing.rank }) }}
                        </td>
                        <th
                            scope="row"
                            class="py-2 text-start font-normal break-words text-base-primary"
                        >
                            {{ standing.name }}
                        </th>
                        <td class="py-2 text-end tabular-nums text-base-primary">
                            {{
                                t(
                                    'surveys.results.quiz.points',
                                    { points: standing.points },
                                    standing.points
                                )
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
