<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Templates;

use LogicException;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\ValueObjects\Definition\GridSettings;
use Modules\Surveys\Domain\ValueObjects\Definition\Option;
use Modules\Surveys\Domain\ValueObjects\Definition\Question;
use Modules\Surveys\Domain\ValueObjects\Definition\ScaleSettings;
use Modules\Surveys\Domain\ValueObjects\Definition\Section;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\TemplateUses;

/**
 * The templates the module ships for a role-playing and wargames association. Structure lives
 * here and texts in lang/<locale>/templates.php. Ids are the keys themselves: they are stable,
 * unique within each template and readable in exports.
 *
 * Questions shared by several templates keep the same key in all of them, and the tournament
 * variants repeat the general tournament section as it is, so they can be compared one day.
 */
final class RecommendedTemplates
{
    /**
     * @return list<RecommendedTemplate>
     */
    public function all(): array
    {
        return [
            $this->template('game_table', 'game-table', [
                $this->section('game_table', 'main', [
                    $this->stars('game_table', 'overall'),
                    $this->scale('game_table', 'game_master'),
                    $this->choice('game_table', 'pacing', QuestionType::SingleChoice, 'shared.pacing'),
                    $this->grid('game_table', 'aspects'),
                    $this->choice('game_table', 'safety_tools', QuestionType::SingleChoice),
                    $this->choice('game_table', 'would_repeat', QuestionType::SingleChoice, 'shared.repeat'),
                    $this->text('game_table', 'comments', QuestionType::Paragraph),
                ]),
            ]),
            $this->template('campaign_session', 'campaign-session', [
                $this->section('campaign_session', 'main', [
                    $this->stars('campaign_session', 'overall'),
                    $this->scale('campaign_session', 'spotlight'),
                    $this->choice('campaign_session', 'pacing', QuestionType::SingleChoice, 'shared.pacing'),
                    $this->text('campaign_session', 'next_session', QuestionType::Paragraph),
                ]),
            ]),
            $this->template('campaign', 'campaign', [
                $this->section('campaign', 'main', [
                    $this->stars('campaign', 'overall'),
                    $this->scale('campaign', 'story_arc'),
                    $this->scale('campaign', 'ending'),
                    $this->scale('campaign', 'game_master'),
                    $this->grid('campaign', 'aspects'),
                ]),
                $this->section('campaign', 'next', [
                    $this->choice('campaign', 'sequel', QuestionType::SingleChoice, 'shared.repeat'),
                    $this->choice('campaign', 'next_tone', QuestionType::MultipleChoice, allowOther: true),
                    $this->text('campaign', 'next_system', QuestionType::ShortText),
                    $this->text('campaign', 'comments', QuestionType::Paragraph),
                ]),
            ]),
            $this->template('association_event', 'event', [
                $this->section('association_event', 'main', [
                    $this->stars('association_event', 'overall'),
                    $this->choice('association_event', 'activities_joined', QuestionType::MultipleChoice, allowOther: true),
                    $this->scale('association_event', 'activities'),
                    $this->scale('association_event', 'schedule'),
                    $this->scale('association_event', 'venue'),
                    $this->choice('association_event', 'prior_info', QuestionType::SingleChoice),
                    $this->scale('association_event', 'recommend', min: 0, max: 10),
                    $this->choice('association_event', 'how_heard', QuestionType::Dropdown, allowOther: true),
                    $this->text('association_event', 'comments', QuestionType::Paragraph),
                ]),
            ]),
            $this->template('tournament', 'tournament', [$this->tournamentSection()]),
            $this->template('tournament_40k', null, sections: [
                $this->tournamentSection(),
                $this->section('tournament_40k', 'game', $this->wargameQuestions()),
            ], for: 'tournament'),
            $this->template('tournament_aos', null, sections: [
                $this->tournamentSection(),
                $this->section('tournament_aos', 'game', [
                    ...$this->wargameQuestions(),
                    $this->scale('tournament_aos', 'battleplans'),
                ]),
            ], for: 'tournament'),
            $this->template('tournament_kill_team', null, sections: [
                $this->tournamentSection(),
                $this->section('tournament_kill_team', 'game', [
                    $this->scale('tournament_kill_team', 'killzones'),
                    $this->choice('tournament_kill_team', 'rounds', QuestionType::SingleChoice),
                    $this->scale('tournament_kill_team', 'team_balance'),
                    $this->choice('wargame', 'round_time', QuestionType::SingleChoice),
                ]),
            ], for: 'tournament'),
            $this->template('tournament_blood_bowl', null, sections: [
                $this->tournamentSection(),
                $this->section('tournament_blood_bowl', 'game', [
                    $this->scale('tournament_blood_bowl', 'team_rules'),
                    $this->scale('tournament_blood_bowl', 'team_tiers'),
                    $this->choice('tournament_blood_bowl', 'inducements', QuestionType::SingleChoice),
                    $this->choice('tournament_blood_bowl', 'match_length', QuestionType::SingleChoice),
                ]),
            ], for: 'tournament'),
            $this->template('member_satisfaction', null, [
                $this->section('member_satisfaction', 'main', [
                    $this->stars('member_satisfaction', 'satisfaction'),
                    $this->scale('member_satisfaction', 'recommend', min: 0, max: 10),
                    $this->grid('member_satisfaction', 'areas'),
                    $this->choice('member_satisfaction', 'attendance', QuestionType::SingleChoice),
                    $this->choice('member_satisfaction', 'help_out', QuestionType::SingleChoice),
                    $this->text('member_satisfaction', 'improve', QuestionType::Paragraph),
                ]),
            ]),
            $this->template('activity_interest', null, [
                $this->section('activity_interest', 'main', [
                    $this->choice('activity_interest', 'activities', QuestionType::MultipleChoice, allowOther: true, required: true),
                    $this->choice('activity_interest', 'rpg_systems', QuestionType::MultipleChoice, allowOther: true),
                    $this->choice('activity_interest', 'wargames', QuestionType::MultipleChoice, allowOther: true),
                    $this->choice('activity_interest', 'run_games', QuestionType::SingleChoice),
                    $this->text('activity_interest', 'comments', QuestionType::ShortText),
                ]),
            ]),
            $this->template('availability', null, [
                $this->section('availability', 'main', [
                    $this->grid('availability', 'days', required: true),
                    $this->choice('availability', 'time_slots', QuestionType::MultipleChoice),
                    $this->text('availability', 'start_time', QuestionType::Time),
                    $this->text('availability', 'proposed_date', QuestionType::Date),
                    $this->text('availability', 'comments', QuestionType::ShortText),
                ]),
            ]),
        ];
    }

