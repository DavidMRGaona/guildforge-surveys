<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { FormDatePicker, FormSelect } from '@/components/form';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '../../../components/PageHeader.vue';
import { CARD_CLASS, SECONDARY_ACTION_CLASS } from '../../../components/styles';
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

const DAY = /^\d{4}-\d{2}-\d{2}$/;

// On phones the filters fold away behind a button that says how many are in use
const filtersOpen = ref(false);
const activeFilters = computed(
    () =>
        [props.filters.type, props.filters.system, props.filters.from, props.filters.to].filter(
            (value) => value !== null
        ).length
);

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

/** A date filter changes once the picker holds a whole day, or nothing. */
function setDate(key: 'from' | 'to', value: string): void {
    if (value === '' || DAY.test(value)) {
        apply({ [key]: text(value) });
    }
}
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:py-12">
        <PageHeader :title="t('surveys.trends.title')">
            <p class="text-sm text-base-secondary">{{ t('surveys.trends.intro') }}</p>
        </PageHeader>

        <EmptyState
            v-if="props.templates.length === 0"
            icon="document"
            :title="t('surveys.trends.empty_title')"
            :description="t('surveys.trends.empty_description')"
        />

        <template v-else>
            <button
                type="button"
                :class="['sm:hidden', SECONDARY_ACTION_CLASS]"
                :aria-expanded="filtersOpen ? 'true' : 'false'"
                aria-controls="trends-filters"
                data-testid="filters-toggle"
                @click="filtersOpen = !filtersOpen"
            >
                <span class="flex-1 text-left">
                    {{
                        t('surveys.trends.filters_toggle', { count: activeFilters }, activeFilters)
                    }}
                </span>
                <svg
                    class="h-5 w-5 shrink-0 transition-transform"
                    :class="{ 'rotate-180': filtersOpen }"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    aria-hidden="true"
                >
                    <path
                        fill-rule="evenodd"
                        d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z"
                        clip-rule="evenodd"
                    />
                </svg>
            </button>
            <section
                id="trends-filters"
                :class="[
                    CARD_CLASS,
                    'gap-4 p-4 sm:grid-cols-2 sm:p-6',
                    filtersOpen ? 'grid' : 'hidden sm:grid',
                ]"
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
                    <div class="mt-1">
                        <FormDatePicker
                            id="trends-from"
                            :model-value="props.filters.from ?? ''"
                            :max="props.filters.to ?? undefined"
                            @update:model-value="setDate('from', $event)"
                        />
                    </div>
                </div>
                <div>
                    <label for="trends-to" class="block text-sm font-medium text-base-primary">
                        {{ t('surveys.trends.to') }}
                    </label>
                    <div class="mt-1">
                        <FormDatePicker
                            id="trends-to"
                            :model-value="props.filters.to ?? ''"
                            :min="props.filters.from ?? undefined"
                            @update:model-value="setDate('to', $event)"
                        />
                    </div>
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
                :class="[CARD_CLASS, 'space-y-1 p-4 sm:p-6']"
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
                :class="[CARD_CLASS, 'space-y-4 p-4 sm:p-6']"
                :data-question="question.key"
            >
                <div>
                    <h2 class="text-lg font-semibold break-words text-base-primary">
                        {{ question.title }}
                    </h2>
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
