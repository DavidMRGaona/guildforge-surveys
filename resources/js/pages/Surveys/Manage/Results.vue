<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { FormCheckbox } from '@/components/form';
import BaseButton from '@/components/ui/BaseButton.vue';
import QuestionChart from '../../../components/results/QuestionChart.vue';
import type { ChartType, ManageResultsProps } from '../../../types/Results';

const props = defineProps<ManageResultsProps>();

const { t, locale } = useI18n();

const chartTypes = ref<Record<string, ChartType>>(
    Object.fromEntries(props.results.questions.map((question) => [question.id, question.chartType]))
);
const hidden = ref<string[]>(props.hiddenQuestions.map((question) => question.id));
const saving = ref(false);

const rate = computed(() =>
    props.results.rate === null
        ? null
        : new Intl.NumberFormat(locale.value, {
              style: 'percent',
              maximumFractionDigits: 0,
          }).format(props.results.rate)
);

const hiddenNotice = computed(() => {
    if (props.results.visible) {
        return null;
    }

    return props.results.hiddenReason === 'below_minimum'
        ? t('surveys.manage.results_below_minimum')
        : t('surveys.manage.results_not_closed');
});

function number(value: number | null): string {
    return value === null
        ? ''
        : new Intl.NumberFormat(locale.value, { maximumFractionDigits: 2 }).format(value);
}

function isHidden(id: string): boolean {
    return hidden.value.includes(id);
}

function setHidden(id: string, value: boolean): void {
    hidden.value = value
        ? [...hidden.value.filter((other) => other !== id), id]
        : hidden.value.filter((other) => other !== id);
}

function save(): void {
    router.put(
        props.settingsUrl,
        { chart_types: chartTypes.value, hidden_question_ids: hidden.value },
        {
            preserveScroll: true,
            onStart: () => {
                saving.value = true;
            },
            onFinish: () => {
                saving.value = false;
            },
        }
    );
}
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-6 px-4 py-12">
        <header class="space-y-1">
            <p class="text-sm text-base-secondary">{{ props.subject.title }}</p>
            <h1 class="text-2xl font-semibold break-words text-base-primary">
                {{ t('surveys.manage.results_heading', { title: props.survey.title }) }}
            </h1>
            <Link
                :href="props.backUrl"
                class="text-sm text-primary underline-offset-2 hover:underline"
            >
                {{ t('surveys.manage.back_to_surveys') }}
            </Link>
        </header>

        <section
            class="grid grid-cols-2 gap-4 rounded-lg border border-neutral-200 bg-surface p-4 sm:p-6"
            :aria-label="t('surveys.manage.results_participation')"
        >
            <div>
                <p class="text-sm text-base-secondary">
                    {{ t('surveys.manage.results_responses') }}
                </p>
                <p
                    class="text-2xl font-semibold tabular-nums text-base-primary"
                    data-testid="responses"
                >
                    {{
                        t('surveys.manage.results_of_audience', {
                            responses: props.results.responses,
                            audience: props.results.audienceSize,
                        })
                    }}
                </p>
            </div>
            <div>
                <p class="text-sm text-base-secondary">{{ t('surveys.manage.results_rate') }}</p>
                <p class="text-2xl font-semibold tabular-nums text-base-primary" data-testid="rate">
                    {{ rate ?? '—' }}
                </p>
            </div>
            <p class="col-span-2 text-sm text-base-secondary">
                {{
                    props.survey.anonymous
                        ? t('surveys.manage.results_anonymous')
                        : t('surveys.manage.results_named')
                }}
            </p>
        </section>

        <p
            v-if="hiddenNotice"
            class="rounded-lg bg-info-bg p-4 text-sm text-base-primary"
            data-testid="hidden-notice"
        >
            {{ hiddenNotice }}
        </p>

        <article
            v-for="question in props.results.questions"
            :key="question.id"
            class="space-y-4 rounded-lg border border-neutral-200 bg-surface p-4 sm:p-6"
            :class="{ 'opacity-60': isHidden(question.id) }"
            :data-question="question.id"
        >
            <div>
                <h2 class="font-medium break-words text-base-primary">{{ question.title }}</h2>
                <p class="mt-1 text-sm text-base-secondary">
                    {{
                        t(
                            'surveys.results.answered',
                            { count: question.answered },
                            question.answered
                        )
                    }}
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
            </div>

            <QuestionChart
                v-if="!isHidden(question.id)"
                :question="question"
                :chart-type="chartTypes[question.id] ?? question.chartType"
            />
            <p v-else class="text-sm text-base-secondary">
                {{ t('surveys.manage.results_will_hide') }}
            </p>

            <div
                class="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-neutral-200 pt-3"
            >
                <fieldset
                    v-if="question.chartTypes.length > 1"
                    class="flex flex-wrap items-center gap-3"
                >
                    <legend class="sr-only">{{ t('surveys.manage.results_chart') }}</legend>
                    <span class="text-sm text-base-secondary" aria-hidden="true">
                        {{ t('surveys.manage.results_chart') }}
                    </span>
                    <label
                        v-for="type in question.chartTypes"
                        :key="type"
                        class="inline-flex items-center gap-1.5 text-sm text-base-primary"
                    >
                        <input
                            v-model="chartTypes[question.id]"
                            type="radio"
                            :name="`chart-${question.id}`"
                            :value="type"
                            class="accent-primary-600"
                        />
                        {{ t(`surveys.manage.chart_types.${type}`) }}
                    </label>
                </fieldset>
                <FormCheckbox
                    :id="`hide-${question.id}`"
                    :model-value="isHidden(question.id)"
                    :label="t('surveys.manage.results_hide')"
                    @update:model-value="setHidden(question.id, $event)"
                />
            </div>
        </article>

        <section
            v-if="props.hiddenQuestions.length > 0"
            class="space-y-3 rounded-lg border border-neutral-200 bg-surface p-4 sm:p-6"
        >
            <h2 class="font-medium text-base-primary">
                {{ t('surveys.manage.results_hidden_title') }}
            </h2>
            <p class="text-sm text-base-secondary">{{ t('surveys.manage.results_hidden_hint') }}</p>
            <ul class="space-y-2">
                <li v-for="question in props.hiddenQuestions" :key="question.id">
                    <FormCheckbox
                        :id="`hidden-${question.id}`"
                        :model-value="isHidden(question.id)"
                        :label="question.title"
                        @update:model-value="setHidden(question.id, $event)"
                    />
                </li>
            </ul>
        </section>

        <div class="flex justify-end">
            <BaseButton :loading="saving" :disabled="saving" @click="save">
                {{ t('surveys.manage.results_save') }}
            </BaseButton>
        </div>
    </div>
</template>
