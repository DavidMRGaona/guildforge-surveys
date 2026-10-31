<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { EditorLocks } from '../../types/Editor';
import type { SurveyDefinition, SurveySection } from '../../types/Respond';
import { move, newSection } from '../../utils/definition';
import SectionEditor from './SectionEditor.vue';
import { ITEM_BUTTON_CLASS } from './styles';

const props = defineProps<{
    locks: EditorLocks;
    /** The server's errors, keyed "definition.<path>". */
    errors: Record<string, string>;
}>();

const definition = defineModel<SurveyDefinition>({ required: true });

const { t } = useI18n();

function setSections(sections: SurveySection[]): void {
    definition.value = { ...definition.value, sections };
}

function setSection(index: number, section: SurveySection): void {
    setSections(definition.value.sections.map((current, i) => (i === index ? section : current)));
}
</script>

<template>
    <div class="space-y-6">
        <p v-if="props.errors['definition.sections']" class="text-sm text-error" role="alert">
            {{ props.errors['definition.sections'] }}
        </p>

        <section
            v-for="(section, index) in definition.sections"
            :key="section.id"
            class="space-y-3 rounded-lg border border-neutral-200 bg-muted p-4 sm:p-6"
        >
            <div
                v-if="!props.locks.structure && !props.locks.poll && definition.sections.length > 1"
                class="flex justify-end gap-1"
            >
                <button
                    type="button"
                    :class="ITEM_BUTTON_CLASS"
                    :disabled="index === 0"
                    :aria-label="t('surveys.editor.move_section_up')"
                    @click="setSections(move(definition.sections, index, -1))"
                >
                    ↑
                </button>
                <button
                    type="button"
                    :class="ITEM_BUTTON_CLASS"
                    :disabled="index === definition.sections.length - 1"
                    :aria-label="t('surveys.editor.move_section_down')"
                    @click="setSections(move(definition.sections, index, 1))"
                >
                    ↓
                </button>
                <button
                    type="button"
                    :class="ITEM_BUTTON_CLASS"
                    :aria-label="t('surveys.editor.remove_section')"
                    @click="setSections(definition.sections.filter((_, i) => i !== index))"
                >
                    ✕
                </button>
            </div>
            <SectionEditor
                :model-value="section"
                :locks="props.locks"
                :errors="props.errors"
                :path="`sections.${index}`"
                :number="index + 1"
                @update:model-value="setSection(index, $event)"
            />
        </section>

        <button
            v-if="!props.locks.structure && !props.locks.poll"
            type="button"
            class="text-sm font-medium text-primary hover:underline"
            @click="setSections([...definition.sections, newSection()])"
        >
            {{ t('surveys.editor.add_section') }}
        </button>
    </div>
</template>
