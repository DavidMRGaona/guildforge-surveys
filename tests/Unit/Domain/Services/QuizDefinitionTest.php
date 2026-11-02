<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Unit\Domain\Services;

use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Services\QuizScorer;
use Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Modules\Surveys\Domain\ValueObjects\Definition\DefinitionViolation;
use Modules\Surveys\Domain\ValueObjects\Definition\QuizSettings;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuizDefinitionTest extends TestCase
{
    public function test_a_quiz_survives_the_round_trip_and_is_valid(): void
    {
        $definition = SurveyDefinition::fromArray(self::data());

        $this->assertEquals(new QuizSettings(showCorrectAnswers: true), $definition->quiz);
        $this->assertSame(['o-b'], $definition->questions()[0]->correctOptionIds);
        $this->assertSame(2, $definition->questions()[0]->points);
        $this->assertTrue($definition->questions()[0]->isScored());
        $this->assertFalse($definition->questions()[2]->isScored());
        $this->assertSame([], (new SurveyDefinitionValidator)->validate($definition, SurveyKind::Form));
        $this->assertSame(self::data(), $definition->toArray());
    }

    public function test_a_definition_stored_before_quizzes_reads_as_no_quiz(): void
    {
        $data = self::data();
        unset($data['quiz'], $data['sections'][0]['questions'][2]['correct_option_ids'], $data['sections'][0]['questions'][2]['points']);
        $data['quiz'] = null;

        $definition = SurveyDefinition::fromArray(['sections' => $data['sections']]);

        $this->assertNull($definition->quiz);
        $this->assertSame([], $definition->questions()[2]->correctOptionIds);
        $this->assertNull($definition->questions()[2]->points);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $break
     */
    #[DataProvider('brokenQuizzes')]
    public function test_reports_each_broken_quiz_rule(callable $break, string $path, string $messageKey, SurveyKind $kind = SurveyKind::Form): void
    {
        $violations = (new SurveyDefinitionValidator)->validate(SurveyDefinition::fromArray($break(self::data())), $kind);

        $this->assertContainsEquals(new DefinitionViolation($path, $messageKey), $violations);
    }

    /**
     * @return iterable<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string, 2: string, 3?: SurveyKind}>
     */
    public static function brokenQuizzes(): iterable
    {
        $q = static fn (int $index): string => "sections.0.questions.{$index}";
        $set = static function (array $d, int $index, string $field, mixed $value): array {
            $d['sections'][0]['questions'][$index][$field] = $value;

            return $d;
        };

        yield 'unknown correct option' => [static fn (array $d): array => $set($d, 0, 'correct_option_ids', ['o-z']), $q(0).'.correct_option_ids', 'unknown_correct_option'];
        yield 'two correct on single choice' => [static fn (array $d): array => $set($d, 0, 'correct_option_ids', ['o-a', 'o-b']), $q(0).'.correct_option_ids', 'too_many_correct_options'];
        yield 'correct on free text' => [static fn (array $d): array => $set($d, 2, 'correct_option_ids', ['o-a']), $q(2).'.correct_option_ids', 'correct_not_allowed'];
        yield 'points without correct' => [static fn (array $d): array => $set($d, 2, 'points', 3), $q(2).'.points', 'points_not_allowed'];
        yield 'no points' => [static fn (array $d): array => $set($d, 0, 'points', null), $q(0).'.points', 'invalid_points'];
        yield 'too many points' => [static fn (array $d): array => $set($d, 0, 'points', 101), $q(0).'.points', 'invalid_points'];
        yield 'zero points' => [static fn (array $d): array => $set($d, 0, 'points', 0), $q(0).'.points', 'invalid_points'];
        yield 'other on a scored question' => [static fn (array $d): array => $set($d, 1, 'allow_other', true), $q(1).'.allow_other', 'other_not_allowed_in_quiz'];
        yield 'nothing scored' => [static function (array $d) use ($set): array {
            $d = $set($d, 0, 'correct_option_ids', []);
            $d = $set($d, 0, 'points', null);
            $d = $set($d, 1, 'correct_option_ids', []);

            return $set($d, 1, 'points', null);
        }, 'quiz', 'quiz_without_scored_questions'];
        yield 'answers without quiz' => [static function (array $d): array {
            $d['quiz'] = null;

            return $d;
        }, $q(0).'.correct_option_ids', 'quiz_off'];
        yield 'a poll' => [static fn (array $d): array => $d, 'quiz', 'quiz_kind_only', SurveyKind::Poll];
    }

    public function test_a_question_scores_only_with_exactly_its_correct_options(): void
    {
        $definition = SurveyDefinition::fromArray(self::data());
        $scorer = new QuizScorer;

        $all = $scorer->score($definition, Submission::fromInput($definition, ['q-capital' => 'o-b', 'q-colours' => ['o-blue', 'o-red'], 'q-notes' => 'Fácil']));
        $this->assertSame([5, 5], [$all->points, $all->maxPoints]);
        $this->assertSame(['q-capital' => true, 'q-colours' => true], $all->correct);

        $missingOne = $scorer->score($definition, Submission::fromInput($definition, ['q-capital' => 'o-b', 'q-colours' => ['o-blue']]));
        $this->assertSame(2, $missingOne->points);
        $this->assertSame(['q-capital' => true, 'q-colours' => false], $missingOne->correct);

        $oneTooMany = $scorer->score($definition, Submission::fromInput($definition, ['q-capital' => 'o-a', 'q-colours' => ['o-blue', 'o-red', 'o-green']]));
        $this->assertSame(0, $oneTooMany->points);

        $nothing = $scorer->score($definition, Submission::fromInput($definition, []));
        $this->assertSame([0, 5], [$nothing->points, $nothing->maxPoints]);
    }

    /**
     * A capital (single choice, 2 points), two colours (checkboxes, 3 points) and an unscored note.
     *
     * @return array<string, mixed>
     */
    public static function data(): array
    {
        $question = static fn (string $id, string $type, array $options, array $correct, ?int $points): array => [
            'id' => $id, 'key' => null, 'type' => $type, 'title' => "Pregunta {$id}", 'help' => null, 'required' => false,
            'options' => $options, 'allow_other' => false, 'scale' => null, 'stars' => null, 'grid' => null,
            'next_by_option' => [], 'slots' => [], 'correct_option_ids' => $correct, 'points' => $points, 'file' => null,
        ];

        return [
            'sections' => [[
                'id' => 's-1',
                'title' => null,
                'description' => null,
                'next' => null,
                'questions' => [
                    $question('q-capital', 'single_choice', [['id' => 'o-a', 'label' => 'Lyon'], ['id' => 'o-b', 'label' => 'París']], ['o-b'], 2),
                    $question('q-colours', 'multiple_choice', [['id' => 'o-blue', 'label' => 'Azul'], ['id' => 'o-red', 'label' => 'Rojo'], ['id' => 'o-green', 'label' => 'Verde']], ['o-blue', 'o-red'], 3),
                    $question('q-notes', 'short_text', [], [], null),
                ],
            ]],
            'quiz' => ['show_correct_answers' => true],
        ];
    }
}
