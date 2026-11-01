<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\DTOs\Results\QuestionResultDTO;
use Modules\Surveys\Application\DTOs\Results\SurveyResultsDTO;
use Modules\Surveys\Application\Services\ResultsService;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Enums\ChartType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Tests\Support\AnswersExampleSurveys;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

final class ResultsServiceTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    use AnswersExampleSurveys;

    private ResultsService $results;

    protected function setUp(): void
    {
        parent::setUp();

        $this->results = $this->app->make(ResultsService::class);
        $this->createExamplePeople();
    }

    public function test_counts_the_answers_of_every_question_type(): void
    {
        [$id] = $this->openForm(anonymous: false);
        $this->answer($id, 0, ['q-single' => 'o-1', 'q-multiple' => ['o-1', 'o-2'], 'q-scale' => 1, 'q-stars' => 5, 'q-date' => '2030-01-10', 'q-time' => '19:15', 'q-grid' => ['r-1' => 'c-1', 'r-2' => 'c-2'], 'q-short' => 'Genial']);
        $this->answer($id, 1, ['q-single' => 'o-1', 'q-multiple' => ['o-2'], 'q-scale' => 4, 'q-stars' => 4, 'q-date' => '2030-01-10', 'q-time' => '19:45', 'q-grid' => ['r-1' => 'c-1', 'r-2' => 'c-1'], 'q-short' => 'Bien']);
        $this->answer($id, 2, ['q-single' => ['options' => [Submission::OTHER], 'other' => 'Una distinta'], 'q-multiple' => ['o-2'], 'q-scale' => 5, 'q-stars' => 4, 'q-date' => '2030-01-12', 'q-time' => '20:00', 'q-grid' => ['r-1' => 'c-2', 'r-2' => 'c-2'], 'q-short' => 'Regular']);

        $results = $this->results->forPanel($id);

        $this->assertTrue($results->visible);
        $this->assertSame(3, $results->responses);
        $this->assertSame(3, $results->audienceSize);
        $this->assertSame(1.0, $results->rate);

        $single = $this->question($results, 'q-single');
        $this->assertSame(3, $single->answered);
        $this->assertSame(ChartType::Bar, $single->chartType);
        $this->assertSame(['o-1' => 2, 'o-2' => 0, Submission::OTHER => 1], $this->counts($single));
        $this->assertSame(['Una distinta'], $single->texts);

        $this->assertSame(['o-1' => 1, 'o-2' => 3], $this->counts($this->question($results, 'q-multiple')));

        $scale = $this->question($results, 'q-scale');
        $this->assertSame([1 => 1, 2 => 0, 3 => 0, 4 => 1, 5 => 1], $this->counts($scale));
        $this->assertEqualsWithDelta(3.33, $scale->mean, 0.01);
        $this->assertSame(4.0, $scale->median);
        $this->assertSame(4.0, $this->question($results, 'q-stars')->median);

        $this->assertSame(['2030-01-10' => 2, '2030-01-12' => 1], $this->counts($this->question($results, 'q-date')));
        $this->assertSame(['19:00' => 2, '20:00' => 1], $this->counts($this->question($results, 'q-time')));

        $grid = $this->question($results, 'q-grid')->grid;
        $this->assertNotNull($grid);
        $this->assertSame(['r-1' => ['c-1' => 2, 'c-2' => 1], 'r-2' => ['c-1' => 1, 'c-2' => 2]], $grid->counts);

        $this->assertEqualsCanonicalizing(['Genial', 'Bien', 'Regular'], $this->question($results, 'q-short')->texts);
        $this->assertSame(ChartType::List, $this->question($results, 'q-short')->chartType);
    }

    public function test_an_anonymous_survey_shows_nothing_until_it_ends_with_enough_responses(): void
    {
        [$id] = $this->openForm(anonymous: true);
        $this->answer($id, 0, ['q-short' => 'Secreto']);
        $this->answer($id, 1, ['q-short' => 'Otro secreto']);

        $open = $this->results->forPanel($id);
        $this->assertFalse($open->visible);
        $this->assertSame('not_closed', $open->hiddenReason);
        $this->assertSame([], $open->questions);
        $this->assertSame(2, $open->responses);

        $this->app->make(SurveyLifecycleService::class)->close($id);
        $closed = $this->results->forPanel($id);
        $this->assertFalse($closed->visible);
        $this->assertSame('below_minimum', $closed->hiddenReason);
        $this->assertSame([], $closed->questions);

        config(['modules.settings.surveys.min_responses' => 0]);
        $this->assertTrue($this->results->forPanel($id)->visible);
    }

    public function test_an_anonymous_question_answered_by_fewer_than_the_minimum_stays_hidden(): void
    {
        $definition = SurveyDefinitionTest::everyTypeDefinition();
        $definition['sections'][0]['questions'][0]['required'] = false;
        [$id] = $this->openExample(SurveyKind::Form, SurveyDefinition::fromArray($definition), true, new ResultsSettings(liveResults: true));
        $this->answer($id, 0, ['q-short' => 'Solo lo escribo yo']);
        $this->answer($id, 1, ['q-short' => '']);
        $this->answer($id, 2, ['q-short' => '']);

        $results = $this->results->forPanel($id);

        $this->assertTrue($results->visible);
        $short = $this->question($results, 'q-short');
        $this->assertTrue($short->belowMinimum);
        $this->assertSame(1, $short->answered);
        $this->assertSame([], $short->texts);
        $this->assertFalse($this->question($results, 'q-single')->belowMinimum);
    }

    public function test_open_link_responses_do_not_reach_the_minimum(): void
    {
        [$id, $opened] = $this->openExample(SurveyKind::Form, SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()), true, new ResultsSettings(liveResults: true), publicLink: true);
        $this->answer($id, 0);
        $this->answer($id, 1);
        foreach (range(1, 5) as $ignored) {
            $this->answerPublicly($id, $opened);
        }

        $results = $this->results->forPanel($id);

        $this->assertSame('below_minimum', $results->hiddenReason);
        $this->assertSame(7, $results->responses);
    }

    public function test_open_link_answers_do_not_reach_a_question_minimum(): void
    {
        $definition = SurveyDefinitionTest::everyTypeDefinition();
        $definition['sections'][0]['questions'][0]['required'] = false;
        [$id, $opened] = $this->openExample(SurveyKind::Form, SurveyDefinition::fromArray($definition), true, new ResultsSettings(liveResults: true), publicLink: true);
        $this->answer($id, 0, ['q-short' => 'Solo lo escribo yo']);
        $this->answer($id, 1, ['q-short' => '']);
        $this->answer($id, 2, ['q-short' => '']);
        $this->answerPublicly($id, $opened, ['q-short' => 'Relleno']);
        $this->answerPublicly($id, $opened, ['q-short' => 'Más relleno']);

        $results = $this->results->forPanel($id);

        $this->assertTrue($results->visible);
        $short = $this->question($results, 'q-short');
        $this->assertTrue($short->belowMinimum);
        $this->assertSame([], $short->texts);
    }

    public function test_open_link_responses_count_in_the_results_once_verified_ones_reach_the_minimum(): void
    {
        [$id, $opened] = $this->openExample(SurveyKind::Form, SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()), true, new ResultsSettings(liveResults: true), publicLink: true);
        $this->answer($id, 0);
        $this->answer($id, 1);
        $this->answer($id, 2);
        $this->answerPublicly($id, $opened, ['q-single' => 'o-1']);

        $results = $this->results->forPanel($id);

        $this->assertTrue($results->visible);
        $this->assertSame(4, $results->responses);
        $this->assertSame(4, $this->question($results, 'q-single')->answered);
    }

    public function test_live_results_show_an_anonymous_survey_while_open_but_keep_the_minimum(): void
    {
        [$id] = $this->openForm(anonymous: true, results: new ResultsSettings(liveResults: true));
        $this->answer($id, 0);
        $this->assertSame('below_minimum', $this->results->forPanel($id)->hiddenReason);

        $this->answer($id, 1);
        $this->answer($id, 2);
        $this->assertTrue($this->results->forPanel($id)->visible);
    }

    public function test_the_panel_marks_hidden_questions_and_respondents_never_get_them_or_free_text(): void
    {
        [$id] = $this->openForm(anonymous: false, results: new ResultsSettings(liveResults: true, visibleToRespondents: true, hiddenQuestionIds: ['q-dropdown']));
        $this->answer($id, 0);

        $this->assertTrue($this->question($this->results->forPanel($id), 'q-dropdown')->hidden);

        $ids = array_map(static fn (QuestionResultDTO $question): string => $question->questionId, $this->results->forRespondents($id)->questions);
        $this->assertNotContains('q-dropdown', $ids);
        $this->assertNotContains('q-short', $ids);
        $this->assertNotContains('q-paragraph', $ids);
        $this->assertContains('q-single', $ids);
        $this->assertSame([], $this->question($this->results->forRespondents($id), 'q-single')->texts);
    }

    public function test_the_organizer_gets_what_the_panel_gets_without_hidden_questions(): void
    {
        [$id] = $this->openForm(anonymous: false, results: new ResultsSettings(hiddenQuestionIds: ['q-dropdown']));
        $this->answer($id, 0);

        $results = $this->results->forOrganizer($id);
        $ids = array_map(static fn (QuestionResultDTO $question): string => $question->questionId, $results->questions);

        $this->assertTrue($results->visible);
        $this->assertNotContains('q-dropdown', $ids);
        $this->assertContains('q-short', $ids);
        $this->assertNotSame([], $this->question($results, 'q-short')->texts);
    }

    public function test_the_organizer_waits_like_the_panel_for_an_anonymous_survey(): void
    {
        [$id] = $this->openForm(anonymous: true);
        $this->answer($id, 0);
        $this->answer($id, 1);
        $this->answer($id, 2);

        $this->assertSame(SurveyResultsDTO::NOT_CLOSED, $this->results->forOrganizer($id)->hiddenReason);
    }

    public function test_respondents_see_results_only_when_the_survey_lets_them_and_it_is_live_or_ended(): void
    {
        [$hidden] = $this->openForm(anonymous: false, results: new ResultsSettings(liveResults: true, visibleToRespondents: false));
        $this->answer($hidden, 0);
        $this->assertFalse($this->results->forRespondents($hidden)->visible);

        [$notLive] = $this->openForm(anonymous: false, results: new ResultsSettings(visibleToRespondents: true));
        $this->answer($notLive, 0);
        $this->assertTrue($this->results->forPanel($notLive)->visible);
        $this->assertFalse($this->results->forRespondents($notLive)->visible);
        $this->assertSame('not_closed', $this->results->forRespondents($notLive)->hiddenReason);
    }

    public function test_a_named_poll_shows_who_voted_each_option_and_an_anonymous_one_does_not(): void
    {
        [$named, $opened] = $this->openPoll(anonymous: false);
        $submissions = $this->app->make(SubmissionService::class);
        $submissions->submit($named, Respondent::account($this->people[1]->id), ['q-1' => 'o-1']);
        $submissions->submit($named, Respondent::account($this->people[0]->id), ['q-1' => 'o-1']);
        $submissions->submit($named, Respondent::guest($opened->guestTokens['guest-1']), ['q-1' => 'o-2']);

        $buckets = $this->question($this->results->forRespondents($named), 'q-1')->buckets;
        $this->assertSame(['Persona A', 'Persona B'], $buckets[0]->voters);
        $this->assertSame(['Invitada de ejemplo'], $buckets[1]->voters);

        [$anonymous] = $this->openPoll(anonymous: true);
        foreach ([0, 1, 2] as $index) {
            $submissions->submit($anonymous, Respondent::account($this->people[$index]->id), ['q-1' => 'o-1']);
        }

        $this->assertSame([], $this->question($this->results->forRespondents($anonymous), 'q-1')->buckets[0]->voters);
    }

    public function test_a_question_after_a_jump_says_how_many_reached_it(): void
    {
        $id = $this->answerBranching(anonymous: false);

        $results = $this->results->forPanel($id);

        // "No" skipped the campaign section: two of the three responses reached it
        $finale = $this->question($results, 'q-finale');
        $this->assertSame(2, $finale->reached);
        $this->assertSame(2, $finale->answered);
        // Everyone reached the others: nothing to point out
        $this->assertNull($this->question($results, 'q-played')->reached);
        $this->assertNull($this->question($results, 'q-comments')->reached);
        $this->assertNull($this->question($this->results->forRespondents($id), 'q-played')->reached);
    }

    public function test_an_anonymous_survey_says_how_many_reached_a_question_only_from_the_minimum(): void
    {
        config(['modules.settings.surveys.min_responses' => 2]);
        $id = $this->answerBranching(anonymous: true);

        $this->assertSame(2, $this->question($this->results->forPanel($id), 'q-finale')->reached);

        config(['modules.settings.surveys.min_responses' => 3]);
        $finale = $this->question($this->results->forPanel($id), 'q-finale');

        $this->assertTrue($finale->belowMinimum);
        $this->assertNull($finale->reached);
    }

    public function test_a_survey_without_audience_has_no_rate(): void
    {
        [$id] = $this->openPoll(anonymous: false, audience: false);

        $results = $this->results->forPanel($id);

        $this->assertSame(0, $results->audienceSize);
        $this->assertNull($results->rate);
    }

    /**
     * SurveyDefinitionTest::branchingDefinition() for the three people, with live results:
     * the first one answers "No" and skips the campaign section, the others answer "Yes".
     */
    private function answerBranching(bool $anonymous): SurveyId
    {
        [$id] = $this->openExample(SurveyKind::Form, SurveyDefinition::fromArray(SurveyDefinitionTest::branchingDefinition()), $anonymous, new ResultsSettings(liveResults: true, visibleToRespondents: true));
        $submissions = $this->app->make(SubmissionService::class);
        $submissions->submit($id, Respondent::account($this->people[0]->id), ['q-played' => 'o-no', 'q-comments' => 'Bien']);
        foreach ([1, 2] as $person) {
            $submissions->submit($id, Respondent::account($this->people[$person]->id), ['q-played' => 'o-yes', 'q-finale' => 'Épico', 'q-comments' => 'Bien']);
        }

        return $id;
    }

    private function question(SurveyResultsDTO $results, string $questionId): QuestionResultDTO
    {
        foreach ($results->questions as $question) {
            if ($question->questionId === $questionId) {
                return $question;
            }
        }

        $this->fail("No results for {$questionId}.");
    }

    /**
     * Numeric keys such as scale values become integers, as PHP does with array keys.
     *
     * @return array<array-key, int>
     */
    private function counts(QuestionResultDTO $question): array
    {
        $counts = [];
        foreach ($question->buckets as $bucket) {
            $counts[$bucket->key] = $bucket->count;
        }

        return $counts;
    }
}
