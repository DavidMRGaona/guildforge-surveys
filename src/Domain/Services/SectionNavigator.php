<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Services;

use Modules\Surveys\Domain\ValueObjects\Definition\Question;
use Modules\Surveys\Domain\ValueObjects\Definition\Section;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;

/**
 * Walks a survey the way someone answering it does: from the first section, each section
 * leads to the one its jumping question's chosen option names, else to its own next, else
 * to the following one, until a jump to Section::SUBMIT or the last section.
 *
 * The response page mirrors this in TypeScript (utils/navigation.ts); both are tested
 * against tests/fixtures/navigation-cases.json. A jump that is not forward (an invalid
 * definition) is ignored, so the walk always ends.
 */
final class SectionNavigator
{
    /**
     * @return list<string>
     */
    public function reachedSectionIds(SurveyDefinition $definition, Submission $submission): array
    {
        return array_map(static fn (Section $section): string => $section->id, $this->reachedSections($definition, $submission));
    }

    /**
     * @return list<string>
     */
    public function reachedQuestionIds(SurveyDefinition $definition, Submission $submission): array
    {
        $ids = [];
        foreach ($this->reachedSections($definition, $submission) as $section) {
            foreach ($section->questions as $question) {
                $ids[] = $question->id;
            }
        }

        return $ids;
    }

    /**
     * @return list<Section>
     */
    private function reachedSections(SurveyDefinition $definition, Submission $submission): array
    {
        $reached = [];
        $index = 0;
        $count = count($definition->sections);

        while ($index < $count) {
            $section = $definition->sections[$index];
            $reached[] = $section;

            $next = $this->chosenJump($section->branchingQuestion(), $submission) ?? $section->next;
            if ($next === Section::SUBMIT) {
                break;
            }

            $target = $next === null ? null : $definition->sectionIndex($next);
            $index = $target !== null && $target > $index ? $target : $index + 1;
        }

        return $reached;
    }

    private function chosenJump(?Question $question, Submission $submission): ?string
    {
        if (! $question instanceof Question) {
            return null;
        }

        $value = $submission->valuesFor($question->id)[0] ?? null;
        if ($value === null) {
            return null;
        }

        return $question->nextFor($value->isOther() ? Submission::OTHER : (string) $value->optionId);
    }
}
