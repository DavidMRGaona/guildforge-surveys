<script setup lang="ts">
import { Download, FileText } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import type { OrganizerQuestionResult } from '../../types/Results';
import { fileSize } from '../../utils/files';
import { FOCUS_RING_CLASS } from '../styles';

const props = defineProps<{
    question: OrganizerQuestionResult;
}>();

const { t, locale } = useI18n();
</script>

<template>
    <div class="space-y-3">
        <p class="text-sm text-base-secondary">{{ t('surveys.results.files.private') }}</p>
        <p v-if="props.question.belowMinimum" class="text-sm text-base-secondary">
            {{ t('surveys.results.below_minimum') }}
        </p>
        <p v-else-if="props.question.files.length === 0" class="text-sm text-base-secondary">
            {{ t('surveys.results.files.none') }}
        </p>
        <ul v-else class="grid gap-3 sm:grid-cols-2" data-testid="answer-files">
            <li
                v-for="file in props.question.files"
                :key="file.id"
                class="flex items-center gap-3 rounded-lg bg-muted p-2"
                data-testid="answer-file"
            >
                <!-- Images open in a new tab; the link names the file for screen readers -->
                <a
                    v-if="file.previewUrl"
                    :href="file.previewUrl"
                    target="_blank"
                    rel="noopener"
                    :class="['shrink-0 rounded-md', FOCUS_RING_CLASS]"
                    :aria-label="t('surveys.results.files.preview', { name: file.name })"
                >
                    <img
                        :src="file.previewUrl"
                        alt=""
                        loading="lazy"
                        class="h-16 w-16 rounded-md object-cover"
                    />
                </a>
                <span
                    v-else
                    class="flex h-16 w-16 shrink-0 items-center justify-center rounded-md bg-surface text-base-secondary"
                    aria-hidden="true"
                >
                    <FileText class="h-6 w-6" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium break-all text-base-primary">{{ file.name }}</p>
                    <p class="text-sm text-base-secondary">{{ fileSize(file.size, locale) }}</p>
                </div>
                <a
                    :href="file.url"
                    download
                    :class="[
                        'inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-md text-primary hover:bg-surface',
                        FOCUS_RING_CLASS,
                    ]"
                    :aria-label="`${t('surveys.results.files.download')}: ${file.name}`"
                >
                    <Download class="h-5 w-5" aria-hidden="true" />
                </a>
            </li>
        </ul>
    </div>
</template>
