<script setup lang="ts">
import { ChevronDown, ChevronUp, GripVertical, Plus, RotateCcw, X } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { SurveyOption } from '../../types/Respond';
import { move } from '../../utils/definition';

/**
 * A ranking. The options are tapped in order of preference, then reordered with the arrows or
 * by dragging the handle (pointer events, so fingers and mice alike). Nothing is placed until
 * the person starts, so an untouched question stays unanswered and the order shown is never
 * taken for an answer; the last option is placed by itself, since one place is left for it.
 * Every change is read out through the status region.
 */
const props = defineProps<{
    name: string;
    label: string;
    options: SurveyOption[];
    /** Ids of the placed options, best first. */
    order: string[];
}>();

const emit = defineEmits<{
    change: [order: string[]];
}>();

const { t } = useI18n();

const root = ref<HTMLElement | null>(null);
const announcement = ref('');
const dragging = ref<string | null>(null);

/** The placed options, without ids the question does not have (an old draft) or repeated. */
const placed = computed<SurveyOption[]>(() => {
    const placedOptions: SurveyOption[] = [];
    for (const id of props.order) {
        const option = props.options.find((candidate) => candidate.id === id);
        if (option !== undefined && !placedOptions.includes(option)) {
            placedOptions.push(option);
        }
    }

    return placedOptions;
});

const unplaced = computed(() => props.options.filter((option) => !placed.value.includes(option)));

function change(next: SurveyOption[]): void {
    emit(
        'change',
        next.map((option) => option.id)
    );
}

function placedAt(option: SurveyOption, position: number): string {
    return t('surveys.respond.ranking.announce', {
        label: option.label,
        position,
        total: props.options.length,
    });
}

async function place(option: SurveyOption): Promise<void> {
    const next = [...placed.value, option];
    let message = placedAt(option, next.length);
    const rest = unplaced.value.filter((candidate) => candidate !== option);
    const last = rest.length === 1 ? rest[0] : undefined;
    if (last !== undefined) {
        next.push(last);
        message += ` ${t('surveys.respond.ranking.last_placed', { label: last.label, position: next.length })}`;
    }
    change(next);
    announcement.value = message;

    await nextTick();
    // On to the next option to place, or to the order once it is complete
    const following = root.value?.querySelector<HTMLElement>('[data-unplaced]');
    (following ?? control(option.id, 'up') ?? control(option.id, 'down'))?.focus();
}

async function shift(index: number, delta: -1 | 1): Promise<void> {
    const option = placed.value[index];
    if (option === undefined) {
        return;
    }
    change(move(placed.value, index, delta));
    announcement.value = placedAt(option, index + delta + 1);

    await nextTick();
    // Moving the item takes the focus with the node: give it back, to the other arrow at an end
    const action = delta === -1 ? 'up' : 'down';
    const button = control(option.id, action);
    (button?.disabled === false
        ? button
        : control(option.id, action === 'up' ? 'down' : 'up')
    )?.focus();
}

async function remove(index: number): Promise<void> {
    const option = placed.value[index];
    if (option === undefined) {
        return;
    }
    change(placed.value.filter((candidate) => candidate !== option));
    announcement.value = t('surveys.respond.ranking.removed', { label: option.label });

    await nextTick();
    [...(root.value?.querySelectorAll<HTMLElement>('[data-unplaced]') ?? [])]
        .find((element) => element.dataset.unplaced === option.id)
        ?.focus();
}

async function clear(): Promise<void> {
    change([]);
    announcement.value = t('surveys.respond.ranking.cleared');

    await nextTick();
    root.value?.querySelector<HTMLElement>('[data-unplaced]')?.focus();
}

/**
 * The moves and the release are followed on the window: reordering moves the dragged item's
 * node, which loses the pointer capture, and the handle would stop hearing the pointer.
 */
function startDrag(event: PointerEvent, optionId: string): void {
    if (event.button !== 0) {
        return;
    }
    event.preventDefault();
    dragging.value = optionId;
    window.addEventListener('pointermove', drag);
    window.addEventListener('pointerup', endDrag);
    window.addEventListener('pointercancel', endDrag);
}

/**
 * The dragged option goes where the pointer is among the others: one place per option whose
 * middle the pointer has passed. Nothing glides while dragging, so the boxes are where they are.
 */
function drag(event: PointerEvent): void {
    const optionId = dragging.value;
    const option = placed.value.find((candidate) => candidate.id === optionId);
    if (option === undefined) {
        return;
    }

    const others = placed.value.filter((candidate) => candidate !== option);
    const target = others.filter((other) => {
        const box = item(other.id)?.getBoundingClientRect();

        return box !== undefined && event.clientY > box.top + box.height / 2;
    }).length;

    if (target !== placed.value.indexOf(option)) {
        change([...others.slice(0, target), option, ...others.slice(target)]);
    }
}

function endDrag(): void {
    stopListening();
    const option = placed.value.find((candidate) => candidate.id === dragging.value);
    dragging.value = null;
    if (option !== undefined) {
        announcement.value = placedAt(option, placed.value.indexOf(option) + 1);
    }
}