    /**
     * The general tournament section, the same in the general template and in every variant.
     */
    private function tournamentSection(): Section
    {
        return $this->section('tournament', 'main', [
            $this->stars('tournament', 'overall'),
            $this->scale('tournament', 'organization'),
            $this->choice('tournament', 'schedule', QuestionType::SingleChoice),
            $this->grid('tournament', 'aspects'),
            $this->scale('tournament', 'sportsmanship'),
            $this->choice('tournament', 'price', QuestionType::SingleChoice),
            $this->choice('tournament', 'would_return', QuestionType::SingleChoice, 'shared.repeat'),
            $this->choice('tournament', 'format', QuestionType::SingleChoice, allowOther: true),
            $this->text('tournament', 'comments', QuestionType::Paragraph),
        ]);
    }

    /**
     * Game questions shared by the Warhammer 40,000 and Age of Sigmar variants.
     *
     * @return list<Question>
     */
    private function wargameQuestions(): array
    {
        return [
            $this->choice('wargame', 'preferred_points', QuestionType::SingleChoice),
            $this->choice('wargame', 'round_time', QuestionType::SingleChoice),
            $this->choice('wargame', 'chess_clock', QuestionType::SingleChoice),
            $this->choice('wargame', 'painting_rule', QuestionType::SingleChoice),
            $this->choice('wargame', 'terrain_density', QuestionType::SingleChoice),
        ];
    }

