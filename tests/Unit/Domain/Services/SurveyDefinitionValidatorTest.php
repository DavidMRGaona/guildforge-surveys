<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Unit\Domain\Services;

use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;
use Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Modules\Surveys\Domain\ValueObjects\Definition\DefinitionViolation;
use Modules\Surveys\Domain\ValueObjects\Definition\Section;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SurveyDefinitionValidatorTest extends TestCase
{
    private SurveyDefinitionValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new SurveyDefinitionValidator;
    }

    public function test_a_complete_form_is_valid(): void
    {
        $this->assertSame([], $this->validator->validate($this->definition(), SurveyKind::Form));
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $break
     */
    #[DataProvider('brokenForms')]
    public function test_reports_each_broken_rule(callable $break, string $path, string $messageKey): void
    {
        $violations = $this->validator->validate(SurveyDefinition::fromArray($break($this->data())), SurveyKind::Form);

        $this->assertContainsEquals(new DefinitionViolation($path, $messageKey), $violations);
    }

    /**
     * @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, string, string}>
     */
    public static function brokenForms(): iterable
    {
        $q = static fn (int $section, int $question): string => "sections.{$section}.questions.{$question}";

        yield 'no questions' => [static fn (array $d): array => ['sections' => [['id' => 's-1', 'questions' => []]]], 'sections', 'no_questions'];
        yield 'empty question id' => [static fn (array $d): array => self::set($d, [0, 0, 'id'], ''), $q(0, 0).'.id', 'required_id'];
        yield 'duplicate question id' => [static fn (array $d): array => self::set($d, [0, 1, 'id'], 'q-short'), $q(0, 1).'.id', 'duplicate_id'];
        yield 'duplicate section id' => [static fn (array $d): array => self::setSection($d, 1, 'id', 's-1'), 'sections.1.id', 'duplicate_id'];
        yield 'empty title' => [static fn (array $d): array => self::set($d, [0, 1, 'title'], '  '), $q(0, 1).'.title', 'required_title'];
        yield 'bad key' => [static fn (array $d): array => self::set($d, [0, 0, 'key'], 'Nick Name'), $q(0, 0).'.key', 'invalid_key'];
        yield 'duplicate key' => [static fn (array $d): array => self::set($d, [1, 0, 'key'], 'nickname'), $q(1, 0).'.key', 'duplicate_key'];
        yield 'one option' => [static fn (array $d): array => self::set($d, [0, 2, 'options'], [['id' => 'o-1', 'label' => 'Uno']]), $q(0, 2).'.options', 'too_few_options'];
        yield 'empty option label' => [static fn (array $d): array => self::set($d, [0, 3, 'options'], [['id' => 'o-1', 'label' => ''], ['id' => 'o-2', 'label' => 'Dos']]), $q(0, 3).'.options.0.label', 'required_label'];
        yield 'duplicate option id' => [static fn (array $d): array => self::set($d, [0, 4, 'options'], [['id' => 'o-1', 'label' => 'Uno'], ['id' => 'o-1', 'label' => 'Dos']]), $q(0, 4).'.options.1.id', 'duplicate_id'];
        yield 'options on free text' => [static fn (array $d): array => self::set($d, [0, 0, 'options'], [['id' => 'o-1', 'label' => 'Uno'], ['id' => 'o-2', 'label' => 'Dos']]), $q(0, 0).'.options', 'options_not_allowed'];
        yield 'other on a scale' => [static fn (array $d): array => self::set($d, [1, 0, 'allow_other'], true), $q(1, 0).'.allow_other', 'other_not_allowed'];
        yield 'scale missing' => [static fn (array $d): array => self::set($d, [1, 0, 'scale'], null), $q(1, 0).'.scale', 'scale_required'];
        yield 'scale min 2' => [static fn (array $d): array => self::set($d, [1, 0, 'scale'], ['min' => 2, 'max' => 5, 'min_label' => null, 'max_label' => null]), $q(1, 0).'.scale.min', 'invalid_scale_min'];
        yield 'scale max 11' => [static fn (array $d): array => self::set($d, [1, 0, 'scale'], ['min' => 1, 'max' => 11, 'min_label' => null, 'max_label' => null]), $q(1, 0).'.scale.max', 'invalid_scale_max'];
        yield 'two stars' => [static fn (array $d): array => self::set($d, [1, 1, 'stars'], 2), $q(1, 1).'.stars', 'invalid_stars'];
        yield 'stars missing' => [static fn (array $d): array => self::set($d, [1, 1, 'stars'], null), $q(1, 1).'.stars', 'invalid_stars'];
        yield 'grid missing' => [static fn (array $d): array => self::set($d, [1, 4, 'grid'], null), $q(1, 4).'.grid', 'grid_required'];
        yield 'grid without rows' => [static fn (array $d): array => self::set($d, [1, 4, 'grid'], ['rows' => [], 'columns' => [['id' => 'c-1', 'label' => 'Mal'], ['id' => 'c-2', 'label' => 'Bien']]]), $q(1, 4).'.grid.rows', 'too_few_rows'];
        yield 'grid with one column' => [static fn (array $d): array => self::set($d, [1, 4, 'grid'], ['rows' => [['id' => 'r-1', 'label' => 'Ambiente']], 'columns' => [['id' => 'c-1', 'label' => 'Mal']]]), $q(1, 4).'.grid.columns', 'too_few_columns'];
        yield 'ranking with one option' => [static fn (array $d): array => self::set($d, [1, 5, 'options'], [['id' => 'k-1', 'label' => 'Rol']]), $q(1, 5).'.options', 'too_few_options'];
        yield 'ranking with eleven options' => [static fn (array $d): array => self::set($d, [1, 5, 'options'], array_map(static fn (int $n): array => ['id' => "k-{$n}", 'label' => "Opción {$n}"], range(1, 11))), $q(1, 5).'.options', 'too_many_ranking_options'];
        yield 'other on a ranking' => [static fn (array $d): array => self::set($d, [1, 5, 'allow_other'], true), $q(1, 5).'.allow_other', 'other_not_allowed'];
        yield 'jumps on a ranking' => [static fn (array $d): array => self::set($d, [1, 5, 'next_by_option'], [['option_id' => 'k-1', 'next' => Section::SUBMIT]]), $q(1, 5).'.next_by_option', 'branching_not_allowed'];
    }

    public function test_jumps_forward_to_sections_or_the_end_are_valid(): void
    {
        $this->assertSame([], $this->validator->validate(SurveyDefinition::fromArray(SurveyDefinitionTest::branchingDefinition()), SurveyKind::Form));
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $break
     */
    #[DataProvider('brokenJumps')]
    public function test_reports_each_broken_jump(callable $break, string $path, string $messageKey): void
    {
        $definition = SurveyDefinition::fromArray($break(SurveyDefinitionTest::branchingDefinition()));

        $this->assertContainsEquals(new DefinitionViolation($path, $messageKey), $this->validator->validate($definition, SurveyKind::Form));
    }

    /**
     * Paths and keys of the jump rules, on SurveyDefinitionTest::branchingDefinition().
     *
     * @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, string, string}>
     */
    public static function brokenJumps(): iterable
    {
        $jumps = 'sections.0.questions.0.next_by_option';
        $jump = static fn (string $option, string $next): array => ['option_id' => $option, 'next' => $next];

        yield 'checkboxes cannot jump' => [static fn (array $d): array => self::set($d, [0, 0, 'type'], 'multiple_choice'), $jumps, 'branching_not_allowed'];
        yield 'two jumping questions in a section' => [static function (array $d) use ($jump): array {
            $second = $d['sections'][0]['questions'][0];
            $second['id'] = 'q-again';
            $second['next_by_option'] = [$jump('o-yes', 's-end')];
            $d['sections'][0]['questions'][] = $second;

            return $d;
        }, 'sections.0.questions.1.next_by_option', 'one_branching_question_per_section'];
        yield 'jump from an unknown option' => [static fn (array $d): array => self::set($d, [0, 0, 'next_by_option'], [$jump('o-maybe', 's-end')]), $jumps.'.0.option_id', 'unknown_option_target'];
        yield 'jump from "Other" without "Other"' => [static fn (array $d): array => self::set($d, [0, 0, 'allow_other'], false), $jumps.'.1.option_id', 'unknown_option_target'];
        yield 'two jumps from one option' => [static fn (array $d): array => self::set($d, [0, 0, 'next_by_option'], [$jump('o-no', 's-end'), $jump('o-no', 's-campaign')]), $jumps.'.1.option_id', 'duplicate_id'];
        yield 'jump to an unknown section' => [static fn (array $d): array => self::set($d, [0, 0, 'next_by_option'], [$jump('o-no', 's-gone')]), $jumps.'.0.next', 'unknown_section'];
        yield 'section jump to an unknown section' => [static fn (array $d): array => self::setSection($d, 1, 'next', 's-gone'), 'sections.1.next', 'unknown_section'];
        yield 'jump to the same section' => [static fn (array $d): array => self::set($d, [0, 0, 'next_by_option'], [$jump('o-no', 's-start')]), $jumps.'.0.next', 'jump_backwards'];
        yield 'section jump backwards' => [static fn (array $d): array => self::setSection($d, 2, 'next', 's-campaign'), 'sections.2.next', 'jump_backwards'];
        yield 'section named like the end' => [static fn (array $d): array => self::setSection($d, 2, 'id', Section::SUBMIT), 'sections.2.id', 'reserved_id'];
    }

    public function test_a_ranking_offers_up_to_ten_options(): void
    {
        $ten = array_map(static fn (int $n): array => ['id' => "k-{$n}", 'label' => "Opción {$n}"], range(1, 10));

        $this->assertSame([], $this->validator->validate(SurveyDefinition::fromArray(self::set($this->data(), [1, 5, 'options'], $ten)), SurveyKind::Form));
    }

    public function test_a_poll_cannot_jump(): void
    {
        $data = SurveyDefinitionTest::branchingDefinition();
        $data['sections'] = [$data['sections'][0]];
        $data['sections'][0]['questions'][0]['allow_other'] = false;
        $data['sections'][0]['questions'][0]['next_by_option'] = [['option_id' => 'o-no', 'next' => Section::SUBMIT]];

        $this->assertContainsEquals(new DefinitionViolation('sections', 'branching_not_allowed_in_poll'), $this->validator->validate(SurveyDefinition::fromArray($data), SurveyKind::Poll));
    }

    public function test_with_responses_jumps_may_not_change(): void
    {
        $old = SurveyDefinition::fromArray(SurveyDefinitionTest::branchingDefinition());
        $new = SurveyDefinition::fromArray(self::setSection(SurveyDefinitionTest::branchingDefinition(), 1, 'next', null));

        $this->assertSame([], $this->validator->validateChange($old, $new, hasResponses: false));
        $this->assertContainsEquals(new DefinitionViolation('sections', 'locked_by_responses'), $this->validator->validateChange($old, $new, hasResponses: true));
    }

    public function test_a_poll_has_exactly_one_choice_question_without_other(): void
    {
        $poll = $this->poll('single_choice');

        $this->assertSame([], $this->validator->validate($poll, SurveyKind::Poll));
        $this->assertSame([], $this->validator->validate($this->poll('multiple_choice'), SurveyKind::Poll));
        $this->assertContainsEquals(new DefinitionViolation('sections.0.questions.0.type', 'poll_type'), $this->validator->validate($this->poll('dropdown'), SurveyKind::Poll));
        $this->assertContainsEquals(new DefinitionViolation('sections.0.questions.0.type', 'poll_type'), $this->validator->validate($this->poll('ranking'), SurveyKind::Poll));
        $this->assertContainsEquals(new DefinitionViolation('sections.0.questions.0.allow_other', 'other_not_allowed'), $this->validator->validate($this->poll('single_choice', allowOther: true), SurveyKind::Poll));
        $this->assertContainsEquals(new DefinitionViolation('sections', 'poll_single_question'), $this->validator->validate($this->definition(), SurveyKind::Poll));
    }

    public function test_keyed_question_cannot_change_type(): void
    {
        $changed = SurveyDefinition::fromArray(self::set($this->data(), [1, 0, 'type'], 'star_rating'));

        foreach ([false, true] as $hasResponses) {
            $this->assertContainsEquals(
                new DefinitionViolation('sections.1.questions.0.type', 'type_locked'),
                $this->validator->validateChange($this->definition(), $changed, $hasResponses),
            );
        }
    }

    public function test_a_survey_may_not_add_a_key(): void
    {
        $changed = SurveyDefinition::fromArray(self::set($this->data(), [0, 2, 'key'], 'mood'));

        $this->assertEquals(
            [new DefinitionViolation('sections.0.questions.2.key', 'key_not_allowed')],
            $this->validator->validateChange($this->definition(), $changed, hasResponses: false, keysFixed: true),
        );
    }

    public function test_a_survey_may_not_rename_a_key(): void
    {
        $changed = SurveyDefinition::fromArray(self::set($this->data(), [0, 0, 'key'], 'alias'));

        $this->assertContainsEquals(
            new DefinitionViolation('sections.0.questions.0.key', 'key_not_allowed'),
            $this->validator->validateChange($this->definition(), $changed, hasResponses: false, keysFixed: true),
        );
    }

    public function test_a_survey_may_drop_a_keyed_question(): void
    {
        $data = $this->data();
        unset($data['sections'][0]['questions'][0]);
        $data['sections'][0]['questions'] = array_values($data['sections'][0]['questions']);

        $this->assertSame([], $this->validator->validateChange($this->definition(), SurveyDefinition::fromArray($data), hasResponses: false, keysFixed: true));
    }

    public function test_templates_may_add_keys(): void
    {
        $changed = SurveyDefinition::fromArray(self::set($this->data(), [0, 2, 'key'], 'mood'));

        $this->assertSame([], $this->validator->validateChange($this->definition(), $changed, hasResponses: false));
    }

    public function test_without_responses_anything_but_keyed_types_may_change(): void
    {
        $data = self::set($this->data(), [0, 1, 'type'], 'short_text');
        $data = self::set($data, [0, 2, 'title'], 'Otra pregunta');
        unset($data['sections'][0]['questions'][3]);
        $data['sections'][0]['questions'] = array_values($data['sections'][0]['questions']);

        $this->assertSame([], $this->validator->validateChange($this->definition(), SurveyDefinition::fromArray($data), hasResponses: false));
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    #[DataProvider('contentChanges')]
    public function test_with_responses_only_help_texts_may_change(callable $change): void
    {
        $this->assertContainsEquals(
            new DefinitionViolation('sections', 'locked_by_responses'),
            $this->validator->validateChange($this->definition(), SurveyDefinition::fromArray($change($this->data())), hasResponses: true),
        );
    }

    /**
     * @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function contentChanges(): iterable
    {
        yield 'title' => [static fn (array $d): array => self::set($d, [0, 2, 'title'], 'Otra')];
        yield 'option label' => [static fn (array $d): array => self::set($d, [0, 2, 'options'], [['id' => 'o-1', 'label' => 'Uno'], ['id' => 'o-2', 'label' => 'Tres']])];
        yield 'required' => [static fn (array $d): array => self::set($d, [0, 2, 'required'], false)];
        yield 'order' => [static function (array $d): array {
            $d['sections'][0]['questions'] = array_reverse($d['sections'][0]['questions']);

            return $d;
        }];
        yield 'removed question' => [static function (array $d): array {
            array_pop($d['sections'][1]['questions']);

            return $d;
        }];
    }

    public function test_with_responses_help_texts_may_change(): void
    {
        $changed = SurveyDefinition::fromArray(self::set($this->data(), [0, 2, 'help'], 'Elige la que más te guste'));

        $this->assertSame([], $this->validator->validateChange($this->definition(), $changed, hasResponses: true));
    }

    public function test_assertions_throw_with_the_violations(): void
    {
        $this->validator->assertValid($this->definition(), SurveyKind::Form);

        $this->expectException(InvalidSurveyDefinitionException::class);

        $this->validator->assertChangeAllowed($this->definition(), SurveyDefinition::fromArray(self::set($this->data(), [0, 2, 'title'], 'Otra')), hasResponses: true);
    }

    private function definition(): SurveyDefinition
    {
        return SurveyDefinition::fromArray($this->data());
    }

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        return SurveyDefinitionTest::everyTypeDefinition();
    }

    private function poll(string $type, bool $allowOther = false): SurveyDefinition
    {
        $data = $this->data();
        $question = $data['sections'][0]['questions'][2];
        $question['type'] = $type;
        $question['allow_other'] = $allowOther;

        return SurveyDefinition::fromArray(['sections' => [['id' => 's-1', 'title' => null, 'description' => null, 'questions' => [$question]]]]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{int, int, string}  $at  section, question and field
     * @return array<string, mixed>
     */
    private static function set(array $data, array $at, mixed $value): array
    {
        [$section, $question, $field] = $at;
        $data['sections'][$section]['questions'][$question][$field] = $value;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function setSection(array $data, int $section, string $field, mixed $value): array
    {
        $data['sections'][$section][$field] = $value;

        return $data;
    }
}
