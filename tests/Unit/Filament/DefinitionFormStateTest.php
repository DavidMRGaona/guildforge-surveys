<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Unit\Filament;

use Modules\Surveys\Domain\ValueObjects\Definition\OptionJump;
use Modules\Surveys\Domain\ValueObjects\Definition\Section;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;
use Modules\Surveys\Filament\Support\DefinitionFormState;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use PHPUnit\Framework\TestCase;

final class DefinitionFormStateTest extends TestCase
{
    public function test_round_trips_every_question_type(): void
    {
        $definition = $this->definition();

        $this->assertEquals($definition, DefinitionFormState::toDefinition(DefinitionFormState::fromDefinition($definition)));
    }

    public function test_builds_the_state_filament_expects(): void
    {
        $state = DefinitionFormState::fromDefinition($this->definition());

        $section = array_values($state)[1];
        $scale = array_first($section['questions']);
        $grid = array_values($section['questions'])[4];

        $this->assertSame('linear_scale', $scale['type']);
        $this->assertSame('fun', $scale['data']['key']);
        $this->assertSame(5, $scale['data']['scale_max']);
        $this->assertSame('Mucho', $scale['data']['scale_max_label']);
        $this->assertSame(['id' => 'c-2', 'label' => 'Bien'], array_values($grid['data']['grid_columns'])[1]);
    }

    public function test_reads_lists_keyed_by_filament_item_keys_and_string_numbers(): void
    {
        $state = [
            'item-a' => [
                'id' => 's-1',
                'title' => null,
                'description' => null,
                'questions' => [
                    'item-b' => ['type' => 'star_rating', 'data' => ['id' => 'q-1', 'title' => 'Valora', 'required' => true, 'stars' => '5']],
                    'item-c' => ['type' => 'dropdown', 'data' => ['id' => 'q-2', 'title' => 'Elige', 'required' => false, 'options' => [
                        'item-d' => ['id' => 'o-1', 'label' => 'Uno'],
                        'item-e' => ['id' => 'o-2', 'label' => 'Dos'],
                    ]]],
                ],
            ],
        ];

        $definition = DefinitionFormState::toDefinition($state);

        $this->assertSame(5, $definition->question('q-1')?->stars);
        $this->assertSame(['o-1', 'o-2'], array_map(static fn ($option): string => $option->id, $definition->question('q-2')->options ?? []));
        $this->assertFalse($definition->question('q-2')?->required);
    }

    public function test_translates_a_domain_path_into_the_form_path(): void
    {
        $state = DefinitionFormState::fromDefinition($this->definition());
        $sectionKeys = array_keys($state);
        $questionKeys = array_keys($state[$sectionKeys[0]]['questions']);
        $optionKeys = array_keys($state[$sectionKeys[0]]['questions'][$questionKeys[2]]['data']['options']);
        $scaleKey = array_keys($state[$sectionKeys[1]]['questions'])[0];

        $this->assertSame(
            "sections.{$sectionKeys[0]}.questions.{$questionKeys[2]}.data.options.{$optionKeys[1]}.label",
            DefinitionFormState::formPath($state, 'sections.0.questions.2.options.1.label'),
        );
        $this->assertSame(
            "sections.{$sectionKeys[1]}.questions.{$scaleKey}.data.scale_min",
            DefinitionFormState::formPath($state, 'sections.1.questions.0.scale.min'),
        );
        $this->assertSame('sections', DefinitionFormState::formPath($state, 'sections'));
        $this->assertSame("sections.{$sectionKeys[1]}.questions.{$scaleKey}.data.title", DefinitionFormState::formPath($state, 'sections.1.questions.0.type'));
        $this->assertSame("sections.{$sectionKeys[0]}.questions.{$questionKeys[1]}.data.title", DefinitionFormState::formPath($state, 'sections.0.questions.1.id'));
        $this->assertSame("sections.{$sectionKeys[0]}.questions.{$questionKeys[2]}.data.options.{$optionKeys[0]}.label", DefinitionFormState::formPath($state, 'sections.0.questions.2.options.0.id'));
        $this->assertSame("sections.{$sectionKeys[1]}.title", DefinitionFormState::formPath($state, 'sections.1.id'));
    }

