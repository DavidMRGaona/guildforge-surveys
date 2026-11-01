<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Services;

use Modules\Surveys\Domain\ValueObjects\Definition\Question;
use Modules\Surveys\Domain\ValueObjects\Definition\QuizSettings;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\Submission\AnswerValue;
use Modules\Surveys\Domain\ValueObjects\Submission\QuizScore;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;

/**
 * Scores a response to a quiz. A question gives its points only when the options chosen are
 * exactly its correct ones: a missing or an extra option scores nothing, and so does a question
 * left unanswered or not reached.
 */
final class QuizScorer
{
    public function score(SurveyDefinition $definition, Submission $submission): QuizScore
    {
        $points = 0;
        $correct = [];
        foreach ($this->scoredQuestions($definition) as $question) {
            $right = $this->isCorrect($question, $submission->valuesFor($question->id));
            $correct[$question->id] = $right;
            $points += $right ? (int) $question->points : 0;
        }

        return new QuizScore($points, $this->maxPoints($definition), $correct);
    }

    public function maxPoints(SurveyDefinition $definition): int
    {
        return array_sum(array_map(static fn (Question $question): int => (int) $question->points, $this->scoredQuestions($definition)));
    }

    /**
     * @return list<Question>
     */
    public function scoredQuestions(SurveyDefinition $definition): array
    {
        if (! $definition->quiz instanceof QuizSettings) {
            return [];
        }

        return array_values(array_filter($definition->questions(), static fn (Question $question): bool => $question->isScored()));
    }

    /**
     * @param  list<AnswerValue>  $values
     */
    public function isCorrect(Question $question, array $values): bool
    {
        $chosen = [];
        foreach ($values as $value) {
            // "Other" is never right
            if ($value->optionId === null) {
                return false;
            }
            $chosen[] = $value->optionId;
        }

        $chosen = array_values(array_unique($chosen));
        $expected = $question->correctOptionIds;
        sort($chosen);
        sort($expected);

        return $chosen !== [] && $chosen === $expected;
    }
}
