<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Unit\Domain\Services;

use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;
use Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Modules\Surveys\Domain\ValueObjects\Definition\DefinitionViolation;
use Modules\Surveys\Domain\ValueObjects\Definition\Slot;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AvailabilityDefinitionTest extends TestCase
{
    public function test_a_question_with_whole_days_and_timed_slots_is_valid_and_survives_the_round_trip(): void
    {
        $definition = SurveyDefinition::fromArray(self::data());
        $question = $definition->questions()[0];

        $this->assertSame([], (new SurveyDefinitionValidator)->validate($definition, SurveyKind::Availability));
        $this->assertSame(QuestionType::Availability, $question->type);
        $this->assertEquals(new Slot('d-1', date: '2026-12-05'), $question->slots[0]);
        $this->assertEquals(new Slot('d-2', startsAt: '2026-12-11T16:00:00+00:00', endsAt: '2026-12-11T20:00:00+00:00'), $question->slots[1]);
        $this->assertTrue($question->slots[0]->isWholeDay());
        $this->assertFalse($question->slots[1]->isWholeDay());
        $this->assertSame(self::data(), $definition->toArray());
    }

    public function test_an_availability_question_is_not_a_choice_and_never_branches(): void
    {
        $this->assertFalse(QuestionType::Availability->hasOptions());
        $this->assertFalse(QuestionType::Availability->isChoice());
        $this->assertFalse(QuestionType::Availability->canBranch());
        $this->assertFalse(QuestionType::Availability->allowsOther());
    }

    public function test_only_forms_and_polls_become_templates(): void
    {
        $this->assertTrue(SurveyKind::Form->allowsTemplates());
        $this->assertTrue(SurveyKind::Poll->allowsTemplates());
        $this->assertFalse(SurveyKind::Availability->allowsTemplates());
    }

    public function test_an_availability_survey_never_becomes_a_template(): void
    {
        try {
            new SurveyTemplate(SurveyTemplateId::generate(), 'Fechas', null, SurveyKind::Availability, SurveyDefinition::fromArray(self::data()));
            $this->fail('An availability template must be refused.');
        } catch (InvalidSurveyDefinitionException $exception) {
            $this->assertEquals([new DefinitionViolation('kind', 'kind_without_templates')], $exception->violations);
        }
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $break
     */
    #[DataProvider('brokenSlots')]
    public function test_reports_each_broken_slot(callable $break, string $path, string $messageKey): void
    {
        $violations = (new SurveyDefinitionValidator)->validate(SurveyDefinition::fromArray($break(self::data())), SurveyKind::Availability);

        $this->assertContainsEquals(new DefinitionViolation($path, $messageKey), $violations);
    }

    /**
     * @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, string, string}>
     */
    public static function brokenSlots(): iterable
    {
        $q = 'sections.0.questions.0';
        $slot = static fn (array $d, int $index, array $slot): array => self::withSlots($d, array_values(array_replace(self::slots($d), [$index => $slot])));

        yield 'one slot' => [static fn (array $d): array => self::withSlots($d, [self::slots($d)[0]]), "{$q}.slots", 'too_few_slots'];
        yield 'thirty one slots' => [static fn (array $d): array => self::withSlots($d, array_map(static fn (int $n): array => ['id' => "d-{$n}", 'date' => sprintf('2027-01-%02d', $n), 'starts_at' => null, 'ends_at' => null], range(1, 31))), "{$q}.slots", 'too_many_slots'];
        yield 'empty slot id' => [static fn (array $d): array => $slot($d, 0, ['id' => '', 'date' => '2026-12-05', 'starts_at' => null, 'ends_at' => null]), "{$q}.slots.0.id", 'required_id'];
        yield 'duplicate slot id' => [static fn (array $d): array => $slot($d, 1, ['id' => 'd-1', 'date' => '2026-12-06', 'starts_at' => null, 'ends_at' => null]), "{$q}.slots.1.id", 'duplicate_id'];
        yield 'no day and no start' => [static fn (array $d): array => $slot($d, 0, ['id' => 'd-1', 'date' => null, 'starts_at' => null, 'ends_at' => null]), "{$q}.slots.0.date", 'invalid_slot_date'];
        yield 'impossible day' => [static fn (array $d): array => $slot($d, 0, ['id' => 'd-1', 'date' => '2026-02-30', 'starts_at' => null, 'ends_at' => null]), "{$q}.slots.0.date", 'invalid_slot_date'];
        yield 'day and start at once' => [static fn (array $d): array => $slot($d, 1, ['id' => 'd-2', 'date' => '2026-12-11', 'starts_at' => '2026-12-11T16:00:00+00:00', 'ends_at' => null]), "{$q}.slots.1.date", 'invalid_slot_date'];
        yield 'end of a whole day' => [static fn (array $d): array => $slot($d, 0, ['id' => 'd-1', 'date' => '2026-12-05', 'starts_at' => null, 'ends_at' => '2026-12-05T20:00:00+00:00']), "{$q}.slots.0.ends_at", 'invalid_slot_end'];
        yield 'start without zone' => [static fn (array $d): array => $slot($d, 1, ['id' => 'd-2', 'date' => null, 'starts_at' => '2026-12-11 16:00', 'ends_at' => null]), "{$q}.slots.1.starts_at", 'invalid_slot_start'];
        yield 'start not in UTC' => [static fn (array $d): array => $slot($d, 1, ['id' => 'd-2', 'date' => null, 'starts_at' => '2026-12-11T17:00:00+01:00', 'ends_at' => null]), "{$q}.slots.1.starts_at", 'invalid_slot_start'];
        yield 'end before start' => [static fn (array $d): array => $slot($d, 1, ['id' => 'd-2', 'date' => null, 'starts_at' => '2026-12-11T16:00:00+00:00', 'ends_at' => '2026-12-11T15:00:00+00:00']), "{$q}.slots.1.ends_at", 'slot_ends_before_start'];
        yield 'end at start' => [static fn (array $d): array => $slot($d, 1, ['id' => 'd-2', 'date' => null, 'starts_at' => '2026-12-11T16:00:00+00:00', 'ends_at' => '2026-12-11T16:00:00+00:00']), "{$q}.slots.1.ends_at", 'slot_ends_before_start'];
        yield 'the same slot twice' => [static fn (array $d): array => $slot($d, 1, ['id' => 'd-2', 'date' => '2026-12-05', 'starts_at' => null, 'ends_at' => null]), "{$q}.slots.1", 'duplicate_slot'];
        yield 'two questions' => [static fn (array $d): array => self::withQuestions($d, [$d['sections'][0]['questions'][0], ['id' => 'q-2', 'key' => null, 'type' => 'short_text', 'title' => 'Comentarios', 'help' => null, 'required' => false, 'options' => [], 'allow_other' => false, 'scale' => null, 'stars' => null, 'grid' => null, 'next_by_option' => [], 'slots' => []]]), 'sections', 'availability_single_question'];
        yield 'another type' => [static fn (array $d): array => self::withQuestions($d, [['id' => 'q-1', 'key' => null, 'type' => 'single_choice', 'title' => '¿Qué día?', 'help' => null, 'required' => true, 'options' => [['id' => 'o-1', 'label' => 'Sábado'], ['id' => 'o-2', 'label' => 'Domingo']], 'allow_other' => false, 'scale' => null, 'stars' => null, 'grid' => null, 'next_by_option' => [], 'slots' => []]]), "{$q}.type", 'availability_type'];
    }

    public function test_an_availability_question_only_lives_in_an_availability_survey(): void
    {
        $validator = new SurveyDefinitionValidator;
        $definition = SurveyDefinition::fromArray(self::data());

        $this->assertContainsEquals(new DefinitionViolation('sections.0.questions.0.type', 'availability_kind_only'), $validator->validate($definition, SurveyKind::Form));
        $this->assertContainsEquals(new DefinitionViolation('sections.0.questions.0.type', 'poll_type'), $validator->validate($definition, SurveyKind::Poll));
    }

    public function test_slots_belong_to_availability_questions_only(): void
    {
        $data = self::data();
        $data['sections'][0]['questions'][0]['type'] = 'short_text';

        $violations = (new SurveyDefinitionValidator)->validate(SurveyDefinition::fromArray($data), SurveyKind::Form);

        $this->assertContainsEquals(new DefinitionViolation('sections.0.questions.0.slots', 'slots_not_allowed'), $violations);
    }

    public function test_reports_a_malformed_slot(): void
    {
        $data = self::withSlots(self::data(), [['id' => 'd-1', 'date' => 5], 'sábado']);

        try {
            SurveyDefinition::fromArray($data);
            $this->fail('A malformed slot must be refused.');
        } catch (InvalidSurveyDefinitionException $exception) {
            $this->assertContainsEquals(new DefinitionViolation('sections.0.questions.0.slots.0.date', 'malformed'), $exception->violations);
            $this->assertContainsEquals(new DefinitionViolation('sections.0.questions.0.slots.1', 'malformed'), $exception->violations);
        }
    }

    /**
     * An availability survey: a whole day and an evening, the second in UTC (17:00-21:00 in Madrid).
     *
     * @return array<string, mixed>
     */
    public static function data(): array
    {
        return ['sections' => [[
            'id' => 's-1',
            'title' => null,
            'description' => null,
            'next' => null,
            'questions' => [[
                'id' => 'q-1',
                'key' => null,
                'type' => 'availability',
                'title' => '¿Qué día os viene bien para la asamblea?',
                'help' => null,
                'required' => true,
                'options' => [],
                'allow_other' => false,
                'scale' => null,
                'stars' => null,
                'grid' => null,
                'next_by_option' => [],
                'slots' => [
                    ['id' => 'd-1', 'date' => '2026-12-05', 'starts_at' => null, 'ends_at' => null],
                    ['id' => 'd-2', 'date' => null, 'starts_at' => '2026-12-11T16:00:00+00:00', 'ends_at' => '2026-12-11T20:00:00+00:00'],
                ],
            ]],
        ]]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private static function slots(array $data): array
    {
        return $data['sections'][0]['questions'][0]['slots'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<mixed>  $slots
     * @return array<string, mixed>
     */
    private static function withSlots(array $data, array $slots): array
    {
        $data['sections'][0]['questions'][0]['slots'] = $slots;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $questions
     * @return array<string, mixed>
     */
    private static function withQuestions(array $data, array $questions): array
    {
        $data['sections'][0]['questions'] = $questions;

        return $data;
    }
}
