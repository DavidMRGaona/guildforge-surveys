<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Modules\Surveys\Application\Contracts\ResponseStoreInterface;
use Modules\Surveys\Application\DTOs\Trends\QuestionTrendDTO;
use Modules\Surveys\Application\DTOs\Trends\TemplateTrendsDTO;
use Modules\Surveys\Application\DTOs\Trends\TrendFilters;
use Modules\Surveys\Application\DTOs\Trends\TrendPointDTO;
use Modules\Surveys\Application\Services\TrendsService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Domain\ValueObjects\Submission\AnswerValue;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Tests\Support\Modules\ModuleTestCase;

final class TrendsServiceTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private TrendsService $trends;

    private SurveyTemplateId $template;

    protected function setUp(): void
    {
        parent::setUp();

        ExampleThingSource::reset();
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        config(['modules.settings.surveys.min_responses' => 3]);
        $this->trends = $this->app->make(TrendsService::class);
        $this->template = $this->saveTemplate($this->definition());
    }

    public function test_mean_per_quarter_across_surveys(): void
    {
        $this->answerMany($this->survey(['starts' => '2027-01-10']), [['sat' => 3], ['sat' => 4], ['sat' => 5]]);
        $this->answerMany($this->survey(['starts' => '2027-02-20']), [['sat' => 1], ['sat' => 1], ['sat' => 5]]);
        $this->answerMany($this->survey(['starts' => '2027-05-01']), [['sat' => 5], ['sat' => 5], ['sat' => 5]]);

        $trends = $this->panel();

        $this->assertTrue($trends->visible);
        $this->assertSame(3, $trends->surveys);
        $this->assertSame(9, $trends->responses);

        $satisfaction = $this->question($trends, 'satisfaction');
        $this->assertSame(QuestionTrendDTO::MEAN, $satisfaction->measure);
        $this->assertSame([1, 5], [$satisfaction->min, $satisfaction->max]);
        $this->assertSame(['2027-Q1', '2027-Q2'], array_column($satisfaction->periods, 'key'));
        $this->assertSame(['T1 2027', 'T2 2027'], array_column($satisfaction->periods, 'label'));
        $this->assertCount(1, $satisfaction->series);

        $this->assertSame(6, $this->point($satisfaction, 0, '2027-Q1')->answered);
        $this->assertSame(3.17, $this->point($satisfaction, 0, '2027-Q1')->mean);
        $this->assertSame(5.0, $this->point($satisfaction, 0, '2027-Q2')->mean);
    }

    public function test_periods_include_the_empty_ones(): void
    {
        $this->answerMany($this->survey(['starts' => '2027-01-10']), [['sat' => 3], ['sat' => 3], ['sat' => 3]]);
        $this->answerMany($this->survey(['starts' => '2027-08-10']), [['sat' => 4], ['sat' => 4], ['sat' => 4]]);

        $satisfaction = $this->question($this->panel(), 'satisfaction');

        $this->assertSame(['2027-Q1', '2027-Q2', '2027-Q3'], array_column($satisfaction->periods, 'key'));
        $empty = $this->point($satisfaction, 0, '2027-Q2');
        $this->assertSame(0, $empty->answered);
        $this->assertNull($empty->mean);
    }

    public function test_months_and_years(): void
    {
        $this->answerMany($this->survey(['starts' => '2027-01-10']), [['sat' => 3], ['sat' => 3], ['sat' => 3]]);
        $this->answerMany($this->survey(['starts' => '2027-02-10']), [['sat' => 4], ['sat' => 4], ['sat' => 4]]);

        $months = $this->question($this->panel(['period' => 'month']), 'satisfaction');
        $this->assertSame(['2027-01', '2027-02'], array_column($months->periods, 'key'));
        $this->assertSame(['ene 2027', 'feb 2027'], array_column($months->periods, 'label'));

        $years = $this->question($this->panel(['period' => 'year']), 'satisfaction');
        $this->assertSame([['key' => '2027', 'label' => '2027']], $years->periods);
        $this->assertSame(3.5, $this->point($years, 0, '2027')->mean);
    }

    public function test_a_cut_below_the_minimum_shows_no_data(): void
    {
        // Named surveys have no minimum of their own, so a small cut is possible
        $this->answerMany($this->survey(['starts' => '2027-01-10', 'anonymous' => false]), [['sat' => 2]]);
        $this->answerMany($this->survey(['starts' => '2027-04-10', 'anonymous' => false]), [['sat' => 4], ['sat' => 4], ['sat' => 4]]);

        $satisfaction = $this->question($this->panel(), 'satisfaction');

        $hidden = $this->point($satisfaction, 0, '2027-Q1');
        $this->assertTrue($hidden->belowMinimum);
        $this->assertNull($hidden->mean);
        $this->assertSame([], $hidden->shares);
        $this->assertFalse($this->point($satisfaction, 0, '2027-Q2')->belowMinimum);
        $this->assertSame(4.0, $this->point($satisfaction, 0, '2027-Q2')->mean);
    }

    public function test_nothing_shows_when_the_whole_cut_is_below_the_minimum(): void
    {
        $this->answerMany($this->survey(['starts' => '2027-01-10', 'anonymous' => false]), [['sat' => 2], ['sat' => 3]]);

        $trends = $this->panel();

        $this->assertFalse($trends->visible);
        $this->assertSame([], $trends->questions);
    }

    public function test_open_anonymous_surveys_do_not_count(): void
    {
        $this->answerMany($this->survey(['starts' => '2027-01-10', 'status' => SurveyStatus::Open]), [['sat' => 1], ['sat' => 1], ['sat' => 1]]);
        $this->answerMany($this->survey(['starts' => '2027-01-12']), [['sat' => 5], ['sat' => 5], ['sat' => 5]]);

        $trends = $this->panel();

        $this->assertSame(1, $trends->surveys);
        $this->assertSame(5.0, $this->point($this->question($trends, 'satisfaction'), 0, '2027-Q1')->mean);
    }

    public function test_live_results_count_while_open(): void
    {
        $this->answerMany($this->survey(['starts' => '2027-01-10', 'status' => SurveyStatus::Open, 'live' => true]), [['sat' => 2], ['sat' => 2], ['sat' => 2]]);

        $this->assertSame(2.0, $this->point($this->question($this->panel(), 'satisfaction'), 0, '2027-Q1')->mean);
    }

    public function test_an_anonymous_survey_below_the_minimum_never_counts(): void
    {
        $this->answerMany($this->survey(['starts' => '2027-01-10']), [['sat' => 1], ['sat' => 1]]);
        $this->answerMany($this->survey(['starts' => '2027-01-12']), [['sat' => 5], ['sat' => 5], ['sat' => 5]]);

        $point = $this->point($this->question($this->panel(), 'satisfaction'), 0, '2027-Q1');

        $this->assertSame(3, $point->answered);
        $this->assertSame(5.0, $point->mean);
    }

    public function test_overlapping_ranges_never_expose_a_survey_below_the_minimum(): void
    {
        $this->answerMany($this->survey(['starts' => '2027-01-10']), [['sat' => 3], ['sat' => 4], ['sat' => 5]]);
        $this->answerMany($this->survey(['starts' => '2027-01-20']), [['sat' => 1]]);

        $wide = $this->point($this->question($this->panel(['from' => '2027-01-01', 'to' => '2027-01-31']), 'satisfaction'), 0, '2027-Q1');
        $narrow = $this->point($this->question($this->panel(['from' => '2027-01-01', 'to' => '2027-01-15']), 'satisfaction'), 0, '2027-Q1');

        $this->assertEquals($narrow, $wide);
        $this->assertSame(4.0, $wide->mean);
    }

    public function test_a_question_below_its_minimum_leaves_only_that_question_out(): void
    {
        $this->answerMany($this->survey(['starts' => '2027-01-10']), [['sat' => 1, 'stars' => 1], ['sat' => 1, 'stars' => 1], ['sat' => 1]]);
        $this->answerMany($this->survey(['starts' => '2027-01-12']), [['sat' => 5, 'stars' => 5], ['sat' => 5, 'stars' => 5], ['sat' => 5, 'stars' => 5]]);

        $trends = $this->panel();

        $this->assertSame(6, $this->point($this->question($trends, 'satisfaction'), 0, '2027-Q1')->answered);
        $stars = $this->point($this->question($trends, 'stars'), 0, '2027-Q1');
        $this->assertSame(3, $stars->answered);
        $this->assertSame(5.0, $stars->mean);
    }

    public function test_minimum_zero_shows_single_answers(): void
    {
        config(['modules.settings.surveys.min_responses' => 0]);
        $this->answerMany($this->survey(['starts' => '2027-01-10']), [['sat' => 2]]);

        $point = $this->point($this->question($this->panel(), 'satisfaction'), 0, '2027-Q1');

        $this->assertFalse($point->belowMinimum);
        $this->assertSame(2.0, $point->mean);
    }

    public function test_filters_by_subject_type_organizer_system_and_dates(): void
    {
        [$ana, $bea] = $this->people(2);
        $this->answerMany($this->survey(['starts' => '2027-01-10', 'organizers' => [$ana], 'system' => 'Rolemaster']), [['sat' => 1], ['sat' => 1], ['sat' => 1]]);
        $this->answerMany($this->survey(['starts' => '2027-02-10', 'organizers' => [$bea], 'system' => 'Pathfinder']), [['sat' => 2], ['sat' => 2], ['sat' => 2]]);
        $this->answerMany($this->survey(['starts' => '2027-03-10', 'organizers' => [$ana], 'system' => null, 'type' => 'other-thing']), [['sat' => 3], ['sat' => 3], ['sat' => 3]]);
        $this->answerMany($this->survey(['starts' => '2027-03-20', 'subject' => false]), [['sat' => 4], ['sat' => 4], ['sat' => 4]]);

        $this->assertSame(4, $this->panel()->surveys);
        $this->assertSame(2, $this->panel(['type' => ExampleThingSource::TYPE])->surveys);
        $this->assertSame(2, $this->panel(['organizer' => $ana])->surveys);
        $this->assertSame(1, $this->panel(['system' => 'Pathfinder'])->surveys);
        $this->assertSame(2, $this->panel(['from' => '2027-02-01', 'to' => '2027-03-15'])->surveys);
        $this->assertSame(1.0, $this->point($this->question($this->panel(['organizer' => $ana, 'system' => 'Rolemaster']), 'satisfaction'), 0, '2027-Q1')->mean);
    }

    public function test_the_subject_date_places_a_survey(): void
    {
        // The game ends on 31 March at 23:30 UTC, already 1 April in Madrid, and the survey opens weeks later
        $this->answerMany($this->survey(['starts' => '2027-03-31T23:30:00+00:00', 'opens' => '2027-05-10']), [['sat' => 2], ['sat' => 2], ['sat' => 2]]);
        $this->answerMany($this->survey(['subject' => false, 'opens' => '2027-08-10']), [['sat' => 4], ['sat' => 4], ['sat' => 4]]);

        $satisfaction = $this->question($this->panel(), 'satisfaction');

        $this->assertSame(['2027-Q2', '2027-Q3'], array_column($satisfaction->periods, 'key'));
        $this->assertSame(2.0, $this->point($satisfaction, 0, '2027-Q2')->mean);
        $this->assertSame(4.0, $this->point($satisfaction, 0, '2027-Q3')->mean);
    }

    public function test_compare_by_organizer_counts_a_survey_for_each_organizer(): void
    {
        [$ana, $bea] = $this->people(2);
        $this->answerMany($this->survey(['starts' => '2027-01-10', 'organizers' => [$ana, $bea]]), [['sat' => 2, 'best' => 'o-1'], ['sat' => 2, 'best' => 'o-1'], ['sat' => 2, 'best' => 'o-2']]);
        $this->answerMany($this->survey(['starts' => '2027-01-12', 'organizers' => [$ana]]), [['sat' => 4, 'best' => 'o-2'], ['sat' => 4, 'best' => 'o-2'], ['sat' => 4, 'best' => 'o-2']]);

        $trends = $this->panel(['compare' => 'organizer']);
        $satisfaction = $this->question($trends, 'satisfaction');

        $this->assertSame([$ana, $bea], array_map(static fn ($series): ?string => $series->id, $satisfaction->series));
        $this->assertSame(['Persona 1', 'Persona 2'], array_map(static fn ($series): string => $series->label, $satisfaction->series));
        $this->assertSame(3.0, $this->point($satisfaction, 0, '2027-Q1')->mean);
        $this->assertSame(2.0, $this->point($satisfaction, 1, '2027-Q1')->mean);

        // Choices compare one bar per series over the whole range
        $best = $this->question($trends, 'best_part');
        $this->assertSame([['key' => QuestionTrendDTO::TOTAL, 'label' => 'Todo el periodo']], $best->periods);
        $this->assertEqualsWithDelta(['o-1' => 2 / 6, 'o-2' => 4 / 6, Submission::OTHER => 0.0], $this->point($best, 0, QuestionTrendDTO::TOTAL)->shares, 0.0001);
        $this->assertEqualsWithDelta(['o-1' => 2 / 3, 'o-2' => 1 / 3, Submission::OTHER => 0.0], $this->point($best, 1, QuestionTrendDTO::TOTAL)->shares, 0.0001);
    }

    public function test_compare_by_organizer_uses_current_names(): void
    {
        [$ana] = $this->people(1);
        $gone = (string) Str::uuid();
        $this->answerMany($this->survey(['starts' => '2027-01-10', 'organizers' => [$ana, $gone]]), [['sat' => 2], ['sat' => 2], ['sat' => 2]]);
        UserModel::query()->whereKey($ana)->update(['name' => 'Anónimo']);

        $series = $this->question($this->panel(['compare' => 'organizer']), 'satisfaction')->series;

        $this->assertSame(['Anónimo', 'Cuenta eliminada'], array_map(static fn ($one): string => $one->label, $series));
    }

    public function test_compare_by_system_and_subject_type(): void
    {
        $this->answerMany($this->survey(['starts' => '2027-01-10', 'system' => 'Rolemaster']), [['sat' => 1], ['sat' => 1], ['sat' => 1]]);
        $this->answerMany($this->survey(['starts' => '2027-01-11', 'system' => null]), [['sat' => 2], ['sat' => 2], ['sat' => 2]]);
        $this->answerMany($this->survey(['starts' => '2027-01-12', 'subject' => false]), [['sat' => 3], ['sat' => 3], ['sat' => 3], ['sat' => 3]]);

        $systems = $this->question($this->panel(['compare' => 'game_system']), 'satisfaction')->series;
        $this->assertSame(['Sin sistema de juego', 'Rolemaster'], array_map(static fn ($one): string => $one->label, $systems));

        $types = $this->question($this->panel(['compare' => 'subject_type']), 'satisfaction')->series;
        $this->assertSame(['Cosa de ejemplo', 'Encuesta general'], array_map(static fn ($one): string => $one->label, $types));
        $this->assertSame([ExampleThingSource::TYPE, ''], array_map(static fn ($one): ?string => $one->id, $types));
    }

    public function test_more_than_eight_series_are_grouped(): void
    {
        config(['modules.settings.surveys.min_responses' => 0]);
        foreach (range(1, 9) as $n) {
            $this->answerMany($this->survey(['starts' => '2027-01-10', 'system' => "Sistema {$n}"]), array_fill(0, 10 - $n, ['sat' => 3]));
        }

        $series = $this->question($this->panel(['compare' => 'game_system']), 'satisfaction')->series;

        $this->assertCount(8, $series);
        $this->assertSame('Sistema 1', $series[0]->label);
        $this->assertSame('Sistema 7', $series[6]->label);
        $this->assertNull($series[7]->id);
        $this->assertSame('Resto', $series[7]->label);
        $this->assertSame(2 + 1, $this->point($this->question($this->panel(['compare' => 'game_system']), 'satisfaction'), 7, '2027-Q1')->answered);
    }

    public function test_shares_for_choices_and_checkboxes(): void
    {
        $this->answerMany($this->survey(['starts' => '2027-01-10']), [
            ['best' => 'o-1', 'liked' => ['o-1', 'o-2']],
            ['best' => 'o-1', 'liked' => ['o-1']],
            ['best' => ['other' => 'Los dados'], 'liked' => ['o-2']],
        ]);

        $trends = $this->panel();

        $best = $this->question($trends, 'best_part');
        $this->assertSame(QuestionTrendDTO::SHARES, $best->measure);
        $this->assertSame([['id' => 'o-1', 'label' => 'Historia'], ['id' => 'o-2', 'label' => 'Combate'], ['id' => Submission::OTHER, 'label' => 'Otra']], $best->options);
        $this->assertEqualsWithDelta(['o-1' => 2 / 3, 'o-2' => 0.0, Submission::OTHER => 1 / 3], $this->point($best, 0, '2027-Q1')->shares, 0.0001);

        // Checkboxes: the share of the people who answered, so they add up to more than one
        $liked = $this->question($trends, 'liked');
        $this->assertEqualsWithDelta(['o-1' => 2 / 3, 'o-2' => 2 / 3], $this->point($liked, 0, '2027-Q1')->shares, 0.0001);
    }

    public function test_grid_rows_trend_as_their_own_charts(): void
    {
        $this->answerMany($this->survey(['starts' => '2027-01-10']), [
            ['grid' => ['r-1' => 'c-1', 'r-2' => 'c-2']],
            ['grid' => ['r-1' => 'c-2', 'r-2' => 'c-2']],
            ['grid' => ['r-1' => 'c-2', 'r-2' => 'c-2']],
        ]);

        $trends = $this->panel();

        $mood = $this->question($trends, 'aspects.r-1');
        $this->assertSame('¿Qué tal fue? · Ambiente', $mood->title);
        $this->assertSame(QuestionTrendDTO::SHARES, $mood->measure);
        $this->assertSame([['id' => 'c-1', 'label' => 'Mal'], ['id' => 'c-2', 'label' => 'Bien']], $mood->options);
        $this->assertEqualsWithDelta(['c-1' => 1 / 3, 'c-2' => 2 / 3], $this->point($mood, 0, '2027-Q1')->shares, 0.0001);
        $this->assertEqualsWithDelta(['c-1' => 0.0, 'c-2' => 1.0], $this->point($this->question($trends, 'aspects.r-2'), 0, '2027-Q1')->shares, 0.0001);
    }

    public function test_questions_without_key_or_trend_type(): void
    {
        $this->answerMany($this->survey(['starts' => '2027-01-10']), [['sat' => 3], ['sat' => 3], ['sat' => 3]]);

        $trends = $this->panel();

        $this->assertSame(['satisfaction', 'stars', 'best_part', 'liked', 'aspects.r-1', 'aspects.r-2', 'comment', 'day'], array_map(static fn (QuestionTrendDTO $question): string => $question->key, $trends->questions));
        $this->assertSame(QuestionTrendDTO::NONE, $this->question($trends, 'comment')->measure);
        $this->assertSame([], $this->question($trends, 'comment')->series);
        $this->assertSame(QuestionTrendDTO::NONE, $this->question($trends, 'day')->measure);

        $only = $this->trends->forPanel($this->template, new TrendFilters, onlyKey: 'stars');
        $this->assertSame(['stars'], array_map(static fn (QuestionTrendDTO $question): string => $question->key, $only->questions));
    }

    public function test_template_changes_after_the_fact(): void
    {
        $old = $this->definition();
        $old['sections'][0]['questions'][2]['options'][] = ['id' => 'o-3', 'label' => 'Antigua'];
        $old['sections'][0]['questions'][0]['scale'] = ['min' => 0, 'max' => 10, 'min_label' => null, 'max_label' => null];
        $this->answerMany($this->survey(['starts' => '2027-01-10', 'definition' => $old]), [['sat' => 9, 'best' => 'o-3'], ['sat' => 9, 'best' => 'o-1'], ['sat' => 9, 'best' => 'o-3']]);
        $this->answerMany($this->survey(['starts' => '2027-01-12']), [['sat' => 4, 'best' => 'o-1'], ['sat' => 4, 'best' => 'o-1'], ['sat' => 4, 'best' => 'o-1']]);

        $trends = $this->panel();

        // A scale that no longer matches the template is left out of that question only
        $this->assertSame(4.0, $this->point($this->question($trends, 'satisfaction'), 0, '2027-Q1')->mean);

        $best = $this->question($trends, 'best_part');
        $this->assertSame(['o-1', 'o-2', Submission::OTHER, QuestionTrendDTO::PREVIOUS], array_column($best->options, 'id'));
        $this->assertSame('Opciones anteriores', $best->options[3]['label']);
        $this->assertEqualsWithDelta(['o-1' => 4 / 6, 'o-2' => 0.0, Submission::OTHER => 0.0, QuestionTrendDTO::PREVIOUS => 2 / 6], $this->point($best, 0, '2027-Q1')->shares, 0.0001);
    }

    public function test_a_question_that_changed_type_is_left_out(): void
    {
        $old = $this->definition();
        $old['sections'][0]['questions'][1] = ['id' => 'q-stars', 'key' => 'stars', 'type' => 'linear_scale', 'title' => 'Estrellas', 'help' => null, 'required' => false, 'scale' => ['min' => 1, 'max' => 5, 'min_label' => null, 'max_label' => null]];
        $this->answerMany($this->survey(['starts' => '2027-01-10', 'definition' => $old]), [['sat' => 3, 'stars' => 1], ['sat' => 3, 'stars' => 1], ['sat' => 3, 'stars' => 1]]);

        $this->assertSame([], $this->question($this->panel(), 'stars')->series);
    }

    public function test_organizer_sees_only_their_surveys_and_not_hidden_questions(): void
    {
        [$ana, $bea] = $this->people(2);
        $this->answerMany($this->survey(['starts' => '2027-01-10', 'organizers' => [$ana]]), [['sat' => 1, 'stars' => 1], ['sat' => 1, 'stars' => 1], ['sat' => 1, 'stars' => 1]]);
        $this->answerMany($this->survey(['starts' => '2027-01-11', 'organizers' => [$ana], 'hidden' => ['q-sat']]), [['sat' => 5, 'stars' => 5], ['sat' => 5, 'stars' => 5], ['sat' => 5, 'stars' => 5]]);
        $this->answerMany($this->survey(['starts' => '2027-01-12', 'organizers' => [$bea]]), [['sat' => 3, 'stars' => 3], ['sat' => 3, 'stars' => 3], ['sat' => 3, 'stars' => 3]]);

        $trends = $this->trends->forOrganizer($this->template, $ana, TrendFilters::fromInput(['organizer' => $bea, 'compare' => 'organizer']));

        $this->assertSame(2, $trends->surveys);
        $this->assertSame(1.0, $this->point($this->question($trends, 'satisfaction'), 0, '2027-Q1')->mean);
        $this->assertSame(3.0, $this->point($this->question($trends, 'stars'), 0, '2027-Q1')->mean);
        $this->assertCount(1, $this->question($trends, 'stars')->series);

        // The panel shows hidden questions
        $this->assertSame(3.0, $this->point($this->question($this->panel(), 'satisfaction'), 0, '2027-Q1')->mean);
    }

    public function test_filter_options_list_types_organizers_and_systems(): void
    {
        [$ana, $bea] = $this->people(2);
        $this->survey(['starts' => '2027-01-10', 'organizers' => [$ana], 'system' => 'Rolemaster']);
        $this->survey(['starts' => '2027-01-11', 'organizers' => [$bea, $ana], 'system' => 'Pathfinder', 'type' => 'other-thing']);
        $this->survey(['starts' => '2027-01-12', 'subject' => false]);
        $this->survey(['starts' => '2027-01-13', 'organizers' => [$bea], 'system' => 'Aquelarre', 'status' => SurveyStatus::Draft]);

        $options = $this->trends->filterOptions($this->template);

        $this->assertSame([['value' => ExampleThingSource::TYPE, 'label' => 'Cosa de ejemplo'], ['value' => 'other-thing', 'label' => 'other-thing']], $options->subjectTypes);
        $this->assertSame([['value' => $ana, 'label' => 'Persona 1'], ['value' => $bea, 'label' => 'Persona 2']], $options->organizers);
        $this->assertSame(['Pathfinder', 'Rolemaster'], $options->gameSystems);

        $own = $this->trends->filterOptions($this->template, $bea);
        $this->assertSame([], $own->organizers);
        $this->assertSame(['Pathfinder'], $own->gameSystems);
    }

    public function test_templates_with_surveys(): void
    {
        [$ana] = $this->people(1);
        $other = $this->saveTemplate($this->definition(), 'Valoración del torneo');
        $this->saveTemplate($this->definition(), 'Plantilla sin usar');
        $this->survey(['starts' => '2027-01-10', 'organizers' => [$ana]]);
        $this->survey(['starts' => '2027-01-10', 'template' => $other]);

        $this->assertSame([['id' => $this->template->value, 'name' => 'Valoración de la partida'], ['id' => $other->value, 'name' => 'Valoración del torneo']], $this->trends->templatesFor());
        $this->assertSame([['id' => $this->template->value, 'name' => 'Valoración de la partida']], $this->trends->templatesFor($ana));
    }

    /**
     * Keyed questions of every kind trends know, a paragraph and a date with keys, and nothing else.
     *
     * @return array<string, mixed>
     */
    private function definition(): array
    {
        return ['sections' => [['id' => 's-1', 'title' => null, 'description' => null, 'questions' => [
            ['id' => 'q-sat', 'key' => 'satisfaction', 'type' => 'linear_scale', 'title' => '¿Te gustó?', 'help' => null, 'required' => false, 'scale' => ['min' => 1, 'max' => 5, 'min_label' => null, 'max_label' => null]],
            ['id' => 'q-stars', 'key' => 'stars', 'type' => 'star_rating', 'title' => 'Estrellas', 'help' => null, 'required' => false, 'stars' => 5],
            ['id' => 'q-best', 'key' => 'best_part', 'type' => 'single_choice', 'title' => 'Lo mejor', 'help' => null, 'required' => false, 'allow_other' => true, 'options' => [['id' => 'o-1', 'label' => 'Historia'], ['id' => 'o-2', 'label' => 'Combate']]],
            ['id' => 'q-liked', 'key' => 'liked', 'type' => 'multiple_choice', 'title' => 'Qué te gustó', 'help' => null, 'required' => false, 'options' => [['id' => 'o-1', 'label' => 'Mapa'], ['id' => 'o-2', 'label' => 'Fichas']]],
            ['id' => 'q-grid', 'key' => 'aspects', 'type' => 'grid', 'title' => '¿Qué tal fue?', 'help' => null, 'required' => false, 'grid' => [
                'rows' => [['id' => 'r-1', 'label' => 'Ambiente'], ['id' => 'r-2', 'label' => 'Ritmo']],
                'columns' => [['id' => 'c-1', 'label' => 'Mal'], ['id' => 'c-2', 'label' => 'Bien']],
            ]],
            ['id' => 'q-comment', 'key' => 'comment', 'type' => 'paragraph', 'title' => 'Comentarios', 'help' => null, 'required' => false],
            ['id' => 'q-day', 'key' => 'day', 'type' => 'date', 'title' => 'Día', 'help' => null, 'required' => false],
        ]]]];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function saveTemplate(array $definition, string $name = 'Valoración de la partida'): SurveyTemplateId
    {
        $template = new SurveyTemplate(SurveyTemplateId::generate(), $name, null, SurveyKind::Form, SurveyDefinition::fromArray($definition));
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($template);

        return $template->id();
    }

    /**
     * A survey made from the template that already opened (and by default closed), with an
     * extra question without key. Options: starts, opens, organizers, system, type, subject,
     * anonymous, status, live, hidden, definition, template.
     *
     * @param  array<string, mixed>  $options
     */
    private function survey(array $options = []): SurveyId
    {
        /** @var array<string, mixed> $definition */
        $definition = $options['definition'] ?? $this->definition();
        $definition['sections'][0]['questions'][] = ['id' => 'q-extra', 'key' => null, 'type' => 'linear_scale', 'title' => 'Extra', 'help' => null, 'required' => false, 'scale' => ['min' => 1, 'max' => 5, 'min_label' => null, 'max_label' => null]];

        $starts = new DateTimeImmutable(is_string($options['starts'] ?? null) ? $options['starts'] : '2027-01-10T18:00:00+00:00');
        $opens = isset($options['opens']) && is_string($options['opens']) ? new DateTimeImmutable($options['opens']) : $starts->modify('+1 day');
        $status = $options['status'] ?? SurveyStatus::Closed;
        $subject = ($options['subject'] ?? true) === false ? null : new SubjectSnapshot(
            sourceType: is_string($options['type'] ?? null) ? $options['type'] : ExampleThingSource::TYPE,
            subjectId: (string) Str::uuid(),
            title: 'Partida de ejemplo',
            startsAt: $starts,
            endsAt: $starts->modify('+4 hours'),
            organizerIds: is_array($options['organizers'] ?? null) ? array_values($options['organizers']) : [],
            attributes: array_key_exists('system', $options) && $options['system'] === null ? [] : [['key' => 'game_system', 'label' => 'Sistema de juego', 'value' => is_string($options['system'] ?? null) ? $options['system'] : 'Rolemaster']],
            url: null,
        );

        $survey = new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Valoración de la partida',
            description: null,
            definition: SurveyDefinition::fromArray($definition),
            anonymous: ($options['anonymous'] ?? true) === true,
            results: new ResultsSettings(liveResults: ($options['live'] ?? false) === true, hiddenQuestionIds: is_array($options['hidden'] ?? null) ? array_values($options['hidden']) : []),
            audienceType: $subject instanceof SubjectSnapshot ? AudienceType::Subject : AudienceType::AllUsers,
            templateId: $options['template'] ?? $this->template,
            subject: $subject,
            status: $status,
            opensAt: $status === SurveyStatus::Draft ? null : $opens,
            closesAt: $status === SurveyStatus::Open ? now()->addDays(7)->toDateTimeImmutable() : $opens->modify('+7 days'),
            closedAt: $status === SurveyStatus::Closed ? $opens->modify('+7 days') : null,
            audienceSize: 10,
        );
        $this->app->make(SurveyRepositoryInterface::class)->save($survey);

        return $survey->id();
    }

    /**
     * Stores responses as the public link would: no person on either side.
     * Keys: sat, stars (numbers), best (option id or ['other' => text]), liked (option ids), grid (row => column).
     *
     * @param  list<array<string, mixed>>  $responses
     */
    private function answerMany(SurveyId $id, array $responses): void
    {
        $store = $this->app->make(ResponseStoreInterface::class);

        foreach ($responses as $answers) {
            $values = [];
            foreach ($answers as $key => $answer) {
                match ($key) {
                    'sat' => $values[] = new AnswerValue('q-sat', number: is_int($answer) ? $answer : null),
                    'stars' => $values[] = new AnswerValue('q-stars', number: is_int($answer) ? $answer : null),
                    'best' => $values[] = is_array($answer)
                        ? new AnswerValue('q-best', text: is_string($answer['other'] ?? null) ? $answer['other'] : null)
                        : new AnswerValue('q-best', optionId: is_string($answer) ? $answer : null),
                    'liked' => array_push($values, ...array_map(static fn (mixed $option): AnswerValue => new AnswerValue('q-liked', optionId: is_string($option) ? $option : null), is_array($answer) ? $answer : [])),
                    'grid' => array_push($values, ...array_map(static fn (mixed $column, mixed $row): AnswerValue => new AnswerValue('q-grid', optionId: is_string($column) ? $column : null, rowId: (string) $row), is_array($answer) ? array_values($answer) : [], is_array($answer) ? array_keys($answer) : [])),
                    default => null,
                };
            }

            $store->record($id, null, null, new DateTimeImmutable('2027-06-01'), null, null, $values);
        }
    }

    /**
     * @return list<string> ids of people named «Persona 1», «Persona 2»…
     */
    private function people(int $count): array
    {
        return array_map(static fn (int $n): string => (string) UserModel::factory()->create(['name' => "Persona {$n}"])->id, range(1, $count));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function panel(array $input = []): TemplateTrendsDTO
    {
        return $this->trends->forPanel($this->template, TrendFilters::fromInput($input));
    }

    private function question(TemplateTrendsDTO $trends, string $key): QuestionTrendDTO
    {
        foreach ($trends->questions as $question) {
            if ($question->key === $key) {
                return $question;
            }
        }

        $this->fail("No trend for {$key}.");
    }

    private function point(QuestionTrendDTO $question, int $series, string $period): TrendPointDTO
    {
        foreach ($question->series[$series]->points ?? [] as $point) {
            if ($point->period === $period) {
                return $point;
            }
        }

        $this->fail("No point {$period} in series {$series} of {$question->key}.");
    }
}
