<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { EditorLocks } from '../../types/Editor';
import type { SurveyDefinition, SurveySection } from '../../types/Respond';
import { move, newSection, withoutSection } from '../../utils/definition';
import SectionEditor from './SectionEditor.vue';
import { ADD_BUTTON_CLASS, ICON_BUTTON_CLASS } from '../styles';
import PlusIcon from '../icons/PlusIcon.vue';

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
            class="space-y-4 rounded-lg border border-default bg-muted p-3 sm:p-6"
            :aria-labelledby="props.locks.poll ? undefined : `section-${section.id}-heading`"
        >
            <div v-if="!props.locks.poll" class="flex items-center justify-between gap-2">
                <h3
                    :id="`section-${section.id}-heading`"
                    class="text-base font-semibold text-base-primary"
                >
                    {{ t('surveys.editor.section', { number: index + 1 }) }}
                </h3>
                <div
                    v-if="!props.locks.structure && definition.sections.length > 1"
                    class="flex gap-1"
                >
                    <button
                        type="button"
                        :class="ICON_BUTTON_CLASS"
                        :disabled="index === 0"
                        :aria-label="t('surveys.editor.move_section_up')"
                        @click="setSections(move(definition.sections, index, -1))"
                    >
                        ↑
                    </button>
                    <button
                        type="button"
                        :class="ICON_BUTTON_CLASS"
                        :disabled="index === definition.sections.length - 1"
                        :aria-label="t('surveys.editor.move_section_down')"
                        @click="setSections(move(definition.sections, index, 1))"
                    >
                        ↓
                    </button>
                    <button
                        type="button"
                        :class="ICON_BUTTON_CLASS"
                        :aria-label="t('surveys.editor.remove_section')"
                        @click="setSections(withoutSection(definition.sections, section.id))"
                    >
                        ✕
                    </button>
                </div>
            </div>
            <SectionEditor
                :model-value="section"
                :locks="props.locks"
                :errors="props.errors"
                :path="`sections.${index}`"
                :sections="definition.sections"
                @update:model-value="setSection(index, $event)"
            />
        </section>

        <button
            v-if="!props.locks.structure && !props.locks.poll"
            type="button"
            :class="['w-full sm:w-auto', ADD_BUTTON_CLASS]"
            @click="setSections([...definition.sections, newSection()])"
        >
            <PlusIcon />
            {{ t('surveys.editor.add_section') }}
        </button>
    </div>
</template>
