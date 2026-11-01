<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { QuestionResult, RespondResults, ResultBucket } from '../types/Respond';
import { bucketLabel, percent } from '../utils/buckets';
import AvailabilityResults from './results/AvailabilityResults.vue';

defineProps<{
    results: RespondResults;
}>();

const { t, locale } = useI18n();

function label(question: QuestionResult, bucket: ResultBucket): string {
    return bucketLabel(question.type, bucket.label, t('surveys.results.other'), locale.value);
}

function number(value: number | null): string {
    return value === null
        ? ''
        : new Intl.NumberFormat(locale.value, { maximumFractionDigits: 2 }).format(value);
}

function rowTotal(counts: Record<string, number>): number {
    return Object.values(counts).reduce((sum, count) => sum + count, 0);
}
</script>

<template>
    <section class="mt-8 space-y-6" :aria-label="t('surveys.results.title')">
        <div>
            <h2 class="text-xl font-semibold text-base-primary">
                {{ t('surveys.results.title') }}
            </h2>
            <p class="mt-1 text-sm text-base-secondary">
                {{
                    t('surveys.results.responses', { count: results.responses }, results.responses)
                }}
            </p>
        </div>

        <article
            v-for="question in results.questions"
            :key="question.id"
            class="rounded-lg border border-line bg-surface p-4 sm:p-6"
        >
            <h3 class="font-medium text-base-primary">{{ question.title }}</h3>
            <p class="mt-1 text-sm text-base-secondary">
                {{ t('surveys.results.answered', { count: question.answered }, question.answered) }}
                <template v-if="question.reached !== null">
                    ·
                    {{
                        t('surveys.results.reached', {
                            reached: question.reached,
                            responses: results.responses,
                        })
                    }}
                </template>
                <template v-if="question.mean !== null && !question.belowMinimum">
                    ·
                    {{
                        t('surveys.results.mean_median', {
                            mean: number(question.mean),
                            median: number(question.median),
                        })
                    }}
                </template>
            </p>

            <p v-if="question.belowMinimum" class="mt-3 text-sm text-base-secondary">
                {{ t('surveys.results.below_minimum') }}
            </p>

            <AvailabilityResults
                v-else-if="question.availability"
                class="mt-3"
                :availability="question.availability"
                :answered="question.answered"
            />

            <ol v-else-if="question.ranking" class="mt-3 space-y-3">
                <li
                    v-for="(option, index) in question.ranking.options"
                    :key="option.id"
                    :data-ranked-option="option.id"
                >
                    <div
                        class="flex items-baseline justify-between gap-3 text-sm text-base-primary"
                    >
                        <span>{{ index + 1 }}. {{ option.label }}</span>
                        <span class="shrink-0 text-right tabular-nums text-base-secondary">
                            {{
                                t('surveys.results.ranking_summary', {
                                    points: option.points,
                                    mean:
                                        option.meanPosition === null
                                            ? '—'
                                            : number(option.meanPosition),
                                })
                            }}
                        </span>
                    </div>
                    <!-- Against the most an option can get: the first place in every answer -->
                    <div
                        class="mt-1 h-2 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        :aria-label="option.label"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        :aria-valuenow="
                            percent(option.points, question.ranking.positions * question.answered)
                        "
                    >
                        <div
                            class="h-full rounded-full bg-primary-600"
                            :style="{
                                width: `${percent(option.points, question.ranking.positions * question.answered)}%`,
                            }"
                        ></div>
                    </div>
                </li>
            </ol>

            <table v-else-if="question.grid" class="mt-3 min-w-full text-sm">
                <thead>
                    <tr>
                        <th scope="col"></th>
                        <th
                            v-for="column in question.grid.columns"
                            :key="column.id"
                            scope="col"
                            class="px-2 py-1 font-medium text-base-secondary"
                        >
                            {{ column.label }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in question.grid.rows" :key="row.id">
                        <th scope="row" class="py-2 pr-3 text-left font-normal text-base-primary">
                            {{ row.label }}
                        </th>
                        <td
                            v-for="column in question.grid.columns"
                            :key="column.id"
                            class="px-2 py-2 text-center text-base-primary"
                        >
                            {{
                                t('surveys.results.percent', {
                                    percent: percent(
                                        question.grid.counts[row.id]?.[column.id] ?? 0,
                                        rowTotal(question.grid.counts[row.id] ?? {})
                                    ),
                                })
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <ul v-else class="mt-3 space-y-3">
                <li v-for="bucket in question.buckets" :key="bucket.key">
                    <div
                        class="flex items-baseline justify-between gap-3 text-sm text-base-primary"
                    >
                        <span>{{ label(question, bucket) }}</span>
                        <span class="shrink-0 tabular-nums text-base-secondary">
                            {{ bucket.count }} ·
                            {{
                                t('surveys.results.percent', {
                                    percent: percent(bucket.count, question.answered),
                                })
                            }}
                        </span>
                    </div>
                    <div
                        class="mt-1 h-2 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        :aria-label="label(question, bucket)"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        :aria-valuenow="percent(bucket.count, question.answered)"
                    >
                        <div
                            class="h-full rounded-full bg-primary-600"
                            :style="{ width: `${percent(bucket.count, question.answered)}%` }"
                        ></div>
                    </div>
                    <p v-if="bucket.voters.length > 0" class="mt-1 text-xs text-base-secondary">
                        {{ t('surveys.results.voters', { names: bucket.voters.join(', ') }) }}
                    </p>
                </li>
            </ul>
        </article>
    </section>
</template>
