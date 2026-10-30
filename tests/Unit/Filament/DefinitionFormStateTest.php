<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Unit\Filament;

use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
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

    public function test_fresh_ids_change_every_id_and_keep_the_keys(): void
    {
        $original = $this->definition();

        $copy = DefinitionFormState::withFreshIds($original);

        foreach ($copy->questions() as $index => $question) {
            $before = $original->questions()[$index];
            $this->assertNotSame($before->id, $question->id);
            $this->assertSame($before->key, $question->key);
            $this->assertSame($before->title, $question->title);
            foreach ($question->options as $o => $option) {
                $this->assertNotSame($before->options[$o]->id, $option->id);
            }
        }
    }

    private function definition(): SurveyDefinition
    {
        return SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition());
    }
}
