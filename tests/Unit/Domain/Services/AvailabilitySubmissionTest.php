<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Unit\Domain\Services;

use Modules\Surveys\Domain\Services\SubmissionValidator;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\Submission\AnswerValue;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AvailabilitySubmissionTest extends TestCase
{
    public function test_each_slot_gets_its_answer_as_a_grid_cell(): void
    {
        $submission = Submission::fromInput($this->definition(), ['q-1' => ['d-1' => 'yes', 'd-2' => 'if_needed']]);

        $this->assertSame([], (new SubmissionValidator)->validate($this->definition(), $submission));
        $this->assertEquals([
            new AnswerValue('q-1', optionId: 'yes', rowId: 'd-1'),
            new AnswerValue('q-1', optionId: 'if_needed', rowId: 'd-2'),
        ], $submission->values);
    }

    public function test_slots_left_unmarked_count_as_no(): void
    {
        $definition = $this->definition();

        $this->assertEquals([
            new AnswerValue('q-1', optionId: 'yes', rowId: 'd-1'),
            new AnswerValue('q-1', optionId: 'no', rowId: 'd-2'),
        ], Submission::fromInput($definition, ['q-1' => ['d-1' => 'yes']])->values);
        $this->assertEquals([
            new AnswerValue('q-1', optionId: 'no', rowId: 'd-1'),
            new AnswerValue('q-1', optionId: 'no', rowId: 'd-2'),
        ], Submission::fromInput($definition, [])->values);
        $this->assertSame([], (new SubmissionValidator)->validate($definition, Submission::fromInput($definition, [])));
    }

    /**
     * @param  array<mixed>  $input
     */
    #[DataProvider('invalidAnswers')]
    public function test_reports_an_invalid_answer(array $input, string $messageKey): void
    {
        $definition = $this->definition();

        $this->assertSame(['q-1' => $messageKey], (new SubmissionValidator)->validate($definition, Submission::fromInput($definition, ['q-1' => $input])));
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidAnswers(): iterable
    {
        yield 'unknown answer' => [['d-1' => 'maybe'], 'invalid_option'];
        yield 'unknown slot' => [['d-9' => 'yes'], 'invalid_option'];
    }

    private function definition(): SurveyDefinition
    {
        return SurveyDefinition::fromArray(AvailabilityDefinitionTest::data());
    }
}
