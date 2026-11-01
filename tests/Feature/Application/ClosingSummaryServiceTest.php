<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use Modules\Surveys\Application\DTOs\Summary\ClosingSummaryDTO;
use Modules\Surveys\Application\DTOs\Summary\SummaryQuestionDTO;
use Modules\Surveys\Application\Services\ClosingSummaryService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Tests\Support\AnswersExampleSurveys;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The summary a closing survey mails: the response rate, the means of scales and stars and the
 * most voted options, built on the results' own rules and never with free text.
 */
final class ClosingSummaryServiceTest extends ModuleTestCase
{
    use AnswersExampleSurveys;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private ClosingSummaryService $summaries;

    protected function setUp(): void
    {
        parent::setUp();

        $this->summaries = $this->app->make(ClosingSummaryService::class);
        $this->createExamplePeople();
    }

    public function test_it_sums_up_choices_scales_and_stars_and_leaves_the_rest_out(): void
    {
        [$id] = $this->openForm(anonymous: false);
        $this->answer($id, 0, ['q-single' => 'o-1', 'q-multiple' => ['o-1', 'o-2'], 'q-dropdown' => 'o-1', 'q-scale' => 1, 'q-stars' => 5, 'q-short' => 'Genial']);
        $this->answer($id, 1, ['q-single' => 'o-2', 'q-multiple' => ['o-2'], 'q-dropdown' => 'o-1', 'q-scale' => 4, 'q-stars' => 4, 'q-short' => 'Bien']);
        $this->close($id);

        $summary = $this->summaries->for($id);

        $this->assertTrue($summary->visible);
        $this->assertSame('Encuesta de ejemplo', $summary->surveyTitle);
        $this->assertSame('Partida de ejemplo', $summary->subjectTitle);
        $this->assertSame(2, $summary->responses);
        $this->assertSame(3, $summary->audienceSize);
        $this->assertEqualsWithDelta(0.6667, $summary->rate, 0.0001);

        $single = $this->question($summary, 'Pregunta q-single');
        $this->assertSame(['Uno', 'Dos'], $single->topOptions);
        $this->assertSame(1, $single->topCount);
        $this->assertSame(2, $single->answered);

        $multiple = $this->question($summary, 'Pregunta q-multiple');
        $this->assertSame(['Dos'], $multiple->topOptions);
        $this->assertSame(2, $multiple->topCount);

        $this->assertSame(['Uno'], $this->question($summary, 'Pregunta q-dropdown')->topOptions);

        $scale = $this->question($summary, 'Pregunta q-scale');
        $this->assertSame(2.5, $scale->mean);
        $this->assertSame(1, $scale->scaleMin);
        $this->assertSame(5, $scale->scaleMax);
        $this->assertSame([], $scale->topOptions);
        $this->assertSame(4.5, $this->question($summary, 'Pregunta q-stars')->mean);

        // Short and long text, date, time, grid and ranking are not summed up
        $this->assertCount(5, $summary->questions);
        $this->assertSame(6, $summary->otherQuestions);
        $this->assertNotContains('Pregunta q-rank', array_map(static fn (SummaryQuestionDTO $question): string => $question->title, $summary->questions));
        $this->assertStringNotContainsString('Genial', serialize($summary));
    }

    public function test_other_wins_by_name_without_its_text(): void
    {
        [$id] = $this->openForm(anonymous: false);
        $this->answer($id, 0, ['q-single' => ['options' => [Submission::OTHER], 'other' => 'Una distinta']]);
        $this->answer($id, 1, ['q-single' => ['options' => [Submission::OTHER], 'other' => 'Otra más']]);
        $this->answer($id, 2, ['q-single' => 'o-1']);
        $this->close($id);

        $summary = $this->summaries->for($id);

        $single = $this->question($summary, 'Pregunta q-single');
        $this->assertSame([null], $single->topOptions);
        $this->assertSame(2, $single->topCount);
        $this->assertStringNotContainsString('Una distinta', serialize($summary));
    }

    public function test_an_anonymous_survey_below_the_minimum_gives_nothing_away(): void
    {
        [$id] = $this->openForm(anonymous: true);
        $this->answer($id, 0);
        $this->answer($id, 1);
        $this->close($id);

        $summary = $this->summaries->for($id);

        $this->assertFalse($summary->visible);
        $this->assertNull($summary->responses);
        $this->assertNull($summary->audienceSize);
        $this->assertNull($summary->rate);
        $this->assertSame([], $summary->questions);
        $this->assertSame(0, $summary->otherQuestions);
    }

    public function test_an_anonymous_question_below_the_minimum_is_named_without_data(): void
    {
        $definition = SurveyDefinitionTest::everyTypeDefinition();
        $definition['sections'][0]['questions'][2]['required'] = false;
        [$id] = $this->openExample(SurveyKind::Form, SurveyDefinition::fromArray($definition), true, ResultsSettings::defaultsFor(SurveyKind::Form));
        $this->answer($id, 0, ['q-single' => 'o-1']);
        $this->answer($id, 1, ['q-single' => null]);
        $this->answer($id, 2, ['q-single' => null]);
        $this->close($id);

        $summary = $this->summaries->for($id);

        $this->assertTrue($summary->visible);
        $single = $this->question($summary, 'Pregunta q-single');
        $this->assertTrue($single->belowMinimum);
        $this->assertSame([], $single->topOptions);
        $this->assertSame(0, $single->topCount);
        $this->assertSame(0, $single->answered);
        $this->assertFalse($this->question($summary, 'Pregunta q-dropdown')->belowMinimum);
    }

    public function test_questions_hidden_from_the_results_are_left_out(): void
    {
        [$id] = $this->openForm(anonymous: false, results: new ResultsSettings(hiddenQuestionIds: ['q-single', 'q-grid']));
        $this->answer($id, 0);
        $this->close($id);

        $summary = $this->summaries->for($id);

        $titles = array_map(static fn (SummaryQuestionDTO $question): string => $question->title, $summary->questions);
        $this->assertNotContains('Pregunta q-single', $titles);
        $this->assertSame(5, $summary->otherQuestions);
    }

    public function test_a_choice_nobody_answered_has_no_winner(): void
    {
        [$id] = $this->openPoll(anonymous: false);
        $this->close($id);

        $summary = $this->summaries->for($id);

        $question = $this->question($summary, '¿Qué día?');
        $this->assertSame([], $question->topOptions);
        $this->assertSame(0, $question->answered);
        $this->assertSame(0, $summary->responses);
    }

    private function close(SurveyId $id): void
    {
        $this->app->make(SurveyLifecycleService::class)->close($id);
    }

    private function question(ClosingSummaryDTO $summary, string $title): SummaryQuestionDTO
    {
        foreach ($summary->questions as $question) {
            if ($question->title === $title) {
                return $question;
            }
        }

        $this->fail("No summary for «{$title}».");
    }
}
