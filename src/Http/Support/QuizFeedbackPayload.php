<?php

declare(strict_types=1);

namespace Modules\Surveys\Http\Support;

use Modules\Surveys\Domain\Services\QuizScorer;
use Modules\Surveys\Domain\ValueObjects\Definition\Option;
use Modules\Surveys\Domain\ValueObjects\Definition\Question;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\Submission\QuizScore;

/**
 * What whoever answered a quiz sees right after sending it: their score and, when the quiz
 * shows them, which questions they got right and the correct answers. It is only shown once,
 * so on an anonymous survey nothing ties the score to the person afterwards.
 */
final class QuizFeedbackPayload
{
    /**
     * @return array{points: int, maxPoints: int, questions: list<array{id: string, title: string, correct: bool, points: int, correctAnswers: list<string>}>|null}
     */
    public static function for(SurveyDefinition $definition, QuizScore $score): array
    {
        $questions = null;
        if ($definition->quiz?->showCorrectAnswers === true) {
            $questions = array_map(static fn (Question $question): array => [
                'id' => $question->id,
                'title' => $question->title,
                'correct' => $score->correct[$question->id] ?? false,
                'points' => (int) $question->points,
                'correctAnswers' => array_values(array_map(
                    static fn (Option $option): string => $option->label,
                    array_filter($question->options, static fn (Option $option): bool => in_array($option->id, $question->correctOptionIds, true)),
                )),
            ], (new QuizScorer)->scoredQuestions($definition));
        }

        return ['points' => $score->points, 'maxPoints' => $score->maxPoints, 'questions' => $questions];
    }
}
