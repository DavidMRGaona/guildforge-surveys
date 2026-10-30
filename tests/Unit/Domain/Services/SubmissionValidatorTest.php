<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Unit\Domain\Services;

use Modules\Surveys\Domain\Services\SubmissionValidator;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\Submission\AnswerValue;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubmissionValidatorTest extends TestCase
{
    public function test_a_complete_submission_is_valid_and_normalised(): void
    {
        $submission = Submission::fromInput($this->definition(), $this->validInput());

        $this->assertSame([], (new SubmissionValidator)->validate($this->definition(), $submission));
        $this->assertContainsEquals(new AnswerValue('q-short', text: 'Ana'), $submission->values);
        $this->assertContainsEquals(new AnswerValue('q-single', text: 'Ninguna'), $submission->values);
        $this->assertContainsEquals(new AnswerValue('q-multiple', optionId: 'o-1'), $submission->values);
        $this->assertContainsEquals(new AnswerValue('q-multiple', optionId: 'o-2'), $submission->values);
        $this->assertContainsEquals(new AnswerValue('q-scale', number: 4), $submission->values);
        $this->assertContainsEquals(new AnswerValue('q-time', text: '18:30'), $submission->values);
        $this->assertContainsEquals(new AnswerValue('q-grid', optionId: 'c-2', rowId: 'r-1'), $submission->values);
    }

    public function test_answers_to_unknown_questions_are_dropped(): void
    {
        $input = $this->validInput();
        $input['q-missing'] = 'algo';

        $submission = Submission::fromInput($this->definition(), $input);

        foreach ($submission->values as $value) {
            $this->assertNotSame('q-missing', $value->questionId);
        }
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('invalidAnswers')]
    public function test_reports_each_invalid_answer(array $change, string $questionId, string $messageKey): void
    {
        $input = array_merge($this->validInput(), $change);

        $errors = (new SubmissionValidator)->validate($this->definition(), Submission::fromInput($this->definition(), $input));

        $this->assertSame($messageKey, $errors[$questionId] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidAnswers(): iterable
    {
        yield 'required left empty' => [['q-short' => '   '], 'q-short', 'required'];
        yield 'unknown option' => [['q-dropdown' => 'o-9'], 'q-dropdown', 'invalid_option'];
        yield 'two options on single choice' => [['q-dropdown' => ['o-1', 'o-2']], 'q-dropdown', 'too_many_options'];
        yield 'other without text' => [['q-single' => ['option' => SubmissionValidator::OTHER, 'other' => ' ']], 'q-single', 'other_required'];
        yield 'other where not offered' => [['q-multiple' => ['options' => ['o-1', SubmissionValidator::OTHER], 'other' => 'Tres']], 'q-multiple', 'invalid_option'];
        yield 'repeated option' => [['q-multiple' => ['o-1', 'o-1']], 'q-multiple', 'duplicate_option'];
        yield 'repeated other' => [['q-single' => ['options' => [SubmissionValidator::OTHER, SubmissionValidator::OTHER], 'other' => 'Tres']], 'q-single', 'too_many_options'];
        yield 'scale above max' => [['q-scale' => 6], 'q-scale', 'out_of_range'];
        yield 'scale below min' => [['q-scale' => '0'], 'q-scale', 'out_of_range'];
        yield 'six stars of five' => [['q-stars' => 6], 'q-stars', 'out_of_range'];
        yield 'impossible date' => [['q-date' => '2026-02-30'], 'q-date', 'invalid_date'];
        yield 'impossible time' => [['q-time' => '25:00'], 'q-time', 'invalid_time'];
        yield 'grid row missing' => [['q-grid' => ['r-1' => 'c-1']], 'q-grid', 'grid_incomplete'];
        yield 'grid unknown column' => [['q-grid' => ['r-1' => 'c-9', 'r-2' => 'c-1']], 'q-grid', 'invalid_option'];
        yield 'short text too long' => [['q-short' => str_repeat('a', 501)], 'q-short', 'too_long'];
        yield 'paragraph too long' => [['q-paragraph' => str_repeat('a', 5001)], 'q-paragraph', 'too_long'];
    }

    public function test_optional_questions_may_stay_empty(): void
    {
        $data = SurveyDefinitionTest::everyTypeDefinition();
        $data['sections'][0]['questions'][1]['required'] = false;
        $definition = SurveyDefinition::fromArray($data);
        $input = $this->validInput();
        unset($input['q-paragraph']);

        $this->assertSame([], (new SubmissionValidator)->validate($definition, Submission::fromInput($definition, $input)));
    }

    private function definition(): SurveyDefinition
    {
        return SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition());
    }

    /**
     * @return array<string, mixed>
     */
    private function validInput(): array
    {
        return [
            'q-short' => ' Ana ',
            'q-paragraph' => 'Muy bien',
            'q-single' => ['option' => SubmissionValidator::OTHER, 'other' => 'Ninguna'],
            'q-multiple' => ['o-1', 'o-2'],
            'q-dropdown' => 'o-2',
            'q-scale' => '4',
            'q-stars' => 5,
            'q-date' => '2026-11-07',
            'q-time' => '18:30:00',
            'q-grid' => ['r-1' => 'c-2', 'r-2' => 'c-1'],
        ];
    }
}
