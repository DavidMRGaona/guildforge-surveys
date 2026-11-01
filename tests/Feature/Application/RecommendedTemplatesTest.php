<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use Modules\Surveys\Application\Templates\RecommendedTemplate;
use Modules\Surveys\Application\Templates\RecommendedTemplates;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Modules\Surveys\Domain\ValueObjects\Definition\Question;
use Tests\Support\Modules\ModuleTestCase;

final class RecommendedTemplatesTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    public function test_it_offers_the_twelve_recommended_templates(): void
    {
        $this->assertSame([
            'game_table',
            'campaign_session',
            'campaign',
            'association_event',
            'tournament',
            'tournament_40k',
            'tournament_aos',
            'tournament_kill_team',
            'tournament_blood_bowl',
            'member_satisfaction',
            'activity_interest',
            'availability',
        ], array_map(static fn (RecommendedTemplate $template): string => $template->catalogKey, $this->templates()));
    }

    public function test_each_kind_of_subject_gets_its_default_template(): void
    {
        $defaults = [];
        foreach ($this->templates() as $template) {
            if ($template->subjectType !== null) {
                $defaults[$template->subjectType] = $template->catalogKey;
            }
        }

        $this->assertSame([
            'game-table' => 'game_table',
            'campaign-session' => 'campaign_session',
            'campaign' => 'campaign',
            'event' => 'association_event',
            'tournament' => 'tournament',
        ], $defaults);
    }

    public function test_every_recommended_template_is_valid(): void
    {
        $validator = new SurveyDefinitionValidator;

        foreach ($this->templates() as $template) {
            $this->assertSame([], $validator->validate($template->definition, SurveyKind::Form), $template->catalogKey);
        }
    }

    public function test_every_question_has_a_key_and_each_template_one_required_question(): void
    {
        foreach ($this->templates() as $template) {
            $questions = $template->definition->questions();

            $this->assertNotContains(null, array_map(static fn (Question $question): ?string => $question->key, $questions), $template->catalogKey);
            $this->assertCount(1, array_filter($questions, static fn (Question $question): bool => $question->required), $template->catalogKey);
        }
    }

    public function test_tournament_variants_repeat_the_general_section(): void
    {
        $templates = $this->templatesByKey();
        $general = $templates['tournament']->definition->sections[0]->toArray();

        foreach (['tournament_40k', 'tournament_aos', 'tournament_kill_team', 'tournament_blood_bowl'] as $variant) {
            $sections = $templates[$variant]->definition->sections;

            $this->assertCount(2, $sections, $variant);
            $this->assertSame($general, $sections[0]->toArray(), $variant);
        }
    }

    public function test_tournament_variants_ask_about_their_game(): void
    {
        $templates = $this->templatesByKey();
        $gameKeys = static fn (string $key): array => array_map(static fn (Question $question): ?string => $question->key, $templates[$key]->definition->sections[1]->questions);

        $this->assertSame(['preferred_points', 'round_time', 'chess_clock', 'painting_rule', 'terrain_density'], $gameKeys('tournament_40k'));
        $this->assertSame(['preferred_points', 'round_time', 'chess_clock', 'painting_rule', 'terrain_density', 'battleplans'], $gameKeys('tournament_aos'));
        $this->assertSame(['killzones', 'rounds', 'team_balance', 'round_time'], $gameKeys('tournament_kill_team'));
        $this->assertSame(['team_rules', 'team_tiers', 'inducements', 'match_length'], $gameKeys('tournament_blood_bowl'));
    }

    public function test_together_they_use_every_question_type(): void
    {
        $types = [];
        foreach ($this->templates() as $template) {
            foreach ($template->definition->questions() as $question) {
                $types[$question->type->value] = true;
            }
        }

        $this->assertEqualsCanonicalizing(array_map(static fn (QuestionType $type): string => $type->value, QuestionType::cases()), array_keys($types));
    }

    public function test_no_text_is_left_untranslated(): void
    {
        foreach ($this->templates() as $template) {
            $texts = [$template->name, $template->description];
            $data = $template->definition->toArray();
            array_walk_recursive($data, static function (mixed $value, int|string $field) use (&$texts): void {
                if (in_array($field, ['title', 'label', 'help', 'min_label', 'max_label', 'description'], true) && is_string($value)) {
                    $texts[] = $value;
                }
            });

            foreach ($texts as $text) {
                $this->assertStringNotContainsString('surveys::', $text, $template->catalogKey);
                $this->assertNotSame('', trim($text), $template->catalogKey);
            }
        }
    }

    public function test_it_reads_the_game_table_template_as_planned(): void
    {
        $questions = $this->templatesByKey()['game_table']->definition->questions();
        $byKey = array_combine(array_map(static fn (Question $question): string => (string) $question->key, $questions), $questions);

        $this->assertSame(['overall', 'game_master', 'pacing', 'aspects', 'safety_tools', 'would_repeat', 'comments'], array_keys($byKey));
        $this->assertSame(QuestionType::StarRating, $byKey['overall']->type);
        $this->assertSame(5, $byKey['overall']->stars);
        $this->assertTrue($byKey['overall']->required);
        $this->assertSame(['Mejorable', 'Excelente'], [$byKey['game_master']->scale?->minLabel, $byKey['game_master']->scale?->maxLabel]);
        $this->assertSame(['Demasiado lento', 'Algo lento', 'Adecuado', 'Algo rápido', 'Demasiado rápido'], array_map(static fn ($option): string => $option->label, $byKey['pacing']->options));
        $this->assertSame(['Mejorable', 'Bien', 'Muy bien'], array_map(static fn ($column): string => $column->label, $byKey['aspects']->grid->columns ?? []));
        $this->assertSame('Partida de rol', $this->templatesByKey()['game_table']->name);
    }

    /**
     * @return list<RecommendedTemplate>
     */
    private function templates(): array
    {
        return (new RecommendedTemplates)->all();
    }

    /**
     * @return array<string, RecommendedTemplate>
     */
    private function templatesByKey(): array
    {
        $byKey = [];
        foreach ($this->templates() as $template) {
            $byKey[$template->catalogKey] = $template;
        }

        return $byKey;
    }
}