    public function test_round_trips_section_jumps(): void
    {
        $definition = SurveyDefinition::fromArray(SurveyDefinitionTest::branchingDefinition());

        $this->assertEquals($definition, DefinitionFormState::toDefinition(DefinitionFormState::fromDefinition($definition)));
    }

    public function test_jumps_sit_on_their_options_behind_a_switch(): void
    {
        $state = DefinitionFormState::fromDefinition(SurveyDefinition::fromArray(SurveyDefinitionTest::branchingDefinition()));

        [$start, $campaign] = array_values($state);
        $question = array_first($start['questions'])['data'];
        $this->assertTrue($question['branches']);
        $this->assertSame([null, 's-end'], array_column(array_values($question['options']), 'next'));
        $this->assertSame(Section::SUBMIT, $question['other_next']);
        $this->assertNull($start['next']);
        $this->assertSame('s-end', $campaign['next']);
    }

    public function test_switching_jumps_off_drops_them(): void
    {
        $state = DefinitionFormState::fromDefinition(SurveyDefinition::fromArray(SurveyDefinitionTest::branchingDefinition()));
        $sectionKey = array_keys($state)[0];
        $questionKey = array_keys($state[$sectionKey]['questions'])[0];
        $state[$sectionKey]['questions'][$questionKey]['data']['branches'] = false;

        $this->assertSame([], DefinitionFormState::toDefinition($state)->sections[0]->questions[0]->nextByOption);
    }

    public function test_jump_errors_land_on_their_select(): void
    {
        $state = DefinitionFormState::fromDefinition(SurveyDefinition::fromArray(SurveyDefinitionTest::branchingDefinition()));
        $sectionKeys = array_keys($state);
        $questionKey = array_key_first($state[$sectionKeys[0]]['questions']);
        $optionKeys = array_keys($state[$sectionKeys[0]]['questions'][$questionKey]['data']['options']);
        $question = "sections.{$sectionKeys[0]}.questions.{$questionKey}.data";

        // The first jump is the one of the second option ("No"), the second the one of "Other"
        $this->assertSame("{$question}.options.{$optionKeys[1]}.next", DefinitionFormState::formPath($state, 'sections.0.questions.0.next_by_option.0.next'));
        $this->assertSame("{$question}.options.{$optionKeys[1]}.next", DefinitionFormState::formPath($state, 'sections.0.questions.0.next_by_option.0.option_id'));
        $this->assertSame("{$question}.other_next", DefinitionFormState::formPath($state, 'sections.0.questions.0.next_by_option.1.next'));
        $this->assertSame("{$question}.branches", DefinitionFormState::formPath($state, 'sections.0.questions.0.next_by_option'));
        $this->assertSame("sections.{$sectionKeys[1]}.next", DefinitionFormState::formPath($state, 'sections.1.next'));
    }

    public function test_fresh_ids_keep_jumps_pointing_at_the_copied_sections(): void
    {
        $original = SurveyDefinition::fromArray(SurveyDefinitionTest::branchingDefinition());

        $copy = DefinitionFormState::withFreshQuestionIds($original);

        [$start, $campaign, $end] = $copy->sections;
        $this->assertNotSame('s-end', $end->id);
        $this->assertSame($end->id, $campaign->next);
        $this->assertEquals([new OptionJump('o-no', $end->id), new OptionJump(Submission::OTHER, Section::SUBMIT)], $start->questions[0]->nextByOption);
        $this->assertNull($start->next);
    }

    public function test_fresh_question_ids_keep_the_keys_options_rows_and_columns(): void
    {
        $original = $this->definition();

        $copy = DefinitionFormState::withFreshQuestionIds($original);

        $this->assertNotSame($original->sections[0]->id, $copy->sections[0]->id);
        foreach ($copy->questions() as $index => $question) {
            $before = $original->questions()[$index];
            $this->assertNotSame($before->id, $question->id);
            $this->assertSame($before->key, $question->key);
            $this->assertSame($before->title, $question->title);
            // Trends match options and grid rows by id with the template's
            $this->assertEquals($before->options, $question->options);
            $this->assertEquals($before->grid, $question->grid);
        }
    }

    private function definition(): SurveyDefinition
    {
        return SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition());
    }
}