    /**
     * A template is meant for the kind of subject it is the default of; without one, for general
     * surveys unless $for says otherwise.
     *
     * @param  list<Section>  $sections
     */
    private function template(string $catalogKey, ?string $subjectType, array $sections, ?string $for = null): RecommendedTemplate
    {
        return new RecommendedTemplate(
            catalogKey: $catalogKey,
            subjectType: $subjectType,
            uses: new TemplateUses([$for ?? $subjectType ?? TemplateUses::GENERAL]),
            name: $this->translate("{$catalogKey}.name"),
            description: $this->translate("{$catalogKey}.description"),
            definition: new SurveyDefinition($sections),
        );
    }

    /**
     * @param  list<Question>  $questions
     */
    private function section(string $group, string $id, array $questions): Section
    {
        return new Section($id, $this->translate("{$group}.sections.{$id}"), null, $questions);
    }

    private function stars(string $group, string $key): Question
    {
        // The overall rating opens every template and is its only required question
        return $this->question($group, $key, QuestionType::StarRating, required: true, stars: 5);
    }

    private function scale(string $group, string $key, int $min = 1, int $max = 5): Question
    {
        $scale = new ScaleSettings(
            $min,
            $max,
            $this->translate("{$group}.questions.{$key}.min"),
            $this->translate("{$group}.questions.{$key}.max"),
        );

        return $this->question($group, $key, QuestionType::LinearScale, scale: $scale);
    }

    /**
     * @param  string|null  $labels  where the option labels are, when not under the question itself
     */
    private function choice(string $group, string $key, QuestionType $type, ?string $labels = null, bool $allowOther = false, bool $required = false): Question
    {
        $options = $this->options($labels ?? "{$group}.questions.{$key}.options");

        return $this->question($group, $key, $type, required: $required, options: $options, allowOther: $allowOther);
    }

    private function grid(string $group, string $key, bool $required = false): Question
    {
        $base = "{$group}.questions.{$key}";
        $columns = $this->has("{$base}.columns") ? "{$base}.columns" : 'shared.aspects';

        return $this->question($group, $key, QuestionType::Grid, required: $required, grid: new GridSettings($this->options("{$base}.rows"), $this->options($columns)));
    }

    private function text(string $group, string $key, QuestionType $type): Question
    {
        return $this->question($group, $key, $type);
    }

    /**
     * @param  list<Option>  $options
     */
    private function question(
        string $group,
        string $key,
        QuestionType $type,
        bool $required = false,
        array $options = [],
        bool $allowOther = false,
        ?ScaleSettings $scale = null,
        ?int $stars = null,
        ?GridSettings $grid = null,
    ): Question {
        $base = "{$group}.questions.{$key}";

        // A comment box without its own wording uses the common one
        $title = $key === 'comments' && ! $this->has("{$base}.title") ? 'shared.comments' : "{$base}.title";

        return new Question(
            id: $key,
            key: $key,
            type: $type,
            title: $this->translate($title),
            help: $this->has("{$base}.help") ? $this->translate("{$base}.help") : null,
            required: $required,
            options: $options,
            allowOther: $allowOther,
            scale: $scale,
            stars: $stars,
            grid: $grid,
        );
    }

    /**
     * @return list<Option>
     */
    private function options(string $path): array
    {
        $labels = __("surveys::templates.{$path}");
        if (! is_array($labels) || $labels === []) {
            throw new LogicException("Missing option labels for surveys::templates.{$path}");
        }

        $options = [];
        foreach ($labels as $id => $label) {
            $options[] = new Option((string) $id, is_string($label) ? $label : '');
        }

        return $options;
    }

    private function has(string $path): bool
    {
        return trans()->has("surveys::templates.{$path}");
    }

    private function translate(string $path): string
    {
        $text = __("surveys::templates.{$path}");
        if (! is_string($text) || $text === "surveys::templates.{$path}") {
            throw new LogicException("Missing text surveys::templates.{$path}");
        }

        return $text;
    }
}
