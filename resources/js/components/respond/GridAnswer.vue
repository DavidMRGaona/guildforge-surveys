<script setup lang="ts">
import { computed } from 'vue';
import type { SurveyOption } from '../../types/Respond';

/**
 * A grid answered row by row, each row a group of radios of its own. On a phone a row is
 * its label over the columns as labelled pills; from `sm` up the rows line up as a matrix
 * under one header of columns, and each cell shows only its dot (the column name stays
 * for screen readers).
 */
const props = defineProps<{
    name: string;
    rows: SurveyOption[];
    columns: SurveyOption[];
    cells: Record<string, string>;
}>();

const emit = defineEmits<{
    choose: [rowId: string, columnId: string];
}>();

const layout = computed(() => ({
    gridTemplateColumns: `minmax(8rem, 1.5fr) repeat(${props.columns.length}, minmax(3rem, 1fr))`,
}));

function rowLabelId(rowId: string): string {
    return `${props.name}-${rowId}-label`;
}
</script>

<template>
    <div class="mt-4">
        <div
            class="hidden gap-2 pb-2 text-center text-sm font-medium text-base-secondary sm:grid"
            :style="layout"
            aria-hidden="true"
        >
            <span></span>
            <span v-for="column in columns" :key="column.id">{{ column.label }}</span>
        </div>
        <div
            v-for="row in rows"
            :key="row.id"
            role="radiogroup"
            :aria-labelledby="rowLabelId(row.id)"
            class="border-t border-line py-3 sm:grid sm:items-center sm:gap-2"
            :style="layout"
        >
            <p :id="rowLabelId(row.id)" class="mb-2 font-medium text-base-primary sm:mb-0">
                {{ row.label }}
            </p>
            <div class="flex flex-wrap gap-2 sm:contents">
                <label
                    v-for="column in columns"
                    :key="column.id"
                    class="flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-full border-2 px-4 transition-colors duration-150 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary-500 sm:rounded-lg sm:border-transparent sm:bg-transparent sm:px-0"
                    :class="
                        cells[row.id] === column.id
                            ? 'border-brand bg-brand-light'
                            : 'border-line bg-surface hover:bg-muted'
                    "
                >
                    <input
                        type="radio"
                        class="sr-only"
                        :name="`${name}-${row.id}`"
                        :value="column.id"
                        :checked="cells[row.id] === column.id"
                        @change="emit('choose', row.id, column.id)"
                    />
                    <span
                        aria-hidden="true"
                        class="hidden h-5 w-5 items-center justify-center rounded-full border-2 sm:flex"
                        :class="
                            cells[row.id] === column.id
                                ? 'border-brand bg-brand'
                                : 'border-line-strong bg-surface'
                        "
                    >
                        <span
                            v-if="cells[row.id] === column.id"
                            class="h-2 w-2 rounded-full bg-white"
                        ></span>
                    </span>
                    <span class="text-sm text-base-primary sm:sr-only">{{ column.label }}</span>
                </label>
            </div>
        </div>
    </div>
</template>