function stopListening(): void {
    window.removeEventListener('pointermove', drag);
    window.removeEventListener('pointerup', endDrag);
    window.removeEventListener('pointercancel', endDrag);
}

onBeforeUnmount(stopListening);

function item(optionId: string): HTMLElement | undefined {
    return [...(root.value?.querySelectorAll<HTMLElement>('[data-ranked]') ?? [])].find(
        (element) => element.dataset.ranked === optionId
    );
}

function control(optionId: string, action: 'up' | 'down'): HTMLButtonElement | null {
    return item(optionId)?.querySelector<HTMLButtonElement>(`[data-action="${action}"]`) ?? null;
}

const iconButton =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-base-secondary transition-colors duration-150 hover:bg-surface hover:text-base-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent';
</script>

<template>
    <div ref="root" class="mt-4 space-y-4">
        <p class="text-sm text-base-secondary">
            {{
                unplaced.length === 0
                    ? t('surveys.respond.ranking.reorder_hint')
                    : t('surveys.respond.ranking.hint')
            }}
        </p>

        <TransitionGroup
            v-if="placed.length > 0"
            tag="ol"
            class="space-y-2"
            :move-class="
                dragging === null
                    ? 'transition-transform duration-200 motion-reduce:transition-none'
                    : ''
            "
            :aria-label="t('surveys.respond.ranking.order', { label: props.label })"
        >
            <li
                v-for="(option, index) in placed"
                :key="option.id"
                :data-ranked="option.id"
                class="flex min-h-12 items-center gap-2 rounded-lg border-2 border-brand bg-brand-light py-1.5 pl-1.5 pr-1"
                :class="
                    dragging === option.id ? 'relative z-10 shadow-lg ring-2 ring-primary-500' : ''
                "
            >
                <span
                    data-action="drag"
                    class="flex h-9 w-6 shrink-0 cursor-grab touch-none select-none items-center justify-center text-base-secondary active:cursor-grabbing"
                    aria-hidden="true"
                    @pointerdown="startDrag($event, option.id)"
                >
                    <GripVertical class="h-5 w-5" />
                </span>
                <span
                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand text-sm font-semibold tabular-nums text-white"
                    aria-hidden="true"
                    >{{ index + 1 }}</span
                >
                <span class="min-w-0 flex-1 break-words py-1 text-base-primary">
                    <span class="sr-only">{{
                        t('surveys.respond.ranking.position', { position: index + 1 })
                    }}</span>
                    {{ option.label }}
                </span>
                <div class="flex shrink-0 items-center gap-0.5">
                    <button
                        type="button"
                        data-action="up"
                        :class="iconButton"
                        :disabled="index === 0"
                        :aria-label="t('surveys.respond.ranking.move_up', { label: option.label })"
                        @click="shift(index, -1)"
                    >
                        <ChevronUp class="h-5 w-5" aria-hidden="true" />
                    </button>
                    <button
                        type="button"
                        data-action="down"
                        :class="iconButton"
                        :disabled="index === placed.length - 1"
                        :aria-label="
                            t('surveys.respond.ranking.move_down', { label: option.label })
                        "
                        @click="shift(index, 1)"
                    >
                        <ChevronDown class="h-5 w-5" aria-hidden="true" />
                    </button>
                    <button
                        type="button"
                        data-action="remove"
                        :class="iconButton"
                        :aria-label="t('surveys.respond.ranking.remove', { label: option.label })"
                        @click="remove(index)"
                    >
                        <X class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>
            </li>
        </TransitionGroup>

        <div v-if="unplaced.length > 0">
            <p
                v-if="placed.length > 0"
                :id="`${props.name}-unplaced`"
                class="mb-2 text-sm font-medium text-base-secondary"
            >
                {{ t('surveys.respond.ranking.unplaced') }}
            </p>
            <ul class="grid gap-2">
                <li v-for="option in unplaced" :key="option.id">
                    <button
                        type="button"
                        :data-unplaced="option.id"
                        class="flex min-h-12 w-full items-center gap-3 rounded-lg border-2 border-dashed border-line bg-surface px-4 py-3 text-left text-base-primary transition-colors duration-150 hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                        :aria-label="
                            t('surveys.respond.ranking.place', {
                                label: option.label,
                                position: placed.length + 1,
                            })
                        "
                        @click="place(option)"
                    >
                        <Plus class="h-5 w-5 shrink-0 text-base-secondary" aria-hidden="true" />
                        <span class="min-w-0 break-words">{{ option.label }}</span>
                    </button>
                </li>
            </ul>
        </div>

        <button
            v-if="placed.length > 0"
            type="button"
            data-action="clear"
            class="inline-flex items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium text-base-secondary transition-colors duration-150 hover:bg-muted hover:text-base-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
            @click="clear"
        >
            <RotateCcw class="h-4 w-4" aria-hidden="true" />
            {{ t('surveys.respond.ranking.clear') }}
        </button>

        <p role="status" class="sr-only">{{ announcement }}</p>
    </div>
</template>
