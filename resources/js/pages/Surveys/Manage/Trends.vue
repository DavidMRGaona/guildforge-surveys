<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { FormSelect } from '@/components/form';
import EmptyState from '@/components/ui/EmptyState.vue';
import TrendChart from '../../../components/results/TrendChart.vue';
import type {
    TrendComparison,
    TrendFilters,
    TrendPeriod,
    TrendsPageProps,
} from '../../../types/Trends';

const props = defineProps<TrendsPageProps>();

const { t } = useI18n();

const PERIODS: TrendPeriod[] = ['month', 'quarter', 'year'];
const COMPARISONS: TrendComparison[] = ['none', 'game_system', 'subject_type'];

const templateOptions = computed(() =>
    props.templates.map((template) => ({ value: template.id, label: template.name }))
);
const typeOptions = computed(() => props.options.subjectTypes);
const systemOptions = computed(() =>
    props.options.gameSystems.map((system) => ({ value: system, label: system }))
);
const periodOptions = PERIODS.map((period) => ({
    value: period,
    label: t(`surveys.trends.periods.${period}`),
}));
const comparisonOptions = COMPARISONS.map((comparison) => ({
    value: comparison,
    label: t(`surveys.trends.comparisons.${comparison}`),
}));

const inputClass =
    'mt-1 w-full rounded-lg border border-neutral-300 bg-surface px-4 py-2.5 text-base-primary focus:border-primary-500 focus:ring-2 focus:ring-primary-500 focus:outline-none';

/**
 * Visits the page with the new cut. A new template drops the filters taken from the last one.
 */
function apply(changes: Partial<TrendFilters> & { template?: string | null }): void {
    const templateChanged = changes.template !== undefined && changes.template !== props.templateId;
    const next: Record<string, string | null> = {
        template: props.templateId,
        ...props.filters,
        ...(templateChanged ? { type: null, system: null } : {}),
        ...changes,
    };

    const query: Record<string, string> = {};
    for (const [key, value] of Object.entries(next)) {
        if (value !== null && value !== '') {
            query[key] = value;
        }
    }

    router.get(props.url, query, { preserveState: true, preserveScroll: true, replace: true });
}

function setPeriod(value: string | number | null): void {
    const period = PERIODS.find((one) => one === value);
    if (period !== undefined) {
        apply({ period });
    }
}

function setComparison(value: string | number | null): void {
    const compare = COMPARISONS.find((one) => one === value);
    if (compare !== undefined) {
        apply({ compare });
    }
}

function text(value: string | number | null): string | null {
    return value === null || value === '' ? null : String(value);
}

