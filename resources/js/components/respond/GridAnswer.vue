<script setup lang="ts">
import { computed } from 'vue';
import type { SurveyOption } from '../../types/Respond';

/**
 * A grid answered row by row, each row a group of radios of its own. On a phone a row is
 * its label over the columns as labelled pills; from `sm` up the rows line up as a matrix
 * under one header of columns, and each cell shows only its dot (the column name stays
 * for screen readers).
 *
 * Phone and matrix styles use exclusive ranges (`max-sm:` and `sm:`), never a base class
 * with an `sm:` override: this sheet sits in a lower cascade layer than the core's, so a
 * base class the core also emits (`flex`, `mb-2`, `rounded-full`…) beats any override
 * written here, and the cells would all pile up in the first column.
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
            class="gap-2 pb-2 text-center text-sm font-medium text-base-secondary max-sm:hidden sm:grid"
            data-testid="grid-header"
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
            <p :id="rowLabelId(row.id)" class="font-medium text-base-primary max-sm:mb-2">
                {{ row.label }}
            </p>
            <div
                class="max-sm:flex max-sm:flex-wrap max-sm:gap-2 sm:contents"
                data-testid="grid-cells"
            >
                <label
                    v-for="column in columns"
                    :key="column.id"
                    class="flex min-h-11 cursor-pointer items-center justify-center gap-2 border-2 transition-colors duration-150 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary-500 max-sm:rounded-full max-sm:px-4 sm:rounded-lg sm:border-transparent"
                    :class="
                        cells[row.id] === column.id
                            ? 'max-sm:border-brand max-sm:bg-brand-light'
                            : 'max-sm:border-line max-sm:bg-surface max-sm:hover:bg-muted'
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
                        class="h-5 w-5 items-center justify-center rounded-full border-2 max-sm:hidden sm:flex"
                        :class="
                            cells[row.id] === column.id
                                ? 'border-brand bg-brand'
                                : 'border-line-strong bg-surface'
                        "
                    >
                        <span
                            v-if="cells[row.id] === column.id"
                            class="h-2 w-2 rounded-full bg-current text-white"
                        ></span>
                    </span>
                    <span class="text-sm text-base-primary sm:sr-only">{{ column.label }}</span>
                </label>
            </div>
        </div>
    </div>
</template>
