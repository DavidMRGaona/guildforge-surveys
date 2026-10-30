<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition;

use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use PHPUnit\Framework\TestCase;

final class SurveyDefinitionTest extends TestCase
{
    public function test_round_trips_every_question_type_through_array(): void
    {
        $data = self::everyTypeDefinition();

        $definition = SurveyDefinition::fromArray($data);

        $this->assertSame($data, $definition->toArray());
        $this->assertEquals($definition, SurveyDefinition::fromArray($definition->toArray()));
        $this->assertCount(count(QuestionType::cases()), $definition->questions());
    }

    public function test_from_array_rejects_unknown_type(): void
    {
        $data = self::everyTypeDefinition();
        $data['sections'][0]['questions'][1]['type'] = 'slider';

        try {
            SurveyDefinition::fromArray($data);
            $this->fail('An unknown question type must be rejected.');
        } catch (InvalidSurveyDefinitionException $exception) {
            $this->assertCount(1, $exception->violations);
            $this->assertSame('sections.0.questions.1.type', $exception->violations[0]->path);
            $this->assertSame('unknown_type', $exception->violations[0]->messageKey);
        }
    }

    public function test_from_array_rejects_a_definition_without_sections_list(): void
    {
        $this->expectException(InvalidSurveyDefinitionException::class);

        SurveyDefinition::fromArray(['sections' => 'not a list']);
    }

    public function test_questions_lists_all_sections_in_order(): void
    {
        $definition = SurveyDefinition::fromArray(self::everyTypeDefinition());

        $ids = array_map(static fn ($question): string => $question->id, $definition->questions());

        $this->assertSame(['q-short', 'q-paragraph', 'q-single', 'q-multiple', 'q-dropdown', 'q-scale', 'q-stars', 'q-date', 'q-time', 'q-grid'], $ids);
        $this->assertSame(QuestionType::Grid, $definition->question('q-grid')?->type);
        $this->assertNull($definition->question('missing'));
    }

    public function test_question_type_capabilities(): void
    {
        $this->assertTrue(QuestionType::Dropdown->hasOptions());
        $this->assertTrue(QuestionType::MultipleChoice->allowsOther());
        $this->assertFalse(QuestionType::LinearScale->hasOptions());
        $this->assertTrue(QuestionType::Paragraph->isFreeText());
        $this->assertFalse(QuestionType::Date->isFreeText());
    }

    /**
     * @return array{sections: list<array<string, mixed>>}
     */
    public static function everyTypeDefinition(): array
    {
        $options = [['id' => 'o-1', 'label' => 'Uno'], ['id' => 'o-2', 'label' => 'Dos']];

        return [
            'sections' => [
                [
                    'id' => 's-1',
                    'title' => 'Sobre la partida',
                    'description' => null,
                    'questions' => [
                        self::question('q-short', 'short_text', key: 'nickname'),
                        self::question('q-paragraph', 'paragraph'),
                        self::question('q-single', 'single_choice', options: $options, allowOther: true),
                        self::question('q-multiple', 'multiple_choice', options: $options),
                        self::question('q-dropdown', 'dropdown', options: $options),
                    ],
                ],
                [
                    'id' => 's-2',
                    'title' => null,
                    'description' => 'Valoraciones',
                    'questions' => [
                        self::question('q-scale', 'linear_scale', key: 'fun', scale: ['min' => 1, 'max' => 5, 'min_label' => 'Nada', 'max_label' => 'Mucho']),
                        self::question('q-stars', 'star_rating', stars: 5),
                        self::question('q-date', 'date'),
                        self::question('q-time', 'time'),
                        self::question('q-grid', 'grid', grid: [
                            'rows' => [['id' => 'r-1', 'label' => 'Ambiente'], ['id' => 'r-2', 'label' => 'Ritmo']],
                            'columns' => [['id' => 'c-1', 'label' => 'Mal'], ['id' => 'c-2', 'label' => 'Bien']],
                        ]),
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  list<array{id: string, label: string}>  $options
     * @param  array{min: int, max: int, min_label: ?string, max_label: ?string}|null  $scale
     * @param  array{rows: list<array{id: string, label: string}>, columns: list<array{id: string, label: string}>}|null  $grid
     * @return array<string, mixed>
     */
    private static function question(
        string $id,
        string $type,
        ?string $key = null,
        array $options = [],
        bool $allowOther = false,
        ?array $scale = null,
        ?int $stars = null,
        ?array $grid = null,
    ): array {
        return [
            'id' => $id,
            'key' => $key,
            'type' => $type,
            'title' => 'Pregunta '.$id,
            'help' => null,
            'required' => true,
            'options' => $options,
            'allow_other' => $allowOther,
            'scale' => $scale,
            'stars' => $stars,
            'grid' => $grid,
        ];
    }
}