function dateValue(event: Event): string | null {
    return text(event.target instanceof HTMLInputElement ? event.target.value : null);
}
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-6 px-4 py-12">
        <header class="space-y-1">
            <h1 class="text-2xl font-semibold break-words text-base-primary">
                {{ t('surveys.trends.title') }}
            </h1>
            <p class="text-sm text-base-secondary">{{ t('surveys.trends.intro') }}</p>
        </header>

        <EmptyState
            v-if="props.templates.length === 0"
            icon="document"
            :title="t('surveys.trends.empty_title')"
            :description="t('surveys.trends.empty_description')"
        />

        <template v-else>
            <section
                class="grid gap-4 rounded-lg border border-neutral-200 bg-surface p-4 sm:grid-cols-2 sm:p-6"
                :aria-label="t('surveys.trends.filters')"
                data-testid="filters"
            >
                <div class="sm:col-span-2">
                    <label
                        for="trends-template"
                        class="block text-sm font-medium text-base-primary"
                    >
                        {{ t('surveys.trends.template') }}
                    </label>
                    <FormSelect
                        id="trends-template"
                        class="mt-1"
                        :model-value="props.templateId"
                        :options="templateOptions"
                        @update:model-value="apply({ template: text($event) })"
                    />
                </div>
                <div>
                    <label for="trends-type" class="block text-sm font-medium text-base-primary">
                        {{ t('surveys.trends.type') }}
                    </label>
                    <FormSelect
                        id="trends-type"
                        class="mt-1"
                        :model-value="props.filters.type"
                        :options="typeOptions"
                        :placeholder="t('surveys.trends.all')"
                        @update:model-value="apply({ type: text($event) })"
                    />
                </div>
                <div>
                    <label for="trends-system" class="block text-sm font-medium text-base-primary">
                        {{ t('surveys.trends.system') }}
                    </label>
                    <FormSelect
                        id="trends-system"
                        class="mt-1"
                        :model-value="props.filters.system"
                        :options="systemOptions"
                        :placeholder="t('surveys.trends.all')"
                        @update:model-value="apply({ system: text($event) })"
                    />
                </div>
                <div>
                    <label for="trends-from" class="block text-sm font-medium text-base-primary">
                        {{ t('surveys.trends.from') }}
                    </label>
                    <input
                        id="trends-from"
                        type="date"
                        :class="inputClass"
                        :value="props.filters.from ?? ''"
                        @change="apply({ from: dateValue($event) })"
                    />
                </div>
                <div>
                    <label for="trends-to" class="block text-sm font-medium text-base-primary">
                        {{ t('surveys.trends.to') }}
                    </label>
                    <input
                        id="trends-to"
                        type="date"
                        :class="inputClass"
                        :value="props.filters.to ?? ''"
                        @change="apply({ to: dateValue($event) })"
                    />
                </div>
                <div>
                    <label for="trends-period" class="block text-sm font-medium text-base-primary">
                        {{ t('surveys.trends.period') }}
                    </label>
                    <FormSelect
                        id="trends-period"
                        class="mt-1"
                        :model-value="props.filters.period"
                        :options="periodOptions"
                        @update:model-value="setPeriod"
                    />
                </div>
                <div>
                    <label for="trends-compare" class="block text-sm font-medium text-base-primary">
                        {{ t('surveys.trends.compare') }}
                    </label>
                    <FormSelect
                        id="trends-compare"
                        class="mt-1"
                        :model-value="props.filters.compare"
                        :options="comparisonOptions"
                        @update:model-value="setComparison"
                    />
                </div>
            </section>

            <section
                v-if="props.trends"
                class="space-y-1 rounded-lg border border-neutral-200 bg-surface p-4 sm:p-6"
                :aria-label="t('surveys.trends.summary_label')"
            >
                <p class="text-lg font-semibold text-base-primary" data-testid="summary">
                    {{
                        t(
                            'surveys.trends.surveys',
                            { count: props.trends.surveys },
                            props.trends.surveys
                        )
                    }}
                    ·
                    {{
                        t(
                            'surveys.trends.responses',
                            { count: props.trends.responses },
                            props.trends.responses
                        )
                    }}
                </p>
                <p class="text-sm text-base-secondary">{{ t('surveys.trends.rule') }}</p>
            </section>

            <p
                v-if="props.trends && !props.trends.visible"
                class="rounded-lg bg-info-bg p-4 text-sm text-base-primary"
                data-testid="below-minimum"
            >
                {{ t('surveys.trends.below_minimum') }}
            </p>

            <article
                v-for="question in props.trends?.questions ?? []"
                :key="question.key"
                class="space-y-4 rounded-lg border border-neutral-200 bg-surface p-4 sm:p-6"
                :data-question="question.key"
            >
                <div>
                    <h2 class="font-medium break-words text-base-primary">{{ question.title }}</h2>
                    <p v-if="question.measure !== 'none'" class="mt-1 text-sm text-base-secondary">
                        {{ t(`surveys.trends.measures.${question.measure}`) }}
                    </p>
                </div>

                <p v-if="question.measure === 'none'" class="text-sm text-base-secondary">
                    {{ t('surveys.trends.no_trend') }}
                </p>
                <p v-else-if="question.series.length === 0" class="text-sm text-base-secondary">
                    {{ t('surveys.trends.no_data') }}
                </p>
                <template v-else>
                    <TrendChart :trend="question" />
                    <p class="text-sm text-base-secondary">
                        {{ t('surveys.trends.gaps', { minimum: props.minimum }) }}
                    </p>
                </template>
            </article>
        </template>
    </div>
</template>
