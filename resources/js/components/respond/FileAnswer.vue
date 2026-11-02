<script setup lang="ts">
import { Paperclip, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { FILE_CONTENT_TYPES, type FileSettings } from '../../types/Respond';
import { FOCUS_RING_CLASS } from '../styles';

const props = defineProps<{
    /** Id of the file input, which the question's title and help describe. */
    id: string;
    settings: FileSettings;
    file: File | null;
    describedBy?: string | undefined;
}>();

const emit = defineEmits<{
    choose: [file: File | null];
}>();

const { t, locale } = useI18n();

const input = ref<HTMLInputElement | null>(null);
const problem = ref<string | null>(null);

const contentTypes = computed(() =>
    props.settings.accept.flatMap((kind) => FILE_CONTENT_TYPES[kind])
);
const kinds = computed(() =>
    new Intl.ListFormat(locale.value, { style: 'long', type: 'disjunction' }).format(
        props.settings.accept.map((kind) => t(`surveys.respond.file.kinds.${kind}`))
    )
);

function size(bytes: number): string {
    const megabytes = bytes / (1024 * 1024);

    return megabytes >= 1
        ? `${new Intl.NumberFormat(locale.value, { maximumFractionDigits: 1 }).format(megabytes)} MB`
        : `${new Intl.NumberFormat(locale.value).format(Math.max(1, Math.ceil(bytes / 1024)))} KB`;
}

/** A first check in the browser; the server reads the contents and decides. */
function choose(event: Event): void {
    const chosen = (event.target as HTMLInputElement).files?.[0] ?? null;
    problem.value = null;
    if (chosen !== null && !contentTypes.value.includes(chosen.type)) {
        problem.value = t('surveys.respond.file.wrong_type', { kinds: kinds.value });
    } else if (chosen !== null && chosen.size > props.settings.max_mb * 1024 * 1024) {
        problem.value = t('surveys.respond.file.too_large', { max: props.settings.max_mb });
    }
    emit('choose', problem.value === null ? chosen : null);
}

function remove(): void {
    problem.value = null;
    if (input.value !== null) {
        input.value.value = '';
    }
    emit('choose', null);
}
</script>

<template>
    <div class="mt-4 space-y-2">
        <label
            :class="[
                'inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border-2 border-line bg-surface px-4 text-sm font-medium text-base-primary transition-colors hover:bg-muted focus-within:ring-2 focus-within:ring-primary-500 focus-within:ring-offset-2 focus-within:ring-offset-page',
            ]"
        >
            <Paperclip class="h-4 w-4" aria-hidden="true" />
            {{ props.file ? t('surveys.respond.file.change') : t('surveys.respond.file.choose') }}
            <input
                :id="props.id"
                ref="input"
                type="file"
                class="sr-only"
                :accept="contentTypes.join(',')"
                :aria-describedby="props.describedBy"
                @change="choose"
            />
        </label>
        <p
            v-if="props.file"
            class="flex flex-wrap items-center gap-x-2 text-sm text-base-primary"
            data-testid="chosen-file"
        >
            <span class="min-w-0 break-all">{{ props.file.name }}</span>
            <span class="text-base-secondary">{{ size(props.file.size) }}</span>
            <button
                type="button"
                :class="[
                    'inline-flex min-h-11 items-center gap-1 rounded-sm px-1 font-medium text-primary underline-offset-2 hover:underline',
                    FOCUS_RING_CLASS,
                ]"
                @click="remove"
            >
                <X class="h-4 w-4" aria-hidden="true" />
                {{ t('surveys.respond.file.remove') }}
            </button>
        </p>
        <p class="text-sm text-base-secondary">
            {{ t('surveys.respond.file.hint', { kinds, max: props.settings.max_mb }) }}
        </p>
        <p v-if="problem" class="text-sm font-medium text-error" role="alert">{{ problem }}</p>
    </div>
</template>
